<?php
declare(strict_types=1);

namespace Convermetry\Admin;

if (!defined('ABSPATH')) exit;

use Convermetry\Analytics\FormEngagementReport;
use Convermetry\Analytics\ReportQueryException;
use Convermetry\Database\MigrationRunner;
use Convermetry\Forms\FormProviderRegistry;
use Convermetry\Forms\FormSettings;

/**
 * The "Convermetry → Forms" admin page.
 *
 * Shows every supported form provider with its availability (Active /
 * Unavailable), automatically discovers each active provider's forms, and
 * exposes per-form configuration:
 *
 *  - Custom/External Form ID (sent as 'form_id' in payloads; the provider's
 *    native id is the fallback),
 *  - Enabled / Excluded (detected forms are INCLUDED by default — new forms
 *    never need manual setup; a form's configuration is preserved while it
 *    is excluded),
 *  - Include page URL query parameters (per-form override of the global
 *    setting),
 *  - per-form URL query parameters and request headers (merged after the
 *    global ones — the highest-precedence layer).
 *
 * The list is filterable client-side by provider, name/id text, and
 * included/excluded state (assets/js/admin.js).
 */
final class FormsPage
{
    /** Menu slug for the submenu page. */
    public const string MENU_SLUG = 'convermetry-forms';

    /** admin-post action name for saving the page. */
    private const string SAVE_ACTION = 'cvm_save_forms';

    private static ?FormProviderRegistry $registry = null;

