<?php
declare(strict_types=1);

namespace Convermetry\Admin;

if (!defined('ABSPATH')) exit;

use Convermetry\Admin\Pages\AnalyticsPage;
use Convermetry\Admin\Pages\FormsPage;
use Convermetry\Admin\Pages\SettingsPage;
use Convermetry\Admin\Pages\SubmissionsPage;
use Convermetry\Analytics\ReportQueryException;
use Convermetry\Analytics\Reports;
use Convermetry\Database\FormSubmissions;
use Convermetry\Forms\FormProviderRegistry;
use Convermetry\Funnels\FunnelRepository;
use Convermetry\Funnels\FunnelSettings;
use Convermetry\Goals\GoalRepository;
use Convermetry\Goals\GoalSettings;
use Convermetry\Settings\Options;
use Convermetry\Webhook\AnalyticsDispatcher;
use Convermetry\Webhook\DeliveryLog;
use Convermetry\Webhook\FormDeliveryQueue;

/**
 * What the Home page knows about this installation.
 *
 * Every value the Home page displays comes from here, and every one of them is
 * observed rather than assumed. That constraint is the whole point of the
 * class: a welcome screen is the one place where inventing a plausible number
 * is easiest and most damaging, because the person reading it has no other
 * basis yet for judging whether the plugin works.
 *
 * So:
 *
 *  - A FAILED READ IS NOT A ZERO. Counts are `?int`, and the analytics reads go
 *    through {@see Reports}, which throws rather than letting $wpdb's null pass
 *    for "nothing happened". An unknown value renders as "—", never as 0.
 *  - NOTHING-YET IS NOT AN ERROR. A site with no webhooks, no submissions and
 *    no recorded events is a new site, not a broken one, and reads Neutral.
 *  - THE SETUP CHECKLIST TICKS ITSELF OFF ONLY ON EVIDENCE. See
 *    {@see HomeSetupStep}.
 *
 * The reads are deliberately cheap, because this page loads on every click of
 * the top-level menu item: six queries in total, none of them unbounded. Two
 * single-row probes (does any event exist, when was the last submission), two
 * COUNT(*)s over small operational tables (delivery queue, recent failures),
 * one COUNT(*) on submissions, and one COUNT(DISTINCT session_id) bounded to
 * seven days by the created_at index. Nothing here loads a data set to
 * summarize it, and nothing is cached, so what the page says is what is
 * currently true. The Goals and Funnels cards add no query of their own: they
 * read {@see GoalRepository::visible()} and {@see FunnelRepository::visible()},
 * each a single already-memoized option read, never {@see \Convermetry\Analytics\GoalReports}
 * or {@see \Convermetry\Analytics\FunnelReport} — this page reports whether
 * goals and funnels are CONFIGURED, not what they measured, which is the one
 * claim cheap enough to make on every menu click.
 *
 * The decision methods are static and take plain facts, with no database and no
 * WordPress state of their own, so the rules about what counts as "Attention
 * Required" can be tested directly rather than through a mock of $wpdb.
 */
final class HomeStatus
{
    /** Window for the at-a-glance session count, in days. */
    private const int GLANCE_DAYS = 7;

    /** How far back a failed delivery still counts as "recent", in days. */
    private const int FAILURE_WINDOW_DAYS = 7;

    /** Providers named individually before the description switches to a count. */
    private const int PROVIDERS_NAMED = 3;

    private bool $read = false;

    /** @var int|null Distinct sessions in the last GLANCE_DAYS; null when unknown. */
    private ?int $sessions = null;

    /** @var int|null Total stored submissions; null when unknown. */
    private ?int $submissions = null;

    /** @var string|null UTC datetime of the newest submission; null when none/unknown. */
    private ?string $latestSubmissionAt = null;

    /** @var int|null Rows waiting in the delivery queue; null when unknown. */
    private ?int $pendingDeliveries = null;

    /** @var int|null Failed deliveries inside the failure window; null when unknown. */
    private ?int $recentFailures = null;

