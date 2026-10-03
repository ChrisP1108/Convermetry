<?php
declare(strict_types=1);

namespace Convermetry\Admin\Pages;

if (!defined('ABSPATH')) exit;

use Convermetry\Admin\AdminAssets;
use Convermetry\Admin\AdminRequest;
use Convermetry\Admin\Capability;
use Convermetry\Admin\ReportPeriod;
use Convermetry\Analytics\GoalReports;
use Convermetry\Analytics\ReportQueryException;
use Convermetry\Database\MigrationRunner;
use Convermetry\Goals\GoalRecorder;
use Convermetry\Goals\GoalRepository;
use Convermetry\Goals\GoalSettings;
use Convermetry\Leads\Money;
use Convermetry\Settings\Options;

/**
 * The "Convermetry → Goals" admin page.
 *
 * Where the Submissions page answers "who converted?", this one answers "what
 * else did visitors do that mattered?" — the phone taps, PDF downloads, booking
 * clicks and pricing-page visits that never produce a form submission and were
 * therefore invisible to every earlier version of the plugin.
 *
 * The screen deliberately does two jobs in one place: it lists each goal WITH
 * its performance for the selected period. A separate configuration screen and
 * reporting screen would have meant a marketer has to hold a goal's definition
 * in their head while looking at its numbers, and the most common question about
 * a goal — "is this actually counting anything?" — is answered by putting the
 * two side by side.
 *
 * Rendering is server-side and form-posted, unlike the Submissions list.
 * Configuration screens here are edited a few times a year and the list is
 * capped at fifty rows; a full AJAX table would be infrastructure for a problem
 * this page does not have.
 */
final class GoalsPage
{
    /** Menu slug for the submenu page. */
    public const string MENU_SLUG = 'convermetry-goals';

    /** Periods (in days) offered by the filter. */
    private const array PERIODS = [7, 30, 90];

    /** admin-post action, and nonce action, for creating or updating a goal. */
    public const string SAVE_ACTION = 'cvmtry_save_goal';

    /** admin-post action, and nonce action, for removing a goal. */
    public const string DELETE_ACTION = 'cvmtry_delete_goal';

    /** Nonce action for the read-only period filter; authorizes nothing else. */
    public const string PERIOD_NONCE = 'cvmtry_goals_period';

