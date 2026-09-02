<?php
declare(strict_types=1);

namespace Convermetry\Admin;

if (!defined('ABSPATH')) exit;

use Convermetry\Analytics\FunnelReport;
use Convermetry\Analytics\ReportQueryException;
use Convermetry\Database\MigrationRunner;
use Convermetry\Funnels\FunnelRepository;
use Convermetry\Funnels\FunnelSettings;
use Convermetry\Funnels\StepCompiler;
use Convermetry\Goals\GoalRepository;

/**
 * The "Convermetry → Funnels" admin page.
 *
 * Answers the question the rest of the plugin cannot: not "how many converted?"
 * but "where did everyone else go?". A funnel is an ordered set of steps, and
 * the report shows how many sessions reached each one and how many were lost
 * between them.
 *
 * Each funnel renders as a bar per step, sized by its share of the entering
 * cohort, with the drop between bars called out. That layout is the point:
 * a table of five numbers makes a reader do the subtraction, and the whole
 * value of a funnel is seeing WHERE the floor falls away.
 *
 * Like the Goals screen, configuration and results share one page, and both
 * post normally — funnels are edited rarely and capped at twenty.
 */
final class FunnelsPage
{
    /** Menu slug for the submenu page. */
    public const string MENU_SLUG = 'convermetry-funnels';

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
     * Adds the Funnels submenu, directly after Goals.
     *
     * @return void
     */
    public static function addMenu(): void
    {
        add_submenu_page(
            AnalyticsPage::MENU_SLUG,
            'Convermetry Funnels',
            'Funnels',
            Capability::required(Capability::FUNNELS_MANAGE),
            self::MENU_SLUG,
            [self::class, 'render']
        );
    }

    /**
     * Enqueues the page's script on this page only.
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
            'cvm-funnels',
            CVM_PLUGIN_URL . 'assets/js/funnels.js',
            [],
            CVM_VERSION,
            true
        );

        wp_localize_script('cvm-funnels', 'CVM_FUNNEL', [
            'maxSteps'  => FunnelSettings::MAX_STEPS,
            'minSteps'  => FunnelSettings::MIN_STEPS,
            'stepTypes' => self::stepTypeLabels(),
            'goals'     => self::goalOptions(),
            'operators' => StepCompiler::PAGE_OPERATORS,
        ]);
    }

    // ── Request handlers ─────────────────────────────────────────────────────

    /**
     * Creates or updates a funnel from a nonce-protected POST.
     *
     * @return void
     */
    public static function processSave(): void
    {
        if (!self::isRequest('save_funnel', 'cvm_save_funnel')) {
            return;
        }

        $submitted = isset($_POST['funnel']) && is_array($_POST['funnel'])
            ? wp_unslash($_POST['funnel'])
            : [];

        // As with goals: the stored funnel is looked up by the submitted id, but
        // the id is never taken FROM the submission into the saved record.
        $existing = FunnelSettings::isValidId((string) ($submitted['funnel_id'] ?? ''))
            ? FunnelRepository::find((string) $submitted['funnel_id'])
            : null;

        $funnel = FunnelSettings::sanitize($submitted, $existing, gmdate('Y-m-d H:i:s'));

        if ($funnel === null) {
            self::redirect(['cvm_funnel_error' => 'invalid']);
        }

        if (!FunnelRepository::save($funnel)) {
            self::redirect(['cvm_funnel_error' => 'limit']);
        }

        self::redirect(['cvm_funnel_saved' => $existing === null ? 'created' : 'updated']);
    }

    /**
     * Soft-deletes a funnel from a nonce-protected POST.
     *
     * @return void
     */
    public static function processDelete(): void
    {
        if (!self::isRequest('delete_funnel', 'cvm_delete_funnel')) {
            return;
        }

        $funnelId = sanitize_text_field((string) ($_POST['funnel_id'] ?? ''));

        if (FunnelSettings::isValidId($funnelId)) {
            FunnelRepository::softDelete($funnelId, gmdate('Y-m-d H:i:s'));
        }

        self::redirect(['cvm_funnel_saved' => 'deleted']);
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
            && Capability::currentUserCan(Capability::FUNNELS_MANAGE);
    }