    /** @var bool|null Whether any analytics event has ever been recorded; null when unknown. */
    private ?bool $hasEvents = null;

    /**
     * @param FormProviderRegistry|null $registry The shared provider registry,
     *                                            or null when the plugin did
     *                                            not hand one over.
     */
    private function __construct(private readonly ?FormProviderRegistry $registry)
    {
    }

    /**
     * A snapshot bound to the shared form-provider registry.
     *
     * @param FormProviderRegistry|null $registry The shared provider registry.
     * @return self
     */
    public static function create(?FormProviderRegistry $registry = null): self
    {
        return new self($registry);
    }

    // ----------------------------------------------------------- the page's data

    /**
     * The four figures in the hero's "At a glance" panel.
     *
     * @return array<int, array{label: string, value: string, isFigure: bool}>
     */
    public function glance(): array
    {
        $this->load();

        return [
            [
                'label'    => sprintf(
                    /* translators: %d: number of days the session count covers. */
                    _n('Sessions (%d day)', 'Sessions (%d days)', self::GLANCE_DAYS, 'convermetry'),
                    self::GLANCE_DAYS
                ),
                'value'    => self::figure($this->sessions),
                'isFigure' => true,
            ],
            [
                'label'    => __('Submissions', 'convermetry'),
                'value'    => self::figure($this->submissions),
                'isFigure' => true,
            ],
            [
                'label'    => __('Delivery queue', 'convermetry'),
                'value'    => self::figure($this->pendingDeliveries),
                'isFigure' => true,
            ],
            [
                'label'    => __('Latest submission', 'convermetry'),
                'value'    => self::elapsed($this->latestSubmissionAt),
                'isFigure' => false,
            ],
        ];
    }

    /**
     * The eight cards of the "Convermetry Status" grid, in display order.
     *
     * @return list<HomeStatusItem>
     */
    public function items(): array
    {
        $this->load();

        $goals   = GoalRepository::visible();
        $funnels = FunnelRepository::visible();

        return [
            $this->analyticsTracking(),
            self::formTrackingState($this->availableProviderNames()),
            self::webhookDeliveryState(
                count(Options::formEndpoints()),
                Options::webhooksActive(),
                $this->recentFailures
            ),
            self::backgroundProcessingState(
                wp_next_scheduled('cvmtry_cleanup_old_events') !== false,
                $this->pendingDeliveries === null
                    || $this->pendingDeliveries === 0
                    || wp_next_scheduled(FormDeliveryQueue::WORKER_HOOK) !== false,
                !self::reportDispatchExpected() || wp_next_scheduled(AnalyticsDispatcher::CRON_HOOK) !== false,
                defined('DISABLE_WP_CRON') && DISABLE_WP_CRON === true
            ),
            HomeStatusItem::measurement(
                __('Latest Submission', 'convermetry'),
                self::elapsed($this->latestSubmissionAt),
                __('Time since the most recent captured form submission.', 'convermetry')
            ),
            self::deliveryQueueState($this->pendingDeliveries),
            self::goalsState(
                count(array_filter($goals, GoalSettings::isActive(...))),
                count($goals),
                Options::goalsEnabled()
            ),
            self::funnelsState(
                count(array_filter($funnels, FunnelSettings::isActive(...))),
                count($funnels)
            ),
        ];
    }

    /**
     * The state of analytics tracking, and the pill shown in the hero panel.
     *
     * @return HomeStatusItem
     */
    public function analyticsTracking(): HomeStatusItem
    {
        $this->load();

        return self::analyticsTrackingState(Options::enabledTypes() !== [], $this->hasEvents);
    }