    /**
     * Registers menu, save, notice, and asset hooks.
     *
     * @param FormProviderRegistry $registry The shared provider registry.
     * @return void
     */
    public static function init(FormProviderRegistry $registry): void
    {
        self::$registry = $registry;

        add_action('admin_menu', [self::class, 'addMenu']);
        add_action('admin_post_' . self::SAVE_ACTION, [self::class, 'handleSave']);
        add_action('admin_notices', [self::class, 'maybeShowNotices']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueueAssets']);
    }

    /**
     * Adds the Forms submenu.
     *
     * @return void
     */
    public static function addMenu(): void
    {
        add_submenu_page(
            AnalyticsPage::MENU_SLUG,
            'Convermetry Forms',
            'Forms',
            Capability::required(Capability::FORMS_MANAGE),
            self::MENU_SLUG,
            [self::class, 'render']
        );
    }

    /**
     * Enqueues the admin script (filters, key/value builders) on this page only.
     *
     * @param string $hook The current admin page hook suffix.
     * @return void
     */
    public static function enqueueAssets(string $hook): void
    {
        if (!str_contains($hook, self::MENU_SLUG)) {
            return;
        }

        wp_enqueue_script(
            'cvm-admin',
            CVM_PLUGIN_URL . 'assets/js/admin.js',
            [],
            CVM_VERSION,
            true
        );
    }

    /**
     * Validates and persists the per-form configuration POST.
     *
     * Only forms actually rendered on the saving page (listed in
     * cvm_rendered_forms) are written; configuration for every other form —
     * e.g. forms of a temporarily deactivated provider — is preserved
     * untouched.
     *
     * @return void
     */
    public static function handleSave(): void
    {
        if (
            !Capability::currentUserCan(Capability::FORMS_MANAGE)
            || !isset($_POST['cvm_forms_nonce'])
            || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['cvm_forms_nonce'])), self::SAVE_ACTION)
        ) {
            wp_die('Invalid request.');
        }

        $rawForms = isset($_POST['cvm_forms']) && is_array($_POST['cvm_forms'])
            ? wp_unslash($_POST['cvm_forms'])
            : [];

        $configs  = [];
        $rendered = [];

        foreach ($rawForms as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            // The real form key travels as a value (field names are hashed) —
            // provider keys and form names can contain characters PHP mangles
            // in top-level field names.
            $formKey = sanitize_text_field((string) ($entry['key'] ?? ''));
            if ($formKey === '' || !str_contains($formKey, ':')) {
                continue;
            }

            $rendered[]        = $formKey;
            $configs[$formKey] = [
                'form_id'             => mb_substr(sanitize_text_field((string) ($entry['form_id'] ?? '')), 0, 191),
                'excluded'            => !empty($entry['excluded']),
                'include_page_params' => !empty($entry['include_page_params']),
                'query_params'        => self::sanitizePairs($entry['query_params'] ?? null),
                'headers'             => self::sanitizePairs($entry['headers'] ?? null),
            ];
        }

        FormSettings::saveRendered($configs, $rendered);

        wp_safe_redirect(add_query_arg(
            ['page' => self::MENU_SLUG, 'cvm_saved' => '1'],
            self_admin_url('admin.php')
        ));
        exit;
    }

    /**
     * Sanitizes a posted key/value pair list.
     *
     * @param mixed $raw Raw (already unslashed) POST value.
     * @return array<int, array{key: string, value: string}>
     */
    private static function sanitizePairs(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $pair) {
            if (!is_array($pair)) {
                continue;
            }

            $key = sanitize_text_field((string) ($pair['key'] ?? ''));
            if ($key === '') {
                continue;
            }

            $out[] = [
                'key'   => $key,
                'value' => sanitize_text_field((string) ($pair['value'] ?? '')),
            ];
        }

        return $out;
    }

    /**
     * Shows the saved notice.
     *
     * @return void
     */
    public static function maybeShowNotices(): void
    {
        if (isset($_GET['page']) && $_GET['page'] === self::MENU_SLUG && !empty($_GET['cvm_saved'])) {
            ?>
            <div class="notice notice-success is-dismissible"><p>Form settings saved.</p></div>
            <?php
        }
    }

    /**
     * Renders the Forms page.
     *
     * @return void
     */
    /**
     * Renders the form engagement and abandonment panel.
     *
     * Sits above the per-form configuration because it answers the question a
     * site owner actually arrives with — "why is this form not converting?" —
     * while the configuration below answers "how is it wired up?".
     *
     * The panel is careful about EVIDENCE. Views, starts and abandonment are
     * what a browser reported; successful submissions are what the form
     * plugin's own server-side hook confirmed. Those are different grades of
     * certainty, and a completion rate above 100% is a real signal (visitors
     * submitting with JavaScript blocked) rather than a bug, so the wording
     * says which is which instead of presenting one merged number.
     *
     * @return void
     */
    private static function renderEngagement(): void
    {
        if (MigrationRunner::isPending()) {
            return;
        }

        $end   = gmdate('Y-m-d H:i:s');
        $start = gmdate('Y-m-d 00:00:00', time() - 29 * DAY_IN_SECONDS);

        try {
            $forms    = FormEngagementReport::totals($start, $end, 20);
            $friction = FormEngagementReport::frictionPoints($start, $end, '', 8);
        } catch (ReportQueryException) {
            return;
        }

        ?>
        <h2>Engagement &amp; abandonment</h2>
        <p class="description" style="max-width:760px;">The last 30 days. <strong>Views</strong>, <strong>Started</strong>
        and <strong>Abandoned</strong> count sessions and are observed in the visitor's browser; <strong>Attempts</strong> counts individual
        submit presses; <strong>Successful</strong> counts submissions your form plugin confirmed on the server. A completion rate
        above 100% is not an error — it means people are submitting with JavaScript blocked, so the browser-observed columns are
        undercounting.</p>
        <?php

        if ($forms === []) {
            ?>
            <div class="notice notice-info inline"><p>No form engagement recorded yet. Form view, start, and validation-error
            tracking are switched on under <strong>Settings &rarr; Tracking</strong>. Elementor forms are not included here — see
            the note below.</p></div>
            <?php
        } else {
            ?>
            <table class="widefat striped cvm-goals-table"><thead><tr>
            <th scope="col">Form</th>
            <?php
            foreach (['Views', 'Started', 'Attempts', 'Successful', 'Abandoned', 'Start Rate', 'Completion Rate'] as $label) {
                ?>
                <th scope="col" class="cvm-num"><?php echo esc_html($label); ?></th>
                <?php
            }
            ?>
            </tr></thead><tbody>
            <?php

            foreach ($forms as $form) {
                ?>
                <tr>
                <td><strong><?php echo esc_html($form['form_name'] !== '' ? $form['form_name'] : $form['form_key']); ?></strong><div class="cvm-goal-meta"><code><?php echo esc_html($form['form_key']); ?></code>
                <?php
                if ($form['in_progress'] > 0) {
                    printf(
                        ' &middot; %d still in progress',
                        (int) $form['in_progress']
                    );
                }
                ?>
                </div></td>
                <?php

                foreach ([
                    number_format_i18n($form['views']),
                    number_format_i18n($form['started']),
                    number_format_i18n($form['attempts']),
                    number_format_i18n($form['successful']),
                    number_format_i18n($form['abandoned']),
                    $form['views'] > 0 ? $form['start_rate'] . '%' : '—',
                    $form['started'] > 0 ? $form['completion_rate'] . '%' : '—',
                ] as $cell) {
                    ?>
                    <td class="cvm-num"><?php echo esc_html((string) $cell); ?></td>
                    <?php
                }

                ?>
                </tr>
                <?php
            }

            ?>
            </tbody></table>
            <?php

            printf(
                '<p class="description">A start counts as abandoned once %d minutes pass with no confirmed '
                . 'submission — anything more recent is shown as still in progress rather than being counted '
                . 'against you.</p>',
                FormEngagementReport::COMPLETION_WINDOW_MINUTES
            );
        }

        if ($friction !== []) {
            ?>
            <h3>Most common friction points</h3>
            <p class="description" style="max-width:760px;">Which fields fail validation most often. Convermetry records
            the field's name, its type, and which check failed — <strong>never what the visitor typed</strong>.</p>
            <table class="widefat striped cvm-goals-table"><thead><tr>
            <th scope="col">Field</th><th scope="col">Type</th><th scope="col">Problem</th>
            <th scope="col" class="cvm-num">Errors</th><th scope="col" class="cvm-num">Sessions</th></tr></thead><tbody>
            <?php

            foreach ($friction as $row) {
                ?>
                <tr>
                <td><code><?php echo esc_html($row['field_id']); ?></code></td>
                <td><?php echo esc_html($row['field_type']); ?></td>
                <td><?php echo esc_html(self::errorLabel($row['error_type'])); ?></td>
                <td class="cvm-num"><?php echo esc_html(number_format_i18n($row['errors'])); ?></td>
                <td class="cvm-num"><?php echo esc_html(number_format_i18n($row['sessions'])); ?></td></tr>
                <?php
            }

            ?>
            </tbody></table>
            <?php
        }

        ?>
        <div class="notice notice-info inline"><p><strong>Elementor forms are not included above.</strong> Elementor identifies
        a form by its display name on the server while exposing a widget id in the browser, so the two cannot be matched reliably —
        and an engagement figure attributed to the wrong form is worse than none. Elementor submissions are recorded and attributed
        normally everywhere else in Convermetry.</p></div>
        <?php
    }

    /**
     * A readable description of a validation failure category.
     *
     * @param string $errorType A stored ValidityState category.
     * @return string
     */
    private static function errorLabel(string $errorType): string
    {
        return match ($errorType) {
            'required'      => 'Left empty',
            'type_mismatch' => 'Wrong format (e.g. not an email address)',
            'pattern'       => 'Did not match the expected pattern',
            'too_short'     => 'Too short',
            'too_long'      => 'Too long',
            'range'         => 'Outside the allowed range',
            'step'          => 'Not an allowed increment',
            default         => 'Invalid',
        };
    }

    public static function render(): void
    {
        if (!Capability::currentUserCan(Capability::FORMS_MANAGE)) {
            return;
        }

        $registry = self::$registry ?? new FormProviderRegistry();

        ?>
        <div class="wrap cvm-wrap">
        <h1>Convermetry Forms</h1>
        <p class="description" style="max-width:760px;">Convermetry automatically detects supported form plugins and discovers
        their forms. Detected forms are <strong>included by default</strong> — a new form starts recording conversions and delivering
        webhooks without any setup. Exclude a form to stop processing it; its configuration is preserved and restored when re-enabled.</p>
        <?php

        self::renderEngagement();

        // ── Provider status cards ──────────────────────────────────────
        ?>
        <div class="cvm-cards cvm-provider-cards">
        <?php
        $availableProviders = [];
        foreach ($registry->all() as $provider) {
            $available = $provider->isAvailable();
            if ($available) {
                $availableProviders[$provider->getKey()] = $provider;
            }

            ?>
            <div class="cvm-card cvm-provider-card">
            <span class="cvm-card-label"><?php echo esc_html($provider->getLabel()); ?></span>
            <span class="cvm-provider-status <?php echo ($available ? 'is-active' : 'is-unavailable'); ?>"><?php echo ($available ? 'Active' : 'Unavailable'); ?></span></div>
            <?php
        }
        ?>
        </div>
        <?php

        if ($availableProviders === []) {
            ?>
            <div class="notice notice-info inline"><p>No supported form plugin is currently active. Install and activate
            Elementor Pro, Gravity Forms, WPForms, Contact Form 7, or Fluent Forms — or integrate a custom form with <code>convermetry_submit_form()</code>
            (see the About page).</p></div></div>
            <?php
            return;
        }

        // ── Discovered forms + filters ─────────────────────────────────
        $discovered = [];
        foreach ($availableProviders as $provider) {
            foreach ($registry->discoveredForms($provider) as $form) {
                $discovered[] = [
                    'provider'       => $provider->getKey(),
                    'provider_label' => $provider->getLabel(),
                    'native_id'      => (string) $form['native_id'],
                    'name'           => (string) $form['name'],
                ];
            }
        }

        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <?php
        wp_nonce_field(self::SAVE_ACTION, 'cvm_forms_nonce');
        ?>
        <input type="hidden" name="action" value="<?php echo esc_attr(self::SAVE_ACTION); ?>">
        <div class="cvm-form-filters">
        <label class="screen-reader-text" for="cvm-form-search">Search forms</label>
        <input type="search" id="cvm-form-search" placeholder="Search by form name or ID…">
        <label class="screen-reader-text" for="cvm-form-provider-filter">Filter by provider</label>
        <select id="cvm-form-provider-filter"><option value="">All Providers</option>
        <?php
        foreach ($availableProviders as $provider) {
            ?>
            <option value="<?php echo esc_attr($provider->getKey()); ?>"><?php echo esc_html($provider->getLabel()); ?></option>
            <?php
        }
        ?>
        </select>
        <label class="screen-reader-text" for="cvm-form-state-filter">Filter by state</label>
        <select id="cvm-form-state-filter"><option value="">All States</option><option value="included">Included</option><option value="excluded">Excluded</option></select>
        <span class="cvm-form-filter-summary"><span id="cvm-form-filter-count"><?php echo count($discovered); ?></span> of
        <?php echo count($discovered); ?> forms shown</span></div>
        <div id="cvm-forms-list">
        <?php

        if ($discovered === []) {
            ?>
            <div class="notice notice-info inline"><p>No forms were discovered yet. Create a form in one of the active
            providers and revisit this page.</p></div>
            <?php
        }

        foreach ($discovered as $form) {
            self::renderFormBlock($form);
        }

        ?>
        </div>
        <?php

        submit_button('Save Form Settings');

        /**
         * Fires at the end of the Forms admin screen, after the settings form.
         *
         * Runs only after this screen's forms.manage capability check has
         * already passed. A callback ECHOES its own markup and MUST escape
         * everything it prints; Convermetry escapes none of it.
         *
         * Note the placement: this is OUTSIDE the settings <form>, so fields
         * added here are not submitted with it. Render your own form, posting to
         * admin-post.php with your own nonce and handler.
         */
        do_action('convermetry_forms_admin_sections');
        ?>
        </form></div>
        <?php
    }

    /**
     * Renders one discovered form's configuration block.
     *
     * @param array{provider: string, provider_label: string, native_id: string, name: string} $form Discovered form.
     * @return void
     */
    private static function renderFormBlock(array $form): void
    {
        // READ through the legacy fallback so a site upgrading from name-keyed
        // Elementor settings sees its existing configuration rather than blank
        // defaults. WRITE to the CURRENT key: posting the legacy key back would
        // keep two same-named widgets sharing one entry forever, which is the
        // exact defect the widget-id change exists to fix. Saving therefore
        // migrates the entry across, and the legacy entry is left in place for
        // queued deliveries that still reference it.
        $formKey   = FormProviderRegistry::formKey($form['provider'], $form['native_id']);
        $readKey   = FormSettings::resolveKey(
            $formKey,
            FormProviderRegistry::legacyFormKey($form['provider'], $form['name'])
        );
        $config    = FormSettings::forForm($readKey);
        $hash      = md5($formKey);
        $name    = 'cvm_forms[' . $hash . ']';

        ?>
        <details class="cvm-form-block" data-provider="<?php echo esc_attr($form['provider']); ?>" data-name="<?php echo esc_attr($form['name']); ?>" data-native-id="<?php echo esc_attr($form['native_id']); ?>" data-form-id="<?php echo esc_attr($config['form_id']); ?>" data-excluded="<?php echo ($config['excluded'] ? '1' : '0'); ?>">
        <summary class="cvm-form-block-summary">
        <span class="cvm-form-block-name"><?php echo esc_html($form['name']); ?></span>
        <span class="cvm-form-block-provider"><?php echo esc_html($form['provider_label']); ?></span>
        <span class="cvm-form-state-badge <?php echo ($config['excluded'] ? 'is-excluded' : 'is-included'); ?>"><?php echo ($config['excluded'] ? 'Excluded' : 'Included'); ?></span></summary>
        <div class="cvm-form-block-body">
        <input type="hidden" name="<?php echo esc_attr($name . '[key]'); ?>" value="<?php echo esc_attr($formKey); ?>">
        <table class="form-table" role="presentation">
        <tr><th scope="row">Native Form ID</th><td><code><?php echo esc_html($form['native_id']); ?></code></td></tr>
        <tr><th scope="row"><label for="cvm-form-id-<?php echo esc_attr($hash); ?>">Custom/External Form ID</label></th><td>
        <input type="text" id="cvm-form-id-<?php echo esc_attr($hash); ?>" class="regular-text cvm-form-id-input" name="<?php echo esc_attr($name . '[form_id]'); ?>" value="<?php echo esc_attr($config['form_id']); ?>">
        <p class="description">Sent as <code>form_id</code> in webhook payloads for this form. Leave blank to use the native
        form ID.</p></td></tr>
        <tr><th scope="row">Status</th><td>
        <label><input type="checkbox" class="cvm-form-excluded-toggle" name="<?php echo esc_attr($name . '[excluded]'); ?>" value="1" <?php echo checked($config['excluded'], true, false); ?>>
        Exclude this form</label>
        <p class="description">Excluded forms are not recorded or delivered. Their configuration is preserved.</p></td></tr>
        <tr><th scope="row">Page URL parameters</th><td>
        <label><input type="checkbox" name="<?php echo esc_attr($name . '[include_page_params]'); ?>" value="1" <?php echo checked($config['include_page_params'], true, false); ?>>
        Include page URL parameters for this form (regardless of the global setting)</label></td></tr></table>
        <h4>URL Query Parameters <span class="description">(this form only — highest precedence)</span></h4>
        <?php
        self::renderKvBuilder($name . '[query_params]', $config['query_params']);

        ?>
        <h4>Request Headers <span class="description">(this form only)</span></h4>
        <?php
        self::renderKvBuilder($name . '[headers]', $config['headers']);

        ?>
        </div></details>
        <?php
    }

    /**
     * Renders one key/value builder for a form block.
     *
     * @param string                                          $name  Field name prefix.
     * @param array<int, array{key?: string, value?: string}> $pairs Saved pairs.
     * @return void
     */
    private static function renderKvBuilder(string $name, array $pairs): void
    {
        ?>
        <div class="cvm-kv-builder" data-kv-name="<?php echo esc_attr($name); ?>" data-kv-next="<?php echo esc_attr((string) count($pairs)); ?>">
        <div class="cvm-kv-rows">
        <?php

        foreach ($pairs as $index => $pair) {
            ?>
            <div class="cvm-kv-row">
            <input type="text" class="regular-text code cvm-kv-key" name="<?php echo esc_attr($name . '[' . $index . '][key]'); ?>" placeholder="Key" value="<?php echo esc_attr((string) ($pair['key'] ?? '')); ?>">
            <input type="text" class="regular-text code cvm-kv-value" name="<?php echo esc_attr($name . '[' . $index . '][value]'); ?>" placeholder="Value" value="<?php echo esc_attr((string) ($pair['value'] ?? '')); ?>">
            <button type="button" class="button cvm-kv-remove" aria-label="Remove this row">Remove</button></div>
            <?php
        }

        ?>
        </div>
        <button type="button" class="button cvm-kv-add">+ Add</button></div>
        <?php
    }
}
