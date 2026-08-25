<?php
declare(strict_types=1);

namespace Convermetry\Admin;

if (!defined('ABSPATH')) exit;

use Convermetry\Forms\FormProviderRegistry;
use Convermetry\Notifications\EmailBuilder;
use Convermetry\Notifications\NotificationMailer;
use Convermetry\Notifications\NotificationQueue;
use Convermetry\Notifications\NotificationSettings;
use Convermetry\Settings\Options;

/**
 * The Convermetry → Notifications admin page.
 *
 * Internal email notifications get their own page rather than a checkbox on
 * Settings because there is real configuration here — recipients, a subject
 * template, per-form rules, and four content toggles with genuine privacy
 * consequences — and because the privacy explanation attached to it needs room
 * to be read.
 *
 * These are INTERNAL notifications only. Nothing here mails the visitor;
 * autoresponders are deliberately out of scope, and recipients are always
 * addresses an administrator typed, never anything derived from submitted
 * data.
 */
final class NotificationsPage
{
    /** Menu slug for the submenu page. */
    public const string MENU_SLUG = 'convermetry-notifications';

    /** admin-post action name for saving the page. */
    private const string SAVE_ACTION = 'cvm_save_notifications';

    /** admin-post action name for discarding queued notifications. */
    private const string CANCEL_ACTION = 'cvm_cancel_notifications';

    private static ?FormProviderRegistry $registry = null;