    /**
     * The Getting Started steps, with the first incomplete one marked current.
     *
     * @return list<HomeSetupStep>
     */
    public function steps(): array
    {
        $this->load();

        $settingsSaved = get_option(Options::OPTION_KEY, false) !== false;

        return self::markNextStep([
            new HomeSetupStep(
                __('Configure Convermetry', 'convermetry'),
                __('Review the plugin settings and configure the options appropriate for your website.', 'convermetry'),
                __('Open Settings', 'convermetry'),
                self::urlFor(SettingsPage::MENU_SLUG, Capability::SETTINGS_MANAGE),
                $settingsSaved
            ),
            new HomeSetupStep(
                __('Connect Your Forms', 'convermetry'),
                __('Verify that your supported WordPress forms are connected so Convermetry can capture submission and attribution data.', 'convermetry'),
                __('View Form Integrations', 'convermetry'),
                self::urlFor(FormsPage::MENU_SLUG, Capability::FORMS_MANAGE),
                $this->availableProviderNames() !== []
            ),
            new HomeSetupStep(
                __('Verify Tracking', 'convermetry'),
                __('Confirm that Convermetry is recording website activity and attribution information correctly.', 'convermetry'),
                __('View Analytics', 'convermetry'),
                self::urlFor(AnalyticsPage::MENU_SLUG, Capability::ANALYTICS_VIEW),
                $this->hasEvents === true
            ),
            new HomeSetupStep(
                __('Review Your Leads', 'convermetry'),
                __('Once visitors begin submitting forms, review captured submissions, attribution information, and delivery status from the Convermetry dashboard.', 'convermetry'),
                __('View Submissions', 'convermetry'),
                self::urlFor(SubmissionsPage::MENU_SLUG, Capability::SUBMISSIONS_VIEW),
                $this->latestSubmissionAt !== null
            ),
        ]);
    }

    // ------------------------------------------------------------- the decisions

    /**
     * Whether analytics tracking is on, and whether it has produced anything.
     *
     * "Enabled but nothing recorded yet" is deliberately Neutral rather than
     * green: on a brand-new install that is the honest answer, and calling it
     * Active would be the plugin vouching for tracking it has not seen work.
     *
     * @param bool      $trackingEnabled Whether any event type is enabled.
     * @param bool|null $hasEvents       Whether any event exists; null when unknown.
     * @return HomeStatusItem
     */
    public static function analyticsTrackingState(bool $trackingEnabled, ?bool $hasEvents): HomeStatusItem
    {
        if (!$trackingEnabled) {
            return HomeStatusItem::state(
                __('Analytics Tracking', 'convermetry'),
                HomeStatusLevel::Warning,
                __('Not Tracking', 'convermetry'),
                __('Every tracked interaction type is switched off under Settings, so no website activity is being recorded.', 'convermetry')
            );
        }

        if ($hasEvents === null) {
            return HomeStatusItem::state(
                __('Analytics Tracking', 'convermetry'),
                HomeStatusLevel::Neutral,
                __('Unavailable', 'convermetry'),
                __('Tracking is enabled. Convermetry could not read the events table to confirm activity.', 'convermetry')
            );
        }

        if (!$hasEvents) {
            return HomeStatusItem::state(
                __('Analytics Tracking', 'convermetry'),
                HomeStatusLevel::Neutral,
                __('Awaiting Data', 'convermetry'),
                __('Tracking is enabled. No website activity has been recorded yet.', 'convermetry')
            );
        }

        return HomeStatusItem::state(
            __('Analytics Tracking', 'convermetry'),
            HomeStatusLevel::Success,
            __('Active', 'convermetry'),
            __('Convermetry is collecting website analytics.', 'convermetry')
        );
    }

