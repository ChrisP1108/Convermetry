<?php
declare(strict_types=1);

namespace Convermetry\Admin;

if (!defined('ABSPATH')) exit;

use Convermetry\Settings\Options;
use Convermetry\Webhook\WebsiteInfoBuilder;

/**
 * The "Convermetry → Settings" submenu page.
 *
 * Manages, via the WordPress Settings API, the non-webhook configuration:
 *  - the Website & Client identity sent as 'website_info' in every payload
 *    (client first/last name, optional client and website ids; the domain
 *    derives automatically from the home URL),
 *  - which interaction types are tracked, whether logged-in users are
 *    excluded, DNT/GPC handling, and the hover dwell threshold,
 *  - the data retention window and whether the Activity Log stores form
 *    submission_data.
 *
 * Webhook endpoints, schedules, and request customization live on the
 * Webhooks page; per-form configuration lives on the Forms page.
 */
final class SettingsPage
{
    /** Menu slug for the settings submenu page. */
    public const string MENU_SLUG = 'convermetry-settings';

    /** Settings API option group. */
    private const string OPTION_GROUP = 'cvm_settings_group';

    /**
     * Registers menu and settings hooks.
     *
     * @return void
     */
    public static function init(): void
    {
        add_action('admin_menu', [self::class, 'addMenu']);
        add_action('admin_init', [self::class, 'registerSettings']);
    }

    /**
     * Adds the Settings submenu.
     *
     * @return void
     */
    public static function addMenu(): void
    {
        add_submenu_page(
            AnalyticsPage::MENU_SLUG,
            'Convermetry Settings',
            'Settings',
            'manage_options',
            self::MENU_SLUG,
            [self::class, 'render']
        );
    }

    /**
     * Registers the single settings option with its sanitize callback.
     *
     * @return void
     */
    public static function registerSettings(): void
    {
        register_setting(self::OPTION_GROUP, Options::OPTION_KEY, [
            'type'              => 'array',
            'sanitize_callback' => [self::class, 'sanitize'],
            'default'           => Options::defaults(),
        ]);
    }

    /**
     * Normalizes raw form input into the canonical settings shape.
     *
     * Numeric fields are clamped to the same ranges {@see Options} enforces
     * on read, so what is stored is always exactly what will be used.
     *
     * @param mixed $input Raw value from the settings form.
     * @return array<string, mixed>
     */
    public static function sanitize(mixed $input): array
    {
        $input = is_array($input) ? $input : [];
        $out   = Options::defaults();

        foreach (Options::EVENT_TYPES as $type) {
            $out['track_' . $type] = !empty($input['track_' . $type]);
        }

        $out['exclude_logged_in']   = !empty($input['exclude_logged_in']);
        $out['respect_dnt']         = !empty($input['respect_dnt']);
        $out['retention_days']      = min(365, max(7, (int) ($input['retention_days'] ?? 90)));
        $out['hover_dwell_ms']      = min(10000, max(200, (int) ($input['hover_dwell_ms'] ?? 800)));
        $out['log_submission_data'] = !empty($input['log_submission_data']);

        $out['client_first_name'] = mb_substr(sanitize_text_field((string) ($input['client_first_name'] ?? '')), 0, 190);
        $out['client_last_name']  = mb_substr(sanitize_text_field((string) ($input['client_last_name'] ?? '')), 0, 190);
        $out['client_id']         = mb_substr(sanitize_text_field((string) ($input['client_id'] ?? '')), 0, 190);
        $out['website_id']        = mb_substr(sanitize_text_field((string) ($input['website_id'] ?? '')), 0, 190);

        return $out;
    }