    /**
     * Redirects back with a notice flag, preserving the period.
     *
     * @param array<string, string> $args Query arguments.
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
     * Renders the Funnels page.
     *
     * @return void
     */
    public static function render(): void
    {
        if (!Capability::currentUserCan(Capability::FUNNELS_MANAGE)) {
            return;
        }

        ?>
        <div class="wrap cvm-wrap cvm-funnels-wrap">
        <h1>Funnels</h1>
        <?php

        self::renderNotices();

        ?>
        <p class="description cvm-goals-intro">A funnel measures the path to a conversion in order: how many visitors reached
        each step, and how many were lost between them. Steps are counted per session and must happen in sequence — a visitor who
        reaches step three without step two is not counted at step three.</p>
        <?php

        if (MigrationRunner::isPending()) {
            ?>
            <div class="notice notice-warning inline"><p><strong>Preparing.</strong> Convermetry is still applying a database
            update from the last plugin upgrade. Funnels will become available as soon as it finishes.</p></div></div>
            <?php

            return;
        }

        $period  = self::currentPeriod();
        $funnels = FunnelRepository::visible();

        self::renderPeriodFilter($period);

        if ($funnels === []) {
            ?>
            <div class="notice notice-info inline"><p>No funnels yet. A good first one is three steps: the page a campaign
            lands on, the form being started, and the submission being confirmed — that alone usually shows whether the problem is
            traffic, the page, or the form.</p></div>
            <?php
        } else {
            $end   = gmdate('Y-m-d H:i:s');
            $start = gmdate('Y-m-d 00:00:00', time() - ($period - 1) * DAY_IN_SECONDS);

            foreach ($funnels as $funnel) {
                self::renderFunnel($funnel, $start, $end);
            }
        }

        self::renderEditor();

        ?>
        </div>
        <?php
    }

    /**
     * Renders save/delete notices.
     *
     * @return void
     */
    private static function renderNotices(): void
    {
        $saved = isset($_GET['cvm_funnel_saved']) ? sanitize_key((string) $_GET['cvm_funnel_saved']) : '';
        $error = isset($_GET['cvm_funnel_error']) ? sanitize_key((string) $_GET['cvm_funnel_error']) : '';

        $message = match ($saved) {
            'created' => 'Funnel created. It reports on activity already recorded, so results appear immediately.',
            'updated' => 'Funnel updated.',
            'deleted' => 'Funnel removed.',
            default   => '',
        };

        if ($message !== '') {
            ?>
            <div class="notice notice-success is-dismissible"><p><?php echo esc_html($message); ?></p></div>
            <?php
        }

        $problem = match ($error) {
            'invalid' => sprintf(
                'That funnel could not be saved. It needs a name and at least %d fully configured steps — '
                . 'a page step needs a path, and a goal step needs a goal.',
                FunnelSettings::MIN_STEPS
            ),
            'limit'   => sprintf(
                'You have reached the limit of %d funnels. Remove one you no longer need to add another.',
                FunnelSettings::MAX_FUNNELS
            ),
            default   => '',
        };

        if ($problem !== '') {
            ?>
            <div class="notice notice-error is-dismissible"><p><?php echo esc_html($problem); ?></p></div>
            <?php
        }
    }

    /**
     * Renders the period selector.
     *
     * @param int $active Selected period in days.
     * @return void
     */
    private static function renderPeriodFilter(int $active): void
    {
        ?>
        <div class="cvm-period-filter">
        <?php
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
        ?>
        </div>
        <?php
    }

    /**
     * Renders one funnel and its results.
     *
     * @param array<string, mixed> $funnel A visible funnel.
     * @param string               $start  UTC datetime (inclusive).
     * @param string               $end    UTC datetime (exclusive).
     * @return void
     */
    private static function renderFunnel(array $funnel, string $start, string $end): void
    {
        ?>
        <div class="cvm-funnel">
        <div class="cvm-funnel-header">
        <h2><?php echo esc_html((string) $funnel['name']); ?></h2>
        <?php

        if (empty($funnel['enabled'])) {
            ?>
            <span class="cvm-status-chip cvm-status-not_sent">Paused</span>
            <?php
        }

        ?>
        <div class="cvm-funnel-actions">
        <?php
        printf(
            '<button type="button" class="button-link cvm-funnel-edit" data-funnel="%s">Edit</button> ',
            esc_attr((string) wp_json_encode($funnel))
        );
        ?>
        <form method="post" class="cvm-inline-form" onsubmit="return confirm(<?php echo esc_attr('Remove this funnel? Its definition is deleted; no analytics data is affected.'); ?>);">
        <?php
        wp_nonce_field('cvm_delete_funnel', 'cvm_nonce');
        ?>
        <input type="hidden" name="cvm_action" value="delete_funnel">
        <input type="hidden" name="funnel_id" value="<?php echo esc_attr((string) $funnel['funnel_id']); ?>">
        <button type="submit" class="button-link cvm-btn-danger-link">Remove</button></form></div></div>
        <?php

        try {
            $report = FunnelReport::compute($funnel, $start, $end);
        } catch (ReportQueryException) {
            ?>
            <p class="cvm-empty-msg">This funnel could not be measured — a database query failed.</p></div>
            <?php

            return;
        }

        if ($report['error'] !== '') {
            ?>
            <p class="cvm-empty-msg"><?php echo esc_html($report['error']); ?></p></div>
            <?php

            return;
        }

        self::renderSteps($report, $funnel);
        ?>
        </div>
        <?php
    }

