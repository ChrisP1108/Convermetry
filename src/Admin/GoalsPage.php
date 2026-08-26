<?php
declare(strict_types=1);

namespace Convermetry\Admin;

if (!defined('ABSPATH')) exit;

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

    /**
     * Registers menu and request hooks.
     *
     * @return void
     */
    public static function init(): void
    {
        add_action('admin_menu', [self::class, 'addMenu']);
        add_action('admin_init', [self::class, 'processSave']);
        add_action('admin_init', [self::class, 'processDelete']);
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

        wp_enqueue_script(
            'cvm-goals',
            CVM_PLUGIN_URL . 'assets/js/goals.js',
            [],
            CVM_VERSION,
            true
        );
    }

    /**
     * Adds the Goals submenu, directly after Submissions.
     *
     * @return void
     */
    public static function addMenu(): void
    {
        add_submenu_page(
            AnalyticsPage::MENU_SLUG,
            'Convermetry Goals',
            'Goals',
            'manage_options',
            self::MENU_SLUG,
            [self::class, 'render']
        );
    }

    // ── Request handlers ─────────────────────────────────────────────────────

    /**
     * Creates or updates a goal from a nonce-protected POST.
     *
     * @return void
     */
    public static function processSave(): void
    {
        if (!self::isRequest('save_goal', 'cvm_save_goal')) {
            return;
        }

        $submitted = isset($_POST['goal']) && is_array($_POST['goal'])
            ? wp_unslash($_POST['goal'])
            : [];

        // The stored goal is looked up by the id in the POST, but the id is
        // never taken FROM the POST into the saved record — GoalSettings keeps
        // whatever the stored goal already had. Otherwise editing one goal could
        // be made to overwrite another's identity, and with it that goal's
        // entire completion history.
        $existing = GoalSettings::isValidId((string) ($submitted['goal_id'] ?? ''))
            ? GoalRepository::find((string) $submitted['goal_id'])
            : null;

        $goal = GoalSettings::sanitize($submitted, $existing, gmdate('Y-m-d H:i:s'));

        if ($goal === null) {
            self::redirect(['cvm_goal_error' => 'invalid']);
        }

        if (!GoalRepository::save($goal)) {
            self::redirect(['cvm_goal_error' => 'limit']);
        }

        self::redirect([
            'cvm_goal_saved' => $existing === null ? 'created' : 'updated',
        ]);
    }

    /**
     * Soft-deletes a goal from a nonce-protected POST.
     *
     * @return void
     */
    public static function processDelete(): void
    {
        if (!self::isRequest('delete_goal', 'cvm_delete_goal')) {
            return;
        }

        $goalId = sanitize_text_field((string) ($_POST['goal_id'] ?? ''));

        if (GoalSettings::isValidId($goalId)) {
            GoalRepository::softDelete($goalId, gmdate('Y-m-d H:i:s'));
        }

        self::redirect(['cvm_goal_saved' => 'deleted']);
    }

    /**
     * Whether the current request is a valid, authorized POST for one action.
     *
     * @param string $action The cvm_action value.
     * @param string $nonce  The nonce action name.
     * @return bool
     */
    private static function isRequest(string $action, string $nonce): bool
    {
        return ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
            && ($_POST['cvm_action'] ?? '') === $action
            && isset($_POST['cvm_nonce'])
            && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['cvm_nonce'])), $nonce)
            && current_user_can('manage_options');
    }

    /**
     * Redirects back to this page with a notice flag, preserving the period.
     *
     * @param array<string, string> $args Query arguments to add.
     * @return never
     */
    private static function redirect(array $args): never
    {
        wp_safe_redirect(add_query_arg(
            array_merge(['page' => self::MENU_SLUG, 'period' => (string) self::currentPeriod()], $args),
            self_admin_url('admin.php')
        ));
        exit;
    }

    /**
     * The selected reporting period in days.
     *
     * @return int
     */
    private static function currentPeriod(): int
    {
        $requested = isset($_GET['period']) ? (int) $_GET['period'] : 30;

        return in_array($requested, self::PERIODS, true) ? $requested : 30;
    }

    // ── Rendering ────────────────────────────────────────────────────────────

    /**
     * Renders the Goals page.
     *
     * @return void
     */
    public static function render(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        echo '<div class="wrap cvm-wrap cvm-goals-wrap">';
        echo '<h1>Goals</h1>';

        self::renderNotices();

        echo '<p class="description cvm-goals-intro">'
            . 'A goal is an important visitor action that is not a form submission — a phone number tapped, '
            . 'a PDF opened, a booking link followed, a pricing page reached. Convermetry matches these on the '
            . 'server as activity arrives, so completions carry the same channel and campaign attribution as '
            . 'everything else and can be broken down the same way.'
            . '</p>';

        // Nothing on this page can work against a half-migrated schema, so it
        // says so plainly rather than rendering controls that would fail.
        if (MigrationRunner::isPending()) {
            echo '<div class="notice notice-warning inline"><p>'
                . '<strong>Preparing.</strong> Convermetry is still applying a database update from the last '
                . 'plugin upgrade. Goals will become available as soon as it finishes — this page will work '
                . 'normally then, and no data is lost in the meantime.'
                . '</p></div>';
            echo '</div>';
            return;
        }

        self::renderTrackingWarnings();
        self::renderOverflowNotice();

        $period = self::currentPeriod();
        $goals  = GoalRepository::visible();

        self::renderPeriodFilter($period);
        self::renderList($goals, $period);
        self::renderEditor();

        echo '</div>';
    }

    /**
     * Renders the save/delete confirmation and error notices.
     *
     * @return void
     */
    private static function renderNotices(): void
    {
        $saved = isset($_GET['cvm_goal_saved']) ? sanitize_key((string) $_GET['cvm_goal_saved']) : '';
        $error = isset($_GET['cvm_goal_error']) ? sanitize_key((string) $_GET['cvm_goal_error']) : '';

        $message = match ($saved) {
            'created' => 'Goal created. It starts counting from now — completions are not applied retroactively.',
            'updated' => 'Goal updated.',
            'deleted' => 'Goal removed. Its past completions are kept and still appear in reports for earlier periods.',
            default   => '',
        };

        if ($message !== '') {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($message) . '</p></div>';
        }

        $problem = match ($error) {
            'invalid' => 'That goal could not be saved. A goal needs a name, and every rule except '
                . '"phone link", "email link", and "external link" needs something to match against.',
            'limit'   => sprintf(
                'You have reached the limit of %d goals. Remove one you no longer need to add another.',
                GoalSettings::MAX_GOALS
            ),
            default   => '',
        };

        if ($problem !== '') {
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html($problem) . '</p></div>';
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
            echo '<div class="notice notice-warning inline"><p>'
                . '<strong>Goal matching is switched off.</strong> Goals below are kept but nothing is being '
                . 'recorded. Turn it back on under <a href="'
                . esc_url(add_query_arg(['page' => SettingsPage::MENU_SLUG], self_admin_url('admin.php')))
                . '">Settings &rarr; Tracking</a>.'
                . '</p></div>';

            return;
        }

        $labels = [
            'pageview'     => ['Page views', 'page and URL goals'],
            'click'        => ['Link &amp; button clicks', 'click goals'],
            'custom_event' => ['Custom events', 'custom event goals'],
        ];

        foreach ($labels as $type => [$setting, $describes]) {
            if (!GoalRepository::needsEventType($type) || Options::isTypeEnabled($type)) {
                continue;
            }

            printf(
                '<div class="notice notice-warning inline"><p>You have %s configured, but <strong>%s</strong> '
                . 'tracking is switched off in <a href="%s">Settings</a>, so those goals cannot record anything.</p></div>',
                esc_html($describes),
                wp_kses($setting, ['amp' => []]),
                esc_url(add_query_arg(['page' => SettingsPage::MENU_SLUG], self_admin_url('admin.php')))
            );
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

        printf(
            '<div class="notice notice-warning inline"><p><strong>Overlapping goals.</strong> %d goal '
            . 'completions were not recorded because single visitor actions matched more than %d goals at '
            . 'once. Narrow the overlapping rules so each action counts towards the goals you actually '
            . 'want.</p></div>',
            (int) $overflow['count'],
            \Convermetry\Goals\GoalMatcher::MAX_MATCHES_PER_EVENT
        );
    }

    /**
     * Renders the period selector.
     *
     * @param int $active The selected period in days.
     * @return void
     */
    private static function renderPeriodFilter(int $active): void
    {
        echo '<div class="cvm-period-filter">';
        foreach (self::PERIODS as $days) {
            $url = add_query_arg(
                ['page' => self::MENU_SLUG, 'period' => (string) $days],
                self_admin_url('admin.php')
            );
            printf(
                '<a href="%s" class="button %s">Last %d days</a> ',
                esc_url($url),
                $days === $active ? 'button-primary' : 'button-secondary',
                $days
            );
        }
        echo '</div>';
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
            echo '<div class="notice notice-info inline"><p>'
                . 'No goals yet. Add one below — <strong>Phone link clicks</strong> and '
                . '<strong>Email link clicks</strong> need no configuration beyond a name, and are the '
                . 'quickest way to see whether this is measuring what you expect.'
                . '</p></div>';

            return;
        }

        $end   = gmdate('Y-m-d H:i:s');
        $start = gmdate('Y-m-d 00:00:00', time() - ($period - 1) * DAY_IN_SECONDS);

        try {
            $summary = GoalReports::summary($start, $end, GoalRepository::names(), GoalSettings::MAX_GOALS);
            $lastSeen = GoalReports::lastSeen();
        } catch (ReportQueryException) {
            echo '<div class="notice notice-error inline"><p>'
                . 'Goal performance could not be loaded — a database query failed. The goals themselves are '
                . 'listed below and are unaffected.'
                . '</p></div>';
            $summary  = ['goals' => [], 'sessions' => 0];
            $lastSeen = [];
        }

        $stats = [];
        foreach ($summary['goals'] as $row) {
            $stats[(string) $row['goal_id']] = $row;
        }

        echo '<table class="widefat striped cvm-goals-table"><thead><tr>';
        echo '<th scope="col">Goal</th><th scope="col">Rule</th>';
        echo '<th scope="col" class="cvm-num">Completions</th>';
        echo '<th scope="col" class="cvm-num">Sessions</th>';
        echo '<th scope="col" class="cvm-num">Rate</th>';
        echo '<th scope="col" class="cvm-num">Value</th>';
        echo '<th scope="col">&nbsp;</th>';
        echo '</tr></thead><tbody>';

        foreach ($goals as $goal) {
            self::renderRow($goal, $stats, $lastSeen);
        }

        echo '</tbody></table>';

        printf(
            '<p class="description">Rates are the share of sessions in this period that completed the goal '
            . '(%s sessions in total). A goal counting once per session can never exceed 100%%.</p>',
            esc_html(number_format_i18n((int) ($summary['sessions'] ?? 0)))
        );
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

        echo '<tr>';

        echo '<td><strong>' . esc_html((string) $goal['name']) . '</strong>';
        if (empty($goal['enabled'])) {
            echo ' <span class="cvm-status-chip cvm-status-not_sent">Paused</span>';
        }
        echo '<div class="cvm-goal-meta">';
        echo esc_html(!empty($goal['once_per_session']) ? 'Once per session' : 'Every occurrence');
        if (($goal['goal_value'] ?? null) !== null) {
            echo ' &middot; worth ' . esc_html(Money::format((string) $goal['goal_value'], Options::leadCurrency()));
        }
        echo '</div></td>';

        echo '<td><code>' . esc_html(self::describeRule($goal)) . '</code></td>';

        $completions = (int) ($row['completions'] ?? 0);

        echo '<td class="cvm-num">' . esc_html(number_format_i18n($completions)) . '</td>';
        echo '<td class="cvm-num">' . esc_html(number_format_i18n((int) ($row['sessions'] ?? 0))) . '</td>';
        echo '<td class="cvm-num">' . esc_html(
            $row === null || ($row['sessions'] ?? 0) === 0 ? '—' : $row['conversion_rate'] . '%'
        ) . '</td>';
        echo '<td class="cvm-num">' . esc_html(
            $row === null || (string) $row['value'] === '0.00'
                ? '—'
                : Money::format((string) $row['value'], (string) ($row['currency'] ?? ''))
        ) . '</td>';

        echo '<td class="cvm-goal-actions">';
        printf(
            '<button type="button" class="button-link cvm-goal-edit" data-goal="%s">Edit</button> ',
            esc_attr((string) wp_json_encode($goal))
        );
        echo '<form method="post" class="cvm-inline-form" onsubmit="return confirm('
            . esc_attr("Remove this goal? Its past completions are kept and still appear in reports for earlier periods.")
            . ');">';
        wp_nonce_field('cvm_delete_goal', 'cvm_nonce');
        echo '<input type="hidden" name="cvm_action" value="delete_goal">';
        echo '<input type="hidden" name="goal_id" value="' . esc_attr($goalId) . '">';
        echo '<button type="submit" class="button-link cvm-btn-danger-link">Remove</button>';
        echo '</form>';
        echo '</td>';

        echo '</tr>';

        // A goal that has never fired is nearly always a rule that matches
        // nothing. Saying so beats showing a zero and leaving the reader to
        // wonder whether the feature works.
        if ($completions === 0 && $seen === '' && !empty($goal['enabled'])) {
            echo '<tr class="cvm-goal-note"><td colspan="7"><em>'
                . 'This goal has never recorded a completion. Check that the rule matches what visitors '
                . 'actually do — a URL rule should be the path as it appears in the address bar, such as '
                . '<code>/thank-you/</code>.'
                . '</em></td></tr>';
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
                'tel'      => 'Click on any tel: link',
                'mailto'   => 'Click on any mailto: link',
                'external' => 'Click leaving this site',
                'selector' => 'Click matching ' . $value,
                'equals'   => 'Click where the link is ' . $value,
                default    => 'Click where the link contains ' . $value,
            };
        }

        return match ($operator) {
            'equals'      => 'Page is ' . $value,
            'starts_with' => 'Page starts with ' . $value,
            'ends_with'   => 'Page ends with ' . $value,
            default       => 'Page contains ' . $value,
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
        <div class="cvm-goal-editor">
            <h2 id="cvm-goal-editor-title">Add a goal</h2>

            <form method="post" class="cvm-goal-form">
                <?php wp_nonce_field('cvm_save_goal', 'cvm_nonce'); ?>
                <input type="hidden" name="cvm_action" value="save_goal">
                <input type="hidden" name="goal[goal_id]" value="" class="cvm-goal-id">

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="cvm-goal-name">Name</label></th>
                        <td>
                            <input type="text" id="cvm-goal-name" name="goal[name]" class="regular-text" required
                                   maxlength="<?php echo esc_attr((string) GoalSettings::MAX_NAME_LEN); ?>">
                            <p class="description">How this goal appears in reports, e.g. "Phone number tapped".</p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label for="cvm-goal-type">What counts</label></th>
                        <td>
                            <select id="cvm-goal-type" name="goal[type]" class="cvm-goal-type">
                                <option value="click">A click</option>
                                <option value="url">Reaching a page</option>
                                <option value="custom_event">A custom event from your own code</option>
                            </select>

                            <select name="goal[operator]" class="cvm-goal-operator" aria-label="Matching rule">
                                <?php foreach (self::operatorLabels() as $type => $operators): ?>
                                    <?php foreach ($operators as $operator => $label): ?>
                                        <option value="<?php echo esc_attr($operator); ?>"
                                                data-type="<?php echo esc_attr($type); ?>">
                                            <?php echo esc_html($label); ?>
                                        </option>
                                    <?php endforeach; ?>
                                <?php endforeach; ?>
                            </select>

                            <input type="text" name="goal[value]" class="regular-text cvm-goal-value"
                                   maxlength="<?php echo esc_attr((string) GoalSettings::MAX_VALUE_LEN); ?>"
                                   placeholder="/thank-you/">

                            <p class="description cvm-goal-value-help">
                                For a page, use the path as it appears in the address bar
                                (<code>/thank-you/</code>). Phone, email, and external-link rules need no value.
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">Counting</th>
                        <td>
                            <label>
                                <input type="checkbox" name="goal[once_per_session]" value="1" checked>
                                Count once per visit
                            </label>
                            <p class="description">
                                On: a visitor who taps the phone number five times counts once — usually what you
                                want for a contact action. Off: every occurrence counts, which suits repeatable
                                actions such as downloads.
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label for="cvm-goal-value-amount">Value</label></th>
                        <td>
                            <input type="text" id="cvm-goal-value-amount" name="goal[goal_value]" class="small-text"
                                   placeholder="0.00">
                            <span class="description"><?php echo esc_html(Options::leadCurrency()); ?></span>
                            <p class="description">
                                Optional. What one completion is worth to you, used to total attributed value.
                                Leave blank if you would rather just count them.
                            </p>
                        </td>
                    </tr>

                    <tr class="cvm-goal-dynamic-row">
                        <th scope="row">Value from your code</th>
                        <td>
                            <label>
                                <input type="checkbox" name="goal[dynamic_value]" value="1">
                                Use the value passed to <code>Convermetry.track()</code> when one is supplied
                            </label>
                            <p class="description">
                                Custom events only. Your code may pass a number, e.g.
                                <code>Convermetry.track('booking', { value: 250 })</code>. Only a numeric value is
                                read — no other data from that call is ever stored.
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">Status</th>
                        <td>
                            <label><input type="checkbox" name="goal[enabled]" value="1" checked> Actively counting</label>
                        </td>
                    </tr>
                </table>

                <p class="submit">
                    <button type="submit" class="button button-primary">Save goal</button>
                    <button type="button" class="button button-secondary cvm-goal-cancel" hidden>Cancel</button>
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
                'tel'      => 'on a phone number link',
                'mailto'   => 'on an email link',
                'external' => 'that leaves this site',
                'contains' => 'where the link contains',
                'equals'   => 'where the link is exactly',
                'selector' => 'matching the CSS selector',
            ],
            'url' => [
                'equals'      => 'where the page is exactly',
                'contains'    => 'where the page contains',
                'starts_with' => 'where the page starts with',
                'ends_with'   => 'where the page ends with',
            ],
            'custom_event' => [
                'name' => 'named',
            ],
        ];
    }
}