    /**
     * Renders the settings page.
     *
     * @return void
     */
    public static function render(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $settings = Options::all();

        echo '<div class="wrap cvm-wrap">';
        echo '<h1>Convermetry Settings</h1>';

        // WordPress only auto-renders settings errors on options-general.php
        // pages; a custom-menu settings page must print them itself. This
        // also surfaces core's own "Settings saved." confirmation.
        settings_errors();

        echo '<form method="post" action="options.php">';
        settings_fields(self::OPTION_GROUP);

        self::renderIdentitySection($settings);
        self::renderTrackingSection($settings);
        self::renderDataSection($settings);

        submit_button();
        echo '</form>';

        echo '</div>';
    }

    /**
     * Renders the "Website & Client" identity card — the values carried as
     * 'website_info' in every outbound webhook payload.
     *
     * @param array<string, mixed> $settings Current settings.
     * @return void
     */
    private static function renderIdentitySection(array $settings): void
    {
        echo '<div class="cvm-card">';
        echo '<h2 class="cvm-card-title">Website &amp; Client</h2>';
        echo '<p class="description" style="margin-bottom:14px;">Sent as <code>website_info</code> in every webhook '
            . 'payload — analytics reports and form submissions alike — so downstream systems receiving deliveries from '
            . 'several installs can identify which site and client a payload belongs to. Every key is always present '
            . '(empty when not configured), giving consumers a predictable schema.</p>';

        echo '<table class="form-table" role="presentation">';

        echo '<tr><th scope="row">Site name / URL / domain</th><td>';
        echo '<code>' . esc_html(get_bloginfo('name')) . '</code> &middot; <code>' . esc_html(home_url()) . '</code>'
            . ' &middot; <code>' . esc_html(WebsiteInfoBuilder::domain()) . '</code>';
        echo '<p class="description">Derived automatically — <code>domain</code> is the home URL host with any leading '
            . '<code>www.</code> removed.</p>';
        echo '</td></tr>';

        echo '<tr><th scope="row"><label for="cvm-client-first-name">Client First Name</label></th><td>';
        echo '<input type="text" id="cvm-client-first-name" class="regular-text" name="'
            . esc_attr(Options::OPTION_KEY . '[client_first_name]') . '" value="' . esc_attr((string) $settings['client_first_name']) . '">';
        echo '</td></tr>';

        echo '<tr><th scope="row"><label for="cvm-client-last-name">Client Last Name</label></th><td>';
        echo '<input type="text" id="cvm-client-last-name" class="regular-text" name="'
            . esc_attr(Options::OPTION_KEY . '[client_last_name]') . '" value="' . esc_attr((string) $settings['client_last_name']) . '">';
        echo '</td></tr>';

        echo '<tr><th scope="row"><label for="cvm-client-id">Client ID <span class="description">(optional)</span></label></th><td>';
        echo '<input type="text" id="cvm-client-id" class="regular-text" name="'
            . esc_attr(Options::OPTION_KEY . '[client_id]') . '" value="' . esc_attr((string) $settings['client_id']) . '">';
        echo '<p class="description">Sent as <code>website_info.client.id</code>.</p>';
        echo '</td></tr>';

        echo '<tr><th scope="row"><label for="cvm-website-id">Website ID <span class="description">(optional)</span></label></th><td>';
        echo '<input type="text" id="cvm-website-id" class="regular-text" name="'
            . esc_attr(Options::OPTION_KEY . '[website_id]') . '" value="' . esc_attr((string) $settings['website_id']) . '">';
        echo '<p class="description">Sent as <code>website_info.id</code>.</p>';
        echo '</td></tr>';

        echo '</table>';
        echo '</div>';
    }