    /**
     * Renders the step bars.
     *
     * @param array{steps: list<array<string, mixed>>, overall_rate: float, error: string} $report The computed funnel.
     * @param array<string, mixed>                                                         $funnel The funnel.
     * @return void
     */
    private static function renderSteps(array $report, array $funnel): void
    {
        $steps   = $report['steps'];
        $entered = (int) ($steps[0]['sessions'] ?? 0);

        if ($entered === 0) {
            ?>
            <p class="cvm-empty-msg">No sessions reached the first step during this period, so there is nothing to measure
            yet. Check that the first step matches a page visitors actually land on.</p>
            <?php

            return;
        }

        ?>
        <div class="cvm-funnel-steps">
        <?php

        foreach ($steps as $index => $step) {
            if ($index > 0) {
                printf(
                    '<div class="cvm-funnel-drop"><span class="cvm-funnel-arrow" aria-hidden="true">&darr;</span> '
                    . '%s%% continued &middot; %s lost</div>',
                    esc_html((string) $step['step_rate']),
                    esc_html(number_format_i18n((int) $step['dropped']))
                );
            }

            $width = max(4.0, (float) $step['overall_rate']);

            printf(
                '<div class="cvm-funnel-step"><div class="cvm-funnel-bar" style="width:%s%%"></div>'
                . '<div class="cvm-funnel-label"><strong>%s</strong>'
                . '<span>%s sessions &middot; %s%% of entrants</span></div></div>',
                esc_attr((string) $width),
                esc_html((string) $step['label'] !== ''
                    ? (string) $step['label']
                    : FunnelSettings::stepLabel($funnel['steps'][$index] ?? [])),
                esc_html(number_format_i18n((int) $step['sessions'])),
                esc_html((string) $step['overall_rate'])
            );
        }

        ?>
        </div>
        <?php

        printf(
            '<p class="description cvm-funnel-summary">Overall conversion: <strong>%s%%</strong> — %s of %s '
            . 'sessions that entered this funnel completed every step, in order. Later steps are counted for '
            . 'up to %d hours after the period ends, so a visit that started near the edge is not unfairly '
            . 'cut off.</p>',
            esc_html((string) $report['overall_rate']),
            esc_html(number_format_i18n((int) ($steps[count($steps) - 1]['sessions'] ?? 0))),
            esc_html(number_format_i18n($entered)),
            FunnelReport::COMPLETION_WINDOW_HOURS
        );
    }

    /**
     * Renders the add/edit form.
     *
     * @return void
     */
    private static function renderEditor(): void
    {
        ?>
        <div class="cvm-goal-editor cvm-funnel-editor">
            <h2 id="cvm-funnel-editor-title">Add a funnel</h2>

            <form method="post" class="cvm-funnel-form">
                <?php wp_nonce_field('cvm_save_funnel', 'cvm_nonce'); ?>
                <input type="hidden" name="cvm_action" value="save_funnel">
                <input type="hidden" name="funnel[funnel_id]" value="" class="cvm-funnel-id">

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="cvm-funnel-name">Name</label></th>
                        <td>
                            <input type="text" id="cvm-funnel-name" name="funnel[name]" class="regular-text" required
                                   maxlength="<?php echo esc_attr((string) FunnelSettings::MAX_NAME_LEN); ?>">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Steps</th>
                        <td>
                            <div class="cvm-funnel-step-rows">
                                <?php for ($i = 0; $i < FunnelSettings::MIN_STEPS; $i++) {
                                    self::renderStepRow($i);
                                } ?>
                            </div>
                            <button type="button" class="button button-secondary cvm-funnel-add-step">Add step</button>
                            <p class="description">
                                Between <?php echo esc_html((string) FunnelSettings::MIN_STEPS); ?> and
                                <?php echo esc_html((string) FunnelSettings::MAX_STEPS); ?> steps, in the order
                                visitors take them. A form step with no specific form counts any form on the site.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Status</th>
                        <td>
                            <label><input type="checkbox" name="funnel[enabled]" value="1" checked> Active</label>
                        </td>
                    </tr>
                </table>

                <p class="submit">
                    <button type="submit" class="button button-primary">Save funnel</button>
                    <button type="button" class="button button-secondary cvm-funnel-cancel" hidden>Cancel</button>
                </p>
            </form>
        </div>
        <?php
    }