    /**
     * Registers menu and request hooks.
     *
     * The handlers hang off admin_post_{action}, so WordPress only calls them
     * for their own form — ordinary admin page loads never reach them.
     *
     * @return void
     */
    public static function init(): void
    {
        add_action('admin_menu', [self::class, 'addMenu']);
        add_action('admin_post_' . self::SAVE_ACTION, [self::class, 'processSave']);
        add_action('admin_post_' . self::DELETE_ACTION, [self::class, 'processDelete']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueueAssets']);
    }

    /**
     * Enqueues the page's script on this page only.
     *
     * The shared admin stylesheet is already enqueued for every Convermetry
     * screen by {@see AnalyticsPage::enqueueAssets()}.
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
            'cvmtry-goals',
            CVMTRY_PLUGIN_URL . 'assets/css/admin-goals.css',
            [AdminAssets::COMMON_HANDLE],
            CVMTRY_VERSION
        );

        wp_enqueue_script(
            'cvmtry-goals',
            CVMTRY_PLUGIN_URL . 'assets/js/goals.js',
            ['wp-i18n', AdminAssets::CONFIRM_HANDLE],
            CVMTRY_VERSION,
            true
        );
        wp_set_script_translations('cvmtry-goals', 'convermetry');
    }

    /**
     * Adds the Goals submenu, directly after Submissions.
     *
     * @return void
     */
    public static function addMenu(): void
    {
        add_submenu_page(
            HomePage::MENU_SLUG,
            __('Convermetry Goals', 'convermetry'),
            __('Goals', 'convermetry'),
            Capability::required(Capability::GOALS_MANAGE),
            self::MENU_SLUG,
            [self::class, 'render']
        );
    }

    // ── Request handlers ─────────────────────────────────────────────────────

    /**
     * Creates or updates a goal (admin_post_cvmtry_save_goal).
     *
     * @return never
     */
    public static function processSave(): never
    {
        // 1. Method: only the editor form's POST.
        if (!AdminRequest::isPost()) {
            AdminRequest::deny(__('Goals can only be saved from the Goals screen.', 'convermetry'), 405);
        }

        // 2. Capability: the scope that grants this screen.
        if (!current_user_can(Capability::required(Capability::GOALS_MANAGE))) {
            AdminRequest::deny(AdminRequest::forbiddenMessage());
        }

        // 3. Nonce present, as one string.
        if (!isset($_POST['cvmtry_nonce']) || !is_string($_POST['cvmtry_nonce'])) {
            AdminRequest::deny(AdminRequest::expiredMessage());
        }

        // 4. Nonce issued for saving a goal.
        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['cvmtry_nonce'])), self::SAVE_ACTION)) {
            AdminRequest::deny(AdminRequest::expiredMessage());
        }

        // 5. Input: the goal fields, each sanitized by GoalSettings::sanitize().
        if (!isset($_POST['goal']) || !is_array($_POST['goal'])) {
            self::redirect(['cvmtry_goal_error' => 'invalid']);
        }

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each field is type-checked and sanitized by GoalSettings::sanitize() below.
        $submitted = wp_unslash($_POST['goal']);

        // The stored goal is looked up by the id in the POST, but the id is
        // never taken FROM the POST into the saved record — GoalSettings keeps
        // whatever the stored goal already had. Otherwise editing one goal could
        // be made to overwrite another's identity, and with it that goal's
        // entire completion history.
        $postedId = is_string($submitted['goal_id'] ?? null) ? sanitize_text_field($submitted['goal_id']) : '';
        $existing = GoalSettings::isValidId($postedId) ? GoalRepository::find($postedId) : null;

        $goal = GoalSettings::sanitize($submitted, $existing, gmdate('Y-m-d H:i:s'));

        if ($goal === null) {
            self::redirect(['cvmtry_goal_error' => 'invalid']);
        }

        if (!GoalRepository::save($goal)) {
            self::redirect(['cvmtry_goal_error' => 'limit']);
        }

        self::redirect([
            'cvmtry_goal_saved' => $existing === null ? 'created' : 'updated',
        ]);
    }

    /**
     * Soft-deletes a goal (admin_post_cvmtry_delete_goal).
     *
     * @return never
     */
    public static function processDelete(): never
    {
        // 1. Method: only the Remove form's POST.
        if (!AdminRequest::isPost()) {
            AdminRequest::deny(__('Goals can only be removed from the Goals screen.', 'convermetry'), 405);
        }

        // 2. Capability: the scope that grants this screen.
        if (!current_user_can(Capability::required(Capability::GOALS_MANAGE))) {
            AdminRequest::deny(AdminRequest::forbiddenMessage());
        }

        // 3. Nonce present, as one string.
        if (!isset($_POST['cvmtry_nonce']) || !is_string($_POST['cvmtry_nonce'])) {
            AdminRequest::deny(AdminRequest::expiredMessage());
        }

        // 4. Nonce issued for removing a goal — a save nonce does not qualify.
        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['cvmtry_nonce'])), self::DELETE_ACTION)) {
            AdminRequest::deny(AdminRequest::expiredMessage());
        }

        // 5. Input: one well-formed goal id.
        $goalId = isset($_POST['goal_id']) && is_string($_POST['goal_id'])
            ? sanitize_text_field(wp_unslash($_POST['goal_id']))
            : '';

        if (!GoalSettings::isValidId($goalId)) {
            self::redirect(['cvmtry_goal_error' => 'missing']);
        }

        GoalRepository::softDelete($goalId, gmdate('Y-m-d H:i:s'));

        self::redirect(['cvmtry_goal_saved' => 'deleted']);
    }

    /**
     * Redirects back to this page with a notice flag, keeping the period the
     * form was posted from (with a fresh filter nonce).
     *
     * @param array<string, string> $args Query arguments to add.
     * @return never
     */
    private static function redirect(array $args): never
    {
        wp_safe_redirect(self::pageUrl(array_merge(self::currentPeriod()->carryArgs(self::PERIOD_NONCE), $args)));
        exit;
    }

    /**
     * This screen's URL with extra query arguments.
     *
     * @param array<string, string> $args Query arguments.
     * @return string
     */
    private static function pageUrl(array $args = []): string
    {
        return add_query_arg(array_merge(['page' => self::MENU_SLUG], $args), self_admin_url('admin.php'));
    }

    /**
     * Where the editor and Remove forms post: admin-post.php, carrying the
     * current period so the redirect afterwards returns to it.
     *
     * @return string
     */
    private static function formAction(): string
    {
        return add_query_arg(self::currentPeriod()->carryArgs(self::PERIOD_NONCE), admin_url('admin-post.php'));
    }

    /**
     * The selected reporting period. A supplied period is accepted only with
     * this screen's filter nonce; see {@see ReportPeriod}.
     *
     * @return ReportPeriod
     */
    private static function currentPeriod(): ReportPeriod
    {
        return ReportPeriod::fromRequest(self::PERIOD_NONCE, self::PERIODS);
    }

    // ── Rendering ────────────────────────────────────────────────────────────

    /**
     * Renders the Goals page.
     *
     * @return void
     */
    public static function render(): void
    {
        if (!Capability::currentUserCan(Capability::GOALS_MANAGE)) {
            return;
        }

        ?>
        <div class="wrap cvmtry-wrap cvmtry-goals-wrap">
        <h1><?php esc_html_e('Goals', 'convermetry'); ?></h1>
        <?php

        self::renderNotices();

        ?>
        <p class="description cvmtry-goals-intro"><?php esc_html_e('A goal is an important visitor action that is not a form submission — a phone number tapped, a PDF opened, a booking link followed, a pricing page reached. Convermetry matches these on the server as activity arrives, so completions carry the same channel and campaign attribution as everything else and can be broken down the same way.', 'convermetry'); ?></p>
        <?php

        // Nothing on this page can work against a half-migrated schema, so it
        // says so plainly rather than rendering controls that would fail.
        if (MigrationRunner::isPending()) {
            ?>
            <div class="notice notice-warning inline"><p><?php echo wp_kses_post(__('<strong>Preparing.</strong> Convermetry is still applying a database update from the last plugin upgrade. Goals will become available as soon as it finishes — this page will work normally then, and no data is lost in the meantime.', 'convermetry')); ?></p></div></div>
            <?php
            return;
        }

        self::renderTrackingWarnings();
        self::renderOverflowNotice();

        $period = self::currentPeriod();
        $goals  = GoalRepository::visible();

        if ($period->refused) {
            ?>
            <div class="notice notice-warning inline"><p><?php echo esc_html(ReportPeriod::refusedMessage()); ?></p></div>
            <?php
        }

        self::renderPeriodFilter($period->days);
        self::renderList($goals, $period->days);
        self::renderEditor();

        ?>
        </div>
        <?php
    }

    /**
     * Renders the save/delete confirmation and error notices.
     *
     * @return void
     */
    private static function renderNotices(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- display-only flags from the redirect after processSave()/processDelete(), which verify their nonce and capability; each only selects one of the fixed notices below.
        $saved = isset($_GET['cvmtry_goal_saved']) ? sanitize_key(wp_unslash($_GET['cvmtry_goal_saved'])) : '';
        $error = isset($_GET['cvmtry_goal_error']) ? sanitize_key(wp_unslash($_GET['cvmtry_goal_error'])) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        $message = match ($saved) {
            'created' => __('Goal created. It starts counting from now — completions are not applied retroactively.', 'convermetry'),
            'updated' => __('Goal updated.', 'convermetry'),
            'deleted' => __('Goal removed. Its past completions are kept and still appear in reports for earlier periods.', 'convermetry'),
            default   => '',
        };

        if ($message !== '') {
            ?>
            <div class="notice notice-success is-dismissible"><p><?php echo esc_html($message); ?></p></div>
            <?php
        }

        $problem = match ($error) {
            'invalid' => __('That goal could not be saved. A goal needs a name, and every rule except "phone link", "email link", and "external link" needs something to match against.', 'convermetry'),
            'limit'   => sprintf(
                /* translators: %d: the maximum number of goals. */
                __('You have reached the limit of %d goals. Remove one you no longer need to add another.', 'convermetry'),
                GoalSettings::MAX_GOALS
            ),
            'missing' => __('That goal could not be found. It may already have been removed.', 'convermetry'),
            default   => '',
        };

        if ($problem !== '') {
            ?>
            <div class="notice notice-error is-dismissible"><p><?php echo esc_html($problem); ?></p></div>
            <?php
        }
    }

    /**
     * Warns when a configured goal cannot fire because the activity it is built
     * on is not being tracked.
     *
     * This is the failure mode most likely to waste somebody's afternoon: a
     * perfectly valid click goal that records nothing because click tracking was
     * switched off months ago. Goals deliberately do NOT override those
     * toggles — silently re-enabling tracking a site owner turned off would be
     * worse — so the page says exactly what is wrong and where to fix it.
     *
     * @return void
     */
    private static function renderTrackingWarnings(): void
    {
        if (!Options::goalsEnabled()) {
            ?>
            <div class="notice notice-warning inline"><p><?php
            echo wp_kses_post(sprintf(
                /* translators: %s: URL of the Convermetry Settings screen. */
                __('<strong>Goal matching is switched off.</strong> Goals below are kept but nothing is being recorded. Turn it back on under <a href="%s">Settings → Tracking</a>.', 'convermetry'),
                esc_url(add_query_arg(['page' => SettingsPage::MENU_SLUG], self_admin_url('admin.php')))
            ));
            ?></p></div>
            <?php

            return;
        }

        $settingsUrl = esc_url(add_query_arg(['page' => SettingsPage::MENU_SLUG], self_admin_url('admin.php')));

        // One complete sentence per case rather than a sentence with the two
        // nouns slotted in: which article and case a noun takes varies by
        // language, and a translator cannot fix that from inside a fragment.
        $warnings = [
            /* translators: %s: URL of the Convermetry Settings screen. */
            'pageview'     => __('You have page and URL goals configured, but <strong>Page views</strong> tracking is switched off in <a href="%s">Settings</a>, so those goals cannot record anything.', 'convermetry'),
            /* translators: %s: URL of the Convermetry Settings screen. */
            'click'        => __('You have click goals configured, but <strong>Link & button clicks</strong> tracking is switched off in <a href="%s">Settings</a>, so those goals cannot record anything.', 'convermetry'),
            /* translators: %s: URL of the Convermetry Settings screen. */
            'custom_event' => __('You have custom event goals configured, but <strong>Custom events</strong> tracking is switched off in <a href="%s">Settings</a>, so those goals cannot record anything.', 'convermetry'),
        ];

        foreach ($warnings as $type => $warning) {
            if (!GoalRepository::needsEventType($type) || Options::isTypeEnabled($type)) {
                continue;
            }

            echo '<div class="notice notice-warning inline"><p>'
                . wp_kses_post(sprintf($warning, $settingsUrl))
                . '</p></div>';
        }
    }

    /**
     * Surfaces goal matches dropped by the per-event cap.
     *
     * A cap that silently discarded conversions would be indistinguishable from
     * a bug, so it is reported rather than only enforced.
     *
     * @return void
     */
    private static function renderOverflowNotice(): void
    {
        $overflow = get_transient(GoalRecorder::OVERFLOW_TRANSIENT);

        if (!is_array($overflow) || (int) ($overflow['count'] ?? 0) < 1) {
            return;
        }

        $count = (int) $overflow['count'];

        echo '<div class="notice notice-warning inline"><p>' . wp_kses_post(sprintf(
            /* translators: 1: number of goal completions not recorded, 2: maximum goals one action may complete. */
            _n(
                '<strong>Overlapping goals.</strong> %1$d goal completion was not recorded because single visitor actions matched more than %2$d goals at once. Narrow the overlapping rules so each action counts towards the goals you actually want.',
                '<strong>Overlapping goals.</strong> %1$d goal completions were not recorded because single visitor actions matched more than %2$d goals at once. Narrow the overlapping rules so each action counts towards the goals you actually want.',
                $count,
                'convermetry'
            ),
            $count,
            \Convermetry\Goals\GoalMatcher::MAX_MATCHES_PER_EVENT
        )) . '</p></div>';
    }

    /**
     * Renders the period selector.
     *
     * @param int $active The selected period in days.
     * @return void
     */
    private static function renderPeriodFilter(int $active): void
    {
        ?>
        <div class="cvmtry-period-filter">
        <?php
        foreach (self::PERIODS as $days) {
            $url = self::pageUrl(ReportPeriod::queryArgs(self::PERIOD_NONCE, $days));
            printf(
                '<a href="%s" class="button %s">%s</a> ',
                esc_url($url),
                $days === $active ? 'button-primary' : 'button-secondary',
                esc_html(sprintf(
                    /* translators: %d: number of days in the reporting period. */
                    _n('Last %d day', 'Last %d days', $days, 'convermetry'),
                    $days
                ))
            );
        }
        ?>
        </div>
        <?php
    }

    /**
     * Renders the goal list with each goal's performance.
     *
     * @param array<int, array<string, mixed>> $goals  Visible goals.
     * @param int                              $period Days.
     * @return void
     */
    private static function renderList(array $goals, int $period): void
    {
        if ($goals === []) {
            ?>
            <div class="notice notice-info inline"><p><?php echo wp_kses_post(__('No goals yet. Add one below — <strong>Phone link clicks</strong> and <strong>Email link clicks</strong> need no configuration beyond a name, and are the quickest way to see whether this is measuring what you expect.', 'convermetry')); ?></p></div>
            <?php

            return;
        }

        $end   = gmdate('Y-m-d H:i:s');
        $start = gmdate('Y-m-d 00:00:00', time() - ($period - 1) * DAY_IN_SECONDS);

        try {
            $summary = GoalReports::summary($start, $end, GoalRepository::names(), GoalSettings::MAX_GOALS);
            $lastSeen = GoalReports::lastSeen();
        } catch (ReportQueryException) {
            ?>
            <div class="notice notice-error inline"><p><?php esc_html_e('Goal performance could not be loaded — a database query failed. The goals themselves are listed below and are unaffected.', 'convermetry'); ?></p></div>
            <?php
            $summary  = ['goals' => [], 'sessions' => 0];
            $lastSeen = [];
        }

        $stats = [];
        foreach ($summary['goals'] as $row) {
            $stats[(string) $row['goal_id']] = $row;
        }

        ?>
        <table class="widefat striped cvmtry-goals-table"><thead><tr>
        <th scope="col"><?php esc_html_e('Goal', 'convermetry'); ?></th><th scope="col"><?php esc_html_e('Rule', 'convermetry'); ?></th>
        <th scope="col" class="cvmtry-num"><?php esc_html_e('Completions', 'convermetry'); ?></th>
        <th scope="col" class="cvmtry-num"><?php esc_html_e('Sessions', 'convermetry'); ?></th>
        <th scope="col" class="cvmtry-num"><?php esc_html_e('Rate', 'convermetry'); ?></th>
        <th scope="col" class="cvmtry-num"><?php esc_html_e('Value', 'convermetry'); ?></th>
        <th scope="col">&nbsp;</th></tr></thead><tbody>
        <?php

        foreach ($goals as $goal) {
            self::renderRow($goal, $stats, $lastSeen);
        }

        ?>
        </tbody></table>
        <?php

        $sessions = (int) ($summary['sessions'] ?? 0);

        echo '<p class="description">' . esc_html(sprintf(
            /* translators: %s: number of sessions in the period. */
            _n(
                'Rates are the share of sessions in this period that completed the goal (%s session in total). A goal counting once per session can never exceed 100%%.',
                'Rates are the share of sessions in this period that completed the goal (%s sessions in total). A goal counting once per session can never exceed 100%%.',
                $sessions,
                'convermetry'
            ),
            number_format_i18n($sessions)
        )) . '</p>';
    }

    /**
     * Renders one goal row.
     *
     * @param array<string, mixed>                $goal     A visible goal.
     * @param array<string, array<string, mixed>> $stats    Performance keyed by goal id.
     * @param array<string, string>               $lastSeen goal_id → last completion timestamp.
     * @return void
     */
    private static function renderRow(array $goal, array $stats, array $lastSeen): void
    {
        $goalId = (string) $goal['goal_id'];
        $row    = $stats[$goalId] ?? null;
        $seen   = $lastSeen[$goalId] ?? '';

        ?>
        <tr>
        <td><strong><?php echo esc_html((string) $goal['name']); ?></strong>
        <?php
        if (empty($goal['enabled'])) {
            ?>
             <span class="cvmtry-status-chip cvmtry-status-not_sent"><?php esc_html_e('Paused', 'convermetry'); ?></span>
            <?php
        }
        ?>
        <div class="cvmtry-goal-meta">
        <?php echo esc_html(!empty($goal['once_per_session']) ? __('Once per session', 'convermetry') : __('Every occurrence', 'convermetry')); ?>
        <?php
        if (($goal['goal_value'] ?? null) !== null) {
            echo ' &middot; ' . esc_html(sprintf(
                /* translators: %s: the monetary value of one goal completion, with currency. */
                __('worth %s', 'convermetry'),
                Money::format((string) $goal['goal_value'], Options::leadCurrency())
            ));
        }
        ?>
        </div></td>
        <td><code><?php echo esc_html(self::describeRule($goal)); ?></code></td>
        <?php

        $completions = (int) ($row['completions'] ?? 0);

        ?>
        <td class="cvmtry-num"><?php echo esc_html(number_format_i18n($completions)); ?></td>
        <td class="cvmtry-num"><?php echo esc_html(number_format_i18n((int) ($row['sessions'] ?? 0))); ?></td>
        <td class="cvmtry-num"><?php echo esc_html(
            $row === null || ($row['sessions'] ?? 0) === 0 ? '—' : $row['conversion_rate'] . '%'
        ); ?></td>
        <td class="cvmtry-num"><?php echo esc_html(
            $row === null || (string) $row['value'] === '0.00'
                ? '—'
                : Money::format((string) $row['value'], (string) ($row['currency'] ?? ''))
        ); ?></td>
        <td class="cvmtry-goal-actions">
        <?php
        printf(
            '<button type="button" class="button-link cvmtry-goal-edit" data-goal="%s">%s</button> ',
            esc_attr((string) wp_json_encode($goal)),
            esc_html__('Edit', 'convermetry')
        );

        // Asked by admin-confirm.js before the form submits.
        $confirm = __('Remove this goal? Its past completions are kept and still appear in reports for earlier periods.', 'convermetry');
        ?>
        <form method="post" action="<?php echo esc_url(self::formAction()); ?>" class="cvmtry-inline-form" data-cvmtry-confirm="<?php echo esc_attr($confirm); ?>">
        <?php
        wp_nonce_field(self::DELETE_ACTION, 'cvmtry_nonce');
        ?>
        <input type="hidden" name="action" value="<?php echo esc_attr(self::DELETE_ACTION); ?>">
        <input type="hidden" name="goal_id" value="<?php echo esc_attr($goalId); ?>">
        <button type="submit" class="button-link cvmtry-btn-danger-link"><?php esc_html_e('Remove', 'convermetry'); ?></button></form></td></tr>
        <?php

        // A goal that has never fired is nearly always a rule that matches
        // nothing. Saying so beats showing a zero and leaving the reader to
        // wonder whether the feature works.
        if ($completions === 0 && $seen === '' && !empty($goal['enabled'])) {
            ?>
            <tr class="cvmtry-goal-note"><td colspan="7"><em><?php echo wp_kses_post(__('This goal has never recorded a completion. Check that the rule matches what visitors actually do — a URL rule should be the path as it appears in the address bar, such as <code>/thank-you/</code>.', 'convermetry')); ?></em></td></tr>
            <?php
        }
    }

    /**
     * A human-readable one-line description of a goal's rule.
     *
     * @param array<string, mixed> $goal A normalized goal.
     * @return string
     */
    public static function describeRule(array $goal): string
    {
        $type     = (string) ($goal['type'] ?? '');
        $operator = (string) ($goal['operator'] ?? '');
        $value    = (string) ($goal['value'] ?? '');

        if ($type === 'custom_event') {
            return 'Convermetry.track("' . $value . '")';
        }

        if ($type === 'click') {
            return match ($operator) {
                'tel'      => __('Click on any tel: link', 'convermetry'),
                'mailto'   => __('Click on any mailto: link', 'convermetry'),
                'external' => __('Click leaving this site', 'convermetry'),
                /* translators: %s: CSS selector. */
                'selector' => sprintf(__('Click matching %s', 'convermetry'), $value),
                /* translators: %s: link URL or text the click must match. */
                'equals'   => sprintf(__('Click where the link is %s', 'convermetry'), $value),
                /* translators: %s: link URL or text the click must contain. */
                default    => sprintf(__('Click where the link contains %s', 'convermetry'), $value),
            };
        }

        return match ($operator) {
            /* translators: %s: page path. */
            'equals'      => sprintf(__('Page is %s', 'convermetry'), $value),
            /* translators: %s: page path prefix. */
            'starts_with' => sprintf(__('Page starts with %s', 'convermetry'), $value),
            /* translators: %s: page path suffix. */
            'ends_with'   => sprintf(__('Page ends with %s', 'convermetry'), $value),
            /* translators: %s: text the page path must contain. */
            default       => sprintf(__('Page contains %s', 'convermetry'), $value),
        };
    }

    /**
     * Renders the add/edit form.
     *
     * @return void
     */
    private static function renderEditor(): void
    {
        ?>
        <div class="cvmtry-goal-editor">
            <h2 id="cvmtry-goal-editor-title"><?php esc_html_e('Add a goal', 'convermetry'); ?></h2>

            <form method="post" action="<?php echo esc_url(self::formAction()); ?>" class="cvmtry-goal-form">
                <?php wp_nonce_field(self::SAVE_ACTION, 'cvmtry_nonce'); ?>
                <input type="hidden" name="action" value="<?php echo esc_attr(self::SAVE_ACTION); ?>">
                <input type="hidden" name="goal[goal_id]" value="" class="cvmtry-goal-id">

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="cvmtry-goal-name"><?php esc_html_e('Name', 'convermetry'); ?></label></th>
                        <td>
                            <input type="text" id="cvmtry-goal-name" name="goal[name]" class="regular-text" required
                                   maxlength="<?php echo esc_attr((string) GoalSettings::MAX_NAME_LEN); ?>">
                            <p class="description"><?php esc_html_e('How this goal appears in reports, e.g. "Phone number tapped".', 'convermetry'); ?></p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label for="cvmtry-goal-type"><?php esc_html_e('What counts', 'convermetry'); ?></label></th>
                        <td>
                            <select id="cvmtry-goal-type" name="goal[type]" class="cvmtry-goal-type">
                                <option value="click"><?php esc_html_e('A click', 'convermetry'); ?></option>
                                <option value="url"><?php esc_html_e('Reaching a page', 'convermetry'); ?></option>
                                <option value="custom_event"><?php esc_html_e('A custom event from your own code', 'convermetry'); ?></option>
                            </select>

                            <select name="goal[operator]" class="cvmtry-goal-operator" aria-label="<?php esc_attr_e('Matching rule', 'convermetry'); ?>">
                                <?php foreach (self::operatorLabels() as $type => $operators): ?>
                                    <?php foreach ($operators as $operator => $label): ?>
                                        <option value="<?php echo esc_attr($operator); ?>"
                                                data-type="<?php echo esc_attr($type); ?>">
                                            <?php echo esc_html($label); ?>
                                        </option>
                                    <?php endforeach; ?>
                                <?php endforeach; ?>
                            </select>

                            <input type="text" name="goal[value]" class="regular-text cvmtry-goal-value"
                                   maxlength="<?php echo esc_attr((string) GoalSettings::MAX_VALUE_LEN); ?>"
                                   placeholder="/thank-you/">

                            <p class="description cvmtry-goal-value-help">
                                <?php echo wp_kses_post(__('For a page, use the path as it appears in the address bar (<code>/thank-you/</code>). Phone, email, and external-link rules need no value.', 'convermetry')); ?>
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><?php esc_html_e('Counting', 'convermetry'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="goal[once_per_session]" value="1" checked>
                                <?php esc_html_e('Count once per visit', 'convermetry'); ?>
                            </label>
                            <p class="description">
                                <?php esc_html_e('On: a visitor who taps the phone number five times counts once — usually what you want for a contact action. Off: every occurrence counts, which suits repeatable actions such as downloads.', 'convermetry'); ?>
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label for="cvmtry-goal-value-amount"><?php esc_html_e('Value', 'convermetry'); ?></label></th>
                        <td>
                            <input type="text" id="cvmtry-goal-value-amount" name="goal[goal_value]" class="small-text"
                                   placeholder="0.00">
                            <span class="description"><?php echo esc_html(Options::leadCurrency()); ?></span>
                            <p class="description">
                                <?php esc_html_e('Optional. What one completion is worth to you, used to total attributed value. Leave blank if you would rather just count them.', 'convermetry'); ?>
                            </p>
                        </td>
                    </tr>

                    <tr class="cvmtry-goal-dynamic-row">
                        <th scope="row"><?php esc_html_e('Value from your code', 'convermetry'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="goal[dynamic_value]" value="1">
                                <?php echo wp_kses_post(__('Use the value passed to <code>Convermetry.track()</code> when one is supplied', 'convermetry')); ?>
                            </label>
                            <p class="description">
                                <?php echo wp_kses_post(__('Custom events only. Your code may pass a number, e.g. <code>Convermetry.track(\'booking\', { value: 250 })</code>. Only a numeric value is read — no other data from that call is ever stored.', 'convermetry')); ?>
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><?php esc_html_e('Status', 'convermetry'); ?></th>
                        <td>
                            <label><input type="checkbox" name="goal[enabled]" value="1" checked> <?php esc_html_e('Actively counting', 'convermetry'); ?></label>
                        </td>
                    </tr>
                </table>

                <p class="submit">
                    <button type="submit" class="button button-primary"><?php esc_html_e('Save goal', 'convermetry'); ?></button>
                    <button type="button" class="button button-secondary cvmtry-goal-cancel" hidden><?php esc_html_e('Cancel', 'convermetry'); ?></button>
                </p>
            </form>
        </div>
        <?php
    }

    /**
     * Operator labels per goal type, written for a marketer rather than a
     * developer.
     *
     * @return array<string, array<string, string>>
     */
    private static function operatorLabels(): array
    {
        return [
            'click' => [
                'tel'      => _x('on a phone number link', 'goal rule: a click…', 'convermetry'),
                'mailto'   => _x('on an email link', 'goal rule: a click…', 'convermetry'),
                'external' => _x('that leaves this site', 'goal rule: a click…', 'convermetry'),
                'contains' => _x('where the link contains', 'goal rule: a click…', 'convermetry'),
                'equals'   => _x('where the link is exactly', 'goal rule: a click…', 'convermetry'),
                'selector' => _x('matching the CSS selector', 'goal rule: a click…', 'convermetry'),
            ],
            'url' => [
                'equals'      => _x('where the page is exactly', 'goal rule: reaching a page…', 'convermetry'),
                'contains'    => _x('where the page contains', 'goal rule: reaching a page…', 'convermetry'),
                'starts_with' => _x('where the page starts with', 'goal rule: reaching a page…', 'convermetry'),
                'ends_with'   => _x('where the page ends with', 'goal rule: reaching a page…', 'convermetry'),
            ],
            'custom_event' => [
                'name' => _x('named', 'goal rule: a custom event…', 'convermetry'),
            ],
        ];
    }
}