    /**
     * Whether any supported form plugin is active on this site.
     *
     * No provider is not a fault — a site can record submissions through the
     * developer API alone — so the empty case is Neutral and says so.
     *
     * @param list<string> $providerNames Names of the available providers.
     * @return HomeStatusItem
     */
    public static function formTrackingState(array $providerNames): HomeStatusItem
    {
        if ($providerNames === []) {
            return HomeStatusItem::state(
                __('Form Tracking', 'convermetry'),
                HomeStatusLevel::Neutral,
                __('No Providers Detected', 'convermetry'),
                __('No supported form plugin is active. Custom forms can still be recorded through the Convermetry developer API.', 'convermetry')
            );
        }

        $more  = count($providerNames) - self::PROVIDERS_NAMED;
        $named = $more <= 0
            ? implode(', ', $providerNames)
            : sprintf(
                /* translators: 1: comma-separated list of form plugin names, 2: number of further form plugins not named. */
                _n('%1$s and %2$d more', '%1$s and %2$d more', $more, 'convermetry'),
                implode(', ', array_slice($providerNames, 0, self::PROVIDERS_NAMED)),
                $more
            );

        return HomeStatusItem::state(
            __('Form Tracking', 'convermetry'),
            HomeStatusLevel::Success,
            __('Active', 'convermetry'),
            sprintf(
                /* translators: %s: list of form plugin names. */
                __('Supported form providers are available for submission tracking: %s.', 'convermetry'),
                $named
            )
        );
    }

    /**
     * The state of outbound webhook delivery.
     *
     * @param int      $endpointCount   Endpoints configured to receive submissions.
     * @param bool     $deliveryActive  Whether the webhook master switch is on.
     * @param int|null $recentFailures  Failed deliveries in the recent window; null when unknown.
     * @return HomeStatusItem
     */
    public static function webhookDeliveryState(
        int $endpointCount,
        bool $deliveryActive,
        ?int $recentFailures,
    ): HomeStatusItem {
        if ($endpointCount < 1) {
            return HomeStatusItem::state(
                __('Webhook Delivery', 'convermetry'),
                HomeStatusLevel::Neutral,
                __('Not Configured', 'convermetry'),
                __('No webhook destinations have been set up yet. Add one to send submissions onward.', 'convermetry')
            );
        }

        if (!$deliveryActive) {
            return HomeStatusItem::state(
                __('Webhook Delivery', 'convermetry'),
                HomeStatusLevel::Warning,
                __('Paused', 'convermetry'),
                __('Endpoints are configured, but webhook delivery is switched off, so nothing is being sent.', 'convermetry')
            );
        }

        if ($recentFailures !== null && $recentFailures > 0) {
            return HomeStatusItem::state(
                __('Webhook Delivery', 'convermetry'),
                HomeStatusLevel::Warning,
                __('Attention Required', 'convermetry'),
                sprintf(
                    /* translators: %d: number of failed webhook deliveries. */
                    _n(
                        '%d recent delivery did not succeed. Review the Activity Log and retry.',
                        '%d recent deliveries did not succeed. Review the Activity Log and retry.',
                        $recentFailures,
                        'convermetry'
                    ),
                    $recentFailures
                )
            );
        }

        return HomeStatusItem::state(
            __('Webhook Delivery', 'convermetry'),
            HomeStatusLevel::Success,
            __('Configured', 'convermetry'),
            sprintf(
                /* translators: %d: number of webhook endpoints. */
                _n(
                    '%d endpoint is configured to receive form submissions.',
                    '%d endpoints are configured to receive form submissions.',
                    $endpointCount,
                    'convermetry'
                ),
                $endpointCount
            )
        );
    }