    /**
     * Renders one empty step row.
     *
     * The editor needs MIN_STEPS rows before it can be submitted at all, and
     * they used to be created only by funnels.js — so with the script blocked
     * or erroring, the container rendered empty and every save failed the
     * server-side minimum with no way for the user to add a row.
     *
     * KEEP IN SYNC with buildRow() in assets/js/funnels.js, which renders the
     * identical markup for rows added after these. The class names are the
     * contract: syncRow() and renumber() query them, and the JS adopts these
     * rows rather than replacing them.
     *
     * @param int $index Zero-based row index, used for the field names.
     * @return void
     */
    private static function renderStepRow(int $index): void
    {
        $operatorLabels = [
            'equals'      => 'is exactly',
            'contains'    => 'contains',
            'starts_with' => 'starts with',
            'ends_with'   => 'ends with',
        ];
        $goals = self::goalOptions();
        ?>
        <div class="cvm-funnel-step-row">
            <span class="cvm-funnel-step-num"><?php echo esc_html((string) ($index + 1)); ?></span>
            <select class="cvm-step-type" name="funnel[steps][<?php echo esc_attr((string) $index); ?>][type]">
                <?php foreach (self::stepTypeLabels() as $key => $label) { ?>
                    <option value="<?php echo esc_attr($key); ?>"<?php selected($key, 'page'); ?>>
                        <?php echo esc_html($label); ?>
                    </option>
                <?php } ?>
            </select>
            <select class="cvm-step-operator" name="funnel[steps][<?php echo esc_attr((string) $index); ?>][operator]">
                <?php foreach (StepCompiler::PAGE_OPERATORS as $operator) { ?>
                    <option value="<?php echo esc_attr($operator); ?>"<?php selected($operator, 'equals'); ?>>
                        <?php echo esc_html($operatorLabels[$operator]); ?>
                    </option>
                <?php } ?>
            </select>
            <?php /* Not hidden here: without JavaScript the row degrades to
                     showing every control at once, which is noisy but usable.
                     syncRow() hides the irrelevant ones as soon as JS runs. */ ?>
            <select class="cvm-step-goal">
                <?php if ($goals === []) { ?>
                    <option value="">No goals configured yet</option>
                <?php } else {
                    foreach ($goals as $goalId => $goalName) { ?>
                        <option value="<?php echo esc_attr($goalId); ?>"><?php echo esc_html($goalName); ?></option>
                    <?php }
                } ?>
            </select>
            <input type="text" class="cvm-step-value"
                   name="funnel[steps][<?php echo esc_attr((string) $index); ?>][value]"
                   value="" placeholder="/services/">
            <input type="text" class="cvm-step-label"
                   name="funnel[steps][<?php echo esc_attr((string) $index); ?>][label]"
                   value="" placeholder="Label (optional)">
            <button type="button" class="button-link cvm-btn-danger-link cvm-step-remove"
                    aria-label="Remove step <?php echo esc_attr((string) ($index + 1)); ?>">Remove</button>
        </div>
        <?php
    }

    /**
     * Step type labels, written for a marketer.
     *
     * @return array<string, string>
     */
    private static function stepTypeLabels(): array
    {
        return [
            'page'         => 'Visited a page',
            'goal'         => 'Completed a goal',
            'form_view'    => 'Saw a form',
            'form_start'   => 'Started filling a form',
            'form_submit'  => 'Attempted to submit a form',
            'form_success' => 'Submission confirmed by the form plugin',
        ];
    }

    /**
     * Goal options for a goal step, as id → name.
     *
     * @return array<string, string>
     */
    private static function goalOptions(): array
    {
        $out = [];

        foreach (GoalRepository::visible() as $goal) {
            $out[(string) $goal['goal_id']] = (string) $goal['name'];
        }

        return $out;
    }
}
