<?php
declare(strict_types=1);

namespace Convermetry\Admin\Pages;

if (!defined('ABSPATH')) exit;

use Convermetry\Admin\AdminAssets;
use Convermetry\Admin\AdminRequest;
use Convermetry\Admin\AnalyticsExport;
use Convermetry\Admin\Capability;
use Convermetry\Admin\Charts;
use Convermetry\Admin\Export;
use Convermetry\Admin\ReportPeriod;
use Convermetry\Analytics\AnalyticsSectionRegistry;
use Convermetry\Analytics\GoalReports;
use Convermetry\Analytics\LeadReports;
use Convermetry\Analytics\ReportQueryException;
use Convermetry\Analytics\Reports;
use Convermetry\Api\TrackingController;
use Convermetry\Goals\GoalRepository;
use Convermetry\Leads\Money;
use Convermetry\Settings\Options;

/**
 * The "Convermetry → Analytics" admin page that visualizes collected
 * analytics.
 *
 * This was the top-level screen until {@see HomePage} took that slot, and the
 * move cost it nothing but its slug: every report, filter, chart and export
 * below is unchanged, and it is still the first entry under the menu. The slug
 * had to change because two pages cannot share one, and the top-level slug is
 * the one a site's existing bookmarks and the plugin's own menu item point at.
 *
 * Renders, for a selectable period (7/30/90 days), an Overview section
 * (summary cards and an accessible daily page-view chart) followed by
 * collapsible report sections — Content, Engagement, Acquisition, Devices,
 * Conversions, and Recent Activity — so the page stays scannable without
 * hiding any data.
 *
 * The chart is dependency-free: each day is a real <button> with its value
 * in an accessible label and data attributes (assets/js/dashboard.js adds
 * the visual tooltip), and a "View data table" fallback exposes every daily
 * value even without JavaScript. Collapsible sections are native <details>
 * elements, so they are keyboard accessible with no script at all. A
 * Print / Save as PDF button produces a print-optimized report.
 *
 * The Overview also carries a device doughnut and a grid of summary charts
 * (top pages, clicked elements, channels, goal completions, lead outcomes).
 * Each is drawn from the SAME result its panel's table prints — see
 * {@see self::result()} — so a chart costs no query and cannot disagree with
 * the table it links to.
 *
 * All numbers come from {@see Reports}, the same query layer the analytics
 * webhook payload uses, so the dashboard and webhook consumers always agree.
 */
final class AnalyticsPage
{
    /** Menu slug for the submenu page. */
    public const string MENU_SLUG = 'convermetry-analytics';

    /** @var int[] Periods (in days) selectable in the dashboard filter. */
    private const array PERIODS = [7, 30, 90];

    /** Nonce action for the read-only period filter; authorizes nothing else. */
    public const string PERIOD_NONCE = 'cvmtry_analytics_period';

    /** Rows each Overview summary chart shows; its panel's table shows them all. */
    private const int HIGHLIGHT_ROWS = 5;

    /** admin-post action, and nonce action, for the CSV export. */
    public const string EXPORT_CSV_ACTION = 'cvmtry_analytics_export_csv';

    /** admin-post action, and nonce action, for the JSON export. */
    public const string EXPORT_JSON_ACTION = 'cvmtry_analytics_export_json';

    /**
     * Report results already fetched during this render, by key — or the
     * query's failure, so a chart and its table fail together, once.
     *
     * @var array<string, mixed>
     */
    private static array $results = [];

