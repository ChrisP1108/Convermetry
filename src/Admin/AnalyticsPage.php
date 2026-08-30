<?php
declare(strict_types=1);

namespace Convermetry\Admin;

if (!defined('ABSPATH')) exit;

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
 * The top-level "Convermetry" admin page (Analytics) that visualizes
 * collected analytics.
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
 * All numbers come from {@see Reports}, the same query layer the analytics
 * webhook payload uses, so the dashboard and webhook consumers always agree.
 */
final class AnalyticsPage
{
    /** Menu slug for the top-level page. */
    public const string MENU_SLUG = 'convermetry';

    /** @var int[] Periods (in days) selectable in the dashboard filter. */
    private const array PERIODS = [7, 30, 90];

    /**
     * Registers the admin menu and asset hooks.
     *
     * @return void
     */
    public static function init(): void
    {
        add_action('admin_menu', [self::class, 'addMenu']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueueAssets']);
    }

    /**
     * Adds the top-level Convermetry menu entry; the top-level item opens
     * the Analytics page. Sibling subpages register themselves under this
     * slug.
     *
     * @return void
     */
    public static function addMenu(): void
    {
        add_menu_page(
            'Convermetry',
            'Convermetry',
            Capability::required(Capability::ANALYTICS_VIEW),
            self::MENU_SLUG,
            [self::class, 'render'],
            'dashicons-chart-area',
            58
        );

        add_submenu_page(
            self::MENU_SLUG,
            'Convermetry Analytics',
            'Analytics',
            Capability::required(Capability::ANALYTICS_VIEW),
            self::MENU_SLUG,
            [self::class, 'render']
        );
    }

    /**
     * Enqueues the shared admin stylesheet on plugin pages only, and the
     * dashboard assets (chart tooltips, print prep) on the Analytics screen
     * only.
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
            'cvm-admin',
            CVM_PLUGIN_URL . 'assets/css/admin.css',
            [],
            CVM_VERSION
        );

        if ($hook === 'toplevel_page_' . self::MENU_SLUG) {
            wp_enqueue_style(
                'cvm-dashboard',
                CVM_PLUGIN_URL . 'assets/css/dashboard.css',
                ['cvm-admin'],
                CVM_VERSION
            );

            wp_enqueue_script(
                'cvm-dashboard',
                CVM_PLUGIN_URL . 'assets/js/dashboard.js',
                [],
                CVM_VERSION,
                true
            );
        }
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

        $days = self::currentPeriod();

        // Clamped to the configured retention window: querying/displaying
        // the full nominal period when retention is shorter would silently
        // zero-fill days past the actual cutoff and understate any "per day"
        // average.
        $effectiveDays = min($days, Options::retentionDays());
        $end           = gmdate('Y-m-d H:i:s');

        // Align the range to calendar days (UTC): the period covers the last
        // N days *including today*, so the chart renders exactly N bars.
        $start = gmdate('Y-m-d 00:00:00', time() - ($effectiveDays - 1) * DAY_IN_SECONDS);

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
        <div class="wrap cvm-wrap cvm-dash">
        <h1>Convermetry Analytics</h1>
        <?php

        self::maybeRenderRateLimitNotice();
        self::maybeRenderRetentionNotice($days);
        self::renderPeriodFilter($days, $effectiveDays);

        ?>
        <section class="cvm-overview" aria-labelledby="cvm-h-overview">
        <h2 id="cvm-h-overview">Overview</h2>
        <?php
        if ($overviewFailed) {
            self::renderErrorNotice();
        } else {
            self::renderSummaryCards($totals, $serverCount);
            self::renderPageviewChart($daily, $effectiveDays);
        }
        ?>
        </section>
        <?php

        // Revealed by dashboard.js: without JavaScript the buttons would do
        // nothing, and the <details> panels are already usable natively.
        ?>
        <div class="cvm-panel-toolbar" hidden>
        <button type="button" class="button cvm-panels-expand">Expand all sections</button>
        <button type="button" class="button cvm-panels-collapse">Collapse all sections</button>
        <button type="button" class="button cvm-print-btn">Print / Save as PDF</button></div>
        <?php

        self::panelStart('content', 'Content', 'Which pages draw traffic and where visitors arrive.', true);
        ?>
        <div class="cvm-tables">
        <?php
        self::renderTopPages($start, $end);
        self::renderLandingPages($start, $end);
        ?>
        </div>
        <?php
        self::panelEnd();

        self::panelStart('engagement', 'Engagement', 'How visitors interact with your pages: clicks, form activity, and attention.');
        ?>
        <div class="cvm-tables">
        <?php
        self::renderTopClicks($start, $end);
        self::renderTopForms($start, $end);
        self::renderTopHovers($start, $end);
        ?>
        </div>
        <?php
        self::panelEnd();

        self::panelStart('acquisition', 'Acquisition', 'Where traffic comes from: referrers, campaigns, and marketing channels.');
        ?>
        <div class="cvm-tables">
        <?php
        self::renderTopReferrers($start, $end);
        self::renderChannels($start, $end);
        self::renderCampaigns($start, $end);
        self::renderCampaignContent($start, $end);
        ?>
        </div>
        <?php
        self::panelEnd();

        self::panelStart('devices', 'Devices', 'Mobile versus desktop share of page views.');
        self::renderDevices($start, $end);
        self::panelEnd();

        self::panelStart('goals', 'Goals', 'Important visitor actions other than form submissions — phone taps, downloads, booking clicks, pricing-page visits.');
        self::renderGoals($start, $end);
        self::panelEnd();

        self::panelStart('outcomes', 'Lead outcomes', 'What the leads were actually worth. Counted by the date each lead arrived, with its status as it stands right now.');
        ?>
        <div class="cvm-tables">
        <?php
        self::renderLeadDimension($start, $end, 'channel', 'Leads by Channel');
        self::renderLeadDimension($start, $end, 'campaign', 'Leads by Campaign');
        self::renderLeadDimension($start, $end, 'landing_page', 'Landing Page Performance');
        self::renderLeadDimension($start, $end, 'form', 'Leads by Form');
        ?>
        </div>
        <?php
        self::renderTimeToLead($start, $end);
        self::panelEnd();

        self::panelStart('conversions', 'Conversions', 'Individual confirmed conversions with their campaign attribution — server-confirmed submissions carry their provider and form identity.');
        self::renderRecentConversions($start, $end);
        self::panelEnd();

        self::panelStart('recent', 'Recent Activity');
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
         * do — a <details class="cvm-panel"> with a <summary> and a
         * <div class="cvm-panel-body"> — to inherit the page's styling.
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

        // cvm-rate-limit-notice keeps this warning visible in the printed
        // report too — it flags that the numbers below may undercount.
        ?>
        <div class="notice notice-warning cvm-rate-limit-notice"><p><strong>Convermetry:</strong> The site-wide event rate
        limit was reached in the last 24 hours (first at <?php echo esc_html(gmdate('Y-m-d H:i', $hitAt)); ?> UTC), so some visitor
        events were not recorded. If this is legitimate traffic rather than a flood, raise the limits with the <code>convermetry_rate_limits</code>
        filter.</p></div>
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
        <div class="notice notice-warning cvm-retention-notice cvm-rate-limit-notice"><p><strong>Convermetry:</strong> The
        selected <?php echo (int) $days; ?>-day period is longer than the configured data retention window (<?php echo (int) $retention; ?>
        days), so events older than <?php echo (int) $retention; ?> days have already been deleted and cannot appear below. Choose
        a shorter period, or raise <strong>Data retention</strong> in Settings.</p></div>
        <?php
    }

    /**
     * Returns the validated period (in days) from the request, defaulting to 30.
     *
     * @return int
     */
    private static function currentPeriod(): int
    {
        $days = isset($_GET['period']) ? (int) $_GET['period'] : 30;

        return in_array($days, self::periods(), true) ? $days : 30;
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
     * Renders the 7/30/90-day period selector as a segmented button group,
     * with the covered UTC date range spelled out below it.
     *
     * @param int $active        Currently selected period in days (button state).
     * @param int $effectiveDays Retention-clamped period actually queried/displayed.
     * @return void
     */
    private static function renderPeriodFilter(int $active, int $effectiveDays): void
    {
        $startLabel = self::utcDate('M j, Y', time() - ($effectiveDays - 1) * DAY_IN_SECONDS);
        $endLabel   = self::utcDate('M j, Y', time());

        ?>
        <div class="cvm-period">
        <nav class="cvm-period-group" aria-label="Reporting period">
        <?php

        foreach (self::periods() as $days) {
            $url = add_query_arg(
                ['page' => self::MENU_SLUG, 'period' => $days],
                self_admin_url('admin.php')
            );

            $isActive = $days === $active;

            ?>
            <a class="cvm-period-btn<?php echo ($isActive ? ' is-active' : ''); ?>"<?php echo ($isActive ? ' aria-current="page"' : ''); ?> href="<?php echo esc_url($url); ?>">Last
            <?php echo (int) $days; ?> days</a>
            <?php
        }

        ?>
        </nav>
        <p class="cvm-period-range"><?php echo esc_html($startLabel); ?> &ndash; <?php echo esc_html($endLabel); ?>. Dates
        are UTC; the current day is still collecting data.</p>
        <?php

        // Print-only report header (dashboard.css shows it in @media print).
        $generatedFormat = trim(get_option('date_format', 'F j, Y') . ' ' . get_option('time_format', 'g:i a'));
        $rangeNote = $active === $effectiveDays
            ? ((string) $active . ' days')
            : ($active . ' days selected; ' . $effectiveDays . ' days shown per data retention');
        ?>
        <p class="cvm-print-meta"><?php echo esc_html(get_bloginfo('name')); ?> &mdash; Convermetry analytics report &middot;
        <?php echo esc_html($startLabel); ?> &ndash; <?php echo esc_html($endLabel); ?> (UTC, last <?php echo esc_html($rangeNote); ?>;
        the final day was still collecting when generated) &middot; Generated <?php echo esc_html(get_date_from_gmt(gmdate('Y-m-d H:i:s'), $generatedFormat)); ?>
        (<?php echo esc_html(self::siteTimezoneLabel()); ?>)</p></div>
        <?php
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
     * @param array<string, int> $totals      Map of event_type → count for the period.
     * @param int                $serverCount Server-confirmed submissions in the period.
     * @return void
     */
    private static function renderSummaryCards(array $totals, int $serverCount): void
    {
        $cards = [
            'pageview'     => ['Page Views', ''],
            'click'        => ['Clicks', ''],
            'form_submit'  => ['Form Submit Attempts', 'Counted when a form is submitted, before the server confirms success.'],
            'form_success' => ['Confirmed Conversions', 'Unique conversions, deduplicated across frontend and server detection.'],
            'hover'        => ['Hovers', ''],
            'scroll_depth' => ['Scroll Milestones', 'Times visitors reached 50% or 100% of a page.'],
        ];

        // Any custom event types recorded via cvm_track_event() are summed
        // into a single "Other Events" card so nothing is invisible.
        $other = array_sum(array_diff_key($totals, $cards));

        ?>
        <div class="cvm-cards">
        <?php
        foreach ($cards as $type => [$label, $desc]) {
            self::renderStatCard(number_format_i18n($totals[$type] ?? 0), $label, $desc);

            if ($type === 'form_success') {
                self::renderStatCard(
                    number_format_i18n($serverCount),
                    'Server-Confirmed Submissions',
                    'Submissions a form plugin\'s server-side hook confirmed — the authoritative lead count.'
                );
            }
        }

        if ($other > 0) {
            self::renderStatCard(number_format_i18n($other), 'Other Events', 'Custom event types recorded via cvm_track_event().');
        }
        ?>
        </div>
        <?php
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
        <div class="cvm-card cvm-stat-card">
        <span class="cvm-card-value"><?php echo esc_html($value); ?></span>
        <span class="cvm-card-label"><?php echo esc_html($label); ?></span>
        <?php
        if ($desc !== '') {
            ?>
            <span class="cvm-card-desc"><?php echo esc_html($desc); ?></span>
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
            $avgLabel = '— (no completed days yet)';
        }

        // X-axis label density: every day at 7, every 5th at 30, every 15th at 90.
        $step = match (true) {
            $days <= 7  => 1,
            $days <= 30 => 5,
            default     => 15,
        };

        ?>
        <div class="cvm-chart-frame">
        <h3>Daily Page Views</h3>
        <p class="cvm-chart-summary">
        <span>Total: <strong><?php echo esc_html(number_format_i18n($total)); ?></strong></span>
        <span>Avg per completed day: <strong><?php echo esc_html($avgLabel); ?></strong></span>
        <?php
        if ($busiestDate !== '') {
            ?>
            <span>Busiest day: <strong><?php echo esc_html(self::utcDate('M j', (int) strtotime($busiestDate . ' UTC'))); ?>
            (<?php echo esc_html(number_format_i18n($busiestCount)); ?>)</strong></span>
            <?php
        }
        ?>
        <span class="cvm-chart-key"><span class="cvm-chart-key-swatch" aria-hidden="true"></span>Today (still collecting)</span></p>
        <?php

        // A density bucket, not a class per exact day count: retention can
        // clamp the effective period to any value, so the scroll/min-width
        // treatment is keyed to "does this many bars need it".
        $isWide      = $days > 10;
        $layoutClass = 'cvm-chart-layout' . ($isWide ? ' cvm-chart-layout--wide' : '');
        $minWidth    = $isWide ? max(640, $days * 16) : 0;

        ?>
        <div class="cvm-chart-scroll">
        <div class="<?php echo esc_attr($layoutClass); ?>"<?php echo ($minWidth > 0 ? ' style="--cvm-chart-min-width:' . esc_attr((string) $minWidth) . 'px"' : ''); ?>>
        <div class="cvm-chart-yaxis" aria-hidden="true">
        <span><?php echo esc_html(number_format_i18n($scale)); ?></span>
        <span><?php echo esc_html(number_format_i18n((int) ($scale / 2))); ?></span>
        <span>0</span></div>
        <div class="cvm-chart-main">
        <div class="cvm-chart-plot">
        <div class="cvm-chart-cols" role="group" aria-label="Daily page views: one button per day, oldest first">
        <?php

        foreach ($daily as $point) {
            $dateLabel = self::utcDate('M j, Y', (int) strtotime($point['date'] . ' UTC'));
            $isToday   = $point['date'] === $today;
            $height    = round($point['count'] / $scale * 100, 2);
            $aria      = sprintf(
                '%s: %s page view%s%s',
                $dateLabel,
                number_format_i18n($point['count']),
                $point['count'] === 1 ? '' : 's',
                $isToday ? ' (today, still collecting)' : ''
            );

            ?>
            <button type="button" class="cvm-chart-col<?php echo ($isToday ? ' is-today' : ''); ?>" data-date="<?php echo esc_attr($dateLabel); ?>" data-count="<?php echo esc_attr(number_format_i18n($point['count'])); ?>" aria-label="<?php echo esc_attr($aria); ?>"><span class="cvm-chart-bar" style="--cvm-h:<?php echo esc_attr((string) $height); ?>%"></span></button>
            <?php
        }

        ?>
        </div>
        <?php // .cvm-chart-cols
        ?>
        </div>
        <?php // .cvm-chart-plot

        ?>
        <div class="cvm-chart-xaxis" aria-hidden="true">
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
            <span class="cvm-chart-xlabel" style="--cvm-x:<?php echo esc_attr((string) $x); ?>%"><?php echo esc_html(self::utcDate('M j', (int) strtotime($point['date'] . ' UTC'))); ?></span>
            <?php
        }
        ?>
        </div>
        <?php // .cvm-chart-xaxis

        ?>
        </div></div></div>
        <?php // .cvm-chart-main, .cvm-chart-layout, .cvm-chart-scroll

        ?>
        <details class="cvm-chart-data">
        <summary>View data table</summary>
        <div class="cvm-table-scroll">
        <table class="wp-list-table widefat striped cvm-chart-data-table">
        <caption class="screen-reader-text">Daily page views for the selected period</caption>
        <thead><tr><th scope="col">Date</th><th scope="col" class="cvm-num">Page Views</th></tr></thead><tbody>
        <?php

        foreach ($daily as $point) {
            $isToday = $point['date'] === $today;
            ?>
            <tr><td><?php echo esc_html(self::utcDate('M j, Y', (int) strtotime($point['date'] . ' UTC'))); ?><?php echo ($isToday ? ' <em>(today, partial)</em>' : ''); ?></td><td class="cvm-num"><?php echo esc_html(number_format_i18n($point['count'])); ?></td></tr>
            <?php
        }

        ?>
        </tbody></table></div></details></div>
        <?php // .cvm-chart-frame
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
        <details class="cvm-panel" id="cvm-panel-<?php echo esc_attr($id); ?>"<?php echo ($open ? ' open' : ''); ?>>
        <summary class="cvm-panel-summary">
        <h2><?php echo esc_html($title); ?></h2>
        <span class="cvm-panel-arrow" aria-hidden="true">&#9660;</span></summary>
        <div class="cvm-panel-body">
        <?php
        if ($desc !== '') {
            ?>
            <p class="cvm-panel-desc"><?php echo esc_html($desc); ?></p>
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
        <div class="cvm-report<?php echo ($wide ? ' cvm-report--wide' : ''); ?>">
        <h3><?php echo esc_html($title); ?></h3>
        <?php
        if ($desc !== '') {
            ?>
            <p class="cvm-report-desc"><?php echo esc_html($desc); ?></p>
            <?php
        }

        ?>
        <div class="cvm-table-scroll">
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
            <th scope="col"<?php echo (!empty($col['num']) ? ' class="cvm-num"' : ''); ?>><?php echo esc_html($col['label']); ?></th>
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
                <td<?php echo (!empty($columns[$i]['num']) ? ' class="cvm-num"' : ''); ?>><?php echo $cell; ?></td>
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
            <div class="cvm-report">
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
        <div class="notice notice-error inline cvm-report-error"><p>This section could not be loaded due to a database error.
        Your data is safe — try refreshing shortly, or check your site's PHP error log if this continues.</p></div>
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
        $sr   = self::isExternalUrl($url)
            ? ' (external link, opens in a new tab)'
            : ' (opens in a new tab)';

        return '<a href="' . esc_url($url) . '" target="_blank" rel="noopener">' . esc_html($text)
            . '<span class="cvm-newtab" aria-hidden="true">&#8599;</span>'
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
            $html .= '<span class="cvm-url-sub">' . esc_html(self::urlDisplayText($url)) . '</span>';
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
            'pageview'     => 'Page View',
            'click'        => 'Click',
            'form_submit'  => 'Form Submit Attempt',
            'form_success' => 'Confirmed Conversion',
            'hover'        => 'Hover',
            'scroll_depth' => 'Scroll Milestone',
            default        => ucwords(str_replace(['_', '-'], ' ', $type)),
        };
    }

    /**
     * Renders the "Top Pages" table.
     */
    private static function renderTopPages(string $start, string $end): void
    {
        self::renderQueriedTable('Top Pages', static function () use ($start, $end): void {
            $rows = Reports::topPages($start, $end);

            self::renderReportTable(
                'Top Pages',
                'The most-viewed pages (up to 10 shown). Sessions group one visit within a 30-minute inactivity window.',
                [
                    ['label' => 'Page'],
                    ['label' => 'Views', 'num' => true],
                    ['label' => 'Sessions', 'num' => true],
                ],
                array_map(static fn(array $row): array => [
                    self::cellPage($row['page_url'], $row['page_title']),
                    self::cellNum($row['views']),
                    self::cellNum($row['sessions']),
                ], $rows),
                'No page views recorded in this period.'
            );
        });
    }

    /**
     * Renders the "Top Landing Pages" table.
     */
    private static function renderLandingPages(string $start, string $end): void
    {
        self::renderQueriedTable('Top Landing Pages', static function () use ($start, $end): void {
            $rows = Reports::topLandingPages($start, $end);

            self::renderReportTable(
                'Top Landing Pages',
                'The first page of each session that started in this period — where visitors actually arrive. Up to 10 shown.',
                [
                    ['label' => 'Landing Page'],
                    ['label' => 'Sessions', 'num' => true],
                ],
                array_map(static fn(array $row): array => [
                    self::cellPage($row['page_url'], $row['page_title']),
                    self::cellNum($row['sessions']),
                ], $rows),
                'No sessions recorded in this period.'
            );
        });
    }

    /**
     * Renders the "Top Clicked Elements" table.
     */
    private static function renderTopClicks(string $start, string $end): void
    {
        self::renderQueriedTable('Top Clicked Elements', static function () use ($start, $end): void {
            $rows = Reports::topClicks($start, $end);

            self::renderReportTable(
                'Top Clicked Elements',
                'The links and buttons visitors click most (up to 10 shown).',
                [
                    ['label' => 'Element'],
                    ['label' => 'Destination'],
                    ['label' => 'Clicks', 'num' => true],
                ],
                array_map(static fn(array $row): array => [
                    self::cellText($row['element_label'] !== '' ? $row['element_label'] : '(unlabeled ' . $row['element_tag'] . ')'),
                    self::cellLink($row['target_url']),
                    self::cellNum($row['clicks']),
                ], $rows),
                'No clicks recorded in this period.'
            );
        });
    }

    /**
     * Renders the "Top Form Submit Attempts" table.
     */
    private static function renderTopForms(string $start, string $end): void
    {
        self::renderQueriedTable('Top Form Submit Attempts', static function () use ($start, $end): void {
            $rows = Reports::topForms($start, $end);

            self::renderReportTable(
                'Top Form Submit Attempts',
                'Counted when a visitor submits the form — success is not confirmed (see Confirmed Conversions). Up to 10 shown.',
                [
                    ['label' => 'Form'],
                    ['label' => 'Page'],
                    ['label' => 'Attempts', 'num' => true],
                ],
                array_map(static fn(array $row): array => [
                    self::cellText($row['element_label'] !== '' ? $row['element_label'] : '(unnamed form)'),
                    self::cellLink($row['page_url']),
                    self::cellNum($row['submissions']),
                ], $rows),
                'No form submissions recorded in this period.'
            );
        });
    }

    /**
     * Renders the "Most Hovered Elements" table.
     */
    private static function renderTopHovers(string $start, string $end): void
    {
        self::renderQueriedTable('Most Hovered Elements', static function () use ($start, $end): void {
            $rows = Reports::topHovers($start, $end);

            self::renderReportTable(
                'Most Hovered Elements',
                'Elements the pointer rested on — where visitor attention lingers before (or without) a click. Up to 10 shown.',
                [
                    ['label' => 'Element'],
                    ['label' => 'Type'],
                    ['label' => 'Hovers', 'num' => true],
                ],
                array_map(static fn(array $row): array => [
                    self::cellText($row['element_label'] !== '' ? $row['element_label'] : '(unlabeled element)'),
                    self::cellText($row['element_tag']),
                    self::cellNum($row['hovers']),
                ], $rows),
                'No hover activity recorded in this period.'
            );
        });
    }

    /**
     * Renders the "Top Referrers" table of external traffic sources.
     */
    private static function renderTopReferrers(string $start, string $end): void
    {
        self::renderQueriedTable('Top Referrers', static function () use ($start, $end): void {
            $rows = Reports::topReferrers($start, $end);

            self::renderReportTable(
                'Top Referrers',
                'The external pages that sent this site the most traffic (up to 10 shown).',
                [
                    ['label' => 'Referring Page'],
                    ['label' => 'Pageviews', 'num' => true],
                ],
                array_map(static fn(array $row): array => [
                    self::cellLink($row['referrer']),
                    self::cellNum($row['visits']),
                ], $rows),
                'No external referrers recorded in this period.'
            );
        });
    }

    /**
     * Renders the "Campaigns" table.
     */
    private static function renderCampaigns(string $start, string $end): void
    {
        self::renderQueriedTable('Campaigns', static function () use ($start, $end): void {
            $rows = Reports::topCampaigns($start, $end);

            self::renderReportTable(
                'Campaigns',
                'Session-attributed performance of utm-tagged visits (up to 10 shown, ranked by views, plus any campaigns that converted without a same-period pageview). Conv. rate is the share of sessions with at least one conversion.',
                [
                    ['label' => 'Source'],
                    ['label' => 'Medium'],
                    ['label' => 'Campaign'],
                    ['label' => 'ID'],
                    ['label' => 'Sessions', 'num' => true],
                    ['label' => 'Views', 'num' => true],
                    ['label' => 'Conversions', 'num' => true],
                    ['label' => 'Conv. Rate', 'num' => true],
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
                'No campaign-tagged (utm) visits recorded in this period.',
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
        self::renderQueriedTable('Campaign Terms & Content', static function () use ($start, $end): void {
            $rows = Reports::topCampaignContent($start, $end);

            self::renderReportTable(
                'Campaign Terms & Content',
                'Keyword (utm_term) and creative (utm_content) performance, with campaign context. Up to 10 shown.',
                [
                    ['label' => 'Source'],
                    ['label' => 'Medium'],
                    ['label' => 'Campaign'],
                    ['label' => 'ID'],
                    ['label' => 'Term'],
                    ['label' => 'Content'],
                    ['label' => 'Sessions', 'num' => true],
                    ['label' => 'Views', 'num' => true],
                    ['label' => 'Conversions', 'num' => true],
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
                'No visits carrying utm_term or utm_content tags were recorded in this period. Campaigns that never tag keywords or creatives simply don\'t appear here.',
                true
            );
        });
    }

    /**
     * Renders the "Channels" table.
     */
    private static function renderChannels(string $start, string $end): void
    {
        self::renderQueriedTable('Channels', static function () use ($start, $end): void {
            $rows = Reports::channelBreakdown($start, $end);

            self::renderReportTable(
                'Channels',
                'Sessions and conversions per marketing channel, classified as events arrive. Conv. rate is the share of sessions with at least one conversion.',
                [
                    ['label' => 'Channel'],
                    ['label' => 'Sessions', 'num' => true],
                    ['label' => 'Views', 'num' => true],
                    ['label' => 'Conversions', 'num' => true],
                    ['label' => 'Conv. Rate', 'num' => true],
                ],
                array_map(static fn(array $row): array => [
                    self::cellText($row['channel']),
                    self::cellNum($row['sessions']),
                    self::cellNum($row['views']),
                    self::cellNum($row['conversions']),
                    self::cellText($row['sessions'] > 0 ? $row['conversion_rate'] . '%' : ''),
                ], $rows),
                'No channel data recorded in this period. Channels are classified as new events arrive, so this fills in from the moment of installation onward.'
            );
        });
    }

    /**
     * Renders the "Devices" table of page views by device bucket.
     */
    private static function renderDevices(string $start, string $end): void
    {
        self::renderQueriedTable('Devices', static function () use ($start, $end): void {
            $devices = Reports::deviceBreakdown($start, $end);
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
                'Devices',
                '',
                [
                    ['label' => 'Device'],
                    ['label' => 'Page Views', 'num' => true],
                    ['label' => 'Share', 'num' => true],
                ],
                $rows,
                'No page views recorded in this period.'
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
        self::renderQueriedTable('Recent Conversions', static function () use ($start, $end): void {
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
                    self::cellText((string) ($row['occurred_at'] ?? '') . ' UTC'),
                    self::cellText((string) ($row['form'] ?? '')),
                    self::cellText(!empty($row['server_confirmed']) ? ucfirst((string) ($row['provider'] ?? '')) : 'Frontend'),
                    self::cellText((string) ($attribution['channel'] ?? '')),
                    self::cellText($campaign),
                    self::cellLink((string) ($row['page_url'] ?? '')),
                    self::cellText((string) ($row['ip_address'] ?? '')),
                    '<code>' . esc_html((string) ($row['conversion_id'] ?? '')) . '</code>',
                ];
            }

            self::renderReportTable(
                'Recent Conversions',
                'The latest confirmed conversions in this period (up to 15 shown), deduplicated by conversion id. "Frontend" rows were detected by the tracker only; provider rows were confirmed server-side. IP is blank when IP storage is off in Settings, or for a visitor whose Do Not Track / Global Privacy Control signal is honored.',
                [
                    ['label' => 'When (UTC)'],
                    ['label' => 'Form'],
                    ['label' => 'Source'],
                    ['label' => 'Channel'],
                    ['label' => 'Campaign'],
                    ['label' => 'Page'],
                    ['label' => 'IP'],
                    ['label' => 'Conversion ID'],
                ],
                $cells,
                'No confirmed conversions recorded in this period.',
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
        self::renderQueriedTable('Goal Completions', static function () use ($start, $end): void {
            $summary = GoalReports::summary($start, $end, GoalRepository::names(), 25);

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
                'Goal Completions',
                'A goal counting once per visit can never exceed 100% — its rate is the share of sessions '
                . 'that completed it. Configure goals under Convermetry → Goals.',
                [
                    ['label' => 'Goal'],
                    ['label' => 'Completions', 'num' => true],
                    ['label' => 'Sessions', 'num' => true],
                    ['label' => 'Rate', 'num' => true],
                    ['label' => 'Value', 'num' => true],
                ],
                $rows,
                'No goal completions in this period. Goals are configured under Convermetry → Goals.'
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

            foreach (LeadReports::byDimension($start, $end, $dimension, 10) as $row) {
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
                'Attributed Lead Value is the total recorded against these leads — not revenue, and not ROI: '
                . 'Convermetry has no ad-spend data. Currencies are listed separately rather than added together.',
                [
                    ['label' => match ($dimension) {
                        'channel'      => 'Channel',
                        'campaign'     => 'Campaign',
                        'landing_page' => 'Landing Page',
                        default        => 'Form',
                    }],
                    ['label' => 'Leads', 'num' => true],
                    ['label' => 'Qualified', 'num' => true],
                    ['label' => 'Won', 'num' => true],
                    ['label' => 'Qual. Rate', 'num' => true],
                    ['label' => 'Attributed Lead Value', 'num' => true],
                ],
                $rows,
                'No leads recorded in this period.'
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
        self::renderQueriedTable('Time to Lead', static function () use ($start, $end): void {
            $lag = LeadReports::timeToLead($start, $end);

            $rows = [];
            foreach ($lag['buckets'] as $label => $count) {
                $rows[] = [
                    esc_html($label),
                    self::cellNum($count),
                    $lag['sampled'] > 0
                        ? esc_html(round($count / $lag['sampled'] * 100, 1) . '%')
                        : '&mdash;',
                ];
            }

            foreach ($lag['medians'] as $channel => $seconds) {
                $rows[] = [
                    '<em>' . esc_html($channel) . ' — median</em>',
                    esc_html(self::humanDuration($seconds)),
                    '&mdash;',
                ];
            }

            self::renderReportTable(
                'Time to Lead',
                'Measured from the first page view of the session that converted, so a visitor who researched '
                . 'over several visits is measured from their final one — Convermetry keeps no persistent '
                . 'visitor identity across sessions. Medians rather than averages: one lead that took three '
                . 'weeks would drag an average past every real experience of the site.',
                [
                    ['label' => 'Time to convert'],
                    ['label' => 'Leads', 'num' => true],
                    ['label' => 'Share', 'num' => true],
                ],
                $rows,
                'No conversions with a measurable session start in this period.'
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
     * A duration in seconds as a short human string.
     *
     * @param int $seconds Duration.
     * @return string
     */
    private static function humanDuration(int $seconds): string
    {
        return match (true) {
            $seconds < MINUTE_IN_SECONDS => $seconds . 's',
            $seconds < HOUR_IN_SECONDS   => round($seconds / MINUTE_IN_SECONDS) . ' min',
            $seconds < DAY_IN_SECONDS    => round($seconds / HOUR_IN_SECONDS, 1) . ' hrs',
            default                      => round($seconds / DAY_IN_SECONDS, 1) . ' days',
        };
    }

    private static function renderRecentEvents(): void
    {
        self::renderQueriedTable('Latest Events', static function (): void {
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
                'Latest Events',
                sprintf(
                    'The latest 15 events, independent of the selected reporting period. Times are shown in the site timezone (%s).',
                    self::siteTimezoneLabel()
                ),
                [
                    ['label' => 'When'],
                    ['label' => 'Event'],
                    ['label' => 'Page'],
                    ['label' => 'Detail'],
                    ['label' => 'Device'],
                ],
                $cells,
                'No events recorded yet. Visit the site\'s frontend to start collecting data.'
            );
        });
    }
}