    /**
     * The state of conversion goal configuration.
     *
     * Deliberately stops at "enabled". Confirming that an enabled goal has
     * ever actually matched anything would mean running a goal report on
     * every Home page load — the one thing this card must not do — so unlike
     * {@see analyticsTrackingState()} there is no "Active" tier here that
     * claims completions are being recorded, only that the configuration
     * would allow it.
     *
     * @param int  $enabledCount    Visible goals with their own switch on.
     * @param int  $visibleCount    Every non-deleted goal, on or off.
     * @param bool $matchingEnabled Whether the global goal-matching switch
     *                              ({@see Options::goalsEnabled()}) is on.
     * @return HomeStatusItem
     */
    public static function goalsState(int $enabledCount, int $visibleCount, bool $matchingEnabled): HomeStatusItem
    {
        if ($visibleCount < 1) {
            return HomeStatusItem::state(
                __('Goals', 'convermetry'),
                HomeStatusLevel::Neutral,
                __('Not Configured', 'convermetry'),
                __('No goals have been defined yet. Measure valuable actions such as phone clicks, booking clicks, and visits to key pages.', 'convermetry')
            );
        }

        if (!$matchingEnabled) {
            return HomeStatusItem::state(
                __('Goals', 'convermetry'),
                HomeStatusLevel::Warning,
                __('Paused', 'convermetry'),
                sprintf(
                    /* translators: %d: number of goals. */
                    _n(
                        '%d goal is defined, but goal matching is switched off in Settings, so none of them are being recorded.',
                        '%d goals are defined, but goal matching is switched off in Settings, so none of them are being recorded.',
                        $visibleCount,
                        'convermetry'
                    ),
                    $visibleCount
                )
            );
        }

        if ($enabledCount < 1) {
            return HomeStatusItem::state(
                __('Goals', 'convermetry'),
                HomeStatusLevel::Warning,
                __('Paused', 'convermetry'),
                sprintf(
                    /* translators: %d: number of goals. */
                    _n(
                        '%d goal is defined, but every one of them is currently disabled.',
                        '%d goals are defined, but every one of them is currently disabled.',
                        $visibleCount,
                        'convermetry'
                    ),
                    $visibleCount
                )
            );
        }

        return HomeStatusItem::state(
            __('Goals', 'convermetry'),
            HomeStatusLevel::Success,
            __('Enabled', 'convermetry'),
            sprintf(
                /* translators: 1: number of enabled goals, 2: total number of goals. */
                _n(
                    '%1$d of %2$d goal is enabled to measure valuable visitor actions.',
                    '%1$d of %2$d goals are enabled to measure valuable visitor actions.',
                    $visibleCount,
                    'convermetry'
                ),
                $enabledCount,
                $visibleCount
            )
        );
    }

    /**
     * The state of funnel configuration.
     *
     * A funnel has no matching switch of its own — see
     * {@see \Convermetry\Funnels\FunnelRepository}'s class docblock — it is a
     * question asked of activity already being recorded, not something that
     * is itself recorded. So unlike {@see goalsState()} there is no "global
     * switch is off" tier here; enabled-but-empty and disabled are the only
     * two ways a configured funnel falls short of reporting.
     *
     * @param int $enabledCount Visible funnels with their own switch on.
     * @param int $visibleCount Every non-deleted funnel, on or off.
     * @return HomeStatusItem
     */
    public static function funnelsState(int $enabledCount, int $visibleCount): HomeStatusItem
    {
        if ($visibleCount < 1) {
            return HomeStatusItem::state(
                __('Funnels', 'convermetry'),
                HomeStatusLevel::Neutral,
                __('Not Configured', 'convermetry'),
                __('No funnels have been defined yet. Build one to see how visitors move through a sequence of steps and where they drop off.', 'convermetry')
            );
        }

        if ($enabledCount < 1) {
            return HomeStatusItem::state(
                __('Funnels', 'convermetry'),
                HomeStatusLevel::Warning,
                __('Paused', 'convermetry'),
                sprintf(
                    /* translators: %d: number of funnels. */
                    _n(
                        '%d funnel is defined, but every one of them is currently disabled.',
                        '%d funnels are defined, but every one of them is currently disabled.',
                        $visibleCount,
                        'convermetry'
                    ),
                    $visibleCount
                )
            );
        }

        return HomeStatusItem::state(
            __('Funnels', 'convermetry'),
            HomeStatusLevel::Success,
            __('Enabled', 'convermetry'),
            sprintf(
                /* translators: 1: number of enabled funnels, 2: total number of funnels. */
                _n(
                    '%1$d of %2$d funnel is enabled to report on visitor drop-off.',
                    '%1$d of %2$d funnels are enabled to report on visitor drop-off.',
                    $visibleCount,
                    'convermetry'
                ),
                $enabledCount,
                $visibleCount
            )
        );
    }

