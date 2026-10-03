<?php
declare(strict_types=1);

namespace Convermetry\Admin\Pages;

if (!defined('ABSPATH')) exit;

use Convermetry\Admin\AdminAssets;
use Convermetry\Admin\AdminRequest;
use Convermetry\Admin\Capability;
use Convermetry\Forms\FormProviderRegistry;
use Convermetry\Notifications\EmailBuilder;
use Convermetry\Notifications\NotificationMailer;
use Convermetry\Notifications\NotificationQueue;
use Convermetry\Notifications\NotificationSettings;
use Convermetry\Notifications\SiteInfo;
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
    private const string SAVE_ACTION = 'cvmtry_save_notifications';

    /** admin-post action name for discarding queued notifications. */
    private const string CANCEL_ACTION = 'cvmtry_cancel_notifications';

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
        add_action('wp_ajax_cvmtry_test_notification', [self::class, 'handleTestAjax']);
    }

    /**
     * Adds the Notifications submenu, between Forms and Webhooks.
     *
     * @return void
     */
    public static function addMenu(): void
    {
        add_submenu_page(
            HomePage::MENU_SLUG,
            __('Convermetry Notifications', 'convermetry'),
            __('Notifications', 'convermetry'),
            Capability::required(Capability::NOTIFICATIONS_MANAGE),
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

        wp_enqueue_style(
            'cvmtry-notifications',
            CVMTRY_PLUGIN_URL . 'assets/css/admin-notifications.css',
            [AdminAssets::COMMON_HANDLE],
            CVMTRY_VERSION
        );

        wp_enqueue_script('cvmtry-admin', CVMTRY_PLUGIN_URL . 'assets/js/admin.js', ['wp-i18n'], CVMTRY_VERSION, true);
        wp_set_script_translations('cvmtry-admin', 'convermetry');

        wp_localize_script('cvmtry-admin', 'CVMTRY_NOTIFY', [
            'ajaxUrl'   => admin_url('admin-ajax.php'),
            'testNonce' => wp_create_nonce('cvmtry_test_notification'),
        ]);
    }

    /**
     * Validates and persists the notification settings POST
     * (admin_post_cvmtry_save_notifications).
     *
     * Per-form rules follow the Forms page's merge contract: only forms
     * actually rendered in this request are replaced, so a provider that is
     * temporarily deactivated (or a form discovery missed) keeps its stored
     * rule instead of being silently wiped by an unrelated save.
     *
     * @return never
     */
    public static function handleSave(): never
    {
        // 1. Method: only the settings form's POST.
        if (!AdminRequest::isPost()) {
            AdminRequest::deny(__('Notification settings can only be saved from the Notifications screen.', 'convermetry'), 405);
        }

        // 2. Capability: recipients and what they are sent.
        if (!current_user_can(Capability::required(Capability::NOTIFICATIONS_MANAGE))) {
            AdminRequest::deny(AdminRequest::forbiddenMessage());
        }

        // 3. Nonce present, as one string.
        if (!isset($_POST['cvmtry_notifications_nonce']) || !is_string($_POST['cvmtry_notifications_nonce'])) {
            AdminRequest::deny(AdminRequest::expiredMessage());
        }

        // 4. Nonce issued for saving notification settings — the queue
        // form's nonce shares the field name but not the action.
        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['cvmtry_notifications_nonce'])), self::SAVE_ACTION)) {
            AdminRequest::deny(AdminRequest::expiredMessage());
        }

        // 5. Input: the settings, shape-checked then sanitized field by field.
        // A malformed request changes nothing rather than resetting whatever
        // it happened to omit.
        if (!isset($_POST['cvmtry_notifications']) || !is_array($_POST['cvmtry_notifications'])) {
            self::redirectMalformed();
        }

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- unslashed once here; NotificationSettings::fromSubmission() type-checks every field and sanitizes each for its meaning (emails, subject text, fixed sets).
        $clean = NotificationSettings::fromSubmission(wp_unslash($_POST['cvmtry_notifications']));

        if ($clean === null) {
            self::redirectMalformed();
        }

        // The forms this page listed. Absent when no form was discovered.
        $rendered = [];
        if (isset($_POST['cvmtry_rendered_forms'])) {
            if (!is_array($_POST['cvmtry_rendered_forms'])) {
                self::redirectMalformed();
            }

            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- unslashed once here; each element is type-checked and validated as a form key by renderedFormKeys().
            $rendered = self::renderedFormKeys(wp_unslash($_POST['cvmtry_rendered_forms']));

            if ($rendered === null) {
                self::redirectMalformed();
            }
        }

        $clean['forms'] = self::mergeFormRules(
            is_array($clean['forms'] ?? null) ? $clean['forms'] : [],
            $rendered
        );

        // Non-autoloaded: the rule map grows with the site, and on a default
        // install this option is read at most once per submission.
        update_option(Options::NOTIFICATION_OPTION_KEY, $clean, false);

        wp_safe_redirect(add_query_arg(
            ['page' => self::MENU_SLUG, 'cvmtry_saved' => '1'],
            self_admin_url('admin.php')
        ));
        exit;
    }

    /**
     * The form keys a save listed as rendered.
     *
     * Each key is validated, never rewritten — a form key's identity half
     * can be a display name with spaces and capitals, and a rewritten key
     * would never match its form again (see
     * {@see FormProviderRegistry::validFormKey()}). A string that is not a
     * form key is skipped; it names no form, so skipping it touches nothing.
     *
     * @param array<array-key, mixed> $raw The unslashed cvmtry_rendered_forms list.
     * @return list<string>|null Null when an element is not a string at all.
     */
    private static function renderedFormKeys(array $raw): ?array
    {
        $keys = [];

        foreach ($raw as $candidate) {
            if (!is_string($candidate)) {
                return null;
            }

            $key = FormProviderRegistry::validFormKey($candidate);
            if ($key !== '') {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /**
     * Returns to the screen with the "not saved" notice, storing nothing.
     *
     * @return never
     */
    private static function redirectMalformed(): never
    {
        wp_safe_redirect(add_query_arg(
            ['page' => self::MENU_SLUG, 'cvmtry_error' => 'malformed'],
            self_admin_url('admin.php')
        ));
        exit;
    }

    /**
     * Replaces rules only for the forms this page actually rendered.
     *
     * @param array<string, string> $submitted Sanitized rules from the POST.
     * @param list<string>          $rendered  Validated form keys shown on the saving page.
     * @return array<string, string>
     */
    private static function mergeFormRules(array $submitted, array $rendered): array
    {
        $stored = Options::notificationAll()['forms'] ?? [];
        $merged = is_array($stored) ? NotificationSettings::sanitizeFormRules($stored) : [];

        foreach ($rendered as $formKey) {
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
     * Discards every queued notification
     * (admin_post_cvmtry_cancel_notifications).
     *
     * The queue does not pause when the master switch is turned off — already
     * queued messages send under the settings frozen when the lead arrived.
     * This is the explicit escape hatch for an admin who wants them dropped.
     *
     * @return never
     */
    public static function handleCancelQueued(): never
    {
        // 1. Method: only the Discard form's POST.
        if (!AdminRequest::isPost()) {
            AdminRequest::deny(__('Queued notifications can only be discarded from the Notifications screen.', 'convermetry'), 405);
        }

        // 2. Capability: notification configuration and its queue.
        if (!current_user_can(Capability::required(Capability::NOTIFICATIONS_MANAGE))) {
            AdminRequest::deny(AdminRequest::forbiddenMessage());
        }

        // 3. Nonce present, as one string.
        if (!isset($_POST['cvmtry_notifications_nonce']) || !is_string($_POST['cvmtry_notifications_nonce'])) {
            AdminRequest::deny(AdminRequest::expiredMessage());
        }

        // 4. Nonce issued for discarding the queue — the settings form's
        // nonce shares the field name but not the action.
        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['cvmtry_notifications_nonce'])), self::CANCEL_ACTION)) {
            AdminRequest::deny(AdminRequest::expiredMessage());
        }

        // 5. No further input: the action takes no parameters.
        NotificationQueue::cancelAll();

        wp_safe_redirect(add_query_arg(
            ['page' => self::MENU_SLUG, 'cvmtry_cancelled' => '1'],
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
        // 1. Method: the test button POSTs.
        if (!AdminRequest::isPost()) {
            AdminRequest::denyAjax(__('Invalid request.', 'convermetry'), 405);
        }

        // 2. Capability: notification configuration.
        if (!current_user_can(Capability::required(Capability::NOTIFICATIONS_MANAGE))) {
            AdminRequest::denyAjax(AdminRequest::forbiddenMessage());
        }

        // 3. Nonce present, as one string.
        if (!isset($_POST['nonce']) || !is_string($_POST['nonce'])) {
            AdminRequest::denyAjax(AdminRequest::expiredMessage());
        }

        // 4. Nonce issued for a test send.
        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'cvmtry_test_notification')) {
            AdminRequest::denyAjax(AdminRequest::expiredMessage());
        }

        // 5. Input: one valid email address, checked before anything is built
        // or sent.
        $recipient = isset($_POST['recipient']) && is_string($_POST['recipient'])
            ? sanitize_email(wp_unslash($_POST['recipient']))
            : '';

        if ($recipient === '' || !is_email($recipient)) {
            AdminRequest::denyAjax(__('Enter a valid recipient email address first.', 'convermetry'), 400);
        }

        $settings = Options::notificationAll();
        $siteInfo = SiteInfo::current();
        $snapshot = NotificationSettings::normalizeSnapshot(
            NotificationSettings::snapshot($settings, 'convermetry:test', $siteInfo)
        );

        $message = EmailBuilder::testMessage($snapshot, $siteInfo);
        $result  = NotificationMailer::send($recipient, $message['subject'], $message['html']);

        wp_send_json_success([
            'ok'      => $result->ok,
            'message' => $result->ok
                // Deliberately not "delivered": wp_mail() returning true means
                // the local transport accepted the message, nothing more.
                ? __('Handed to your site\'s mail system. Check the inbox (and spam folder) to confirm it arrived.', 'convermetry')
                : ($result->message !== '' ? $result->message : __('Your site\'s mail system rejected the message.', 'convermetry')),
        ]);
    }

    /**
     * Shows saved/cancelled/malformed notices and any recent permanent send failure.
     *
     * @return void
     */
    public static function maybeShowNotices(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- display-only: identifies this screen and reads the flags from the redirects after handleSave() and handleCancelQueued(), which verify their nonce and capability; each flag is compared with fixed values and only selects one of the fixed notices below.
        $page      = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        $saved     = isset($_GET['cvmtry_saved']) && sanitize_key(wp_unslash($_GET['cvmtry_saved'])) === '1';
        $cancelled = isset($_GET['cvmtry_cancelled']) && sanitize_key(wp_unslash($_GET['cvmtry_cancelled'])) === '1';
        $malformed = isset($_GET['cvmtry_error']) && sanitize_key(wp_unslash($_GET['cvmtry_error'])) === 'malformed';
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        if ($page !== self::MENU_SLUG) {
            return;
        }

        if ($malformed) {
            ?>
            <div class="notice notice-error is-dismissible"><p><?php esc_html_e('Notification settings were not saved: the submitted form was incomplete or malformed, so the stored settings were left unchanged. Reload this page and try again.', 'convermetry'); ?></p></div>
            <?php
        }

        if ($saved) {
            ?>
            <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Notification settings saved.', 'convermetry'); ?></p></div>
            <?php
        }

        if ($cancelled) {
            ?>
            <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Queued notifications discarded.', 'convermetry'); ?></p></div>
            <?php
        }

        // Without this, a site whose mail system is broken sends nothing,
        // forever, with no signal anywhere in the admin.
        $failure = get_transient(NotificationQueue::FAILURE_TRANSIENT);
        if (is_array($failure)) {
            ?>
            <div class="notice notice-warning is-dismissible"><p><strong><?php esc_html_e('A notification could not be sent.', 'convermetry'); ?></strong> <?php echo esc_html(sprintf(
                    /* translators: 1: recipient email address, 2: the mail system's error message. */
                    __('The last attempt to email %1$s failed and was given up on: %2$s', 'convermetry'),
                    (string) ($failure['recipient'] ?? __('a recipient', 'convermetry')),
                    (string) ($failure['error'] ?? __('no reason reported', 'convermetry'))
                )); ?> <?php esc_html_e('This usually means the site cannot send mail at all — an SMTP plugin normally fixes it.', 'convermetry'); ?></p></div>
            <?php
        }
    }

    /**
     * Renders the Notifications page.
     *
     * @return void
     */
    public static function render(): void
    {
        if (!Capability::currentUserCan(Capability::NOTIFICATIONS_MANAGE)) {
            return;
        }

        $settings = Options::notificationAll();
        $includes = Options::notificationIncludes();
        $enabled  = !empty($settings['enabled']);
        $scope    = Options::notificationScope();

        ?>
        <div class="wrap cvmtry-wrap">
        <h1><?php esc_html_e('Convermetry Notifications', 'convermetry'); ?></h1>
        <p class="description"><?php esc_html_e('Send an internal email whenever a form submission is recorded, enriched with the analytics context Convermetry already captured for that visitor.', 'convermetry'); ?></p>
        <?php

        self::renderPrivacyCard();

        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <?php
        wp_nonce_field(self::SAVE_ACTION, 'cvmtry_notifications_nonce');
        ?>
        <input type="hidden" name="action" value="<?php echo esc_attr(self::SAVE_ACTION); ?>">
        <?php

        self::renderMasterSection($enabled, $settings);
        self::renderContentSection($includes);
        self::renderFormsSection($scope);

        submit_button(__('Save Notification Settings', 'convermetry'));
        ?>
        </form>
        <?php

        self::renderQueueSection();

        ?>
        </div>
        <?php
    }

    /**
     * The privacy and expectations card.
     *
     * @return void
     */
    private static function renderPrivacyCard(): void
    {
        ?>
        <div class="cvmtry-card"><span class="cvmtry-card-label"><?php esc_html_e('Before you switch this on', 'convermetry'); ?></span><ul>
        <li><?php echo wp_kses_post(__('<strong>Email creates a copy of lead data outside Convermetry\'s control.</strong> Deleting a submission, or letting retention expire it, cancels any notification still queued — but it cannot recall a message that has already been sent. Those copies live in the recipients\' mailboxes under whatever retention policy applies there, not yours.', 'convermetry')); ?></li>
        <li><?php echo wp_kses_post(__('<strong>Your form plugin may already email you.</strong> Most form plugins send their own notification. These are in addition to those, not a replacement — check before you end up with two.', 'convermetry')); ?></li>
        <li><?php echo wp_kses_post(__('<strong>"Sent" means handed to your mail system.</strong> Convermetry can tell you that WordPress accepted a message, which is not the same as it reaching an inbox. Nothing here can confirm delivery or detect spam foldering.', 'convermetry')); ?></li>
        <li><?php echo wp_kses_post(__('Notifications are <strong>internal only</strong>. Convermetry never emails the person who submitted the form, and never uses a submitted address as the sender.', 'convermetry')); ?></li>
        <li><?php echo wp_kses_post(__('Convermetry uses <code>wp_mail()</code>, so any SMTP plugin you already run keeps working. It stores no mail credentials of its own.', 'convermetry')); ?></li></ul></div>
        <?php
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

        ?>
        <h2><?php esc_html_e('Delivery', 'convermetry'); ?></h2><table class="form-table" role="presentation"><tbody>
        <tr><th scope="row"><?php esc_html_e('Notifications', 'convermetry'); ?></th><td>
        <label><input type="checkbox" name="cvmtry_notifications[enabled]" value="1" <?php echo checked($enabled, true, false); ?>>
        <?php esc_html_e('Email me when a form submission is recorded', 'convermetry'); ?></label>
        <p class="description"><?php echo wp_kses_post(__('Off by default. Turning this off stops new notifications; any already queued (at most about two hours\' worth) still send with the settings that were active when the lead arrived. Use <em>Discard queued notifications</em> below to drop them instead.', 'convermetry')); ?></p></td></tr>
        <tr><th scope="row"><label for="cvmtry-notify-recipients"><?php esc_html_e('Send to', 'convermetry'); ?></label></th><td>
        <textarea id="cvmtry-notify-recipients" name="cvmtry_notifications[recipients]" rows="4" class="large-text" placeholder="sales@example.com&#10;owner@example.com"><?php echo esc_textarea(implode("\n", array_map('strval', $recipients))); ?></textarea>
        <p class="description"><?php
        echo esc_html(sprintf(
            /* translators: %d: maximum number of notification recipients. */
            __('One address per line (commas and semicolons also work). Invalid addresses and duplicates are removed when you save. Maximum %d. Each recipient gets their own message, so nobody sees who else is on the list.', 'convermetry'),
            NotificationSettings::MAX_RECIPIENTS
        ));
        ?></p></td></tr>
        <tr><th scope="row"><label for="cvmtry-notify-subject"><?php esc_html_e('Subject', 'convermetry'); ?></label></th><td>
        <input type="text" id="cvmtry-notify-subject" name="cvmtry_notifications[subject]" class="large-text" value="<?php echo esc_attr(Options::notificationSubjectTemplate()); ?>">
        <p class="description"><?php echo wp_kses_post(__('Available placeholders: <code>{site_name}</code>, <code>{form_name}</code>, <code>{provider}</code>, <code>{channel}</code>, <code>{submission_id}</code>, <code>{form_id}</code>, <code>{campaign}</code>, <code>{date}</code>. Anything else is left as literal text.', 'convermetry')); ?></p></td></tr>
        <tr><th scope="row"><?php esc_html_e('Test', 'convermetry'); ?></th><td>
        <input type="email" id="cvmtry-notify-test-address" class="regular-text" placeholder="you@example.com"> 
        <button type="button" class="button cvmtry-test-notification"><?php esc_html_e('Send test email', 'convermetry'); ?></button> 
        <span class="cvmtry-test-result" role="status" aria-live="polite"></span>
        <p class="description"><?php esc_html_e('Sends a sample built entirely from made-up data. It never reads a real submission, so testing cannot expose a lead.', 'convermetry'); ?></p></td></tr></tbody></table>
        <?php
    }

    /**
     * The four content toggles.
     *
     * @param array{fields: bool, analytics: bool, journey: bool, ip: bool} $includes Current toggles.
     * @return void
     */
    private static function renderContentSection(array $includes): void
    {
        ?>
        <h2><?php esc_html_e('What to include', 'convermetry'); ?></h2><table class="form-table" role="presentation"><tbody>
        <tr><th scope="row"><?php esc_html_e('Submitted fields', 'convermetry'); ?></th><td>
        <label><input type="checkbox" name="cvmtry_notifications[include_fields]" value="1" <?php echo checked($includes['fields'], true, false); ?>>
        <?php esc_html_e('Include the visitor\'s answers', 'convermetry'); ?></label>
        <p class="description"><?php esc_html_e('Fields that look like credentials — passwords, tokens, API keys, secrets, authorization values — are always left out, even with this on.', 'convermetry'); ?></p></td></tr>
        <tr><th scope="row"><?php esc_html_e('Analytics summary', 'convermetry'); ?></th><td>
        <label><input type="checkbox" name="cvmtry_notifications[include_analytics]" value="1" <?php echo checked($includes['analytics'], true, false); ?>>
        <?php esc_html_e('Include channel, campaign, and session details', 'convermetry'); ?></label>
        <p class="description"><?php esc_html_e('Channel, UTM source/medium/campaign, landing page, device, pages viewed, and session start. When a visitor could not be correlated, the email says so explicitly.', 'convermetry'); ?></p></td></tr>
        <tr><th scope="row"><?php esc_html_e('Visitor journey', 'convermetry'); ?></th><td>
        <label><input type="checkbox" name="cvmtry_notifications[include_journey]" value="1" <?php echo checked($includes['journey'], true, false); ?>>
        <?php esc_html_e('Include the pages this visitor viewed', 'convermetry'); ?></label>
        <p class="description"><?php echo wp_kses_post(__('<strong>Off by default.</strong> This is browsing history for an identifiable person; mailing it to a shared inbox is a policy decision worth making deliberately.', 'convermetry')); ?></p></td></tr>
        <tr><th scope="row"><?php esc_html_e('IP address', 'convermetry'); ?></th><td>
        <label><input type="checkbox" name="cvmtry_notifications[include_ip]" value="1" <?php echo checked($includes['ip'], true, false); ?>>
        <?php esc_html_e('Include the submitter\'s IP address', 'convermetry'); ?></label>
        <p class="description"><?php echo wp_kses_post(__('<strong>Off by default.</strong> An IP address is personal data in the EU and UK. It is only available at all when IP storage is enabled on the Settings page.', 'convermetry')); ?></p></td></tr></tbody></table>
        <?php
    }

    /**
     * The scope selector and per-form rules.
     *
     * @param string $scope Current scope ('all' or 'selected').
     * @return void
     */
    private static function renderFormsSection(string $scope): void
    {
        ?>
        <h2><?php esc_html_e('Which forms', 'convermetry'); ?></h2><table class="form-table" role="presentation"><tbody>
        <tr><th scope="row"><?php esc_html_e('Scope', 'convermetry'); ?></th><td>
        <label><input type="radio" name="cvmtry_notifications[scope]" value="all" <?php echo checked($scope, 'all', false); ?>>
        <?php esc_html_e('Every form, except those switched off below', 'convermetry'); ?></label><br>
        <label><input type="radio" name="cvmtry_notifications[scope]" value="selected" <?php echo checked($scope, 'selected', false); ?>>
        <?php esc_html_e('Only the forms switched on below', 'convermetry'); ?></label></td></tr></tbody></table>
        <?php

        $discovered = self::discoveredForms();

        if ($discovered === []) {
            ?>
            <div class="notice notice-info inline"><p><?php echo wp_kses_post(__('No forms have been discovered yet. Under <em>Every form</em>, any form that appears later will notify automatically.', 'convermetry')); ?></p></div>
            <?php
            return;
        }

        ?>
        <table class="widefat striped"><thead><tr><th scope="col"><?php esc_html_e('Form', 'convermetry'); ?></th><th scope="col"><?php esc_html_e('Provider', 'convermetry'); ?></th><th scope="col"><?php esc_html_e('Notifications', 'convermetry'); ?></th></tr></thead><tbody>
        <?php

        foreach ($discovered as $form) {
            $formKey = (string) $form['form_key'];
            $rule    = Options::notificationFormRule($formKey);

            ?>
            <tr>
            <td><?php echo esc_html((string) $form['name']); ?><br><code><?php echo esc_html($formKey); ?></code></td>
            <td><?php echo esc_html((string) $form['provider_label']); ?></td>
            <td>
            <input type="hidden" name="cvmtry_rendered_forms[]" value="<?php echo esc_attr($formKey); ?>">
            <select name="cvmtry_notifications[forms][<?php echo esc_attr($formKey); ?>]">
            <?php
            $rules = [
                'inherit'  => __('Use the scope above', 'convermetry'),
                'enabled'  => __('Always notify', 'convermetry'),
                'disabled' => __('Never notify', 'convermetry'),
            ];
            foreach ($rules as $value => $label) {
                ?>
                <option value="<?php echo esc_attr($value); ?>" <?php echo selected($rule, $value, false); ?>><?php echo esc_html($label); ?></option>
                <?php
            }
            ?>
            </select></td></tr>
            <?php
        }

        ?>
        </tbody></table>
        <p class="description"><?php echo wp_kses_post(__('Elementor forms are identified by their <em>name</em>, so renaming an Elementor form resets its rule here to the scope default — the same behaviour as the Forms page.', 'convermetry')); ?></p>
        <?php
    }

    /**
     * The pending-queue readout and the discard action.
     *
     * @return void
     */
    private static function renderQueueSection(): void
    {
        $pending = NotificationQueue::pendingCount();

        ?>
        <h2><?php esc_html_e('Queue', 'convermetry'); ?></h2>
        <p><?php echo esc_html(sprintf(
            /* translators: %d: number of queued notification emails. */
            _n('%d notification waiting to be sent.', '%d notifications waiting to be sent.', $pending, 'convermetry'),
            $pending
        )); ?></p>
        <?php

        if ($pending > 0) {
            ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php
            wp_nonce_field(self::CANCEL_ACTION, 'cvmtry_notifications_nonce');
            ?>
            <input type="hidden" name="action" value="<?php echo esc_attr(self::CANCEL_ACTION); ?>">
            <?php
            submit_button(__('Discard queued notifications', 'convermetry'), 'delete', 'submit', false);
            ?>
            </form>
            <?php
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
