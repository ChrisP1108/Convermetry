<?php
declare(strict_types=1);

namespace Convermetry\Admin\Pages;

if (!defined('ABSPATH')) exit;

use Convermetry\Admin\AdminAssets;
use Convermetry\Admin\AdminRequest;
use Convermetry\Admin\Capability;
use Convermetry\Analytics\FormEngagementReport;
use Convermetry\Analytics\ReportQueryException;
use Convermetry\Database\MigrationRunner;
use Convermetry\Forms\Atomic\AtomicFormsBridge;
use Convermetry\Forms\Bricks\BricksFormsBridge;
use Convermetry\Forms\FormProviderRegistry;
use Convermetry\Forms\FormSettings;
use Convermetry\Support\KeyValuePairs;

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
    private const string SAVE_ACTION = 'cvmtry_save_forms';

    /** Per-user transient prefix for what the last save skipped or refused. */
    private const string SKIPPED_TRANSIENT = 'cvmtry_forms_skipped_';

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
            HomePage::MENU_SLUG,
            __('Convermetry Forms', 'convermetry'),
            __('Forms', 'convermetry'),
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

        wp_enqueue_style(
            'cvmtry-forms',
            CVMTRY_PLUGIN_URL . 'assets/css/admin-forms.css',
            [AdminAssets::COMMON_HANDLE],
            CVMTRY_VERSION
        );

        wp_enqueue_script(
            'cvmtry-admin',
            CVMTRY_PLUGIN_URL . 'assets/js/admin.js',
            ['wp-i18n'],
            CVMTRY_VERSION,
            true
        );
        wp_set_script_translations('cvmtry-admin', 'convermetry');
    }

    /**
     * Validates and persists the per-form configuration POST
     * (admin_post_cvmtry_save_forms).
     *
     * Only forms actually rendered on the saving page (listed in
     * cvmtry_rendered_forms) are written; configuration for every other form —
     * e.g. forms of a temporarily deactivated provider — is preserved
     * untouched.
     *
     * @return never
     */
    public static function handleSave(): never
    {
        // 1. Method: only the settings form's POST.
        if (!AdminRequest::isPost()) {
            AdminRequest::deny(__('Form settings can only be saved from the Forms screen.', 'convermetry'), 405);
        }

        // 2. Capability: per-form configuration.
        if (!current_user_can(Capability::required(Capability::FORMS_MANAGE))) {
            AdminRequest::deny(AdminRequest::forbiddenMessage());
        }

        // 3. Nonce present, as one string.
        if (!isset($_POST['cvmtry_forms_nonce']) || !is_string($_POST['cvmtry_forms_nonce'])) {
            AdminRequest::deny(AdminRequest::expiredMessage());
        }

        // 4. Nonce issued for saving form settings.
        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['cvmtry_forms_nonce'])), self::SAVE_ACTION)) {
            AdminRequest::deny(AdminRequest::expiredMessage());
        }

        // 5. Input: one block per listed form. Absent when no form was
        // discovered, in which case nothing is written.
        $rawForms = [];
        if (isset($_POST['cvmtry_forms'])) {
            if (!is_array($_POST['cvmtry_forms'])) {
                wp_safe_redirect(add_query_arg(
                    ['page' => self::MENU_SLUG, 'cvmtry_error' => 'malformed'],
                    self_admin_url('admin.php')
                ));
                exit;
            }

            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- unslashed once here; sanitizeFormBlock() type-checks every field and validates or sanitizes each for its meaning.
            $rawForms = wp_unslash($_POST['cvmtry_forms']);
        }

        $configs  = [];
        $rendered = [];
        $skipped  = 0;
        $pairs    = 0;

        foreach ($rawForms as $entry) {
            $block = self::sanitizeFormBlock($entry);

            // A block that is not the shape this page posts is skipped as a
            // whole. Because only rendered keys are written, skipping it
            // leaves that form's stored configuration exactly as it was.
            if ($block === null) {
                $skipped++;
                continue;
            }

            $rendered[]             = $block['key'];
            $configs[$block['key']] = $block['config'];
            $pairs                 += $block['rejected_pairs'];
        }

        FormSettings::saveRendered($configs, $rendered);

        if ($skipped > 0 || $pairs > 0) {
            set_transient(
                self::SKIPPED_TRANSIENT . get_current_user_id(),
                ['forms' => $skipped, 'pairs' => $pairs],
                MINUTE_IN_SECONDS
            );
        }

        wp_safe_redirect(add_query_arg(
            ['page' => self::MENU_SLUG, 'cvmtry_saved' => '1'],
            self_admin_url('admin.php')
        ));
        exit;
    }

    /**
     * Validates one posted form block.
     *
     * The real form key travels as a value (field names are hashed) because
     * provider keys and form names can contain characters PHP mangles in
     * top-level field names. It is validated, never rewritten — see
     * {@see FormProviderRegistry::validFormKey()}.
     *
     * @param mixed $entry One unslashed cvmtry_forms[...] block.
     * @return array{key: string, config: array{form_id: string, excluded: bool, include_page_params: bool, query_params: list<array{key: string, value: string}>, headers: list<array{key: string, value: string}>}, rejected_pairs: int}|null
     *         Null when the block is malformed and must not be written.
     */
    private static function sanitizeFormBlock(mixed $entry): ?array
    {
        if (!is_array($entry)) {
            return null;
        }

        foreach (['key', 'form_id', 'excluded', 'include_page_params'] as $field) {
            if (isset($entry[$field]) && !is_string($entry[$field])) {
                return null;
            }
        }

        $formKey = FormProviderRegistry::validFormKey($entry['key'] ?? '');
        if ($formKey === '') {
            return null;
        }

        $query   = KeyValuePairs::fromQueryInput($entry['query_params'] ?? null);
        $headers = KeyValuePairs::fromHeaderInput($entry['headers'] ?? null);

        if ($query['malformed'] || $headers['malformed']) {
            return null;
        }

        return [
            'key'            => $formKey,
            'config'         => [
                'form_id'             => mb_substr(sanitize_text_field((string) ($entry['form_id'] ?? '')), 0, 191),
                'excluded'            => !empty($entry['excluded']),
                'include_page_params' => !empty($entry['include_page_params']),
                'query_params'        => $query['pairs'],
                'headers'             => $headers['pairs'],
            ],
            'rejected_pairs' => $query['rejected'] + $headers['rejected'],
        ];
    }

    /**
     * Shows the saved notice.
     *
     * @return void
     */
    public static function maybeShowNotices(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- display-only: identifies this screen and reads the flags from the redirect after handleSave(), which verifies its nonce and capability; each flag is compared with a fixed value and only selects one of the fixed notices below.
        $page      = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        $saved     = isset($_GET['cvmtry_saved']) && sanitize_key(wp_unslash($_GET['cvmtry_saved'])) === '1';
        $malformed = isset($_GET['cvmtry_error']) && sanitize_key(wp_unslash($_GET['cvmtry_error'])) === 'malformed';
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        if ($page !== self::MENU_SLUG) {
            return;
        }

        if ($malformed) {
            ?>
            <div class="notice notice-error is-dismissible"><p><?php esc_html_e('Form settings were not saved: the submitted form was malformed, so the stored settings were left unchanged. Reload this page and try again.', 'convermetry'); ?></p></div>
            <?php
        }

        if (!$saved) {
            return;
        }

        ?>
        <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Form settings saved.', 'convermetry'); ?></p></div>
        <?php

        $transient = self::SKIPPED_TRANSIENT . get_current_user_id();
        $stored    = get_transient($transient);

        if (!is_array($stored)) {
            return;
        }

        delete_transient($transient);

        $forms = is_int($stored['forms'] ?? null) ? $stored['forms'] : 0;
        $pairs = is_int($stored['pairs'] ?? null) ? $stored['pairs'] : 0;

        if ($forms > 0) {
            ?>
            <div class="notice notice-warning"><p><?php
            echo esc_html(sprintf(
                /* translators: %d: number of forms whose settings were left unchanged. */
                _n(
                    'The settings of %d form were left unchanged because what was submitted for it was malformed. Reload this page and try again.',
                    'The settings of %d forms were left unchanged because what was submitted for them was malformed. Reload this page and try again.',
                    $forms,
                    'convermetry'
                ),
                $forms
            ));
            ?></p></div>
            <?php
        }

        if ($pairs > 0) {
            ?>
            <div class="notice notice-warning"><p><?php
            echo esc_html(sprintf(
                /* translators: %d: number of header or query-parameter rows that were not saved. */
                _n(
                    '%d header or query-parameter row was not saved. A header name may contain only letters, digits, hyphens and a few other punctuation marks (no spaces or colons), and no name or value may contain line breaks or other control characters.',
                    '%d header or query-parameter rows were not saved. A header name may contain only letters, digits, hyphens and a few other punctuation marks (no spaces or colons), and no name or value may contain line breaks or other control characters.',
                    $pairs,
                    'convermetry'
                ),
                $pairs
            ));
            ?></p></div>
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
        <h2><?php esc_html_e('Engagement & abandonment', 'convermetry'); ?></h2>
        <p class="description" style="max-width:760px;"><?php echo wp_kses_post(__('The last 30 days. <strong>Views</strong>, <strong>Started</strong> and <strong>Abandoned</strong> count sessions and are observed in the visitor\'s browser; <strong>Attempts</strong> counts individual submit presses; <strong>Successful</strong> counts submissions your form plugin confirmed on the server. A completion rate above 100% is not an error — it means people are submitting with JavaScript blocked, so the browser-observed columns are undercounting.', 'convermetry')); ?></p>
        <?php

        if ($forms === []) {
            ?>
            <div class="notice notice-info inline"><p><?php echo wp_kses_post(__('No form engagement recorded yet. Form view, start, and validation-error tracking are switched on under <strong>Settings → Tracking</strong>. Elementor forms are not included here — see the note below.', 'convermetry')); ?></p></div>
            <?php
        } else {
            ?>
            <table class="widefat striped cvmtry-goals-table"><thead><tr>
            <th scope="col"><?php esc_html_e('Form', 'convermetry'); ?></th>
            <?php
            $columns = [
                __('Views', 'convermetry'),
                __('Started', 'convermetry'),
                __('Attempts', 'convermetry'),
                __('Successful', 'convermetry'),
                __('Abandoned', 'convermetry'),
                __('Start Rate', 'convermetry'),
                __('Completion Rate', 'convermetry'),
            ];
            foreach ($columns as $label) {
                ?>
                <th scope="col" class="cvmtry-num"><?php echo esc_html($label); ?></th>
                <?php
            }
            ?>
            </tr></thead><tbody>
            <?php

            foreach ($forms as $form) {
                ?>
                <tr>
                <td><strong><?php echo esc_html($form['form_name'] !== '' ? $form['form_name'] : $form['form_key']); ?></strong><div class="cvmtry-goal-meta"><code><?php echo esc_html($form['form_key']); ?></code>
                <?php
                if ($form['in_progress'] > 0) {
                    echo ' &middot; ' . esc_html(sprintf(
                        /* translators: %d: number of form starts not yet completed or abandoned. */
                        _n('%d still in progress', '%d still in progress', (int) $form['in_progress'], 'convermetry'),
                        (int) $form['in_progress']
                    ));
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
                    <td class="cvmtry-num"><?php echo esc_html((string) $cell); ?></td>
                    <?php
                }

                ?>
                </tr>
                <?php
            }

            ?>
            </tbody></table>
            <?php

            echo '<p class="description">' . esc_html(sprintf(
                /* translators: %d: minutes after which an unfinished form start counts as abandoned. */
                _n(
                    'A start counts as abandoned once %d minute passes with no confirmed submission — anything more recent is shown as still in progress rather than being counted against you.',
                    'A start counts as abandoned once %d minutes pass with no confirmed submission — anything more recent is shown as still in progress rather than being counted against you.',
                    FormEngagementReport::COMPLETION_WINDOW_MINUTES,
                    'convermetry'
                ),
                FormEngagementReport::COMPLETION_WINDOW_MINUTES
            )) . '</p>';
        }

        if ($friction !== []) {
            ?>
            <h3><?php esc_html_e('Most common friction points', 'convermetry'); ?></h3>
            <p class="description" style="max-width:760px;"><?php echo wp_kses_post(__('Which fields fail validation most often. Convermetry records the field\'s name, its type, and which check failed — <strong>never what the visitor typed</strong>.', 'convermetry')); ?></p>
            <table class="widefat striped cvmtry-goals-table"><thead><tr>
            <th scope="col"><?php esc_html_e('Field', 'convermetry'); ?></th><th scope="col"><?php esc_html_e('Type', 'convermetry'); ?></th><th scope="col"><?php esc_html_e('Problem', 'convermetry'); ?></th>
            <th scope="col" class="cvmtry-num"><?php esc_html_e('Errors', 'convermetry'); ?></th><th scope="col" class="cvmtry-num"><?php esc_html_e('Sessions', 'convermetry'); ?></th></tr></thead><tbody>
            <?php

            foreach ($friction as $row) {
                ?>
                <tr>
                <td><code><?php echo esc_html($row['field_id']); ?></code></td>
                <td><?php echo esc_html($row['field_type']); ?></td>
                <td><?php echo esc_html(self::errorLabel($row['error_type'])); ?></td>
                <td class="cvmtry-num"><?php echo esc_html(number_format_i18n($row['errors'])); ?></td>
                <td class="cvmtry-num"><?php echo esc_html(number_format_i18n($row['sessions'])); ?></td></tr>
                <?php
            }

            ?>
            </tbody></table>
            <?php
        }

        ?>
        <div class="notice notice-info inline"><p><?php echo wp_kses_post(__('<strong>Elementor forms are not included above.</strong> Elementor identifies a form by its display name on the server while exposing a widget id in the browser, so the two cannot be matched reliably — and an engagement figure attributed to the wrong form is worse than none. Elementor submissions are recorded and attributed normally everywhere else in Convermetry.', 'convermetry')); ?></p></div>
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
            'required'      => __('Left empty', 'convermetry'),
            'type_mismatch' => __('Wrong format (e.g. not an email address)', 'convermetry'),
            'pattern'       => __('Did not match the expected pattern', 'convermetry'),
            'too_short'     => __('Too short', 'convermetry'),
            'too_long'      => __('Too long', 'convermetry'),
            'range'         => __('Outside the allowed range', 'convermetry'),
            'step'          => __('Not an allowed increment', 'convermetry'),
            default         => __('Invalid', 'convermetry'),
        };
    }

    public static function render(): void
    {
        if (!Capability::currentUserCan(Capability::FORMS_MANAGE)) {
            return;
        }

        $registry = self::$registry ?? new FormProviderRegistry();

        ?>
        <div class="wrap cvmtry-wrap">
        <h1><?php esc_html_e('Convermetry Forms', 'convermetry'); ?></h1>
        <p class="description" style="max-width:760px;"><?php echo wp_kses_post(__('Convermetry automatically detects supported form plugins and discovers their forms. Detected forms are <strong>included by default</strong> — a new form starts recording conversions and delivering webhooks without any setup. Exclude a form to stop processing it; its configuration is preserved and restored when re-enabled. <strong>Builder forms that run an explicit action list are the exception</strong> — Elementor Atomic forms and Bricks Builder forms only run the actions you choose in their own editor, so each one needs the Convermetry action added before anything is captured. Both are listed here as soon as they are found, whether or not that action has been added; the notices below say what to do.', 'convermetry')); ?></p>
        <?php

        self::renderEngagement();

        // ── Provider status cards ──────────────────────────────────────
        ?>
        <div class="cvmtry-cards cvmtry-provider-cards">
        <?php
        $availableProviders = [];
        foreach ($registry->all() as $provider) {
            $available = $provider->isAvailable();
            if ($available) {
                $availableProviders[$provider->getKey()] = $provider;
            }

            ?>
            <div class="cvmtry-card cvmtry-provider-card">
            <span class="cvmtry-card-label"><?php echo esc_html($provider->getLabel()); ?></span>
            <span class="cvmtry-provider-status <?php echo ($available ? 'is-active' : 'is-unavailable'); ?>"><?php echo esc_html($available ? __('Active', 'convermetry') : __('Unavailable', 'convermetry')); ?></span></div>
            <?php
        }
        ?>
        </div>
        <?php

        self::renderAtomicSetupNotice(isset($availableProviders[AtomicFormsBridge::PROVIDER_KEY]));
        self::renderBricksSetupNotice(isset($availableProviders[BricksFormsBridge::PROVIDER_KEY]));

        if ($availableProviders === []) {
            ?>
            <div class="notice notice-info inline"><p><?php echo wp_kses_post(__('No supported form plugin or builder is currently active. Install and activate Elementor Pro, Bricks Builder, Gravity Forms, WPForms, Contact Form 7, or Fluent Forms — or integrate a custom form with <code>convermetry_submit_form()</code> (see the About page).', 'convermetry')); ?></p></div></div>
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
        wp_nonce_field(self::SAVE_ACTION, 'cvmtry_forms_nonce');
        ?>
        <input type="hidden" name="action" value="<?php echo esc_attr(self::SAVE_ACTION); ?>">
        <div class="cvmtry-form-filters">
        <label class="screen-reader-text" for="cvmtry-form-search"><?php esc_html_e('Search forms', 'convermetry'); ?></label>
        <input type="search" id="cvmtry-form-search" placeholder="<?php esc_attr_e('Search by form name or ID…', 'convermetry'); ?>">
        <label class="screen-reader-text" for="cvmtry-form-provider-filter"><?php esc_html_e('Filter by provider', 'convermetry'); ?></label>
        <select id="cvmtry-form-provider-filter"><option value=""><?php esc_html_e('All Providers', 'convermetry'); ?></option>
        <?php
        foreach ($availableProviders as $provider) {
            ?>
            <option value="<?php echo esc_attr($provider->getKey()); ?>"><?php echo esc_html($provider->getLabel()); ?></option>
            <?php
        }
        ?>
        </select>
        <label class="screen-reader-text" for="cvmtry-form-state-filter"><?php esc_html_e('Filter by state', 'convermetry'); ?></label>
        <select id="cvmtry-form-state-filter"><option value=""><?php esc_html_e('All States', 'convermetry'); ?></option><option value="included"><?php esc_html_e('Included', 'convermetry'); ?></option><option value="excluded"><?php esc_html_e('Excluded', 'convermetry'); ?></option></select>
        <span class="cvmtry-form-filter-summary"><?php
        // The count span is what forms admin.js rewrites as the filters change.
        echo wp_kses_post(sprintf(
            /* translators: 1: number of forms currently shown, 2: total number of forms. */
            _n('%1$s of %2$d form shown', '%1$s of %2$d forms shown', count($discovered), 'convermetry'),
            '<span id="cvmtry-form-filter-count">' . count($discovered) . '</span>',
            count($discovered)
        ));
        ?></span></div>
        <div id="cvmtry-forms-list">
        <?php

        if ($discovered === []) {
            ?>
            <div class="notice notice-info inline"><p><?php esc_html_e('No forms were discovered yet. Create a form in one of the active providers and revisit this page.', 'convermetry'); ?></p></div>
            <?php
        }

        foreach ($discovered as $form) {
            self::renderFormBlock($form);
        }

        ?>
        </div>
        <?php

        submit_button(__('Save Form Settings', 'convermetry'));

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
     * Explains the one setup step Convermetry cannot perform for the site owner.
     *
     * Elementor's Atomic forms run an explicit "Actions after submit" list, so a
     * form captures nothing until "Convermetry" is added to it. Convermetry
     * deliberately does not add it: that would mean rewriting saved Elementor
     * documents behind the owner's back, and a plugin that edits another
     * plugin's content without being asked is a worse problem than a manual
     * step. So the step is stated plainly, in the place someone goes looking.
     *
     * Rendered whenever the Atomic provider is available — not only when an
     * Atomic form has been discovered — because the most likely moment to need
     * these instructions is right after building the first one.
     *
     * @param bool $atomicAvailable Whether the Atomic provider is active.
     * @return void
     */
    private static function renderAtomicSetupNotice(bool $atomicAvailable): void
    {
        if (!$atomicAvailable) {
            return;
        }

        ?>
        <div class="notice notice-warning inline"><p><?php echo wp_kses_post(__('<strong>Elementor Atomic forms require one extra step.</strong> Open the form in the Elementor editor, select the Atomic form element, add <strong>Convermetry</strong> under <strong>Actions after submit</strong>, and save or update the page or template. Convermetry captures submissions from that form once the action is enabled — submissions made before then are not recorded and cannot be recovered. <strong>Classic Elementor forms are captured automatically</strong> and need none of this.', 'convermetry')); ?></p>
        <p class="description"><?php echo wp_kses_post(__('Atomic forms below are listed as soon as Convermetry finds them in your Elementor content, which happens whether or not the action has been added. Marking one <strong>Included</strong> here does not add the action for you, and Convermetry cannot tell from the outside which forms have it.', 'convermetry')); ?></p></div>
        <?php
    }

    /**
     * The setup step, and the two limits, that apply to Bricks Builder forms.
     *
     * Bricks forms run an explicit "Actions after successful form submit" list,
     * so a form captures nothing until "Convermetry" is one of the selected
     * actions. Convermetry deliberately does not add it: that would mean
     * rewriting saved Bricks content behind the owner's back, and a plugin that
     * edits a builder's documents without being asked is a worse problem than a
     * manual step.
     *
     * Rendered whenever the Bricks provider is available — not only when a
     * Bricks form has been discovered — because the most likely moment to need
     * these instructions is right after building the first one. A Bricks that is
     * installed but older than the release with named custom actions gets a
     * different notice, since for that site there is no step to take.
     *
     * @param bool $bricksAvailable Whether the Bricks provider is active.
     * @return void
     */
    private static function renderBricksSetupNotice(bool $bricksAvailable): void
    {
        if (!$bricksAvailable) {
            self::renderBricksVersionNotice();

            return;
        }

        ?>
        <div class="notice notice-warning inline"><p><?php echo wp_kses_post(__('<strong>Bricks Builder forms require one extra step.</strong> Open the page or template in Bricks, select the <strong>Form</strong> element, tick <strong>Convermetry</strong> under <strong>Actions after successful form submit</strong>, and save. Convermetry captures submissions from that form once the action is selected — submissions made before then are not recorded and cannot be recovered.', 'convermetry')); ?></p>
        <p class="description"><?php echo wp_kses_post(__('Bricks forms below are listed as soon as Convermetry finds them in your Bricks content, which happens whether or not the action has been selected. Marking one <strong>Included</strong> here does not select the action for you, and Convermetry cannot tell from the outside which forms have it.', 'convermetry')); ?></p>
        <p class="description"><?php echo wp_kses_post(__('Each Bricks form is identified by its <strong>element ID</strong>, so one form used in a header, footer, popup or reusable template keeps a single configuration across every page it appears on. Two limits are worth knowing: a form placed inside a Bricks <strong>component</strong> is not listed here (Bricks stores component definitions outside page content), and <strong>password</strong> fields are never recorded, exported, emailed or delivered. Only the native Bricks Form element is supported; third-party Bricks form add-ons are not.', 'convermetry')); ?></p></div>
        <?php
    }

    /**
     * Says so when Bricks is installed but predates named custom form actions.
     *
     * Without this the provider card simply reads "Unavailable" next to an
     * obviously-present Bricks, which reads as a bug rather than as a version
     * requirement.
     *
     * @return void
     */
    private static function renderBricksVersionNotice(): void
    {
        if (!BricksFormsBridge::isInstalled() || BricksFormsBridge::isSupported()) {
            return;
        }

        ?>
        <div class="notice notice-info inline"><p><?php
        echo wp_kses_post(sprintf(
            /* translators: 1: installed Bricks version, 2: minimum Bricks version Convermetry supports. */
            __('<strong>Bricks %1$s is installed, but Convermetry needs Bricks %2$s or newer.</strong> Named custom form actions arrived in that release; on an older Bricks the Convermetry action could be selected in the editor but would never run. Update Bricks to capture its form submissions.', 'convermetry'),
            esc_html(BricksFormsBridge::installedVersion()),
            esc_html(BricksFormsBridge::MIN_VERSION)
        ));
        ?></p></div>
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
        $name    = 'cvmtry_forms[' . $hash . ']';

        ?>
        <details class="cvmtry-form-block" data-provider="<?php echo esc_attr($form['provider']); ?>" data-name="<?php echo esc_attr($form['name']); ?>" data-native-id="<?php echo esc_attr($form['native_id']); ?>" data-form-id="<?php echo esc_attr($config['form_id']); ?>" data-excluded="<?php echo ($config['excluded'] ? '1' : '0'); ?>">
        <summary class="cvmtry-form-block-summary">
        <span class="cvmtry-form-block-name"><?php echo esc_html($form['name']); ?></span>
        <span class="cvmtry-form-block-provider"><?php echo esc_html($form['provider_label']); ?></span>
        <span class="cvmtry-form-state-badge <?php echo ($config['excluded'] ? 'is-excluded' : 'is-included'); ?>"><?php echo esc_html($config['excluded'] ? __('Excluded', 'convermetry') : __('Included', 'convermetry')); ?></span></summary>
        <div class="cvmtry-form-block-body">
        <input type="hidden" name="<?php echo esc_attr($name . '[key]'); ?>" value="<?php echo esc_attr($formKey); ?>">
        <table class="form-table" role="presentation">
        <tr><th scope="row"><?php esc_html_e('Native Form ID', 'convermetry'); ?></th><td><code><?php echo esc_html($form['native_id']); ?></code></td></tr>
        <tr><th scope="row"><label for="cvmtry-form-id-<?php echo esc_attr($hash); ?>"><?php esc_html_e('Custom/External Form ID', 'convermetry'); ?></label></th><td>
        <input type="text" id="cvmtry-form-id-<?php echo esc_attr($hash); ?>" class="regular-text cvmtry-form-id-input" name="<?php echo esc_attr($name . '[form_id]'); ?>" value="<?php echo esc_attr($config['form_id']); ?>">
        <p class="description"><?php echo wp_kses_post(__('Sent as <code>form_id</code> in webhook payloads for this form. Leave blank to use the native form ID.', 'convermetry')); ?></p></td></tr>
        <tr><th scope="row"><?php esc_html_e('Status', 'convermetry'); ?></th><td>
        <label><input type="checkbox" class="cvmtry-form-excluded-toggle" name="<?php echo esc_attr($name . '[excluded]'); ?>" value="1" <?php echo checked($config['excluded'], true, false); ?>>
        <?php esc_html_e('Exclude this form', 'convermetry'); ?></label>
        <p class="description"><?php esc_html_e('Excluded forms are not recorded or delivered. Their configuration is preserved.', 'convermetry'); ?>
        <?php
        if ($form['provider'] === AtomicFormsBridge::PROVIDER_KEY) {
            ?>
            <?php echo wp_kses_post(__('<br><strong>Atomic form:</strong> leaving this included does not switch capture on by itself — this form also needs <strong>Convermetry</strong> added under <strong>Actions after submit</strong> in the Elementor editor.', 'convermetry')); ?>
            <?php
        }

        if ($form['provider'] === BricksFormsBridge::PROVIDER_KEY) {
            ?>
            <?php echo wp_kses_post(__('<br><strong>Bricks form:</strong> leaving this included does not switch capture on by itself — this form also needs <strong>Convermetry</strong> ticked under <strong>Actions after successful form submit</strong> in Bricks.', 'convermetry')); ?>
            <?php
        }
        ?>
        </p></td></tr>
        <tr><th scope="row"><?php esc_html_e('Page URL parameters', 'convermetry'); ?></th><td>
        <label><input type="checkbox" name="<?php echo esc_attr($name . '[include_page_params]'); ?>" value="1" <?php echo checked($config['include_page_params'], true, false); ?>>
        <?php esc_html_e('Include page URL parameters for this form (regardless of the global setting)', 'convermetry'); ?></label></td></tr></table>
        <h4><?php esc_html_e('URL Query Parameters', 'convermetry'); ?> <span class="description"><?php esc_html_e('(this form only — highest precedence)', 'convermetry'); ?></span></h4>
        <?php
        self::renderKvBuilder($name . '[query_params]', $config['query_params']);

        ?>
        <h4><?php esc_html_e('Request Headers', 'convermetry'); ?> <span class="description"><?php esc_html_e('(this form only)', 'convermetry'); ?></span></h4>
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
        <div class="cvmtry-kv-builder" data-kv-name="<?php echo esc_attr($name); ?>" data-kv-next="<?php echo esc_attr((string) count($pairs)); ?>">
        <div class="cvmtry-kv-rows">
        <?php

        foreach ($pairs as $index => $pair) {
            ?>
            <div class="cvmtry-kv-row">
            <input type="text" class="regular-text code cvmtry-kv-key" name="<?php echo esc_attr($name . '[' . $index . '][key]'); ?>" placeholder="<?php esc_attr_e('Key', 'convermetry'); ?>" value="<?php echo esc_attr((string) ($pair['key'] ?? '')); ?>">
            <input type="text" class="regular-text code cvmtry-kv-value" name="<?php echo esc_attr($name . '[' . $index . '][value]'); ?>" placeholder="<?php esc_attr_e('Value', 'convermetry'); ?>" value="<?php echo esc_attr((string) ($pair['value'] ?? '')); ?>">
            <button type="button" class="button cvmtry-kv-remove" aria-label="<?php esc_attr_e('Remove this row', 'convermetry'); ?>"><?php esc_html_e('Remove', 'convermetry'); ?></button></div>
            <?php
        }

        ?>
        </div>
        <button type="button" class="button cvmtry-kv-add"><?php esc_html_e('+ Add', 'convermetry'); ?></button></div>
        <?php
    }
}
