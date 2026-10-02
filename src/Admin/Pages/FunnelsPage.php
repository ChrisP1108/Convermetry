<?php
declare(strict_types=1);

namespace Convermetry\Admin\Pages;

if (!defined('ABSPATH')) exit;

use Convermetry\Admin\AdminAssets;
use Convermetry\Admin\Capability;
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
            HomePage::MENU_SLUG,
            __('Convermetry Funnels', 'convermetry'),
            __('Funnels', 'convermetry'),
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

        wp_enqueue_style(
            'cvmtry-funnels',
            CVMTRY_PLUGIN_URL . 'assets/css/admin-funnels.css',
            [AdminAssets::COMMON_HANDLE],
            CVMTRY_VERSION
        );

        wp_enqueue_script(
            'cvmtry-funnels',
            CVMTRY_PLUGIN_URL . 'assets/js/funnels.js',
            ['wp-i18n', AdminAssets::CONFIRM_HANDLE],
            CVMTRY_VERSION,
            true
        );
        wp_set_script_translations('cvmtry-funnels', 'convermetry');

        wp_localize_script('cvmtry-funnels', 'CVMTRY_FUNNEL', [
            'maxSteps'  => FunnelSettings::MAX_STEPS,
            'minSteps'  => FunnelSettings::MIN_STEPS,
            'stepTypes' => self::stepTypeLabels(),
            'goals'     => self::goalOptions(),
            'operators' => StepCompiler::PAGE_OPERATORS,
            'operatorLabels' => self::operatorLabels(),
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
        if (!self::isRequest('save_funnel', 'cvmtry_save_funnel')) {
            return;
        }

        // phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- the nonce was verified by self::isRequest() above; every field is sanitized by FunnelSettings::sanitize() below.
        $submitted = isset($_POST['funnel']) && is_array($_POST['funnel'])
            ? wp_unslash($_POST['funnel'])
            : [];
        // phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

        // As with goals: the stored funnel is looked up by the submitted id, but
        // the id is never taken FROM the submission into the saved record.
        $existing = FunnelSettings::isValidId((string) ($submitted['funnel_id'] ?? ''))
            ? FunnelRepository::find((string) $submitted['funnel_id'])
            : null;

        $funnel = FunnelSettings::sanitize($submitted, $existing, gmdate('Y-m-d H:i:s'));

        if ($funnel === null) {
            self::redirect(['cvmtry_funnel_error' => 'invalid']);
        }

        if (!FunnelRepository::save($funnel)) {
            self::redirect(['cvmtry_funnel_error' => 'limit']);
        }

        self::redirect(['cvmtry_funnel_saved' => $existing === null ? 'created' : 'updated']);
    }

    /**
     * Soft-deletes a funnel from a nonce-protected POST.
     *
     * @return void
     */
    public static function processDelete(): void
    {
        if (!self::isRequest('delete_funnel', 'cvmtry_delete_funnel')) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by self::isRequest() above.
        $funnelId = sanitize_text_field(wp_unslash((string) ($_POST['funnel_id'] ?? '')));

        if (FunnelSettings::isValidId($funnelId)) {
            FunnelRepository::softDelete($funnelId, gmdate('Y-m-d H:i:s'));
        }

        self::redirect(['cvmtry_funnel_saved' => 'deleted']);
    }

    /**
     * Whether the current request is a valid, authorized POST for one action.
     *
     * @param string $action The cvmtry_action value.
     * @param string $nonce  The nonce action name.
     * @return bool
     */
    private static function isRequest(string $action, string $nonce): bool
    {
        return sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD'] ?? '')) === 'POST'
            && sanitize_key(wp_unslash($_POST['cvmtry_action'] ?? '')) === $action
            && isset($_POST['cvmtry_nonce'])
            && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['cvmtry_nonce'])), $nonce)
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
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only report filter, kept bookmarkable: it is matched against PERIODS and only chooses the date range displayed.
        $requested = isset($_GET['period']) ? absint(wp_unslash($_GET['period'])) : 30;

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
        <div class="wrap cvmtry-wrap cvmtry-funnels-wrap">
        <h1><?php esc_html_e('Funnels', 'convermetry'); ?></h1>
        <?php

        self::renderNotices();

        ?>
        <p class="description cvmtry-goals-intro"><?php esc_html_e('A funnel measures the path to a conversion in order: how many visitors reached each step, and how many were lost between them. Steps are counted per session and must happen in sequence — a visitor who reaches step three without step two is not counted at step three.', 'convermetry'); ?></p>
        <?php

        if (MigrationRunner::isPending()) {
            ?>
            <div class="notice notice-warning inline"><p><?php echo wp_kses_post(__('<strong>Preparing.</strong> Convermetry is still applying a database update from the last plugin upgrade. Funnels will become available as soon as it finishes.', 'convermetry')); ?></p></div></div>
            <?php

            return;
        }

        $period  = self::currentPeriod();
        $funnels = FunnelRepository::visible();

        self::renderPeriodFilter($period);

        if ($funnels === []) {
            ?>
            <div class="notice notice-info inline"><p><?php esc_html_e('No funnels yet. A good first one is three steps: the page a campaign lands on, the form being started, and the submission being confirmed — that alone usually shows whether the problem is traffic, the page, or the form.', 'convermetry'); ?></p></div>
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
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- display-only flags from the redirect after processSave()/processDelete(), which verify their nonce and capability; each only selects one of the fixed notices below.
        $saved = isset($_GET['cvmtry_funnel_saved']) ? sanitize_key(wp_unslash($_GET['cvmtry_funnel_saved'])) : '';
        $error = isset($_GET['cvmtry_funnel_error']) ? sanitize_key(wp_unslash($_GET['cvmtry_funnel_error'])) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        $message = match ($saved) {
            'created' => __('Funnel created. It reports on activity already recorded, so results appear immediately.', 'convermetry'),
            'updated' => __('Funnel updated.', 'convermetry'),
            'deleted' => __('Funnel removed.', 'convermetry'),
            default   => '',
        };

        if ($message !== '') {
            ?>
            <div class="notice notice-success is-dismissible"><p><?php echo esc_html($message); ?></p></div>
            <?php
        }

        $problem = match ($error) {
            'invalid' => sprintf(
                /* translators: %d: minimum number of steps in a funnel. */
                __('That funnel could not be saved. It needs a name and at least %d fully configured steps — a page step needs a path, and a goal step needs a goal.', 'convermetry'),
                FunnelSettings::MIN_STEPS
            ),
            'limit'   => sprintf(
                /* translators: %d: the maximum number of funnels. */
                __('You have reached the limit of %d funnels. Remove one you no longer need to add another.', 'convermetry'),
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
        <div class="cvmtry-period-filter">
        <?php
        foreach (self::PERIODS as $days) {
            $url = add_query_arg(
                ['page' => self::MENU_SLUG, 'period' => (string) $days],
                self_admin_url('admin.php')
            );
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
        <div class="cvmtry-funnel">
        <div class="cvmtry-funnel-header">
        <h2><?php echo esc_html((string) $funnel['name']); ?></h2>
        <?php

        if (empty($funnel['enabled'])) {
            ?>
            <span class="cvmtry-status-chip cvmtry-status-not_sent"><?php esc_html_e('Paused', 'convermetry'); ?></span>
            <?php
        }

        ?>
        <div class="cvmtry-funnel-actions">
        <?php
        printf(
            '<button type="button" class="button-link cvmtry-funnel-edit" data-funnel="%s">%s</button> ',
            esc_attr((string) wp_json_encode($funnel)),
            esc_html__('Edit', 'convermetry')
        );

        // Asked by admin-confirm.js before the form submits.
        $confirm = __('Remove this funnel? Its definition is deleted; no analytics data is affected.', 'convermetry');
        ?>
        <form method="post" class="cvmtry-inline-form" data-cvmtry-confirm="<?php echo esc_attr($confirm); ?>">
        <?php
        wp_nonce_field('cvmtry_delete_funnel', 'cvmtry_nonce');
        ?>
        <input type="hidden" name="cvmtry_action" value="delete_funnel">
        <input type="hidden" name="funnel_id" value="<?php echo esc_attr((string) $funnel['funnel_id']); ?>">
        <button type="submit" class="button-link cvmtry-btn-danger-link"><?php esc_html_e('Remove', 'convermetry'); ?></button></form></div></div>
        <?php

        try {
            $report = FunnelReport::compute($funnel, $start, $end);
        } catch (ReportQueryException) {
            ?>
            <p class="cvmtry-empty-msg"><?php esc_html_e('This funnel could not be measured — a database query failed.', 'convermetry'); ?></p></div>
            <?php

            return;
        }

        if ($report['error'] !== '') {
            ?>
            <p class="cvmtry-empty-msg"><?php echo esc_html($report['error']); ?></p></div>
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
            <p class="cvmtry-empty-msg"><?php esc_html_e('No sessions reached the first step during this period, so there is nothing to measure yet. Check that the first step matches a page visitors actually land on.', 'convermetry'); ?></p>
            <?php

            return;
        }

        ?>
        <div class="cvmtry-funnel-steps">
        <?php

        foreach ($steps as $index => $step) {
            if ($index > 0) {
                printf(
                    '<div class="cvmtry-funnel-drop"><span class="cvmtry-funnel-arrow" aria-hidden="true">&darr;</span> %s</div>',
                    esc_html(sprintf(
                        /* translators: 1: percentage of sessions that continued to this step, 2: number of sessions lost. */
                        __('%1$s%% continued · %2$s lost', 'convermetry'),
                        (string) $step['step_rate'],
                        number_format_i18n((int) $step['dropped'])
                    ))
                );
            }

            $width = max(4.0, (float) $step['overall_rate']);

            $sessions = (int) $step['sessions'];

            printf(
                '<div class="cvmtry-funnel-step"><div class="cvmtry-funnel-bar" style="width:%s%%"></div>'
                . '<div class="cvmtry-funnel-label"><strong>%s</strong><span>%s</span></div></div>',
                esc_attr((string) $width),
                esc_html((string) $step['label'] !== ''
                    ? (string) $step['label']
                    : FunnelSettings::stepLabel($funnel['steps'][$index] ?? [])),
                esc_html(sprintf(
                    /* translators: 1: number of sessions that reached the step, 2: percentage of funnel entrants. */
                    _n('%1$s session · %2$s%% of entrants', '%1$s sessions · %2$s%% of entrants', $sessions, 'convermetry'),
                    number_format_i18n($sessions),
                    (string) $step['overall_rate']
                ))
            );
        }

        ?>
        </div>
        <?php

        echo '<p class="description cvmtry-funnel-summary">' . wp_kses_post(sprintf(
            /* translators: 1: overall conversion percentage, 2: sessions that completed every step, 3: sessions that entered the funnel, 4: hours after the period during which later steps still count. */
            _n(
                'Overall conversion: <strong>%1$s%%</strong> — %2$s of %3$s sessions that entered this funnel completed every step, in order. Later steps are counted for up to %4$d hour after the period ends, so a visit that started near the edge is not unfairly cut off.',
                'Overall conversion: <strong>%1$s%%</strong> — %2$s of %3$s sessions that entered this funnel completed every step, in order. Later steps are counted for up to %4$d hours after the period ends, so a visit that started near the edge is not unfairly cut off.',
                FunnelReport::COMPLETION_WINDOW_HOURS,
                'convermetry'
            ),
            esc_html((string) $report['overall_rate']),
            esc_html(number_format_i18n((int) ($steps[count($steps) - 1]['sessions'] ?? 0))),
            esc_html(number_format_i18n($entered)),
            FunnelReport::COMPLETION_WINDOW_HOURS
        )) . '</p>';
    }

    /**
     * Renders the add/edit form.
     *
     * @return void
     */
    private static function renderEditor(): void
    {
        ?>
        <div class="cvmtry-goal-editor cvmtry-funnel-editor">
            <h2 id="cvmtry-funnel-editor-title"><?php esc_html_e('Add a funnel', 'convermetry'); ?></h2>

            <form method="post" class="cvmtry-funnel-form">
                <?php wp_nonce_field('cvmtry_save_funnel', 'cvmtry_nonce'); ?>
                <input type="hidden" name="cvmtry_action" value="save_funnel">
                <input type="hidden" name="funnel[funnel_id]" value="" class="cvmtry-funnel-id">

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="cvmtry-funnel-name"><?php esc_html_e('Name', 'convermetry'); ?></label></th>
                        <td>
                            <input type="text" id="cvmtry-funnel-name" name="funnel[name]" class="regular-text" required
                                   maxlength="<?php echo esc_attr((string) FunnelSettings::MAX_NAME_LEN); ?>">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Steps', 'convermetry'); ?></th>
                        <td>
                            <div class="cvmtry-funnel-step-rows">
                                <?php for ($i = 0; $i < FunnelSettings::MIN_STEPS; $i++) {
                                    self::renderStepRow($i);
                                } ?>
                            </div>
                            <button type="button" class="button button-secondary cvmtry-funnel-add-step"><?php esc_html_e('Add step', 'convermetry'); ?></button>
                            <p class="description">
                                <?php
                                echo esc_html(sprintf(
                                    /* translators: 1: minimum number of funnel steps, 2: maximum number of funnel steps. */
                                    __('Between %1$d and %2$d steps, in the order visitors take them. A form step with no specific form counts any form on the site.', 'convermetry'),
                                    FunnelSettings::MIN_STEPS,
                                    FunnelSettings::MAX_STEPS
                                ));
                                ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Status', 'convermetry'); ?></th>
                        <td>
                            <label><input type="checkbox" name="funnel[enabled]" value="1" checked> <?php esc_html_e('Active', 'convermetry'); ?></label>
                        </td>
                    </tr>
                </table>

                <p class="submit">
                    <button type="submit" class="button button-primary"><?php esc_html_e('Save funnel', 'convermetry'); ?></button>
                    <button type="button" class="button button-secondary cvmtry-funnel-cancel" hidden><?php esc_html_e('Cancel', 'convermetry'); ?></button>
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
        $operatorLabels = self::operatorLabels();
        $goals = self::goalOptions();
        ?>
        <div class="cvmtry-funnel-step-row">
            <span class="cvmtry-funnel-step-num"><?php echo esc_html((string) ($index + 1)); ?></span>
            <select class="cvmtry-step-type" name="funnel[steps][<?php echo esc_attr((string) $index); ?>][type]">
                <?php foreach (self::stepTypeLabels() as $key => $label) { ?>
                    <option value="<?php echo esc_attr($key); ?>"<?php selected($key, 'page'); ?>>
                        <?php echo esc_html($label); ?>
                    </option>
                <?php } ?>
            </select>
            <select class="cvmtry-step-operator" name="funnel[steps][<?php echo esc_attr((string) $index); ?>][operator]">
                <?php foreach (StepCompiler::PAGE_OPERATORS as $operator) { ?>
                    <option value="<?php echo esc_attr($operator); ?>"<?php selected($operator, 'equals'); ?>>
                        <?php echo esc_html($operatorLabels[$operator]); ?>
                    </option>
                <?php } ?>
            </select>
            <?php /* Not hidden here: without JavaScript the row degrades to
                     showing every control at once, which is noisy but usable.
                     syncRow() hides the irrelevant ones as soon as JS runs. */ ?>
            <select class="cvmtry-step-goal">
                <?php if ($goals === []) { ?>
                    <option value=""><?php esc_html_e('No goals configured yet', 'convermetry'); ?></option>
                <?php } else {
                    foreach ($goals as $goalId => $goalName) { ?>
                        <option value="<?php echo esc_attr($goalId); ?>"><?php echo esc_html($goalName); ?></option>
                    <?php }
                } ?>
            </select>
            <input type="text" class="cvmtry-step-value"
                   name="funnel[steps][<?php echo esc_attr((string) $index); ?>][value]"
                   value="" placeholder="/services/">
            <input type="text" class="cvmtry-step-label"
                   name="funnel[steps][<?php echo esc_attr((string) $index); ?>][label]"
                   value="" placeholder="<?php esc_attr_e('Label (optional)', 'convermetry'); ?>">
            <button type="button" class="button-link cvmtry-btn-danger-link cvmtry-step-remove"
                    aria-label="<?php
                    echo esc_attr(sprintf(
                        /* translators: %d: the funnel step's position. */
                        __('Remove step %d', 'convermetry'),
                        $index + 1
                    ));
                    ?>"><?php esc_html_e('Remove', 'convermetry'); ?></button>
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
            'page'         => __('Visited a page', 'convermetry'),
            'goal'         => __('Completed a goal', 'convermetry'),
            'form_view'    => __('Saw a form', 'convermetry'),
            'form_start'   => __('Started filling a form', 'convermetry'),
            'form_submit'  => __('Attempted to submit a form', 'convermetry'),
            'form_success' => __('Submission confirmed by the form plugin', 'convermetry'),
        ];
    }

    /**
     * Page-step operator labels, keyed by StepCompiler::PAGE_OPERATORS.
     *
     * Also handed to funnels.js, so rows added in the browser read exactly
     * like the ones rendered here.
     *
     * @return array<string, string>
     */
    private static function operatorLabels(): array
    {
        return [
            'equals'      => _x('is exactly', 'funnel step: the page…', 'convermetry'),
            'contains'    => _x('contains', 'funnel step: the page…', 'convermetry'),
            'starts_with' => _x('starts with', 'funnel step: the page…', 'convermetry'),
            'ends_with'   => _x('ends with', 'funnel step: the page…', 'convermetry'),
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