    /**
     * Renders the "Tracking" checkboxes.
     *
     * @param array<string, mixed> $settings Current settings.
     * @return void
     */
    private static function renderTrackingSection(array $settings): void
    {
        $labels = [
            'pageview'     => 'Page views',
            'click'        => 'Link &amp; button clicks',
            'form_submit'  => 'Form submissions (attempts)',
            'form_success' => 'Confirmed form conversions (frontend events and server-confirmed submissions)',
            'hover'        => 'Mouse hover activity',
            'scroll_depth' => 'Scroll depth milestones (50/100%)',
        ];

        echo '<h2>Tracking</h2>';
        echo '<table class="form-table" role="presentation">';

        echo '<tr><th scope="row">Interactions to track</th><td>';
        foreach ($labels as $type => $label) {
            $field = 'track_' . $type;
            echo '<label style="display:block;margin-bottom:4px;">';
            echo '<input type="checkbox" name="' . esc_attr(Options::OPTION_KEY . '[' . $field . ']') . '" value="1" '
                . checked(!empty($settings[$field]), true, false) . '> ' . $label;
            echo '</label>';
        }
        echo '</td></tr>';

        echo '<tr><th scope="row">Logged-in users</th><td>';
        echo '<label><input type="checkbox" name="' . esc_attr(Options::OPTION_KEY . '[exclude_logged_in]') . '" value="1" '
            . checked(!empty($settings['exclude_logged_in']), true, false) . '> Exclude logged-in users from tracking</label>';
        echo '<p class="description">Recommended, so admin and editor activity does not skew visitor analytics. '
            . 'Webhook delivery of form submissions is unaffected.</p>';
        echo '</td></tr>';

        echo '<tr><th scope="row">Browser privacy signals</th><td>';
        echo '<label><input type="checkbox" name="' . esc_attr(Options::OPTION_KEY . '[respect_dnt]') . '" value="1" '
            . checked(!empty($settings['respect_dnt']), true, false) . '> Honor Do Not Track / Global Privacy Control</label>';
        echo '<p class="description">Visitors whose browser sends these signals are not tracked at all — no analytics '
            . 'events are recorded and no analytics context accompanies their form submissions. Enabling this typically '
            . 'reduces recorded traffic. Note DNT/GPC is an opt-out signal, not a consent mechanism.</p>';
        echo '</td></tr>';

        echo '<tr><th scope="row"><label for="cvm-hover-dwell">Hover dwell time (ms)</label></th><td>';
        echo '<input type="number" id="cvm-hover-dwell" min="200" max="10000" step="50" name="'
            . esc_attr(Options::OPTION_KEY . '[hover_dwell_ms]') . '" value="' . esc_attr((string) $settings['hover_dwell_ms']) . '" class="small-text">';
        echo '<p class="description">How long the pointer must rest on an element before a hover event is recorded. '
            . 'Add <code>data-cvm-hover</code> to any element — images included — to opt it into hover tracking.</p>';
        echo '</td></tr>';

        echo '</table>';
    }

    /**
     * Renders the data retention and log-privacy fields.
     *
     * @param array<string, mixed> $settings Current settings.
     * @return void
     */
    private static function renderDataSection(array $settings): void
    {
        echo '<h2>Data</h2>';
        echo '<table class="form-table" role="presentation">';

        echo '<tr><th scope="row"><label for="cvm-retention">Retention period (days)</label></th><td>';
        echo '<input type="number" id="cvm-retention" min="7" max="365" name="'
            . esc_attr(Options::OPTION_KEY . '[retention_days]') . '" value="' . esc_attr((string) $settings['retention_days']) . '" class="small-text">';
        echo '<p class="description">Analytics events, form submission records, and activity log entries older than this '
            . 'are deleted by a daily cleanup job. Default is 90 days.</p>';
        echo '</td></tr>';

        echo '<tr><th scope="row">Activity Log privacy</th><td>';
        echo '<label><input type="checkbox" name="' . esc_attr(Options::OPTION_KEY . '[log_submission_data]') . '" value="1" '
            . checked(!empty($settings['log_submission_data']), true, false) . '> Store form submission data in the Activity Log</label>';
        echo '<p class="description">When disabled, the Activity Log records every delivery\'s metadata but replaces the '
            . 'visitor\'s field values with a placeholder — useful when compliance rules forbid a second copy of lead '
            . 'data. The payload actually delivered to endpoints is unaffected.</p>';
        echo '</td></tr>';

        echo '</table>';
    }
}