    /**
     * Registers menu, save, notice, asset, and AJAX hooks.
     *
     * @param FormProviderRegistry $registry The shared provider registry.
     * @return void
     */
    public static function init(FormProviderRegistry $registry): void
    {
        self::$registry = $registry;

        add_action('admin_menu', [self::class, 'addMenu']);
        add_action('admin_post_' . self::SAVE_ACTION, [self::class, 'handleSave']);
        add_action('admin_post_' . self::CANCEL_ACTION, [self::class, 'handleCancelQueued']);
        add_action('admin_notices', [self::class, 'maybeShowNotices']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueueAssets']);
        add_action('wp_ajax_cvm_test_notification', [self::class, 'handleTestAjax']);
    }

    /**
     * Adds the Notifications submenu, between Forms and Webhooks.
     *
     * @return void
     */
    public static function addMenu(): void
    {
        add_submenu_page(
            AnalyticsPage::MENU_SLUG,
            'Convermetry Notifications',
            'Notifications',
            'manage_options',
            self::MENU_SLUG,
            [self::class, 'render']
        );
    }

    /**
     * Enqueues the shared admin script on this page only.
     *
     * @param string $hook The current admin page hook suffix.
     * @return void
     */
    public static function enqueueAssets(string $hook): void
    {
        if (!str_contains($hook, self::MENU_SLUG)) {
            return;
        }

        wp_enqueue_script('cvm-admin', CVM_PLUGIN_URL . 'assets/js/admin.js', [], CVM_VERSION, true);

        wp_localize_script('cvm-admin', 'CVM_NOTIFY', [
            'ajaxUrl'   => admin_url('admin-ajax.php'),
            'testNonce' => wp_create_nonce('cvm_test_notification'),
        ]);
    }

    /**
     * Validates and persists the notification settings POST.
     *
     * Per-form rules follow the Forms page's merge contract: only forms
     * actually rendered in this request are replaced, so a provider that is
     * temporarily deactivated (or a form discovery missed) keeps its stored
     * rule instead of being silently wiped by an unrelated save.
     *
     * @return void
     */
    public static function handleSave(): void
    {
        if (
            !current_user_can('manage_options')
            || !isset($_POST['cvm_notifications_nonce'])
            || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['cvm_notifications_nonce'])), self::SAVE_ACTION)
        ) {
            wp_die('Invalid request.');
        }

        $raw = isset($_POST['cvm_notifications']) && is_array($_POST['cvm_notifications'])
            ? wp_unslash($_POST['cvm_notifications'])
            : [];

        $rendered = isset($_POST['cvm_rendered_forms']) && is_array($_POST['cvm_rendered_forms'])
            ? array_map(
                static fn(mixed $key): string => sanitize_text_field((string) $key),
                wp_unslash($_POST['cvm_rendered_forms'])
            )
            : [];

        $clean = NotificationSettings::sanitize($raw);

        $clean['forms'] = self::mergeFormRules(
            NotificationSettings::sanitizeFormRules($raw['forms'] ?? []),
            $rendered
        );

        // Non-autoloaded: the rule map grows with the site, and on a default
        // install this option is read at most once per submission.
        update_option(Options::NOTIFICATION_OPTION_KEY, $clean, false);

        wp_safe_redirect(add_query_arg(
            ['page' => self::MENU_SLUG, 'cvm_saved' => '1'],
            self_admin_url('admin.php')
        ));
        exit;
    }

    /**
     * Replaces rules only for the forms this page actually rendered.
     *
     * @param array<string, string> $submitted Sanitized rules from the POST.
     * @param list<string>          $rendered  Form keys shown on the saving page.
     * @return array<string, string>
     */
    private static function mergeFormRules(array $submitted, array $rendered): array
    {
        $stored = Options::notificationAll()['forms'] ?? [];
        $merged = is_array($stored) ? NotificationSettings::sanitizeFormRules($stored) : [];

        foreach ($rendered as $formKey) {
            if ($formKey === '') {
                continue;
            }

            // 'inherit' is the absence of a rule, so a form returned to
            // inherit is removed rather than stored.
            if (isset($submitted[$formKey])) {
                $merged[$formKey] = $submitted[$formKey];
            } else {
                unset($merged[$formKey]);
            }
        }

        return $merged;
    }

    /**
     * Discards every queued notification.
     *
     * The queue does not pause when the master switch is turned off — already
     * queued messages send under the settings frozen when the lead arrived.
     * This is the explicit escape hatch for an admin who wants them dropped.
     *
     * @return void
     */
    public static function handleCancelQueued(): void
    {
        if (
            !current_user_can('manage_options')
            || !isset($_POST['cvm_notifications_nonce'])
            || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['cvm_notifications_nonce'])), self::CANCEL_ACTION)
        ) {
            wp_die('Invalid request.');
        }

        NotificationQueue::cancelAll();

        wp_safe_redirect(add_query_arg(
            ['page' => self::MENU_SLUG, 'cvm_cancelled' => '1'],
            self_admin_url('admin.php')
        ));
        exit;
    }

    /**
     * Sends a synthetic test message to one address.
     *
     * The message is built entirely from fabricated data — it never loads a
     * submission, so a test send cannot expose a real lead. It honors the
     * SAVED content toggles, so what arrives is what the current configuration
     * actually produces.
     *
     * @return never
     */
    public static function handleTestAjax(): never
    {
        if (
            !isset($_POST['nonce'])
            || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'cvm_test_notification')
            || !current_user_can('manage_options')
        ) {
            wp_send_json_error(['message' => 'Unauthorized.']);
        }

        $recipient = sanitize_email((string) wp_unslash($_POST['recipient'] ?? ''));
        if ($recipient === '' || !is_email($recipient)) {
            wp_send_json_error(['message' => 'Enter a valid recipient email address first.']);
        }

        $settings = Options::notificationAll();
        $siteInfo = EmailBuilder::siteInfo();
        $snapshot = NotificationSettings::normalizeSnapshot(
            NotificationSettings::snapshot($settings, 'convermetry:test', $siteInfo)
        );

        $message = EmailBuilder::testMessage($snapshot, $siteInfo);
        $result  = NotificationMailer::send($recipient, $message['subject'], $message['html']);

        wp_send_json_success([
            'ok'      => $result['ok'],
            'message' => $result['ok']
                // Deliberately not "delivered": wp_mail() returning true means
                // the local transport accepted the message, nothing more.
                ? 'Handed to your site\'s mail system. Check the inbox (and spam folder) to confirm it arrived.'
                : ($result['message'] !== '' ? $result['message'] : 'Your site\'s mail system rejected the message.'),
        ]);
    }

    /**
     * Shows saved/cancelled notices and any recent permanent send failure.
     *
     * @return void
     */
    public static function maybeShowNotices(): void
    {
        if (!isset($_GET['page']) || $_GET['page'] !== self::MENU_SLUG) {
            return;
        }

        if (!empty($_GET['cvm_saved'])) {
            echo '<div class="notice notice-success is-dismissible"><p>Notification settings saved.</p></div>';
        }

        if (!empty($_GET['cvm_cancelled'])) {
            echo '<div class="notice notice-success is-dismissible"><p>Queued notifications discarded.</p></div>';
        }

        // Without this, a site whose mail system is broken sends nothing,
        // forever, with no signal anywhere in the admin.
        $failure = get_transient(NotificationQueue::FAILURE_TRANSIENT);
        if (is_array($failure)) {
            echo '<div class="notice notice-warning is-dismissible"><p><strong>A notification could not be sent.</strong> '
                . esc_html(sprintf(
                    'The last attempt to %s failed and was given up on: %s',
                    (string) ($failure['recipient'] ?? 'a recipient'),
                    (string) ($failure['error'] ?? 'no reason reported')
                ))
                . ' ' . esc_html('This usually means the site cannot send mail at all — an SMTP plugin normally fixes it.')
                . '</p></div>';
        }
    }

    /**
     * Renders the Notifications page.
     *
     * @return void
     */
    public static function render(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $settings = Options::notificationAll();
        $includes = Options::notificationIncludes();
        $enabled  = !empty($settings['enabled']);
        $scope    = Options::notificationScope();

        echo '<div class="wrap cvm-wrap">';
        echo '<h1>Convermetry Notifications</h1>';

        echo '<p class="description">Send an internal email whenever a form submission is recorded, '
            . 'enriched with the analytics context Convermetry already captured for that visitor.</p>';

        self::renderPrivacyCard();

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field(self::SAVE_ACTION, 'cvm_notifications_nonce');
        echo '<input type="hidden" name="action" value="' . esc_attr(self::SAVE_ACTION) . '">';

        self::renderMasterSection($enabled, $settings);
        self::renderContentSection($includes);
        self::renderFormsSection($scope);

        submit_button('Save Notification Settings');
        echo '</form>';

        self::renderQueueSection();

        echo '</div>';
    }

    /**
     * The privacy and expectations card.
     *
     * @return void
     */
    private static function renderPrivacyCard(): void
    {
        echo '<div class="cvm-card"><span class="cvm-card-label">Before you switch this on</span><ul>';

        echo '<li><strong>Email creates a copy of lead data outside Convermetry\'s control.</strong> '
            . 'Deleting a submission, or letting retention expire it, cancels any notification still queued — '
            . 'but it cannot recall a message that has already been sent. Those copies live in the recipients\' '
            . 'mailboxes under whatever retention policy applies there, not yours.</li>';

        echo '<li><strong>Your form plugin may already email you.</strong> Most form plugins send their own '
            . 'notification. These are in addition to those, not a replacement — check before you end up with two.</li>';

        echo '<li><strong>"Sent" means handed to your mail system.</strong> Convermetry can tell you that WordPress '
            . 'accepted a message, which is not the same as it reaching an inbox. Nothing here can confirm delivery '
            . 'or detect spam foldering.</li>';

        echo '<li>Notifications are <strong>internal only</strong>. Convermetry never emails the person who '
            . 'submitted the form, and never uses a submitted address as the sender.</li>';

        echo '<li>Convermetry uses <code>wp_mail()</code>, so any SMTP plugin you already run keeps working. '
            . 'It stores no mail credentials of its own.</li>';

        echo '</ul></div>';
    }

    /**
     * The master switch, recipients, subject, and test send.
     *
     * @param bool                 $enabled  Whether notifications are on.
     * @param array<string, mixed> $settings Full notification settings.
     * @return void
     */
    private static function renderMasterSection(bool $enabled, array $settings): void
    {
        $recipients = is_array($settings['recipients'] ?? null) ? $settings['recipients'] : [];

        echo '<h2>Delivery</h2><table class="form-table" role="presentation"><tbody>';

        echo '<tr><th scope="row">Notifications</th><td>';
        echo '<label><input type="checkbox" name="cvm_notifications[enabled]" value="1" '
            . checked($enabled, true, false) . '> Email me when a form submission is recorded</label>';
        echo '<p class="description">Off by default. Turning this off stops new notifications; any already queued '
            . '(at most about two hours\' worth) still send with the settings that were active when the lead '
            . 'arrived. Use <em>Discard queued notifications</em> below to drop them instead.</p>';
        echo '</td></tr>';

        echo '<tr><th scope="row"><label for="cvm-notify-recipients">Send to</label></th><td>';
        echo '<textarea id="cvm-notify-recipients" name="cvm_notifications[recipients]" rows="4" class="large-text" '
            . 'placeholder="sales@example.com&#10;owner@example.com">'
            . esc_textarea(implode("\n", array_map('strval', $recipients)))
            . '</textarea>';
        echo '<p class="description">One address per line (commas and semicolons also work). Invalid addresses and '
            . 'duplicates are removed when you save. Maximum '
            . esc_html((string) NotificationSettings::MAX_RECIPIENTS) . '. Each recipient gets their own '
            . 'message, so nobody sees who else is on the list.</p>';
        echo '</td></tr>';

        echo '<tr><th scope="row"><label for="cvm-notify-subject">Subject</label></th><td>';
        echo '<input type="text" id="cvm-notify-subject" name="cvm_notifications[subject]" class="large-text" '
            . 'value="' . esc_attr(Options::notificationSubjectTemplate()) . '">';
        echo '<p class="description">Available placeholders: <code>{site_name}</code>, <code>{form_name}</code>, '
            . '<code>{provider}</code>, <code>{channel}</code>, <code>{submission_id}</code>, <code>{form_id}</code>, '
            . '<code>{campaign}</code>, <code>{date}</code>. Anything else is left as literal text.</p>';
        echo '</td></tr>';

        echo '<tr><th scope="row">Test</th><td>';
        echo '<input type="email" id="cvm-notify-test-address" class="regular-text" placeholder="you@example.com"> ';
        echo '<button type="button" class="button cvm-test-notification">Send test email</button> ';
        echo '<span class="cvm-test-result" role="status" aria-live="polite"></span>';
        echo '<p class="description">Sends a sample built entirely from made-up data. It never reads a real '
            . 'submission, so testing cannot expose a lead.</p>';
        echo '</td></tr>';

        echo '</tbody></table>';
    }

    /**
     * The four content toggles.
     *
     * @param array{fields: bool, analytics: bool, journey: bool, ip: bool} $includes Current toggles.
     * @return void
     */
    private static function renderContentSection(array $includes): void
    {
        echo '<h2>What to include</h2><table class="form-table" role="presentation"><tbody>';

        echo '<tr><th scope="row">Submitted fields</th><td>';
        echo '<label><input type="checkbox" name="cvm_notifications[include_fields]" value="1" '
            . checked($includes['fields'], true, false) . '> Include the visitor\'s answers</label>';
        echo '<p class="description">Fields that look like credentials — passwords, tokens, API keys, secrets, '
            . 'authorization values — are always left out, even with this on.</p>';
        echo '</td></tr>';

        echo '<tr><th scope="row">Analytics summary</th><td>';
        echo '<label><input type="checkbox" name="cvm_notifications[include_analytics]" value="1" '
            . checked($includes['analytics'], true, false) . '> Include channel, campaign, and session details</label>';
        echo '<p class="description">Channel, UTM source/medium/campaign, landing page, device, pages viewed, and '
            . 'session start. When a visitor could not be correlated, the email says so explicitly.</p>';
        echo '</td></tr>';

        echo '<tr><th scope="row">Visitor journey</th><td>';
        echo '<label><input type="checkbox" name="cvm_notifications[include_journey]" value="1" '
            . checked($includes['journey'], true, false) . '> Include the pages this visitor viewed</label>';
        echo '<p class="description"><strong>Off by default.</strong> This is browsing history for an identifiable '
            . 'person; mailing it to a shared inbox is a policy decision worth making deliberately.</p>';
        echo '</td></tr>';

        echo '<tr><th scope="row">IP address</th><td>';
        echo '<label><input type="checkbox" name="cvm_notifications[include_ip]" value="1" '
            . checked($includes['ip'], true, false) . '> Include the submitter\'s IP address</label>';
        echo '<p class="description"><strong>Off by default.</strong> An IP address is personal data in the EU and UK. '
            . 'It is only available at all when IP storage is enabled on the Settings page.</p>';
        echo '</td></tr>';

        echo '</tbody></table>';
    }

    /**
     * The scope selector and per-form rules.
     *
     * @param string $scope Current scope ('all' or 'selected').
     * @return void
     */
    private static function renderFormsSection(string $scope): void
    {
        echo '<h2>Which forms</h2><table class="form-table" role="presentation"><tbody>';

        echo '<tr><th scope="row">Scope</th><td>';
        echo '<label><input type="radio" name="cvm_notifications[scope]" value="all" '
            . checked($scope, 'all', false) . '> Every form, except those switched off below</label><br>';
        echo '<label><input type="radio" name="cvm_notifications[scope]" value="selected" '
            . checked($scope, 'selected', false) . '> Only the forms switched on below</label>';
        echo '</td></tr>';

        echo '</tbody></table>';

        $discovered = self::discoveredForms();

        if ($discovered === []) {
            echo '<div class="notice notice-info inline"><p>No forms have been discovered yet. Under '
                . '<em>Every form</em>, any form that appears later will notify automatically.</p></div>';
            return;
        }

        echo '<table class="widefat striped"><thead><tr>'
            . '<th scope="col">Form</th><th scope="col">Provider</th><th scope="col">Notifications</th>'
            . '</tr></thead><tbody>';

        foreach ($discovered as $form) {
            $formKey = (string) $form['form_key'];
            $rule    = Options::notificationFormRule($formKey);

            echo '<tr>';
            echo '<td>' . esc_html((string) $form['name']) . '<br><code>' . esc_html($formKey) . '</code></td>';
            echo '<td>' . esc_html((string) $form['provider_label']) . '</td>';
            echo '<td>';
            echo '<input type="hidden" name="cvm_rendered_forms[]" value="' . esc_attr($formKey) . '">';
            echo '<select name="cvm_notifications[forms][' . esc_attr($formKey) . ']">';
            foreach (['inherit' => 'Use the scope above', 'enabled' => 'Always notify', 'disabled' => 'Never notify'] as $value => $label) {
                echo '<option value="' . esc_attr($value) . '" ' . selected($rule, $value, false) . '>'
                    . esc_html($label) . '</option>';
            }
            echo '</select></td>';
            echo '</tr>';
        }

        echo '</tbody></table>';

        echo '<p class="description">Elementor forms are identified by their <em>name</em>, so renaming an Elementor '
            . 'form resets its rule here to the scope default — the same behaviour as the Forms page.</p>';
    }

    /**
     * The pending-queue readout and the discard action.
     *
     * @return void
     */
    private static function renderQueueSection(): void
    {
        $pending = NotificationQueue::pendingCount();

        echo '<h2>Queue</h2>';
        echo '<p>' . esc_html(sprintf(
            '%d notification%s waiting to be sent.',
            $pending,
            $pending === 1 ? '' : 's'
        )) . '</p>';

        if ($pending > 0) {
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            wp_nonce_field(self::CANCEL_ACTION, 'cvm_notifications_nonce');
            echo '<input type="hidden" name="action" value="' . esc_attr(self::CANCEL_ACTION) . '">';
            submit_button('Discard queued notifications', 'delete', 'submit', false);
            echo '</form>';
        }
    }

    /**
     * Every discovered form, with its settings key.
     *
     * Uses the SHARED registry so discovery transients are not duplicated.
     *
     * @return list<array{form_key: string, name: string, provider_label: string}>
     */
    private static function discoveredForms(): array
    {
        $registry = self::$registry;
        if ($registry === null) {
            return [];
        }

        $out = [];

        foreach ($registry->all() as $provider) {
            if (!$provider->isAvailable()) {
                continue;
            }

            foreach ($registry->discoveredForms($provider) as $form) {
                $out[] = [
                    'form_key'       => FormProviderRegistry::formKey($provider->getKey(), (string) $form['native_id']),
                    'name'           => (string) $form['name'],
                    'provider_label' => $provider->getLabel(),
                ];
            }
        }

        return $out;
    }
}