    /**
     * Registers the admin menu, asset, and export hooks.
     *
     * @return void
     */
    public static function init(): void
    {
        add_action('admin_menu', [self::class, 'addMenu']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueueAssets']);
        add_action('admin_post_' . self::EXPORT_CSV_ACTION, [self::class, 'processExportCsv']);
        add_action('admin_post_' . self::EXPORT_JSON_ACTION, [self::class, 'processExportJson']);
    }

    /**
     * Streams the selected period's reports as CSV
     * (admin_post_cvmtry_analytics_export_csv).
     *
     * @return never
     */
    public static function processExportCsv(): never
    {
        $days = self::authorizeExport(self::EXPORT_CSV_ACTION);
        [$effectiveDays, $start, $end] = self::reportWindow($days);

        AnalyticsExport::csv(
            'convermetry-analytics-' . $days . 'd-' . gmdate('Y-m-d') . '.csv',
            self::reportHeading($days, $effectiveDays),
            AnalyticsExport::sections($start, $end)
        );
    }

    /**
     * Sends the selected period's reports as JSON
     * (admin_post_cvmtry_analytics_export_json).
     *
     * @return never
     */
    public static function processExportJson(): never
    {
        $days = self::authorizeExport(self::EXPORT_JSON_ACTION);
        [$effectiveDays, $start, $end] = self::reportWindow($days);

        Export::json('convermetry-analytics-' . $days . 'd-' . gmdate('Y-m-d') . '.json', AnalyticsExport::document(
            AnalyticsExport::sections($start, $end),
            [
                'report'       => 'convermetry_analytics',
                'site'         => ['name' => get_bloginfo('name'), 'url' => home_url()],
                'period'       => [
                    'days_selected' => $days,
                    'days_shown'    => $effectiveDays,
                    'start'         => $start,
                    'end'           => $end,
                    'timezone'      => 'UTC',
                ],
                'generated_at' => gmdate('Y-m-d H:i:s'),
            ]
        ));
    }

    /**
     * The guard both exports share, in the order every handler here checks:
     * method, scope, nonce present, nonce issued for THIS format — then the
     * one input, the period, which must be the screen's own signed choice.
     *
     * @param string $action The export's admin-post and nonce action.
     * @return int The period, in days.
     */
    private static function authorizeExport(string $action): int
    {
        // 1. Method: export links are followed, never posted.
        if (!AdminRequest::isGet()) {
            AdminRequest::deny(__('Exports are downloaded from the Analytics screen.', 'convermetry'), 405);
        }

        // 2. Capability: the export holds what the screen shows.
        if (!current_user_can(Capability::required(Capability::ANALYTICS_VIEW))) {
            AdminRequest::deny(AdminRequest::forbiddenMessage());
        }

        // 3 and 4. Nonce present, as one string, and issued for this format.
        if (!Export::nonceIsValid($action)) {
            AdminRequest::deny(__('Invalid or expired export link.', 'convermetry'));
        }

        // 5. Input: the period. A refused one is refused here too — the
        // screen falls back to its default and says so, but a file cannot
        // carry that notice, and a download covering a different period than
        // the one asked for would be silently wrong.
        $period = self::currentPeriod();
        if ($period->refused) {
            AdminRequest::deny(__('Invalid or expired export link.', 'convermetry'));
        }

        return $period->days;
    }

    /**
     * The window a period covers: clamped to retention, aligned to UTC days.
     *
     * Shared by the screen and its exports so both always cover exactly the
     * same rows.
     *
     * @param int $days Selected period in days.
     * @return array{0: int, 1: string, 2: string} [days shown, start, end]
     */
    private static function reportWindow(int $days): array
    {
        // Clamped to the configured retention window: querying/displaying
        // the full nominal period when retention is shorter would silently
        // zero-fill days past the actual cutoff and understate any "per day"
        // average.
        $effectiveDays = min($days, Options::retentionDays());
        $end           = gmdate('Y-m-d H:i:s');

        // Align the range to calendar days (UTC): the period covers the last
        // N days *including today*, so the chart renders exactly N bars.
        $start = gmdate('Y-m-d 00:00:00', time() - ($effectiveDays - 1) * DAY_IN_SECONDS);

        return [$effectiveDays, $start, $end];
    }

    /**
     * Adds the Analytics submenu, first under the Convermetry menu after Home.
     *
     * @return void
     */
    public static function addMenu(): void
    {
        add_submenu_page(
            HomePage::MENU_SLUG,
            __('Convermetry Analytics', 'convermetry'),
            __('Analytics', 'convermetry'),
            Capability::required(Capability::ANALYTICS_VIEW),
            self::MENU_SLUG,
            [self::class, 'render']
        );
    }

    /**
     * Enqueues the dashboard assets (chart tooltips, print prep) on this
     * screen only.
     *
     * The shared stylesheets belong to every Convermetry screen and are
     * enqueued by {@see AdminAssets}; this page used to carry them because it
     * owned the top-level slug, which made the plugin's styling depend on an
     * accident of routing.
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
            'cvmtry-analytics',
            CVMTRY_PLUGIN_URL . 'assets/css/admin-analytics.css',
            [AdminAssets::COMMON_HANDLE],
            CVMTRY_VERSION
        );

        // The script keeps the 'cvmtry-dashboard' handle and file name
        // (assets/js/dashboard.js): only the stylesheet was renamed, to
        // match every other screen's assets/css/admin-<page>.css.
        wp_enqueue_script(
            'cvmtry-dashboard',
            CVMTRY_PLUGIN_URL . 'assets/js/dashboard.js',
            ['wp-i18n'],
            CVMTRY_VERSION,
            true
        );
        wp_set_script_translations('cvmtry-dashboard', 'convermetry');
    }

    /**
     * Renders the Analytics page.
     *
     * @return void
     */
    public static function render(): void
    {
        if (!Capability::currentUserCan(Capability::ANALYTICS_VIEW)) {
            return;
        }

        $selected = self::currentPeriod();
        $days     = $selected->days;

        [$effectiveDays, $start, $end] = self::reportWindow($days);

        // Queried together and guarded by one try/catch: a failure partway
        // through must not render summary cards built from only half of
        // these values.
        $overviewFailed = false;
        $totals         = [];
        $daily          = [];
        $serverCount    = 0;

        try {
            $totals = Reports::totalsByType($start, $end);
            $daily  = Reports::dailyCounts($start, $end, 'pageview');

            // The Confirmed Conversions card must agree with the Campaigns
            // and Channels reports, which deduplicate by conversion id.
            $totals['form_success'] = Reports::conversionCount($start, $end);
            $serverCount            = Reports::serverSubmissionCount($start, $end);
        } catch (ReportQueryException) {
            $overviewFailed = true;
        }

        ?>
        <div class="wrap cvmtry-wrap cvmtry-dash">
        <div class="cvmtry-page-header">
        <h1><?php esc_html_e('Convermetry Analytics', 'convermetry'); ?></h1>
        <div class="cvmtry-page-actions">
        <?php
        self::renderPeriodNav($days);

        // The exports cover the period shown, so each link carries it, signed.
        $periodArgs = ReportPeriod::queryArgs(self::PERIOD_NONCE, $days);

        // The print button is revealed by dashboard.js, like the panel
        // toolbar below: without JavaScript it would do nothing.
        ?>
        <span class="cvmtry-export-group">
        <a class="button" href="<?php echo esc_url(Export::url(self::EXPORT_CSV_ACTION, $periodArgs)); ?>"><?php esc_html_e('Export CSV', 'convermetry'); ?></a>
        <a class="button" href="<?php echo esc_url(Export::url(self::EXPORT_JSON_ACTION, $periodArgs)); ?>"><?php esc_html_e('Export JSON', 'convermetry'); ?></a>
        <button type="button" class="button cvmtry-print-btn" hidden><?php esc_html_e('Print / Save as PDF', 'convermetry'); ?></button></span></div></div>
        <hr class="wp-header-end">
        <?php

        self::maybeRenderRateLimitNotice();

        if ($selected->refused) {
            ?>
            <div class="notice notice-warning inline"><p><?php echo esc_html(ReportPeriod::refusedMessage()); ?></p></div>
            <?php
        }

        self::maybeRenderRetentionNotice($days);
        self::renderPeriodRange($days, $effectiveDays);

        ?>
        <section class="cvmtry-overview" aria-labelledby="cvmtry-h-overview">
        <h2 id="cvmtry-h-overview"><?php esc_html_e('Overview', 'convermetry'); ?></h2>
        <?php
        if ($overviewFailed) {
            self::renderErrorNotice();
        } else {
            self::renderSummaryCards($totals, $serverCount);
        }

        // The device query is its own; it fails, or succeeds, independently
        // of the overview figures above.
        ?>
        <div class="cvmtry-overview-charts">
        <?php
        if (!$overviewFailed) {
            self::renderPageviewChart($daily, $effectiveDays);
        }
        self::renderDeviceSummary($start, $end);
        ?>
        </div>
        <?php
        self::renderHighlights($start, $end);
        ?>
        </section>
        <?php

        // Revealed by dashboard.js: without JavaScript the buttons would do
        // nothing, and the <details> panels are already usable natively.
        ?>
        <div class="cvmtry-panel-toolbar" hidden>
        <button type="button" class="button cvmtry-panels-expand"><?php esc_html_e('Expand all sections', 'convermetry'); ?></button>
        <button type="button" class="button cvmtry-panels-collapse"><?php esc_html_e('Collapse all sections', 'convermetry'); ?></button></div>
        <?php

        self::panelStart('content', __('Content', 'convermetry'), __('Which pages draw traffic and where visitors arrive.', 'convermetry'), true);
        ?>
        <div class="cvmtry-tables">
        <?php
        self::renderTopPages($start, $end);
        self::renderLandingPages($start, $end);
        ?>
        </div>
        <?php
        self::panelEnd();

        self::panelStart('engagement', __('Engagement', 'convermetry'), __('How visitors interact with your pages: clicks, form activity, and attention.', 'convermetry'));
        ?>
        <div class="cvmtry-tables">
        <?php
        self::renderTopClicks($start, $end);
        self::renderTopForms($start, $end);
        self::renderTopHovers($start, $end);
        ?>
        </div>
        <?php
        self::panelEnd();

        self::panelStart('acquisition', __('Acquisition', 'convermetry'), __('Where traffic comes from: referrers, campaigns, and marketing channels.', 'convermetry'));
        ?>
        <div class="cvmtry-tables">
        <?php
        self::renderTopReferrers($start, $end);
        self::renderChannels($start, $end);
        self::renderCampaigns($start, $end);
        self::renderCampaignContent($start, $end);
        ?>
        </div>
        <?php
        self::panelEnd();

        self::panelStart('devices', __('Devices', 'convermetry'), __('Mobile versus desktop share of page views.', 'convermetry'));
        self::renderDevices($start, $end);
        self::panelEnd();

        self::panelStart('goals', __('Goals', 'convermetry'), __('Important visitor actions other than form submissions — phone taps, downloads, booking clicks, pricing-page visits.', 'convermetry'));
        self::renderGoals($start, $end);
        self::panelEnd();

        self::panelStart('outcomes', __('Lead outcomes', 'convermetry'), __('What the leads were actually worth. Counted by the date each lead arrived, with its status as it stands right now.', 'convermetry'));
        ?>
        <div class="cvmtry-tables">
        <?php
        self::renderLeadDimension($start, $end, 'channel', __('Leads by Channel', 'convermetry'));
        self::renderLeadDimension($start, $end, 'campaign', __('Leads by Campaign', 'convermetry'));
        self::renderLeadDimension($start, $end, 'landing_page', __('Landing Page Performance', 'convermetry'));
        self::renderLeadDimension($start, $end, 'form', __('Leads by Form', 'convermetry'));
        ?>
        </div>
        <?php
        self::renderTimeToLead($start, $end);
        self::panelEnd();

        self::panelStart('conversions', __('Conversions', 'convermetry'), __('Individual confirmed conversions with their campaign attribution — server-confirmed submissions carry their provider and form identity.', 'convermetry'));
        self::renderRecentConversions($start, $end);
        self::panelEnd();

        self::panelStart('recent', __('Recent Activity', 'convermetry'));
        self::renderRecentEvents();
        self::panelEnd();

        self::renderExtensionSections($start, $end);

        /**
         * Fires at the end of the analytics dashboard, after every core panel.
         *
         * Runs only after this screen's capability check has already passed —
         * see Capability::ANALYTICS_VIEW — so a callback does not need to
         * re-authorize, though it must apply its own check if it renders
         * anything a viewer of this page should not see.
         *
         * A callback ECHOES its own markup and MUST escape everything it prints.
         * Convermetry escapes none of it. Use the same structure the core panels
         * do — a <details class="cvmtry-panel"> with a <summary> and a
         * <div class="cvmtry-panel-body"> — to inherit the page's styling.
         *
         * For a reporting block that should also reach the analytics webhook
         * payload, register an AnalyticsSectionInterface through
         * convermetry_analytics_sections instead: those render here too, and
         * their data travels on the wire.
         *
         * @param string $start UTC window start (inclusive), 'Y-m-d H:i:s'.
         * @param string $end   UTC window end (exclusive), 'Y-m-d H:i:s'.
         */
        do_action('convermetry_analytics_admin_panels', $start, $end);

        ?>
        </div>
        <?php
    }

    /**
     * Renders a panel for each registered analytics section.
     *
     * With none registered the loop body never runs, so this page's HTML is
     * byte-for-byte what it was before third-party sections existed.
     *
     * @param string $start UTC window start (inclusive).
     * @param string $end   UTC window end (exclusive).
     * @return void
     */
    private static function renderExtensionSections(string $start, string $end): void
    {
        foreach (AnalyticsSectionRegistry::all() as $key => $section) {
            // The dashboard's own row limit, not the webhook's — the same split
            // the core reports already have between screen and wire.
            try {
                $summary = $section->summarize($start, $end, 10);
            } catch (\Throwable) {
                self::panelStart($key, $section->getLabel(), $section->getDescription());
                self::renderErrorNotice();
                self::panelEnd();
                continue;
            }

            self::panelStart($key, $section->getLabel(), $section->getDescription());

            // A section that throws while rendering must not take the rest of
            // the dashboard down with it — including the panels after this one.
            try {
                $section->render($summary);
            } catch (\Throwable) {
                self::renderErrorNotice();
            }

            self::panelEnd();
        }
    }

    /**
     * Warns when the site-wide ingestion rate limit was hit in the last 24
     * hours — events were dropped, so the numbers below may undercount.
     *
     * @return void
     */
    private static function maybeRenderRateLimitNotice(): void
    {
        $hitAt = (int) get_transient(TrackingController::RATE_LIMITED_FLAG);
        if ($hitAt <= 0) {
            return;
        }

        // cvmtry-rate-limit-notice keeps this warning visible in the printed
        // report too — it flags that the numbers below may undercount.
        ?>
        <div class="notice notice-warning cvmtry-rate-limit-notice"><p><?php
        echo wp_kses_post(sprintf(
            /* translators: 1: UTC date and time the limit was first hit, 2: the name of a PHP filter. */
            __('<strong>Convermetry:</strong> The site-wide event rate limit was reached in the last 24 hours (first at %1$s UTC), so some visitor events were not recorded. If this is legitimate traffic rather than a flood, raise the limits with the %2$s filter.', 'convermetry'),
            esc_html(gmdate('Y-m-d H:i', $hitAt)),
            '<code>convermetry_rate_limits</code>'
        ));
        ?></p></div>
        <?php
    }

    /**
     * Warns when the selected period is longer than the configured retention
     * window.
     *
     * @param int $days Nominal selected period in days (before clamping).
     * @return void
     */
    private static function maybeRenderRetentionNotice(int $days): void
    {
        $retention = Options::retentionDays();
        if ($days <= $retention) {
            return;
        }

        ?>
        <div class="notice notice-warning cvmtry-retention-notice cvmtry-rate-limit-notice"><p><?php
        echo wp_kses_post(sprintf(
            /* translators: 1: selected period in days, 2: retention window in days. */
            __('<strong>Convermetry:</strong> The selected %1$d-day period is longer than the configured data retention window (%2$d days), so events older than %2$d days have already been deleted and cannot appear below. Choose a shorter period, or raise <strong>Data retention</strong> in Settings.', 'convermetry'),
            $days,
            $retention
        ));
        ?></p></div>
        <?php
    }

    /**
     * The selected reporting period. A supplied period is accepted only with
     * this screen's filter nonce and only when it is one of {@see periods()};
     * see {@see ReportPeriod}.
     *
     * @return ReportPeriod
     */
    private static function currentPeriod(): ReportPeriod
    {
        return ReportPeriod::fromRequest(self::PERIOD_NONCE, self::periods());
    }

    /**
     * The selectable reporting periods, in days.
     *
     * @return int[] Sorted ascending, deduplicated, each within retention.
     */
    private static function periods(): array
    {
        /**
         * Filters the reporting periods offered on the dashboard.
         *
         * Each value is a number of DAYS. The defaults are 7, 30, and 90; append
         * to them for a longer view, or return a shorter list to simplify the
         * screen.
         *
         * The result is validated: non-integers and values below 1 are dropped,
         * everything is clamped to the site's retention period, duplicates are
         * removed, and the list is sorted. Clamping matters — offering "last 365
         * days" on a site that keeps 90 days of data would draw a chart that
         * looks like traffic collapsed nine months ago, when in fact the rows
         * were deleted. If nothing survives, the defaults are used.
         *
         * This changes which windows an administrator can SELECT. It does not
         * change the scheduled webhook reporting window, which is driven by the
         * delivery interval on the Webhooks screen.
         *
         * @param int[] $periods Selectable periods in days. Default [7, 30, 90].
         */
        $filtered = apply_filters('convermetry_analytics_periods', self::PERIODS);

        if ($filtered === self::PERIODS) {
            return self::PERIODS;
        }

        $retention = Options::retentionDays();
        $periods   = [];

        foreach (is_array($filtered) ? $filtered : [] as $days) {
            if (!is_int($days) && !(is_string($days) && ctype_digit($days))) {
                continue;
            }

            $days = min($retention, (int) $days);
            if ($days >= 1) {
                $periods[$days] = $days;
            }
        }

        if ($periods === []) {
            return self::PERIODS;
        }

        sort($periods);

        return $periods;
    }

    /**
     * Renders the 7/30/90-day period selector as a button group, in the page
     * header.
     *
     * @param int $active Currently selected period in days (button state).
     * @return void
     */
    private static function renderPeriodNav(int $active): void
    {
        ?>
        <nav class="cvmtry-period-group" aria-label="<?php esc_attr_e('Reporting period', 'convermetry'); ?>">
        <?php

        foreach (self::periods() as $days) {
            $url = add_query_arg(
                array_merge(['page' => self::MENU_SLUG], ReportPeriod::queryArgs(self::PERIOD_NONCE, $days)),
                self_admin_url('admin.php')
            );

            $isActive = $days === $active;

            // Same .button/.button-primary/.button-secondary classes the
            // Goals and Funnels period filters use (see admin-common.css's
            // design-system alignment section), rather than this page's own
            // now-retired .cvmtry-period-btn skin — one button look, shared.
            ?>
            <a class="button <?php echo ($isActive ? 'button-primary' : 'button-secondary'); ?>"<?php echo ($isActive ? ' aria-current="page"' : ''); ?> href="<?php echo esc_url($url); ?>"><?php
            echo esc_html(sprintf(
                /* translators: %d: number of days in the reporting period. */
                _n('Last %d day', 'Last %d days', $days, 'convermetry'),
                $days
            ));
            ?></a>
            <?php
        }

        ?>
        </nav>
        <?php
    }

    /**
     * Spells out the covered UTC date range under the page header, with the
     * print-only report header beside it.
     *
     * @param int $active        Currently selected period in days.
     * @param int $effectiveDays Retention-clamped period actually queried/displayed.
     * @return void
     */
    private static function renderPeriodRange(int $active, int $effectiveDays): void
    {
        $startLabel = self::utcDate(__('M j, Y', 'convermetry'), time() - ($effectiveDays - 1) * DAY_IN_SECONDS);
        $endLabel   = self::utcDate(__('M j, Y', 'convermetry'), time());

        ?>
        <div class="cvmtry-period">
        <p class="cvmtry-period-range"><?php
        echo esc_html(sprintf(
            /* translators: 1: first date of the period, 2: last date of the period. */
            __('%1$s – %2$s. Dates are UTC; the current day is still collecting data.', 'convermetry'),
            $startLabel,
            $endLabel
        ));
        ?></p>
        <?php

        // Print-only report header (admin-analytics.css shows it in @media print).
        ?>
        <p class="cvmtry-print-meta"><?php echo esc_html(self::reportHeading($active, $effectiveDays)); ?></p></div>
        <?php
    }

    /**
     * The one-line description of a report — site, range, period, and when
     * it was generated. Heads the printed report and the CSV export alike.
     *
     * @param int $active        Selected period in days.
     * @param int $effectiveDays Retention-clamped period actually queried.
     * @return string Plain text; escape for its context.
     */
    private static function reportHeading(int $active, int $effectiveDays): string
    {
        $startLabel      = self::utcDate(__('M j, Y', 'convermetry'), time() - ($effectiveDays - 1) * DAY_IN_SECONDS);
        $endLabel        = self::utcDate(__('M j, Y', 'convermetry'), time());
        $generatedFormat = trim(get_option('date_format', 'F j, Y') . ' ' . get_option('time_format', 'g:i a'));
        $rangeNote       = $active === $effectiveDays
            /* translators: %d: number of days in the reporting period. */
            ? sprintf(_n('last %d day', 'last %d days', $active, 'convermetry'), $active)
            /* translators: 1: selected number of days, 2: number of days actually shown after the retention limit. */
            : sprintf(__('last %1$d days selected; %2$d days shown per data retention', 'convermetry'), $active, $effectiveDays);

        return sprintf(
            /* translators: 1: site name, 2: first date, 3: last date, 4: period description, 5: generation date and time, 6: site timezone. */
            __('%1$s — Convermetry analytics report · %2$s – %3$s (UTC, %4$s; the final day was still collecting when generated) · Generated %5$s (%6$s)', 'convermetry'),
            get_bloginfo('name'),
            $startLabel,
            $endLabel,
            $rangeNote,
            get_date_from_gmt(gmdate('Y-m-d H:i:s'), $generatedFormat),
            self::siteTimezoneLabel()
        );
    }

    /**
     * Renders the totals-per-event-type summary cards, with short
     * explanations under metrics whose meaning isn't self-evident.
     *
     * The three form metrics are deliberately distinct:
     *  - Form Submit Attempts     — frontend submit events, success unconfirmed.
     *  - Confirmed Conversions    — deduplicated conversions from BOTH detection
     *    paths (frontend success events and server hooks share conversion ids).
     *  - Server-Confirmed Submissions — submissions a form plugin's own
     *    server-side success hook confirmed (the authoritative count).
     *
     * They are laid out as two groups: visitor activity first, then the three
     * form metrics side by side on their own band — so the three are read
     * against each other rather than mistaken for one another — closed by
     * "Other Events", which must stay last because it describes itself as
     * everything "not shown above".
     *
     * @param array<string, int> $totals      Map of event_type → count for the period.
     * @param int                $serverCount Server-confirmed submissions in the period.
     * @return void
     */
    private static function renderSummaryCards(array $totals, int $serverCount): void
    {
        $activity = [
            'pageview'     => [__('Page Views', 'convermetry'), ''],
            'click'        => [__('Clicks', 'convermetry'), ''],
            'hover'        => [__('Hovers', 'convermetry'), ''],
            'scroll_depth' => [__('Scroll Milestones', 'convermetry'), __('Times visitors reached 50% or 100% of a page.', 'convermetry')],
        ];
        $forms = [
            'form_submit'  => [__('Form Submit Attempts', 'convermetry'), __('Counted when a form is submitted, before the server confirms success.', 'convermetry')],
            'form_success' => [__('Confirmed Conversions', 'convermetry'), __('Unique conversions, deduplicated across frontend and server detection.', 'convermetry')],
        ];

        // Every other event type — form views, starts and validation errors,
        // custom events, and anything recorded via cvmtry_track_event() — is
        // summed into a single "Other Events" card so nothing is invisible.
        $other = array_sum(array_diff_key($totals, $activity, $forms));

        ?>
        <div class="cvmtry-kpis">
        <div class="cvmtry-cards cvmtry-kpi-group">
        <?php
        foreach ($activity as $type => [$label, $desc]) {
            self::renderStatCard(number_format_i18n($totals[$type] ?? 0), $label, $desc);
        }
        ?>
        </div>
        <div class="cvmtry-cards cvmtry-kpi-group cvmtry-kpi-group--forms">
        <?php
        foreach ($forms as $type => [$label, $desc]) {
            self::renderStatCard(number_format_i18n($totals[$type] ?? 0), $label, $desc);
        }

        self::renderStatCard(
            number_format_i18n($serverCount),
            __('Server-Confirmed Submissions', 'convermetry'),
            __('Submissions a form plugin\'s server-side hook confirmed — the authoritative lead count.', 'convermetry')
        );

        if ($other > 0) {
            self::renderStatCard(number_format_i18n($other), __('Other Events', 'convermetry'), __('Event types not shown above, such as form views, form starts, validation errors and custom events.', 'convermetry'));
        }
        ?>
        </div></div>
        <?php
    }

    /**
     * The compact device breakdown beside the page-view chart: a doughnut of
     * page-view share with a legend naming every part, its share and its count.
     *
     * Drawn from the same result the Devices panel's table prints, with the
     * same rounding, and linked to it.
     *
     * @param string $start UTC window start (inclusive).
     * @param string $end   UTC window end (exclusive).
     * @return void
     */
    private static function renderDeviceSummary(string $start, string $end): void
    {
        $headingId = 'cvmtry-h-device-summary';

        ?>
        <section class="cvmtry-chart-frame cvmtry-device-summary" aria-labelledby="<?php echo esc_attr($headingId); ?>">
        <h3 id="<?php echo esc_attr($headingId); ?>"><?php esc_html_e('Devices', 'convermetry'); ?></h3>
        <p class="cvmtry-chart-summary"><?php esc_html_e('Mobile versus desktop share of page views.', 'convermetry'); ?></p>
        <?php

        try {
            $devices = self::deviceData($start, $end);
        } catch (ReportQueryException) {
            $devices = null;
            self::renderErrorNotice();
        }

        if ($devices === []) {
            ?>
            <p class="cvmtry-highlight-empty"><?php esc_html_e('No page views recorded in this period.', 'convermetry'); ?></p>
            <?php
        } elseif ($devices !== null) {
            $total = array_sum($devices);
            $parts = [];

            foreach ($devices as $device => $views) {
                $parts[] = [
                    'label'  => ucfirst($device),
                    'share'  => $total > 0 ? round($views / $total * 100) . '%' : '',
                    'detail' => sprintf(
                        /* translators: %s: number of page views, already formatted. */
                        _n('%s page view', '%s page views', $views, 'convermetry'),
                        number_format_i18n($views)
                    ),
                    'value'  => $views,
                    // Colour follows the device, not its rank this period.
                    'slot'   => match ($device) {
                        'desktop' => 1,
                        'mobile'  => 2,
                        'tablet'  => 3,
                        default   => 4,
                    },
                ];
            }

            Charts::doughnut($headingId, $parts);
        }

        self::panelLink('devices', $headingId);
        ?>
        </section>
        <?php
    }

    /**
     * The grid of summary charts at the foot of the Overview, one per report
     * the panels below print in full.
     *
     * @param string $start UTC window start (inclusive).
     * @param string $end   UTC window end (exclusive).
     * @return void
     */
    private static function renderHighlights(string $start, string $end): void
    {
        ?>
        <div class="cvmtry-highlights">
        <?php
        self::highlight(
            'pages',
            __('Top Pages', 'convermetry'),
            'content',
            [__('Views', 'convermetry')],
            static fn(): array => array_map(static fn(array $row): array => [
                'label'  => $row['page_title'] !== '' ? $row['page_title'] : self::urlDisplayText($row['page_url']),
                'values' => [(int) $row['views']],
            ], self::topPagesData($start, $end)),
            __('No page views recorded in this period.', 'convermetry')
        );

        self::highlight(
            'clicks',
            __('Top Clicked Elements', 'convermetry'),
            'engagement',
            [__('Clicks', 'convermetry')],
            static fn(): array => array_map(static fn(array $row): array => [
                'label'  => self::clickLabel($row),
                'values' => [(int) $row['clicks']],
            ], self::topClicksData($start, $end)),
            __('No clicks recorded in this period.', 'convermetry')
        );

        self::highlight(
            'channels',
            __('Channels', 'convermetry'),
            'acquisition',
            [__('Sessions', 'convermetry')],
            static fn(): array => array_map(static fn(array $row): array => [
                'label'  => $row['channel'] !== '' ? $row['channel'] : '—',
                'values' => [(int) $row['sessions']],
            ], self::channelData($start, $end)),
            __('No channel data recorded in this period. Channels are classified as new events arrive, so this fills in from the moment of installation onward.', 'convermetry')
        );

        self::highlight(
            'goals',
            __('Goal Completions', 'convermetry'),
            'goals',
            [__('Completions', 'convermetry')],
            static fn(): array => array_map(static fn(array $goal): array => [
                'label'  => (string) $goal['name'],
                'values' => [(int) $goal['completions']],
            ], self::goalData($start, $end)['goals']),
            __('No goal completions in this period. Goals are configured under Convermetry → Goals.', 'convermetry')
        );

        // Grouped, never stacked: a won lead is also a qualified lead, and both
        // are leads, so these three counts overlap rather than add up.
        self::highlight(
            'leads',
            __('Leads by Channel', 'convermetry'),
            'outcomes',
            [__('Leads', 'convermetry'), __('Qualified', 'convermetry'), __('Won', 'convermetry')],
            static fn(): array => array_map(static fn(array $row): array => [
                'label'  => (string) $row['label'] !== '' ? (string) $row['label'] : '—',
                'values' => [(int) $row['leads'], (int) $row['qualified'], (int) $row['won']],
            ], self::leadData($start, $end, 'channel')),
            __('No leads recorded in this period.', 'convermetry'),
            true
        );
        ?>
        </div>
        <?php
    }

    /**
     * One Overview summary card: a chart of a report's first rows, and a link
     * to the panel that prints the whole report.
     *
     * @param string                                                  $key    Slug for the card's element ids.
     * @param string                                                  $title  The report's own title.
     * @param string                                                  $panel  Slug of the panel holding the full table.
     * @param list<string>                                            $series Series names (the table's own column labels).
     * @param callable(): array<int, array{label: string, values: array<int, int>}> $rows Chart rows, built from the cached report result.
     * @param string                                                  $empty  The report's own empty-state message.
     * @param bool                                                    $wide   Span two grid columns (grouped bars).
     * @return void
     */
    private static function highlight(string $key, string $title, string $panel, array $series, callable $rows, string $empty, bool $wide = false): void
    {
        $headingId = 'cvmtry-hl-' . $key;

        ?>
        <section class="cvmtry-highlight<?php echo $wide ? ' cvmtry-highlight--wide' : ''; ?>" aria-labelledby="<?php echo esc_attr($headingId); ?>">
        <div class="cvmtry-highlight-head">
        <h3 id="<?php echo esc_attr($headingId); ?>"><?php echo esc_html($title); ?></h3>
        <?php
        if (count($series) === 1) {
            ?>
            <span class="cvmtry-highlight-metric"><?php echo esc_html($series[0]); ?></span>
            <?php
        }
        ?>
        </div>
        <?php

        try {
            $data = $rows();
        } catch (ReportQueryException) {
            $data = null;
            self::renderErrorNotice();
        }

        if ($data === []) {
            ?>
            <p class="cvmtry-highlight-empty"><?php echo esc_html($empty); ?></p>
            <?php
        } elseif ($data !== null) {
            Charts::bars($headingId, $series, array_slice($data, 0, self::HIGHLIGHT_ROWS));
        }

        self::panelLink($panel, $headingId);
        ?>
        </section>
        <?php
    }

    /**
     * A "View data table" link to one report panel. dashboard.js opens the
     * panel as the link is followed; without it the anchor still scrolls there.
     *
     * @param string $panel     Panel slug.
     * @param string $headingId Id of the card heading, so the link is announced with it.
     * @return void
     */
    private static function panelLink(string $panel, string $headingId): void
    {
        ?>
        <p class="cvmtry-highlight-link"><a href="#cvmtry-panel-<?php echo esc_attr($panel); ?>" aria-describedby="<?php echo esc_attr($headingId); ?>"><?php esc_html_e('View data table', 'convermetry'); ?></a></p>
        <?php
    }

    /**
     * Runs a report query once per page render and returns its rows.
     *
     * A summary chart and the table it links to read the same key, so the
     * second reader gets the first one's result — or the same failure, rethrown
     * for its own error notice — instead of a second query.
     *
     * @template T
     * @param string      $key   Identifies the query and its arguments.
     * @param callable(): T $query Runs the query.
     * @return T
     * @throws ReportQueryException When the query failed.
     */
    private static function result(string $key, callable $query): mixed
    {
        if (!array_key_exists($key, self::$results)) {
            try {
                self::$results[$key] = $query();
            } catch (ReportQueryException $e) {
                self::$results[$key] = $e;
            }
        }

        if (self::$results[$key] instanceof ReportQueryException) {
            throw self::$results[$key];
        }

        return self::$results[$key];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function topPagesData(string $start, string $end): array
    {
        return self::result('top_pages', static fn(): array => Reports::topPages($start, $end));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function topClicksData(string $start, string $end): array
    {
        return self::result('top_clicks', static fn(): array => Reports::topClicks($start, $end));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function channelData(string $start, string $end): array
    {
        return self::result('channels', static fn(): array => Reports::channelBreakdown($start, $end));
    }

    /**
     * @return array<string, int>
     */
    private static function deviceData(string $start, string $end): array
    {
        return self::result('devices', static fn(): array => Reports::deviceBreakdown($start, $end));
    }

    /**
     * @return array{goals: list<array<string, mixed>>, sessions: int, truncated: bool, total: int}
     */
    private static function goalData(string $start, string $end): array
    {
        return self::result('goals', static fn(): array => GoalReports::summary($start, $end, GoalRepository::names(), 25));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function leadData(string $start, string $end, string $dimension): array
    {
        return self::result('leads_' . $dimension, static fn(): array => LeadReports::byDimension($start, $end, $dimension, 10));
    }

    /**
     * The display label of one clicked element.
     *
     * @param array<string, mixed> $row A Reports::topClicks() row.
     * @return string
     */
    private static function clickLabel(array $row): string
    {
        return (string) $row['element_label'] !== '' ? (string) $row['element_label'] : sprintf(
            /* translators: %s: HTML tag name of the clicked element, such as "a" or "button". */
            __('(unlabeled %s)', 'convermetry'),
            (string) $row['element_tag']
        );
    }

    /**
     * Renders a single summary card.
     *
     * @param string $value Formatted metric value.
     * @param string $label Metric name.
     * @param string $desc  Optional one-line explanation.
     * @return void
     */
    private static function renderStatCard(string $value, string $label, string $desc): void
    {
        ?>
        <div class="cvmtry-card cvmtry-stat-card">
        <span class="cvmtry-card-value"><?php echo esc_html($value); ?></span>
        <span class="cvmtry-card-label"><?php echo esc_html($label); ?></span>
        <?php
        if ($desc !== '') {
            ?>
            <span class="cvmtry-card-desc"><?php echo esc_html($desc); ?></span>
            <?php
        }
        ?>
        </div>
        <?php
    }

    /**
     * Renders the daily page-view chart.
     *
     * Each day is a <button> whose accessible label carries the date and
     * exact count; the visible bar is a child span sized via a CSS custom
     * property, so a zero-view day renders as truly zero. The current
     * (incomplete) day is patterned, a Y-axis scale and spaced X-axis date
     * labels frame the plot, and a native <details> data table provides
     * every value without JavaScript.
     *
     * @param array<int, array{date: string, count: int}> $daily Zero-filled daily series.
     * @param int                                         $days  Period length, for layout density.
     * @return void
     */
    private static function renderPageviewChart(array $daily, int $days): void
    {
        $counts = array_column($daily, 'count');
        $count  = count($daily);
        $total  = array_sum($counts);
        $max    = $counts === [] ? 0 : max($counts);
        $scale  = self::niceScaleMax($max);
        $today  = gmdate('Y-m-d');

        $busiestDate  = '';
        $busiestCount = 0;
        foreach ($daily as $point) {
            if ($point['count'] > $busiestCount) {
                $busiestCount = $point['count'];
                $busiestDate  = $point['date'];
            }
        }

        // The average uses completed days only: today is still collecting,
        // so including it would drag the number down all day long.
        $completedTotal = 0;
        $completedCount = 0;
        foreach ($daily as $point) {
            if ($point['date'] < $today) {
                $completedTotal += $point['count'];
                $completedCount++;
            }
        }

        if ($completedCount > 0) {
            $avg      = $completedTotal / $completedCount;
            $avgLabel = $avg >= 10
                ? number_format_i18n(round($avg))
                : number_format_i18n(round($avg, 1), 1);
        } else {
            $avgLabel = __('— (no completed days yet)', 'convermetry');
        }

        // X-axis label density: every day at 7, every 5th at 30, every 15th at 90.
        $step = match (true) {
            $days <= 7  => 1,
            $days <= 30 => 5,
            default     => 15,
        };

        ?>
        <div class="cvmtry-chart-frame">
        <h3><?php esc_html_e('Daily Page Views', 'convermetry'); ?></h3>
        <p class="cvmtry-chart-summary">
        <span><?php
        /* translators: %s: total page views in the period. */
        echo wp_kses_post(sprintf(__('Total: <strong>%s</strong>', 'convermetry'), esc_html(number_format_i18n($total))));
        ?></span>
        <span><?php
        /* translators: %s: average page views per completed day. */
        echo wp_kses_post(sprintf(__('Avg per completed day: <strong>%s</strong>', 'convermetry'), esc_html($avgLabel)));
        ?></span>
        <?php
        if ($busiestDate !== '') {
            ?>
            <span><?php
            echo wp_kses_post(sprintf(
                /* translators: 1: date of the busiest day, 2: page views on that day. */
                __('Busiest day: <strong>%1$s (%2$s)</strong>', 'convermetry'),
                esc_html(self::utcDate(__('M j', 'convermetry'), (int) strtotime($busiestDate . ' UTC'))),
                esc_html(number_format_i18n($busiestCount))
            ));
            ?></span>
            <?php
        }
        ?>
        <span class="cvmtry-chart-key"><span class="cvmtry-chart-key-swatch" aria-hidden="true"></span><?php esc_html_e('Today (still collecting)', 'convermetry'); ?></span></p>
        <?php

        // A density bucket, not a class per exact day count: retention can
        // clamp the effective period to any value, so the scroll/min-width
        // treatment is keyed to "does this many bars need it".
        $isWide      = $days > 10;
        $layoutClass = 'cvmtry-chart-layout' . ($isWide ? ' cvmtry-chart-layout--wide' : '');
        $minWidth    = $isWide ? max(640, $days * 16) : 0;

        ?>
        <div class="cvmtry-chart-scroll">
        <div class="<?php echo esc_attr($layoutClass); ?>"<?php echo ($minWidth > 0 ? ' style="--cvmtry-chart-min-width:' . esc_attr((string) $minWidth) . 'px"' : ''); ?>>
        <div class="cvmtry-chart-yaxis" aria-hidden="true">
        <span><?php echo esc_html(number_format_i18n($scale)); ?></span>
        <span><?php echo esc_html(number_format_i18n((int) ($scale / 2))); ?></span>
        <span>0</span></div>
        <div class="cvmtry-chart-main">
        <div class="cvmtry-chart-plot">
        <div class="cvmtry-chart-cols" role="group" aria-label="<?php esc_attr_e('Daily page views: one button per day, oldest first', 'convermetry'); ?>">
        <?php

        foreach ($daily as $point) {
            $dateLabel = self::utcDate(__('M j, Y', 'convermetry'), (int) strtotime($point['date'] . ' UTC'));
            $isToday   = $point['date'] === $today;
            $height    = round($point['count'] / $scale * 100, 2);
            $aria      = $isToday
                ? sprintf(
                    /* translators: 1: date, 2: number of page views. */
                    _n('%1$s: %2$s page view (today, still collecting)', '%1$s: %2$s page views (today, still collecting)', $point['count'], 'convermetry'),
                    $dateLabel,
                    number_format_i18n($point['count'])
                )
                : sprintf(
                    /* translators: 1: date, 2: number of page views. */
                    _n('%1$s: %2$s page view', '%1$s: %2$s page views', $point['count'], 'convermetry'),
                    $dateLabel,
                    number_format_i18n($point['count'])
                );

            ?>
            <button type="button" class="cvmtry-chart-col<?php echo ($isToday ? ' is-today' : ''); ?>" data-date="<?php echo esc_attr($dateLabel); ?>" data-count="<?php echo esc_attr(number_format_i18n($point['count'])); ?>" aria-label="<?php echo esc_attr($aria); ?>"><span class="cvmtry-chart-bar" style="--cvmtry-h:<?php echo esc_attr((string) $height); ?>%"></span></button>
            <?php
        }

        ?>
        </div>
        <?php // .cvmtry-chart-cols
        ?>
        </div>
        <?php // .cvmtry-chart-plot

        ?>
        <div class="cvmtry-chart-xaxis" aria-hidden="true">
        <?php
        foreach ($daily as $i => $point) {
            $isLast = $i === $count - 1;
            // Step labels stop short of the final label so the two never collide.
            $onStep = $i % $step === 0 && ($count - 1 - $i) >= $step / 2;
            if (!$isLast && !$onStep) {
                continue;
            }
            $x = round((($i + 0.5) / max(1, $count)) * 100, 2);
            ?>
            <span class="cvmtry-chart-xlabel" style="--cvmtry-x:<?php echo esc_attr((string) $x); ?>%"><?php echo esc_html(self::utcDate(__('M j', 'convermetry'), (int) strtotime($point['date'] . ' UTC'))); ?></span>
            <?php
        }
        ?>
        </div>
        <?php // .cvmtry-chart-xaxis

        ?>
        </div></div></div>
        <?php // .cvmtry-chart-main, .cvmtry-chart-layout, .cvmtry-chart-scroll

        ?>
        <details class="cvmtry-chart-data">
        <summary><?php esc_html_e('View data table', 'convermetry'); ?></summary>
        <div class="cvmtry-table-scroll">
        <table class="wp-list-table widefat striped cvmtry-chart-data-table">
        <caption class="screen-reader-text"><?php esc_html_e('Daily page views for the selected period', 'convermetry'); ?></caption>
        <thead><tr><th scope="col"><?php esc_html_e('Date', 'convermetry'); ?></th><th scope="col" class="cvmtry-num"><?php esc_html_e('Page Views', 'convermetry'); ?></th></tr></thead><tbody>
        <?php

        foreach ($daily as $point) {
            $isToday = $point['date'] === $today;
            ?>
            <tr><td><?php echo esc_html(self::utcDate(__('M j, Y', 'convermetry'), (int) strtotime($point['date'] . ' UTC'))); ?><?php echo ($isToday ? ' <em>' . esc_html__('(today, partial)', 'convermetry') . '</em>' : ''); ?></td><td class="cvmtry-num"><?php echo esc_html(number_format_i18n($point['count'])); ?></td></tr>
            <?php
        }

        ?>
        </tbody></table></div></details></div>
        <?php // .cvmtry-chart-frame
    }

    /**
     * Rounds a series maximum up to a "nice" chart ceiling whose half is also
     * a round number, so the Y-axis ticks (max, half, 0) read cleanly.
     *
     * @param int $max Largest value in the series.
     * @return int Always >= 2 and >= $max.
     */
    private static function niceScaleMax(int $max): int
    {
        if ($max <= 2) {
            return 2;
        }

        for ($pow = 1; $pow <= 1000000000; $pow *= 10) {
            foreach ([2, 4, 10] as $base) {
                if ($base * $pow >= $max) {
                    return $base * $pow;
                }
            }
        }

        return $max;
    }

    /**
     * Opens a collapsible dashboard section (a native <details> panel).
     * Must be paired with {@see panelEnd()}.
     *
     * @param string $id    Slug used for the element id.
     * @param string $title Section heading.
     * @param string $desc  Optional one-line description under the heading.
     * @param bool   $open  Whether the panel starts expanded.
     * @return void
     */
    private static function panelStart(string $id, string $title, string $desc = '', bool $open = false): void
    {
        ?>
        <details class="cvmtry-panel" id="cvmtry-panel-<?php echo esc_attr($id); ?>"<?php echo ($open ? ' open' : ''); ?>>
        <summary class="cvmtry-panel-summary">
        <h2><?php echo esc_html($title); ?></h2>
        <span class="cvmtry-panel-arrow" aria-hidden="true">&#9660;</span></summary>
        <div class="cvmtry-panel-body">
        <?php
        if ($desc !== '') {
            ?>
            <p class="cvmtry-panel-desc"><?php echo esc_html($desc); ?></p>
            <?php
        }
    }

    /**
     * Closes a panel opened with {@see panelStart()}.
     *
     * @return void
     */
    private static function panelEnd(): void
    {
        ?>
        </div></details>
        <?php
    }

    /**
     * Renders one report table: heading, optional description, and the table
     * inside a horizontal-scroll container so wide data can never break the
     * page layout.
     *
     * @param string                                        $title   Table heading (h3).
     * @param string                                        $desc    Optional description under the heading.
     * @param array<int, array{label: string, num?: bool}>  $columns Column definitions; num columns are right-aligned.
     * @param array<int, array<int, string>>                $rows    Rows of pre-escaped cell HTML (from the cell*() helpers).
     * @param string                                        $empty   Empty-state message.
     * @param bool                                          $wide    Span the full grid width (for many-column tables).
     * @return void
     */
    private static function renderReportTable(string $title, string $desc, array $columns, array $rows, string $empty, bool $wide = false): void
    {
        ?>
        <div class="cvmtry-report<?php echo ($wide ? ' cvmtry-report--wide' : ''); ?>">
        <h3><?php echo esc_html($title); ?></h3>
        <?php
        if ($desc !== '') {
            ?>
            <p class="cvmtry-report-desc"><?php echo esc_html($desc); ?></p>
            <?php
        }

        ?>
        <div class="cvmtry-table-scroll">
        <table class="wp-list-table widefat striped">
        <?php
        // Named tables let screen-reader users tell "Top Pages" from "Top
        // Referrers" when jumping directly between tables.
        ?>
        <caption class="screen-reader-text"><?php echo esc_html($title); ?></caption>
        <thead><tr>
        <?php
        foreach ($columns as $col) {
            ?>
            <th scope="col"<?php echo (!empty($col['num']) ? ' class="cvmtry-num"' : ''); ?>><?php echo esc_html($col['label']); ?></th>
            <?php
        }
        ?>
        </tr></thead><tbody>
        <?php

        if ($rows === []) {
            ?>
            <tr><td colspan="<?php echo count($columns); ?>"><?php echo esc_html($empty); ?></td></tr>
            <?php
        }

        foreach ($rows as $cells) {
            ?>
            <tr>
            <?php
            foreach (array_values($cells) as $i => $cell) {
                ?>
                <td<?php echo (!empty($columns[$i]['num']) ? ' class="cvmtry-num"' : ''); ?>><?php
                // Cells arrive as HTML built by the cell*() helpers, which
                // escape at the leaf; kses here keeps that promise checkable.
                echo wp_kses_post($cell);
                ?></td>
                <?php
            }
            ?>
            </tr>
            <?php
        }

        ?>
        </tbody></table></div></div>
        <?php
    }

    /**
     * Runs a report section's query-and-render callback, showing an inline
     * error notice in its place — instead of a table silently full of
     * zeros — when the underlying query fails.
     *
     * @param string   $title  Report heading, reused for the error notice's own heading.
     * @param callable $render Runs the normal query + {@see renderReportTable()} call.
     * @return void
     */
    private static function renderQueriedTable(string $title, callable $render): void
    {
        try {
            $render();
        } catch (ReportQueryException) {
            ?>
            <div class="cvmtry-report">
            <h3><?php echo esc_html($title); ?></h3>
            <?php
            self::renderErrorNotice();
            ?>
            </div>
            <?php
        }
    }

    /**
     * The inline notice shown in place of a report section whose query
     * failed. Deliberately does not include the raw database error text.
     *
     * @return void
     */
    private static function renderErrorNotice(): void
    {
        ?>
        <div class="notice notice-error inline cvmtry-report-error"><p><?php esc_html_e('This section could not be loaded due to a database error. Your data is safe — try refreshing shortly, or check your site\'s PHP error log if this continues.', 'convermetry'); ?></p></div>
        <?php
    }

    /**
     * Escapes a plain-text cell, rendering an em dash for empty values.
     *
     * @param string $value Raw cell text.
     * @return string Safe HTML.
     */
    private static function cellText(string $value): string
    {
        return $value !== '' ? esc_html($value) : '&mdash;';
    }

    /**
     * Formats an integer cell with locale thousands separators.
     *
     * @param int $value Raw count.
     * @return string Safe HTML.
     */
    private static function cellNum(int $value): string
    {
        return esc_html(number_format_i18n($value));
    }

    /**
     * Renders a URL cell: a link (new tab) when the value is a usable web
     * URL, plain text otherwise.
     *
     * @param string $url   Raw URL value.
     * @param string $label Optional display label; defaults to a readable host/path.
     * @return string Safe HTML.
     */
    private static function cellLink(string $url, string $label = ''): string
    {
        if ($url === '') {
            return '&mdash;';
        }

        if (!self::isLinkableUrl($url)) {
            return esc_html($label !== '' ? $label : $url);
        }

        $text = $label !== '' ? $label : self::urlDisplayText($url);
        $sr   = ' ' . (self::isExternalUrl($url)
            ? __('(external link, opens in a new tab)', 'convermetry')
            : __('(opens in a new tab)', 'convermetry'));

        return '<a href="' . esc_url($url) . '" target="_blank" rel="noopener">' . esc_html($text)
            . '<span class="cvmtry-newtab" aria-hidden="true">&#8599;</span>'
            . '<span class="screen-reader-text">' . esc_html($sr) . '</span></a>';
    }

    /**
     * Renders a page cell: the title as the link text with the readable URL
     * beneath it, or just the readable URL when no title was captured.
     *
     * @param string $url   Page URL.
     * @param string $title Page title (possibly empty).
     * @return string Safe HTML.
     */
    private static function cellPage(string $url, string $title): string
    {
        $html = self::cellLink($url, $title);

        if ($title !== '' && self::isLinkableUrl($url)) {
            $html .= '<span class="cvmtry-url-sub">' . esc_html(self::urlDisplayText($url)) . '</span>';
        }

        return $html;
    }

    /**
     * Whether a value is a usable http(s) URL and therefore safe to link.
     *
     * Display validation, not request validation — deliberately NOT
     * wp_http_validate_url(), which rejects private-network hosts and would
     * strip legitimate links on local, staging, and intranet installs.
     *
     * @param string $url Raw value.
     * @return bool
     */
    private static function isLinkableUrl(string $url): bool
    {
        if (!preg_match('#^https?://#i', $url)) {
            return false;
        }

        $parts = wp_parse_url($url);
        if (!is_array($parts) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        $host = strtolower((string) $parts['host']);

        if (in_array($host, array_map('strtolower', Options::allowedHosts()), true)) {
            return true;
        }

        // Hostname, IPv4, or bracketed IPv6 characters only.
        return (bool) preg_match('/^[a-z0-9.\-\[\]:]+$/', $host);
    }

    /**
     * Whether a URL points off this site (its host is not an allowed host).
     *
     * @param string $url Web URL.
     * @return bool
     */
    private static function isExternalUrl(string $url): bool
    {
        $host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
        if ($host === '') {
            return false;
        }

        return !in_array($host, array_map('strtolower', Options::allowedHosts()), true);
    }

    /**
     * A compact, readable form of a URL for display: host plus path.
     *
     * @param string $url Web URL.
     * @return string
     */
    private static function urlDisplayText(string $url): string
    {
        $parts = wp_parse_url($url);
        if (!is_array($parts) || empty($parts['host'])) {
            return $url;
        }

        $path = (string) ($parts['path'] ?? '');

        return $parts['host'] . ($path === '/' ? '' : $path);
    }

    /**
     * Formats a Unix timestamp for display as a localized date pinned to
     * UTC — report boundaries are UTC calendar days.
     *
     * @param string $format    PHP date format.
     * @param int    $timestamp Unix timestamp.
     * @return string
     */
    private static function utcDate(string $format, int $timestamp): string
    {
        return (string) wp_date($format, $timestamp, new \DateTimeZone('UTC'));
    }

    /**
     * A display label for the site timezone.
     *
     * @return string
     */
    private static function siteTimezoneLabel(): string
    {
        $tz = wp_timezone_string();

        return ($tz !== '' && ($tz[0] === '+' || $tz[0] === '-')) ? 'UTC' . $tz : $tz;
    }

    /**
     * Human-readable label for a raw event type key.
     *
     * @param string $type Raw event_type value.
     * @return string
     */
    private static function eventLabel(string $type): string
    {
        return match ($type) {
            'pageview'     => __('Page View', 'convermetry'),
            'click'        => __('Click', 'convermetry'),
            'form_submit'  => __('Form Submit Attempt', 'convermetry'),
            'form_success' => __('Confirmed Conversion', 'convermetry'),
            'hover'        => __('Hover', 'convermetry'),
            'scroll_depth' => __('Scroll Milestone', 'convermetry'),
            default        => ucwords(str_replace(['_', '-'], ' ', $type)),
        };
    }

    /**
     * Renders the "Top Pages" table.
     */
    private static function renderTopPages(string $start, string $end): void
    {
        self::renderQueriedTable(__('Top Pages', 'convermetry'), static function () use ($start, $end): void {
            $rows = self::topPagesData($start, $end);

            self::renderReportTable(
                __('Top Pages', 'convermetry'),
                __('The most-viewed pages (up to 10 shown). Sessions group one visit within a 30-minute inactivity window.', 'convermetry'),
                [
                    ['label' => __('Page', 'convermetry')],
                    ['label' => __('Views', 'convermetry'), 'num' => true],
                    ['label' => __('Sessions', 'convermetry'), 'num' => true],
                ],
                array_map(static fn(array $row): array => [
                    self::cellPage($row['page_url'], $row['page_title']),
                    self::cellNum($row['views']),
                    self::cellNum($row['sessions']),
                ], $rows),
                __('No page views recorded in this period.', 'convermetry')
            );
        });
    }

    /**
     * Renders the "Top Landing Pages" table.
     */
    private static function renderLandingPages(string $start, string $end): void
    {
        self::renderQueriedTable(__('Top Landing Pages', 'convermetry'), static function () use ($start, $end): void {
            $rows = Reports::topLandingPages($start, $end);

            self::renderReportTable(
                __('Top Landing Pages', 'convermetry'),
                __('The first page of each session that started in this period — where visitors actually arrive. Up to 10 shown.', 'convermetry'),
                [
                    ['label' => __('Landing Page', 'convermetry')],
                    ['label' => __('Sessions', 'convermetry'), 'num' => true],
                ],
                array_map(static fn(array $row): array => [
                    self::cellPage($row['page_url'], $row['page_title']),
                    self::cellNum($row['sessions']),
                ], $rows),
                __('No sessions recorded in this period.', 'convermetry')
            );
        });
    }

    /**
     * Renders the "Top Clicked Elements" table.
     */
    private static function renderTopClicks(string $start, string $end): void
    {
        self::renderQueriedTable(__('Top Clicked Elements', 'convermetry'), static function () use ($start, $end): void {
            $rows = self::topClicksData($start, $end);

            self::renderReportTable(
                __('Top Clicked Elements', 'convermetry'),
                __('The links and buttons visitors click most (up to 10 shown).', 'convermetry'),
                [
                    ['label' => __('Element', 'convermetry')],
                    ['label' => __('Destination', 'convermetry')],
                    ['label' => __('Clicks', 'convermetry'), 'num' => true],
                ],
                array_map(static fn(array $row): array => [
                    self::cellText(self::clickLabel($row)),
                    self::cellLink($row['target_url']),
                    self::cellNum($row['clicks']),
                ], $rows),
                __('No clicks recorded in this period.', 'convermetry')
            );
        });
    }

    /**
     * Renders the "Top Form Submit Attempts" table.
     */
    private static function renderTopForms(string $start, string $end): void
    {
        self::renderQueriedTable(__('Top Form Submit Attempts', 'convermetry'), static function () use ($start, $end): void {
            $rows = Reports::topForms($start, $end);

            self::renderReportTable(
                __('Top Form Submit Attempts', 'convermetry'),
                __('Counted when a visitor submits the form — success is not confirmed (see Confirmed Conversions). Up to 10 shown.', 'convermetry'),
                [
                    ['label' => __('Form', 'convermetry')],
                    ['label' => __('Page', 'convermetry')],
                    ['label' => __('Attempts', 'convermetry'), 'num' => true],
                ],
                array_map(static fn(array $row): array => [
                    self::cellText($row['element_label'] !== '' ? $row['element_label'] : __('(unnamed form)', 'convermetry')),
                    self::cellLink($row['page_url']),
                    self::cellNum($row['submissions']),
                ], $rows),
                __('No form submissions recorded in this period.', 'convermetry')
            );
        });
    }

    /**
     * Renders the "Most Hovered Elements" table.
     */
    private static function renderTopHovers(string $start, string $end): void
    {
        self::renderQueriedTable(__('Most Hovered Elements', 'convermetry'), static function () use ($start, $end): void {
            $rows = Reports::topHovers($start, $end);

            self::renderReportTable(
                __('Most Hovered Elements', 'convermetry'),
                __('Elements the pointer rested on — where visitor attention lingers before (or without) a click. Up to 10 shown.', 'convermetry'),
                [
                    ['label' => __('Element', 'convermetry')],
                    ['label' => __('Type', 'convermetry')],
                    ['label' => __('Hovers', 'convermetry'), 'num' => true],
                ],
                array_map(static fn(array $row): array => [
                    self::cellText($row['element_label'] !== '' ? $row['element_label'] : __('(unlabeled element)', 'convermetry')),
                    self::cellText($row['element_tag']),
                    self::cellNum($row['hovers']),
                ], $rows),
                __('No hover activity recorded in this period.', 'convermetry')
            );
        });
    }

    /**
     * Renders the "Top Referrers" table of external traffic sources.
     */
    private static function renderTopReferrers(string $start, string $end): void
    {
        self::renderQueriedTable(__('Top Referrers', 'convermetry'), static function () use ($start, $end): void {
            $rows = Reports::topReferrers($start, $end);

            self::renderReportTable(
                __('Top Referrers', 'convermetry'),
                __('The external pages that sent this site the most traffic (up to 10 shown).', 'convermetry'),
                [
                    ['label' => __('Referring Page', 'convermetry')],
                    ['label' => __('Pageviews', 'convermetry'), 'num' => true],
                ],
                array_map(static fn(array $row): array => [
                    self::cellLink($row['referrer']),
                    self::cellNum($row['visits']),
                ], $rows),
                __('No external referrers recorded in this period.', 'convermetry')
            );
        });
    }

    /**
     * Renders the "Campaigns" table.
     */
    private static function renderCampaigns(string $start, string $end): void
    {
        self::renderQueriedTable(__('Campaigns', 'convermetry'), static function () use ($start, $end): void {
            $rows = Reports::topCampaigns($start, $end);

            self::renderReportTable(
                __('Campaigns', 'convermetry'),
                __('Session-attributed performance of utm-tagged visits (up to 10 shown, ranked by views, plus any campaigns that converted without a same-period pageview). Conv. rate is the share of sessions with at least one conversion.', 'convermetry'),
                [
                    ['label' => __('Source', 'convermetry')],
                    ['label' => __('Medium', 'convermetry')],
                    ['label' => __('Campaign', 'convermetry')],
                    ['label' => __('ID', 'convermetry')],
                    ['label' => __('Sessions', 'convermetry'), 'num' => true],
                    ['label' => __('Views', 'convermetry'), 'num' => true],
                    ['label' => __('Conversions', 'convermetry'), 'num' => true],
                    ['label' => __('Conv. Rate', 'convermetry'), 'num' => true],
                ],
                array_map(static fn(array $row): array => [
                    self::cellText($row['utm_source']),
                    self::cellText($row['utm_medium']),
                    self::cellText($row['utm_campaign']),
                    self::cellText($row['utm_id']),
                    self::cellNum($row['sessions']),
                    self::cellNum($row['views']),
                    self::cellNum($row['conversions']),
                    self::cellText($row['sessions'] > 0 ? $row['conversion_rate'] . '%' : ''),
                ], $rows),
                __('No campaign-tagged (utm) visits recorded in this period.', 'convermetry'),
                true
            );
        });
    }

    /**
     * Renders the "Campaign Terms & Content" drilldown. Always rendered —
     * an explanatory empty state keeps the report discoverable.
     */
    private static function renderCampaignContent(string $start, string $end): void
    {
        self::renderQueriedTable(__('Campaign Terms & Content', 'convermetry'), static function () use ($start, $end): void {
            $rows = Reports::topCampaignContent($start, $end);

            self::renderReportTable(
                __('Campaign Terms & Content', 'convermetry'),
                __('Keyword (utm_term) and creative (utm_content) performance, with campaign context. Up to 10 shown.', 'convermetry'),
                [
                    ['label' => __('Source', 'convermetry')],
                    ['label' => __('Medium', 'convermetry')],
                    ['label' => __('Campaign', 'convermetry')],
                    ['label' => __('ID', 'convermetry')],
                    ['label' => __('Term', 'convermetry')],
                    ['label' => __('Content', 'convermetry')],
                    ['label' => __('Sessions', 'convermetry'), 'num' => true],
                    ['label' => __('Views', 'convermetry'), 'num' => true],
                    ['label' => __('Conversions', 'convermetry'), 'num' => true],
                ],
                array_map(static fn(array $row): array => [
                    self::cellText($row['utm_source']),
                    self::cellText($row['utm_medium']),
                    self::cellText($row['utm_campaign']),
                    self::cellText($row['utm_id']),
                    self::cellText($row['utm_term']),
                    self::cellText($row['utm_content']),
                    self::cellNum($row['sessions']),
                    self::cellNum($row['views']),
                    self::cellNum($row['conversions']),
                ], $rows),
                __('No visits carrying utm_term or utm_content tags were recorded in this period. Campaigns that never tag keywords or creatives simply don\'t appear here.', 'convermetry'),
                true
            );
        });
    }

    /**
     * Renders the "Channels" table.
     */
    private static function renderChannels(string $start, string $end): void
    {
        self::renderQueriedTable(__('Channels', 'convermetry'), static function () use ($start, $end): void {
            $rows = self::channelData($start, $end);

            self::renderReportTable(
                __('Channels', 'convermetry'),
                __('Sessions and conversions per marketing channel, classified as events arrive. Conv. rate is the share of sessions with at least one conversion.', 'convermetry'),
                [
                    ['label' => __('Channel', 'convermetry')],
                    ['label' => __('Sessions', 'convermetry'), 'num' => true],
                    ['label' => __('Views', 'convermetry'), 'num' => true],
                    ['label' => __('Conversions', 'convermetry'), 'num' => true],
                    ['label' => __('Conv. Rate', 'convermetry'), 'num' => true],
                ],
                array_map(static fn(array $row): array => [
                    self::cellText($row['channel']),
                    self::cellNum($row['sessions']),
                    self::cellNum($row['views']),
                    self::cellNum($row['conversions']),
                    self::cellText($row['sessions'] > 0 ? $row['conversion_rate'] . '%' : ''),
                ], $rows),
                __('No channel data recorded in this period. Channels are classified as new events arrive, so this fills in from the moment of installation onward.', 'convermetry')
            );
        });
    }

    /**
     * Renders the "Devices" table of page views by device bucket.
     */
    private static function renderDevices(string $start, string $end): void
    {
        self::renderQueriedTable(__('Devices', 'convermetry'), static function () use ($start, $end): void {
            $devices = self::deviceData($start, $end);
            $total   = array_sum($devices);

            $rows = [];
            foreach ($devices as $device => $views) {
                $rows[] = [
                    self::cellText(ucfirst($device)),
                    self::cellNum($views),
                    self::cellText($total > 0 ? round($views / $total * 100) . '%' : ''),
                ];
            }

            self::renderReportTable(
                __('Devices', 'convermetry'),
                '',
                [
                    ['label' => __('Device', 'convermetry')],
                    ['label' => __('Page Views', 'convermetry'), 'num' => true],
                    ['label' => __('Share', 'convermetry'), 'num' => true],
                ],
                $rows,
                __('No page views recorded in this period.', 'convermetry')
            );
        });
    }

    /**
     * Renders the "Recent Conversions" table: individual conversions with
     * attribution, merged with the provider/form identity of their
     * server-confirmed submissions where one exists.
     */
    private static function renderRecentConversions(string $start, string $end): void
    {
        self::renderQueriedTable(__('Recent Conversions', 'convermetry'), static function () use ($start, $end): void {
            $rows = Reports::recentConversions($start, $end, 15);

            $cells = [];
            foreach ($rows as $row) {
                $attribution = (array) ($row['attribution'] ?? []);
                $campaign    = trim(implode(' / ', array_filter([
                    (string) ($attribution['utm_source'] ?? ''),
                    (string) ($attribution['utm_medium'] ?? ''),
                    (string) ($attribution['utm_campaign'] ?? ''),
                ])));

                $cells[] = [
                    /* translators: %s: date and time in UTC. */
                    self::cellText(sprintf(__('%s UTC', 'convermetry'), (string) ($row['occurred_at'] ?? ''))),
                    self::cellText((string) ($row['form'] ?? '')),
                    self::cellText(!empty($row['server_confirmed']) ? ucfirst((string) ($row['provider'] ?? '')) : __('Frontend', 'convermetry')),
                    self::cellText((string) ($attribution['channel'] ?? '')),
                    self::cellText($campaign),
                    self::cellLink((string) ($row['page_url'] ?? '')),
                    self::cellText((string) ($row['ip_address'] ?? '')),
                    '<code>' . esc_html((string) ($row['conversion_id'] ?? '')) . '</code>',
                ];
            }

            self::renderReportTable(
                __('Recent Conversions', 'convermetry'),
                __('The latest confirmed conversions in this period (up to 15 shown), deduplicated by conversion id. "Frontend" rows were detected by the tracker only; provider rows were confirmed server-side. IP is blank when IP storage is off in Settings, or for a visitor whose Do Not Track / Global Privacy Control signal is honored.', 'convermetry'),
                [
                    ['label' => __('When (UTC)', 'convermetry')],
                    ['label' => __('Form', 'convermetry')],
                    ['label' => __('Source', 'convermetry')],
                    ['label' => __('Channel', 'convermetry')],
                    ['label' => __('Campaign', 'convermetry')],
                    ['label' => __('Page', 'convermetry')],
                    ['label' => __('IP', 'convermetry')],
                    ['label' => __('Conversion ID', 'convermetry')],
                ],
                $cells,
                __('No confirmed conversions recorded in this period.', 'convermetry'),
                true
            );
        });
    }

    /**
     * Renders the "Recent Activity" table of the latest raw events.
     */
    /**
     * Goal completions for the period.
     *
     * @param string $start UTC datetime (inclusive).
     * @param string $end   UTC datetime (exclusive).
     * @return void
     */
    private static function renderGoals(string $start, string $end): void
    {
        self::renderQueriedTable(__('Goal Completions', 'convermetry'), static function () use ($start, $end): void {
            $summary = self::goalData($start, $end);

            $rows = [];
            foreach ($summary['goals'] as $goal) {
                $rows[] = [
                    self::cellText((string) $goal['name']),
                    self::cellNum((int) $goal['completions']),
                    self::cellNum((int) $goal['sessions']),
                    $goal['sessions'] > 0 ? esc_html($goal['conversion_rate'] . '%') : '&mdash;',
                    (string) $goal['value'] === '0.00'
                        ? '&mdash;'
                        : esc_html(Money::format((string) $goal['value'], (string) $goal['currency'])),
                ];
            }

            self::renderReportTable(
                __('Goal Completions', 'convermetry'),
                __('A goal counting once per visit can never exceed 100% — its rate is the share of sessions that completed it. Configure goals under Convermetry → Goals.', 'convermetry'),
                [
                    ['label' => __('Goal', 'convermetry')],
                    ['label' => __('Completions', 'convermetry'), 'num' => true],
                    ['label' => __('Sessions', 'convermetry'), 'num' => true],
                    ['label' => __('Rate', 'convermetry'), 'num' => true],
                    ['label' => __('Value', 'convermetry'), 'num' => true],
                ],
                $rows,
                __('No goal completions in this period. Goals are configured under Convermetry → Goals.', 'convermetry')
            );
        });
    }

    /**
     * One lead-outcome breakdown.
     *
     * @param string $start     UTC datetime (inclusive).
     * @param string $end       UTC datetime (exclusive).
     * @param string $dimension A LeadReports::DIMENSIONS key.
     * @param string $title     Table heading.
     * @return void
     */
    private static function renderLeadDimension(string $start, string $end, string $dimension, string $title): void
    {
        self::renderQueriedTable($title, static function () use ($start, $end, $dimension, $title): void {
            $rows = [];

            foreach (self::leadData($start, $end, $dimension) as $row) {
                $leads = (int) $row['leads'];

                $rows[] = [
                    $dimension === 'landing_page'
                        ? self::cellPage((string) $row['label'], '')
                        : self::cellText((string) $row['label']),
                    self::cellNum($leads),
                    self::cellNum((int) $row['qualified']),
                    self::cellNum((int) $row['won']),
                    // A dimension with no leads has no rate. Printing 0% would
                    // read as "they all failed" rather than "there were none".
                    $leads > 0 ? esc_html($row['qualified_rate'] . '%') : '&mdash;',
                    self::cellMoney($row['value']),
                ];
            }

            self::renderReportTable(
                $title,
                __('Attributed Lead Value is the total recorded against these leads — not revenue, and not ROI: Convermetry has no ad-spend data. Currencies are listed separately rather than added together.', 'convermetry'),
                [
                    ['label' => match ($dimension) {
                        'channel'      => __('Channel', 'convermetry'),
                        'campaign'     => __('Campaign', 'convermetry'),
                        'landing_page' => __('Landing Page', 'convermetry'),
                        default        => __('Form', 'convermetry'),
                    }],
                    ['label' => __('Leads', 'convermetry'), 'num' => true],
                    ['label' => __('Qualified', 'convermetry'), 'num' => true],
                    ['label' => __('Won', 'convermetry'), 'num' => true],
                    ['label' => __('Qual. Rate', 'convermetry'), 'num' => true],
                    ['label' => __('Attributed Lead Value', 'convermetry'), 'num' => true],
                ],
                $rows,
                __('No leads recorded in this period.', 'convermetry')
            );
        });
    }

    /**
     * Time from a session's first page view to its confirmed submission.
     *
     * @param string $start UTC datetime (inclusive).
     * @param string $end   UTC datetime (exclusive).
     * @return void
     */
    private static function renderTimeToLead(string $start, string $end): void
    {
        self::renderQueriedTable(__('Time to Lead', 'convermetry'), static function () use ($start, $end): void {
            $lag = LeadReports::timeToLead($start, $end);

            $rows = [];
            foreach ($lag['buckets'] as $label => $count) {
                $rows[] = [
                    esc_html(LeadReports::bucketLabel($label)),
                    self::cellNum($count),
                    $lag['sampled'] > 0
                        ? esc_html(round($count / $lag['sampled'] * 100, 1) . '%')
                        : '&mdash;',
                ];
            }

            foreach ($lag['medians'] as $channel => $seconds) {
                $rows[] = [
                    '<em>' . esc_html(sprintf(
                        /* translators: %s: marketing channel name. */
                        __('%s — median', 'convermetry'),
                        $channel
                    )) . '</em>',
                    esc_html(self::humanDuration($seconds)),
                    '&mdash;',
                ];
            }

            self::renderReportTable(
                __('Time to Lead', 'convermetry'),
                __('Measured from the first page view of the session that converted, so a visitor who researched over several visits is measured from their final one — Convermetry keeps no persistent visitor identity across sessions. Medians rather than averages: one lead that took three weeks would drag an average past every real experience of the site.', 'convermetry'),
                [
                    ['label' => __('Time to convert', 'convermetry')],
                    ['label' => __('Leads', 'convermetry'), 'num' => true],
                    ['label' => __('Share', 'convermetry'), 'num' => true],
                ],
                $rows,
                __('No conversions with a measurable session start in this period.', 'convermetry')
            );
        });
    }

    /**
     * Renders a per-currency value map.
     *
     * Currencies are listed, never added together — a column showing "200" for
     * 100 EUR plus 100 USD would be a fabricated number.
     *
     * @param array<string, string> $values Currency code => decimal string.
     * @return string Safe HTML.
     */
    private static function cellMoney(array $values): string
    {
        $parts = [];

        foreach ($values as $currency => $amount) {
            if ((string) $amount !== '' && (float) $amount !== 0.0) {
                $parts[] = esc_html(Money::format((string) $amount, (string) $currency));
            }
        }

        return $parts === [] ? '&mdash;' : implode('<br>', $parts);
    }

    /**
     * A duration in seconds as a short human string. Public so the export
     * writes Time to Lead's medians exactly as the panel prints them.
     *
     * @param int $seconds Duration.
     * @return string
     */
    public static function humanDuration(int $seconds): string
    {
        return match (true) {
            /* translators: %s: number of seconds. */
            $seconds < MINUTE_IN_SECONDS => sprintf(__('%ss', 'convermetry'), number_format_i18n($seconds)),
            /* translators: %s: number of minutes. */
            $seconds < HOUR_IN_SECONDS   => sprintf(__('%s min', 'convermetry'), number_format_i18n(round($seconds / MINUTE_IN_SECONDS))),
            /* translators: %s: number of hours, possibly fractional. */
            $seconds < DAY_IN_SECONDS    => sprintf(__('%s hrs', 'convermetry'), number_format_i18n(round($seconds / HOUR_IN_SECONDS, 1), 1)),
            /* translators: %s: number of days, possibly fractional. */
            default                      => sprintf(__('%s days', 'convermetry'), number_format_i18n(round($seconds / DAY_IN_SECONDS, 1), 1)),
        };
    }

    private static function renderRecentEvents(): void
    {
        self::renderQueriedTable(__('Latest Events', 'convermetry'), static function (): void {
            $rows = Reports::recentEvents(15);

            // The site's own date/time display settings, as everywhere in wp-admin.
            $format = trim(get_option('date_format', 'F j, Y') . ' ' . get_option('time_format', 'g:i a'));

            $cells = [];
            foreach ($rows as $row) {
                $detail = $row['element_label'] !== '' ? $row['element_label'] : $row['target_url'];
                if (($row['event_value'] ?? '') !== '') {
                    $detail = trim($detail . ' (' . $row['event_value'] . ')');
                }

                $cells[] = [
                    self::cellText(get_date_from_gmt((string) $row['created_at'], $format)),
                    self::cellText(self::eventLabel((string) $row['event_type'])),
                    self::cellPage((string) $row['page_url'], (string) $row['page_title']),
                    self::cellText($detail),
                    self::cellText(ucfirst((string) $row['device'])),
                ];
            }

            self::renderReportTable(
                __('Latest Events', 'convermetry'),
                sprintf(
                    /* translators: %s: the site's timezone. */
                    __('The latest 15 events, independent of the selected reporting period. Times are shown in the site timezone (%s).', 'convermetry'),
                    self::siteTimezoneLabel()
                ),
                [
                    ['label' => __('When', 'convermetry')],
                    ['label' => __('Event', 'convermetry')],
                    ['label' => __('Page', 'convermetry')],
                    ['label' => __('Detail', 'convermetry')],
                    ['label' => __('Device', 'convermetry')],
                ],
                $cells,
                __('No events recorded yet. Visit the site\'s frontend to start collecting data.', 'convermetry')
            );
        });
    }
}