    /**
     * The state of the scheduled work Convermetry does unattended.
     *
     * Each input is a specific scheduled thing being where it should be. WP-Cron
     * being disabled is reported but not faulted: a site running cron from the
     * system scheduler is correctly configured, and colouring that red would
     * teach owners to ignore this card.
     *
     * @param bool $retentionScheduled  The daily retention/cleanup event exists.
     * @param bool $queueWorkerHealthy  Nothing is waiting, or a worker is scheduled for it.
     * @param bool $reportDispatchHealthy No analytics reports are due, or their event exists.
     * @param bool $wpCronDisabled      DISABLE_WP_CRON is on for this site.
     * @return HomeStatusItem
     */
    public static function backgroundProcessingState(
        bool $retentionScheduled,
        bool $queueWorkerHealthy,
        bool $reportDispatchHealthy,
        bool $wpCronDisabled,
    ): HomeStatusItem {
        // Whole sentences rather than clauses joined with ", and": a list
        // assembled from fragments cannot be translated grammatically.
        $problems = [];

        if (!$retentionScheduled) {
            $problems[] = __('The daily data-retention task is not scheduled.', 'convermetry');
        }

        if (!$queueWorkerHealthy) {
            $problems[] = __('Submissions are waiting in the delivery queue with no worker scheduled.', 'convermetry');
        }

        if (!$reportDispatchHealthy) {
            $problems[] = __('Scheduled analytics report delivery is not scheduled.', 'convermetry');
        }

        $cronNote = $wpCronDisabled
            ? ' ' . __('WP-Cron is disabled on this site, so these run from your server\'s own scheduler.', 'convermetry')
            : '';

        if ($problems !== []) {
            return HomeStatusItem::state(
                __('Background Processing', 'convermetry'),
                HomeStatusLevel::Warning,
                __('Attention Required', 'convermetry'),
                implode(' ', $problems) . $cronNote
            );
        }

        return HomeStatusItem::state(
            __('Background Processing', 'convermetry'),
            HomeStatusLevel::Success,
            __('Running Normally', 'convermetry'),
            __('Scheduled Convermetry processing tasks are operating normally.', 'convermetry') . $cronNote
        );
    }

    /**
     * How many submissions are waiting to be delivered.
     *
     * @param int|null $pending Rows in the queue; null when unknown.
     * @return HomeStatusItem
     */
    public static function deliveryQueueState(?int $pending): HomeStatusItem
    {
        return HomeStatusItem::measurement(
            __('Delivery Queue', 'convermetry'),
            sprintf(
                /* translators: %s: number of submissions waiting to be delivered, or a dash when unknown. */
                __('%s pending', 'convermetry'),
                self::figure($pending)
            ),
            __('Submissions waiting to be delivered to external systems.', 'convermetry')
        );
    }

    /**
     * Marks the first incomplete step as the current one.
     *
     * Exactly one step is ever current, and a fully complete list has none —
     * there is no "next" left to point at.
     *
     * @param list<HomeSetupStep> $steps The steps in order.
     * @return list<HomeSetupStep>
     */
    public static function markNextStep(array $steps): array
    {
        $marked = false;
        $out    = [];

        foreach ($steps as $step) {
            if (!$marked && !$step->complete) {
                $out[]  = $step->asCurrent();
                $marked = true;

                continue;
            }

            $out[] = $step;
        }

        return $out;
    }

    /**
     * How many of the steps are done.
     *
     * @param list<HomeSetupStep> $steps The steps.
     * @return int
     */
    public static function completedCount(array $steps): int
    {
        return count(array_filter($steps, static fn(HomeSetupStep $step): bool => $step->complete));
    }

    // --------------------------------------------------------------- formatting

    /**
     * A count as display text; an unknown count as an em dash.
     *
     * @param int|null $value The count, or null when the read failed.
     * @return string
     */
    public static function figure(?int $value): string
    {
        return $value === null ? '—' : number_format_i18n($value);
    }

    /**
     * How long ago something happened, from a stored UTC datetime.
     *
     * @param string|null $utcDatetime 'Y-m-d H:i:s' in UTC, or null.
     * @return string
     */
    public static function elapsed(?string $utcDatetime): string
    {
        if ($utcDatetime === null) {
            return __('None yet', 'convermetry');
        }

        $timestamp = strtotime($utcDatetime . ' UTC');
        if ($timestamp === false) {
            return '—';
        }

        return sprintf(
            /* translators: %s: a human-readable time span, such as "5 mins". */
            __('%s ago', 'convermetry'),
            human_time_diff($timestamp, time())
        );
    }

    // ------------------------------------------------------------------- reading

    /**
     * Runs every read once, converting a failure into "unknown" rather than
     * into a zero.
     *
     * @return void
     */
    private function load(): void
    {
        if ($this->read) {
            return;
        }

        $this->read = true;

        $end   = gmdate('Y-m-d H:i:s');
        $start = gmdate('Y-m-d H:i:s', time() - self::GLANCE_DAYS * DAY_IN_SECONDS);

        // Analytics reads go through the layer that throws on a failed query,
        // so a database error is reported as unknown instead of as "no traffic".
        try {
            $this->sessions  = Reports::sessionCount($start, $end);
            $this->hasEvents = Reports::hasEvents();
        } catch (ReportQueryException) {
            $this->sessions  = null;
            $this->hasEvents = null;
        }

        $this->submissions        = self::countOrNull(static fn(): int => FormSubmissions::getCount());
        $this->latestSubmissionAt = FormSubmissions::latestCreatedAt();
        $this->pendingDeliveries  = self::countOrNull(static fn(): int => FormDeliveryQueue::pendingCount());
        $this->recentFailures     = self::countOrNull(static fn(): int => DeliveryLog::getLogCount([
            'status'       => 'error',
            'created_from' => gmdate('Y-m-d H:i:s', time() - self::FAILURE_WINDOW_DAYS * DAY_IN_SECONDS),
        ]));
    }

    /**
     * Runs one count, reporting a database error as unknown.
     *
     * These repositories predate {@see \Convermetry\Analytics\ReportQuery} and
     * return 0 for a failed query, so the error has to be read off $wpdb here.
     *
     * @param callable(): int $count The read.
     * @return int|null
     */
    private static function countOrNull(callable $count): ?int
    {
        global $wpdb;

        $value = $count();

        return isset($wpdb->last_error) && $wpdb->last_error !== '' ? null : $value;
    }

    /**
     * Names of the form providers whose plugin is active on this site.
     *
     * @return list<string>
     */
    private function availableProviderNames(): array
    {
        if ($this->registry === null) {
            return [];
        }

        $names = [];
        foreach ($this->registry->all() as $provider) {
            // isAvailable() is the same feature detection the submission hooks
            // are gated on, so this reports the providers that would actually
            // record a submission — not the ones Convermetry ships adapters for.
            if ($provider->isAvailable()) {
                $names[] = $provider->getLabel();
            }
        }

        sort($names);

        return $names;
    }

    /**
     * Whether this site expects the scheduled analytics report event to exist.
     *
     * @return bool
     */
    private static function reportDispatchExpected(): bool
    {
        return Options::webhooksActive() && Options::analyticsEndpoints() !== [];
    }

    /**
     * An admin URL for one Convermetry screen, or '' when the current user may
     * not open it — so the page never offers a link that would refuse them.
     *
     * @param string $slug  The screen's menu slug.
     * @param string $scope The capability scope guarding it.
     * @return string
     */
    public static function urlFor(string $slug, string $scope): string
    {
        if (!Capability::currentUserCan($scope)) {
            return '';
        }

        return add_query_arg(['page' => $slug], self_admin_url('admin.php'));
    }
}
