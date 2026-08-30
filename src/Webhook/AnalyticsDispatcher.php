<?php
declare(strict_types=1);

namespace Convermetry\Webhook;

if (!defined('ABSPATH')) exit;

use Convermetry\Analytics\ReportQueryException;
use Convermetry\Analytics\Reports;
use Convermetry\Settings\Options;
use Convermetry\Support\Http;

/**
 * Sends aggregated analytics reports to every endpoint whose delivery types
 * include Analytics Reports, as JSON, on the configured schedule.
 *
 * A single cron event (cvm_dispatch_webhooks) fires at the interval chosen
 * on the Webhooks page. On each run, every analytics endpoint receives a
 * POST whose payload covers the window since that endpoint's last successful
 * delivery — last-sent timestamps are tracked per endpoint, so adding a new
 * endpoint or a transient failure at one endpoint never skews the data
 * another receives. A gap materially longer than one interval (downtime, a
 * paused toggle, or a backfilled first send) is delivered as consecutive
 * interval-sized windows rather than one coarse catch-all window.
 *
 * Retry handling:
 *  When a delivery fails (transport error or a non-2xx status), the exact
 *  JSON body that failed is FROZEN — serialized and stored with the retry
 *  state, together with the final request URL and headers — and retried up
 *  to 5 more times over about 24 hours (5m, 30m, 2h, 6h, 16h) via
 *  single-event crons on the cvm_retry_webhook hook. Every attempt re-sends
 *  the stored bytes under the same delivery_id; retention cleanup, settings
 *  changes, or plugin updates between attempts can never alter the payload.
 *  Scheduled runs skip an endpoint while its chain is actively pending.
 *
 *  If the chain is exhausted — or a retry cron could not be scheduled — the
 *  frozen delivery is NOT discarded: it is kept in an "exhausted" state and
 *  the next scheduled dispatch re-sends that exact body under the same
 *  delivery_id (restarting the chain on another failure). Only after the
 *  frozen delivery is acknowledged does the endpoint advance to newer
 *  events, so consecutive deliveries never cover overlapping windows and
 *  any true duplicate always carries a delivery_id the receiver has already
 *  seen. Delivery is therefore at-least-once, and deduplicating by
 *  delivery_id (also sent as an Idempotency-Key header) is sufficient to
 *  never double-count.
 *
 *  Frozen deliveries do not live forever: a chain whose delivery was frozen
 *  longer ago than the data retention window is dropped by the next dispatch
 *  run, and the Webhooks page offers an explicit Discard action per pending
 *  retry.
 *
 *  Every mutation of the shared retry-state option happens while holding the
 *  dispatch mutex. A retry that cannot acquire the lock only re-schedules
 *  its cron event and touches nothing else; if even that fails, the chain is
 *  detected as ORPHANED by the next dispatch run (state pending, but no cron
 *  event scheduled and well past due) and resumed exactly like an exhausted
 *  one — under the same frozen bytes and delivery_id.
 *
 * Delivery scatter:
 *  The recurring cron is anchored at a random offset within the send
 *  interval (capped at 24 hours) rather than at the moment of scheduling, so
 *  many sites running this plugin against one shared endpoint deliver at
 *  different, stable times instead of stampeding it simultaneously.
 *
 * Concurrency:
 *  Every dispatch (and every retry) runs under a site-wide mutex (see
 *  {@see acquireLock()}): overlapping cron executions can never read the
 *  same last-sent marker and build overlapping windows under different
 *  delivery IDs. A run that cannot get the lock simply yields.
 *
 * Every delivery attempt — scheduled, retry, or test — is recorded in the
 * {@see DeliveryLog} (Activity Log) with a redacted copy of the payload and the
 * response received.
 */
final class AnalyticsDispatcher
{
    /** Cron hook name for scheduled dispatch. */
    public const string CRON_HOOK = 'cvm_dispatch_webhooks';

    /** Cron hook name for single-event delivery retries. */
    public const string RETRY_HOOK = 'cvm_retry_webhook';

    /**
     * Default delay before each retry attempt, in seconds:
     * 5 minutes, 30 minutes, 2 hours, 6 hours, 16 hours (~24.6 hours total).
     * Filterable via 'convermetry_retry_schedule'.
     *
     * @var int[]
     */
    private const array RETRY_DELAYS = [300, 1800, 7200, 21600, 57600];

    /** Option key mapping md5(endpoint URL) → last-success unix timestamp. */
    private const string LAST_SENT_OPTION = 'cvm_webhook_last_sent';

    /** Option key mapping md5(endpoint URL) → pending retry state. */
    private const string RETRY_STATE_OPTION = 'cvm_webhook_retry_state';

    /** Option key for the fallback dispatch mutex (when GET_LOCK is unavailable). */
    private const string LOCK_OPTION = 'cvm_webhook_dispatch_lock';

    /**
     * Transient key throttling repeated "report query failed" error_log()
     * calls, so a sustained database outage doesn't write the same
     * diagnostic on every cron tick.
     */
    private const string REPORT_FAILURE_LOG_FLAG = 'cvm_report_query_failure_logged';

    /**
     * Sanitized message used for the Activity Log when a report query fails.
     * Never the raw $wpdb->last_error text — that can incidentally include
     * table/column names or query fragments not meant for an admin-facing or
     * receiver-adjacent surface; the raw message goes only to error_log().
     */
    private const string REPORT_FAILURE_MESSAGE = 'Report data could not be generated (database error)';

    /**
     * Seconds after which a fallback option lock's LEASE is considered stale
     * and the lock may be stolen. Healthy runs renew their lease between
     * delivery windows, so even a run that legally exceeds this duration is
     * never mistaken for dead.
     */
    private const int LOCK_TIMEOUT = 15 * MINUTE_IN_SECONDS;

    /**
     * Maximum individual conversions per delivery. A window holding more is
     * SHRUNK to end at the overflowing conversion (see freezeDelivery()), so
     * the overflow becomes the next delivery's window instead of being
     * silently dropped — conversion delivery is lossless.
     */
    private const int MAX_CONVERSIONS_PER_DELIVERY = 100;

    /** Maximum consecutive windows one endpoint may send per dispatch run, bounding catch-up work. */
    private const int MAX_WINDOWS_PER_RUN = 10;

    /**
     * Maximum rows per "top_*" list in the webhook payload (filterable via
     * 'convermetry_webhook_report_limit'). Deliberately much deeper than the
     * dashboard's top 10: a receiver aggregating deliveries long-term needs
     * (near-)complete dimension rankings.
     */
    private const int REPORT_ROW_LIMIT = 200;

    /**
     * Registers the cron callbacks and the settings-change listener.
     *
     * @return void
     */
    public static function init(): void
    {
        add_action(self::CRON_HOOK, [self::class, 'dispatch']);
        add_action(self::RETRY_HOOK, [self::class, 'retry'], 10, 1);
        add_action('update_option_' . Options::WEBHOOK_OPTION_KEY, [self::class, 'onSettingsSaved'], 10, 2);
    }

    /**
     * Schedules the dispatch cron event if it is not already scheduled.
     *
     * Called on activation and as a safety net on every load. The first run
     * is offset by {@see scatterOffset()}; WordPress anchors every
     * subsequent recurrence to that timestamp, so the scattered send time is
     * stable for the lifetime of the schedule.
     *
     * @return void
     */
    public static function schedule(): void
    {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + self::scatterOffset(), Options::webhookInterval(), self::CRON_HOOK);
        }
    }

    /**
     * Re-schedules the cron event when the admin changes the send interval.
     *
     * Hooked to the webhook option's update hook, which only fires when the
     * stored value actually changed. The new schedule gets a fresh random
     * anchor so fleets reconfigured together do not end up synchronized.
     *
     * @param mixed $oldValue Previous settings array.
     * @param mixed $newValue New settings array.
     * @return void
     */
    public static function onSettingsSaved(mixed $oldValue, mixed $newValue): void
    {
        $oldInterval = is_array($oldValue) ? ($oldValue['interval'] ?? 'daily') : 'daily';
        $newInterval = is_array($newValue) ? ($newValue['interval'] ?? 'daily') : 'daily';

        if ($oldInterval === $newInterval) {
            return;
        }

        wp_clear_scheduled_hook(self::CRON_HOOK);
        wp_schedule_event(time() + self::scatterOffset(), Options::webhookInterval(), self::CRON_HOOK);
    }

    /**
     * Random offset applied to the schedule anchor, in seconds.
     *
     * Many independent sites with the same interval and endpoint would
     * otherwise deliver in near-synchrony. Anchoring each site's schedule at
     * a random point within the interval (at least one minute out, capped at
     * 24 hours so a weekly report is not delayed most of a week) breaks the
     * herd while keeping each site's send time stable.
     *
     * @return int Seconds between 60 and min(interval, 24h).
     */
    private static function scatterOffset(): int
    {
        $window = min(Options::intervalSeconds(Options::webhookInterval()), DAY_IN_SECONDS);

        return wp_rand(MINUTE_IN_SECONDS, max(2 * MINUTE_IN_SECONDS, $window));
    }

    /**
     * Cron callback: sends analytics to every configured analytics endpoint.
     *
     * Endpoints with an actively pending retry chain are skipped — the chain
     * owns its frozen delivery. An endpoint whose chain was exhausted
     * resumes here: the frozen body is re-sent under its original
     * delivery_id first, and only once it is acknowledged does a fresh
     * window (starting exactly at the frozen window's end) go out.
     *
     * Skipped entirely while the "Webhook Status" setting is inactive.
     *
     * @return void
     */
    public static function dispatch(): void
    {
        // Piggyback: recover any orphaned form-delivery queue work whenever
        // the analytics cron fires, so a lost single-event worker cron can
        // never strand queued form submissions for long.
        FormDeliveryQueue::ensureWorkerScheduled();

        if (!Options::webhooksActive()) {
            return;
        }

        $urls = Options::analyticsEndpointUrls();
        if ($urls === []) {
            return;
        }

        // Only one dispatch may run at a time: two overlapping runs would
        // read the same last-sent marker and deliver overlapping windows
        // under different delivery IDs, breaking the dedup guarantee.
        $lock = self::acquireLock();
        if ($lock === null) {
            return;
        }

        try {
            self::dispatchLocked($urls, $lock);
        } finally {
            self::releaseLock($lock);
        }
    }

    /**
     * The body of {@see dispatch()}, run while holding the dispatch mutex.
     *
     * The delivery horizon — the wall-clock moment this run stops reporting
     * at — is captured ONCE, before the endpoint loop, so caught-up
     * endpoints share identical windows and one cached summary instead of
     * re-running every aggregate query per endpoint.
     *
     * @param string[] $urls Configured analytics endpoint URLs.
     * @param string   $lock The held dispatch lock, renewed between windows.
     * @return void
     */
    private static function dispatchLocked(array $urls, string $lock): void
    {
        self::pruneStaleState($urls);

        $horizon = time();

        foreach ($urls as $url) {
            $state = self::retryStateFor($url);

            if ($state !== null) {
                if (self::retryChainActive($url, $state)) {
                    continue; // An active retry chain owns this endpoint.
                }

                // Resume the exhausted (or orphaned) chain: same frozen
                // bytes, same id.
                self::renewLock($lock);
                $delivery = self::deliveryFromState($url, $state);

                if (!self::attemptDelivery($url, $delivery, DeliveryKind::Scheduled, 0)) {
                    self::scheduleRetry($url, 1, $delivery);
                    continue;
                }
                // Acknowledged — last-sent now sits at the frozen window's
                // end, so the fresh window below picks up exactly there.
            }

            // A gap longer than ~1.5 send intervals — a backfilled first
            // send, downtime, or a paused toggle — is worked off in
            // interval-sized windows, so history arrives at the same
            // granularity as live deliveries. The 50% slack matters:
            // WP-Cron always drifts a little past the interval, and capping
            // strictly at one interval would make every routine run emit a
            // full window plus a sliver covering the drift.
            for ($round = 0; $round < self::MAX_WINDOWS_PER_RUN; $round++) {
                self::renewLock($lock);

                $startTs = self::windowStart($url, $horizon);

                if ($startTs >= $horizon) {
                    break;
                }

                $interval = Options::intervalSeconds(Options::webhookInterval());
                $endTs    = ($horizon - $startTs > $interval + intdiv($interval, 2)) ? $startTs + $interval : $horizon;
                $delivery = self::freezeDelivery($url, $startTs, $endTs);

                if (!self::attemptDelivery($url, $delivery, DeliveryKind::Scheduled, 0)) {
                    self::scheduleRetry($url, 1, $delivery);
                    break;
                }

                if ($delivery->windowEnd >= $horizon) {
                    break; // Caught up — the window was not shrunk.
                }
            }
        }
    }

    /**
     * Cron callback for a single retry attempt against one endpoint.
     *
     * Re-sends the chain's frozen delivery — the identical bytes, URL, and
     * delivery_id as the attempt that failed. Every retry-state mutation
     * happens while holding the dispatch mutex; a retry that finds the lock
     * taken only re-schedules its own cron event and returns.
     *
     * @param string $url The endpoint URL this retry targets.
     * @return void
     */
    public static function retry(string $url): void
    {
        $lock = self::acquireLock();
        if ($lock === null) {
            wp_schedule_single_event(time() + MINUTE_IN_SECONDS, self::RETRY_HOOK, [$url]);
            return;
        }

        try {
            // The endpoint was removed from settings after this retry was
            // scheduled. Checked under the lock, like every state mutation.
            if (!in_array($url, Options::analyticsEndpointUrls(), true)) {
                self::clearRetry($url);
                return;
            }

            $state = self::retryStateFor($url);

            // No stored state means the frozen delivery was already
            // acknowledged (or explicitly discarded) while this cron event
            // was in flight. Building a fresh delivery here would violate
            // the same-bytes/same-delivery-id guarantee — stop instead.
            if ($state === null) {
                return;
            }

            $attempt  = $state->attempt;
            $delivery = self::deliveryFromState($url, $state);

            if (self::attemptDelivery($url, $delivery, DeliveryKind::Retry, $attempt)) {
                return; // Success already cleared the pending retry state.
            }

            if ($attempt < count(self::retryDelays())) {
                self::scheduleRetry($url, $attempt + 1, $delivery);
            } else {
                self::storeRetryState($url, $attempt, 0, $delivery, true);

                // Not "abandoned": the frozen body stays in the retry state and
                // the next scheduled dispatch resumes it, so the window is not
                // lost. Only retention expiry ever discards it.
                DeliveryContext::retryChainExhausted(
                    self::contextFor($url, $delivery, DeliveryKind::Retry, $attempt)
                );
            }
        } finally {
            self::releaseLock($lock);
        }
    }

    /**
     * The effective retry backoff schedule, in seconds.
     *
     * @return int[] At least one entry; each at least 60 seconds.
     */
    public static function retryDelays(): array
    {
        /**
         * Filters the webhook retry backoff schedule (seconds before each
         * retry attempt). Applies to analytics-report retries and
         * form-submission delivery retries alike.
         *
         * @param int[] $delays The default schedule [300, 1800, 7200, 21600, 57600].
         */
        $delays = (array) apply_filters('convermetry_retry_schedule', self::RETRY_DELAYS);
        $delays = array_values(array_map(static fn(mixed $delay): int => max(60, (int) $delay), $delays));

        return $delays !== [] ? $delays : self::RETRY_DELAYS;
    }

    /**
     * Whether an endpoint's retry chain still has a live cron attempt coming.
     *
     * Exhausted chains never do — dispatch resumes them. A chain that claims
     * to be pending but has no retry cron event scheduled and whose due time
     * has clearly passed is ORPHANED and resumed by dispatch exactly like an
     * exhausted one. The grace period covers ordinary WP-Cron lag.
     *
     * @param string     $url   Endpoint URL.
     * @param RetryState $state The endpoint's stored retry state.
     * @return bool
     */
    private static function retryChainActive(string $url, RetryState $state): bool
    {
        if ($state->exhausted) {
            return false;
        }

        if (wp_next_scheduled(self::RETRY_HOOK, [$url]) !== false) {
            return true;
        }

        return $state->scheduledFor > time() - 15 * MINUTE_IN_SECONDS;
    }

    /**
     * Sends an immediate analytics test payload (last 7 days) to ONE
     * endpoint without advancing any last-sent timestamps.
     *
     * Test sends never trigger retries — they exist to answer "does this
     * endpoint work right now?", so a failure is simply logged.
     *
     * @param string $url Endpoint URL to test.
     * @return array{ok: bool, code: int, message: string}
     */
    public static function testEndpoint(string $url): array
    {
        $now   = time();
        $label = Options::endpointLabel($url);

        try {
            $payload = PayloadBuilder::analyticsReport($now - 7 * DAY_IN_SECONDS, $now, self::reportRowLimit());
        } catch (ReportQueryException $e) {
            self::logReportQueryFailure($e);

            $deliveryId = md5($url . '|test|' . $now . '|' . wp_rand());
            $result     = TransportResult::failure(self::REPORT_FAILURE_MESSAGE);

            $logged = DeliveryLog::log(new DeliveryLogEntry(
                result: $result,
                endpointUrl: $url,
                endpointLabel: $label,
                deliveryId: $deliveryId,
                messageType: MessageType::AnalyticsReport,
                kind: DeliveryKind::Test,
            ));

            // No before_send: the report query failed, so nothing was ever
            // composed and nothing reached the wire.
            $context = DeliveryContext::attempted(
                DeliveryDetails::for(
                    $url,
                    messageType: MessageType::AnalyticsReport,
                    kind: DeliveryKind::Test,
                    attempt: 1,
                    deliveryId: $deliveryId,
                    endpointLabel: $label,
                ),
                $result,
                false
            );
            DeliveryContext::attemptLogged($context, $logged);

            return $result->toTestSummary();
        }

        $payload['test'] = true;

        // Unique per test run — tests are never retried, so there is no
        // chain to keep the id stable across.
        $deliveryId             = md5($url . '|test|' . $now . '|' . wp_rand());
        $payload['delivery_id'] = $deliveryId;

        $encoded = wp_json_encode($payload);

        $context = DeliveryDetails::for(
            $url,
            messageType: MessageType::AnalyticsReport,
            kind: DeliveryKind::Test,
            attempt: 1,
            deliveryId: $deliveryId,
            endpointLabel: $label,
        );

        $requestUrl = RequestFactory::buildUrl($url, '', [], [], $context);
        $headers    = RequestFactory::buildHeaders('', [], $context);

        // Never POST an empty body when encoding fails (e.g. a filter
        // introduced an unencodable value) — log the failure instead.
        if (!is_string($encoded) || $encoded === '') {
            $encoded = '';
            $result  = TransportResult::failure('Payload could not be JSON-encoded');
        } else {
            $sendHeaders = RequestFactory::withProtocolHeaders($headers, $url, $encoded, $deliveryId);

            DeliveryContext::beforeSend($context, $requestUrl, $sendHeaders, $encoded);
            $result = Http::postJson($requestUrl, $encoded, $sendHeaders, $context);
        }

        $logged = DeliveryLog::log(new DeliveryLogEntry(
            result: $result,
            endpointUrl: $url,
            endpointLabel: $label,
            deliveryId: $deliveryId,
            messageType: MessageType::AnalyticsReport,
            kind: DeliveryKind::Test,
            requestUrl: $requestUrl,
            requestHeaders: $headers,
            requestData: $encoded,
        ));

        $context = DeliveryContext::attempted($context, $result, $encoded !== '');
        DeliveryContext::attemptLogged($context, $logged);

        // A test advances no bookkeeping, so there is nothing to commit before
        // announcing it, and no retry chain to schedule or exhaust.
        if ($result->ok) {
            DeliveryContext::succeeded($context);
        }

        return $result->toTestSummary();
    }

    /**
     * Deterministic delivery id for one endpoint + exact reporting window.
     *
     * A chain freezes its serialized body, so every attempt — including the
     * resumed attempts after an exhausted chain — re-sends the same bytes
     * under the same id; any different window gets a new id.
     *
     * @param string $url     Endpoint URL.
     * @param int    $startTs Window start as a unix timestamp.
     * @param int    $endTs   Window end as a unix timestamp.
     * @return string 32-character hex id.
     */
    private static function deliveryId(string $url, int $startTs, int $endTs): string
    {
        return md5(home_url() . '|' . $url . '|' . $startTs . '|' . $endTs);
    }

    /**
     * Returns every pending retry, for display on the Webhooks page.
     *
     * The stored payload body is stripped — it can be tens of kilobytes and
     * no UI needs it.
     *
     * @return list<array<string, mixed>>
     */
    public static function getPendingRetries(): array
    {
        return array_values(array_map(
            static fn(RetryState $state): array => $state->toSummaryArray(),
            self::getRetryStates()
        ));
    }

    /**
     * Total number of retry attempts made per failed delivery.
     *
     * @return int
     */
    public static function maxRetries(): int
    {
        return count(self::retryDelays());
    }

    /**
     * Discards one endpoint's pending retry chain — the cron event and the
     * stored frozen delivery — on explicit admin request.
     *
     * The underlying analytics rows are untouched: the endpoint's last-sent
     * marker does not advance, so its next scheduled delivery covers the
     * discarded window's data again, just under a NEW delivery_id. Runs
     * under the dispatch mutex like every other retry-state mutation.
     *
     * @param string $urlKey md5 of the endpoint URL (as keyed in the state map).
     * @return bool True when done; false when the dispatch lock was busy.
     */
    public static function discardRetry(string $urlKey): bool
    {
        $lock = self::acquireLock();
        if ($lock === null) {
            return false;
        }

        try {
            foreach (self::getRetryStates() as $key => $state) {
                if ($key === $urlKey) {
                    self::clearRetry($state->url);
                    break;
                }
            }

            return true;
        } finally {
            self::releaseLock($lock);
        }
    }

    /**
     * Unschedules every pending retry cron event but KEEPS the frozen
     * delivery state, marking each chain exhausted.
     *
     * Called on plugin deactivation. Discarding the state instead would
     * break the delivery_id guarantee: kept as exhausted, the first
     * scheduled dispatch after reactivation re-sends the frozen bytes under
     * the original id, so duplicates remain deduplicable.
     *
     * When the mutex is busy (a dispatch run is mid-flight during
     * deactivation), the mark is simply skipped: a chain whose cron events
     * vanished is detected as ORPHANED by the first dispatch after
     * reactivation and resumed under the same frozen bytes and delivery_id
     * anyway.
     *
     * @return void
     */
    public static function suspendAllRetries(): void
    {
        wp_unschedule_hook(self::RETRY_HOOK);

        $lock = self::acquireLock();
        if ($lock === null) {
            return;
        }

        try {
            $states = self::getRetryStates();
            if ($states === []) {
                return;
            }

            update_option(
                self::RETRY_STATE_OPTION,
                array_map(static fn(RetryState $state): array => $state->suspended()->toArray(), $states),
                false
            );
        } finally {
            self::releaseLock($lock);
        }
    }

    /**
     * The start of the next reporting window for an endpoint: its last
     * successful delivery, one full interval ago for a first send (or the
     * start of the retention window when history backfill is enabled), and
     * never older than the data the retention window still holds.
     *
     * @param string $url Endpoint URL.
     * @param int    $now Current unix timestamp.
     * @return int Window start as a unix timestamp.
     */
    private static function windowStart(string $url, int $now): int
    {
        $maxAge   = $now - Options::retentionDays() * DAY_IN_SECONDS;
        $fallback = Options::webhookBackfill()
            ? $maxAge
            : $now - Options::intervalSeconds(Options::webhookInterval());

        $lastSent = get_option(self::LAST_SENT_OPTION, []);
        $lastSent = is_array($lastSent) ? $lastSent : [];

        $startTs = isset($lastSent[md5($url)]) ? (int) $lastSent[md5($url)] : $fallback;

        return max($startTs, $maxAge);
    }

    /**
     * Builds and serializes the payload for one exact window, producing the
     * immutable delivery a chain re-sends verbatim.
     *
     * Serializing once, up front, is what makes the byte-identical promise
     * real: retention cleanup, settings or site-name changes, filters with
     * dynamic output, and even plugin updates between attempts can no longer
     * alter what a given delivery_id accompanies. The final request URL
     * (with global query parameters merged) and the delivery headers are
     * frozen alongside the body, so a configuration change after a failure
     * can never mutate an already-frozen retry. Retries also skip the
     * aggregate queries entirely.
     *
     * The requested window is first bounded to at most
     * {@see MAX_CONVERSIONS_PER_DELIVERY} individual conversions; a shrunk
     * window's overflow becomes the next delivery's window.
     *
     * @param string $url     Absolute endpoint URL.
     * @param int    $startTs Window start as a unix timestamp (inclusive).
     * @param int    $endTs   Requested window end as a unix timestamp (exclusive); may be shrunk.
     * @return FrozenDelivery
     */
    private static function freezeDelivery(string $url, int $startTs, int $endTs): FrozenDelivery
    {
        $body          = false;
        $failureReason = null;

        try {
            $boundary = Reports::conversionWindowEnd(
                gmdate('Y-m-d H:i:s', $startTs),
                gmdate('Y-m-d H:i:s', $endTs),
                self::MAX_CONVERSIONS_PER_DELIVERY
            );

            $endTs = min($endTs, (int) strtotime($boundary . ' UTC'));

            $payload = PayloadBuilder::analyticsReport($startTs, $endTs, self::reportRowLimit());

            $payload['delivery_id'] = self::deliveryId($url, $startTs, $endTs);

            // wp_json_encode() returns false on failure — casting that to ''
            // would silently deliver an empty body, and a 2xx from the
            // endpoint would then advance the marker past a window that was
            // never really sent. Deliberately NOT rebuilt without the
            // 'convermetry_webhook_payload' filter: the filter may exist to
            // redact sensitive data.
            $body = wp_json_encode($payload);
        } catch (ReportQueryException $e) {
            self::logReportQueryFailure($e);
            $failureReason = FrozenDelivery::REPORT_QUERY_FAILED;
        }

        $delivery = new FrozenDelivery(
            windowStart: $startTs,
            windowEnd: $endTs,
            // Recomputed rather than read from $payload — deliveryId() is a
            // pure function of ($url, $startTs, $endTs), so this is
            // byte-identical to what the try block would have produced, and
            // stays available even when the try block never reached that line.
            deliveryId: self::deliveryId($url, $startTs, $endTs),
            body: is_string($body) ? $body : '',
            url: '',
            headers: [],
            failureReason: $failureReason,
        );

        // Composed from the delivery being frozen, so the query-argument and
        // header filters can see which endpoint and which window they are
        // composing for. Both run exactly here — once per logical delivery,
        // before the request is frozen — and never again for its retries.
        $context = self::contextFor($url, $delivery, DeliveryKind::Scheduled, 0);

        $delivery = $delivery->withRequest(
            RequestFactory::buildUrl($url, '', [], [], $context),
            RequestFactory::buildHeaders('', [], $context)
        );

        // 'memory': an analytics delivery freezes as a value here, and is
        // persisted only if it later needs a retry.
        DeliveryContext::frozen($context, 'memory', strlen($delivery->body));

        return $delivery;
    }

    /**
     * Logs a report-query failure's raw diagnostic to the PHP error log, at
     * most once per cooldown — see {@see REPORT_FAILURE_LOG_FLAG}.
     *
     * @param \Throwable $e The caught ReportQueryException.
     * @return void
     */
    private static function logReportQueryFailure(\Throwable $e): void
    {
        if (get_transient(self::REPORT_FAILURE_LOG_FLAG) !== false) {
            return;
        }

        set_transient(self::REPORT_FAILURE_LOG_FLAG, time(), 15 * MINUTE_IN_SECONDS);

        error_log('Convermetry: report query failed during webhook dispatch - ' . $e->getMessage());
    }

    /**
     * Reconstructs a frozen delivery from stored retry state.
     *
     * State written by an older plugin version may lack pieces; anything
     * missing is rebuilt from the frozen window — the best still-possible
     * approximation.
     *
     * @param string     $url   Endpoint URL the state belongs to.
     * @param RetryState $state Stored retry state.
     * @return FrozenDelivery
     */
    private static function deliveryFromState(string $url, RetryState $state): FrozenDelivery
    {
        $now     = time();
        $startTs = $state->windowStart !== 0 ? $state->windowStart : self::windowStart($url, $now);
        $endTs   = $state->windowEnd !== 0 ? $state->windowEnd : $now;

        if ($state->hasFrozenBody()) {
            return new FrozenDelivery(
                windowStart: $startTs,
                windowEnd: $endTs,
                deliveryId: $state->deliveryId !== ''
                    ? $state->deliveryId
                    : self::deliveryId($url, $startTs, $endTs),
                body: $state->body,
                // Rebuilt WITHOUT the composition filters: this state was
                // written before frozen request columns existed, and re-running
                // them mid-chain would let a delivery change destination between
                // attempt one and attempt four.
                url: $state->requestUrl !== '' ? $state->requestUrl : RequestFactory::recoverUrl($url),
                headers: $state->headers !== [] ? $state->headers : RequestFactory::recoverHeaders(),
            );
        }

        return self::freezeDelivery($url, $startTs, $endTs);
    }

    /**
     * Sends one frozen delivery to one endpoint and updates bookkeeping.
     *
     * On success the last-sent timestamp advances exactly to the delivery's
     * window end (never past what the receiver acknowledged) and any pending
     * retry chain is cancelled.
     *
     * @param string         $url      Configured endpoint URL (bookkeeping key).
     * @param FrozenDelivery $delivery Frozen delivery.
     * @param DeliveryKind   $kind     Delivery kind for the log.
     * @param int            $attempt  Retry attempt number (0 for scheduled runs).
     * @return bool True when the endpoint returned a 2xx response.
     */
    private static function attemptDelivery(
        string $url,
        FrozenDelivery $delivery,
        DeliveryKind $kind,
        int $attempt
    ): bool {
        $requestUrl = $delivery->url !== '' ? $delivery->url : $url;
        $label      = Options::endpointLabel($url);
        $context    = self::contextFor($url, $delivery, $kind, $attempt, $label);

        // An empty body means either payload encoding failed or a report
        // query failed (see freezeDelivery()) — either way, sending it could
        // earn a 2xx from a lenient endpoint and advance the marker past a
        // window whose data was never delivered.
        if (!$delivery->isSendable()) {
            $result = TransportResult::failure($delivery->failureMessage(self::REPORT_FAILURE_MESSAGE));
        } else {
            // Resolved into a local so the lifecycle action announces the exact
            // header set — signature included — that goes on the wire.
            $sendHeaders = RequestFactory::withProtocolHeaders(
                $delivery->headers,
                $url,
                $delivery->body,
                $delivery->deliveryId
            );

            DeliveryContext::beforeSend($context, $requestUrl, $sendHeaders, $delivery->body);
            $result = Http::postJson($requestUrl, $delivery->body, $sendHeaders, $context);
        }

        $logged = DeliveryLog::log(new DeliveryLogEntry(
            result: $result,
            endpointUrl: $url,
            endpointLabel: $label,
            deliveryId: $delivery->deliveryId,
            messageType: MessageType::AnalyticsReport,
            kind: $kind,
            attempt: $attempt,
            requestUrl: $requestUrl,
            requestHeaders: $delivery->headers,
            requestData: $delivery->body,
        ));

        $context = DeliveryContext::attempted($context, $result, $delivery->isSendable());
        DeliveryContext::attemptLogged($context, $logged);

        if ($result->ok) {
            $lastSent = get_option(self::LAST_SENT_OPTION, []);
            $lastSent = is_array($lastSent) ? $lastSent : [];

            $key = md5($url);
            $lastSent[$key] = max((int) ($lastSent[$key] ?? 0), $delivery->windowEnd);

            update_option(self::LAST_SENT_OPTION, $lastSent, false);
            self::clearRetry($url);

            // Announced only now: the window marker has advanced and the retry
            // chain is cleared, so a listener reading either sees settled state.
            DeliveryContext::succeeded($context);
        }

        return $result->ok;
    }

    /**
     * Builds the public lifecycle details for one analytics delivery.
     *
     * @param string         $url      Configured endpoint URL.
     * @param FrozenDelivery $delivery Frozen delivery.
     * @param DeliveryKind   $kind     Why this attempt is happening.
     * @param int            $attempt  Attempt number.
     * @param string|null    $label    Endpoint label, when the caller already resolved it.
     * @return DeliveryDetails
     */
    private static function contextFor(
        string $url,
        FrozenDelivery $delivery,
        DeliveryKind $kind,
        int $attempt,
        ?string $label = null
    ): DeliveryDetails {
        return DeliveryDetails::for(
            $url,
            messageType: MessageType::AnalyticsReport,
            kind: $kind,
            attempt: $attempt,
            deliveryId: $delivery->deliveryId,
            endpointLabel: $label,
            windowStart: $delivery->windowStart,
            windowEnd: $delivery->windowEnd,
        );
    }

    /**
     * Schedules retry attempt $attempt for an endpoint, storing the frozen
     * delivery every attempt in the chain must re-send.
     *
     * When the cron event cannot be scheduled, the frozen delivery is stored
     * in the exhausted state instead of being dropped — the next scheduled
     * dispatch then resumes it under the same delivery_id.
     *
     * @param string         $url      Endpoint URL to retry.
     * @param int            $attempt  Attempt number being scheduled (1-based).
     * @param FrozenDelivery $delivery Frozen delivery to re-send.
     * @return void
     */
    private static function scheduleRetry(string $url, int $attempt, FrozenDelivery $delivery): void
    {
        $delays = self::retryDelays();

        if ($attempt < 1 || $attempt > count($delays)) {
            return;
        }

        $when = time() + $delays[$attempt - 1];

        if (wp_schedule_single_event($when, self::RETRY_HOOK, [$url]) === false) {
            self::storeRetryState($url, max(1, $attempt - 1), 0, $delivery, true);
            DeliveryContext::retryChainExhausted(
                self::contextFor($url, $delivery, DeliveryKind::Retry, max(1, $attempt - 1))
            );
            return;
        }

        self::storeRetryState($url, $attempt, $when, $delivery, false);

        // Announced only after both the cron event and the frozen state are
        // persisted — a listener told about a retry that was never stored would
        // be watching for an attempt that never comes.
        DeliveryContext::retryScheduled(
            self::contextFor($url, $delivery, DeliveryKind::Retry, $attempt),
            $attempt,
            $when
        );
    }

    /**
     * Persists one endpoint's retry state.
     *
     * @param string         $url          Endpoint URL.
     * @param int            $attempt      Attempt number (next to run, or last made when exhausted).
     * @param int            $scheduledFor Unix timestamp of the pending cron event (0 when exhausted).
     * @param FrozenDelivery $delivery     Frozen delivery.
     * @param bool           $exhausted    True when no cron is pending and the next scheduled dispatch must resume the delivery.
     * @return void
     */
    private static function storeRetryState(
        string $url,
        int $attempt,
        int $scheduledFor,
        FrozenDelivery $delivery,
        bool $exhausted
    ): void {
        $states = self::getRetryStates();
        $key    = md5($url);

        // frozen_at marks when this DELIVERY first froze, not when the state
        // row was last rewritten — later attempts in the same chain must not
        // refresh it, or the retention-window expiry would never trigger for
        // a persistently failing endpoint.
        $existing = $states[$key] ?? null;
        $frozenAt = ($existing !== null && $existing->deliveryId === $delivery->deliveryId && $existing->frozenAt > 0)
            ? $existing->frozenAt
            : time();

        $stored        = array_map(static fn(RetryState $state): array => $state->toArray(), $states);
        $stored[$key]  = RetryState::forDelivery($delivery, $url, $attempt, $scheduledFor, $exhausted, $frozenAt)
            ->toArray();

        update_option(self::RETRY_STATE_OPTION, $stored, false);
    }

    /**
     * Cancels an endpoint's pending retry (cron event and stored state).
     *
     * @param string $url Endpoint URL.
     * @return void
     */
    private static function clearRetry(string $url): void
    {
        $states = self::getRetryStates();
        $key    = md5($url);

        if (!isset($states[$key])) {
            return;
        }

        if ($states[$key]->scheduledFor > 0) {
            wp_unschedule_event($states[$key]->scheduledFor, self::RETRY_HOOK, [$url]);
        }

        unset($states[$key]);

        update_option(
            self::RETRY_STATE_OPTION,
            array_map(static fn(RetryState $state): array => $state->toArray(), $states),
            false
        );
    }

    /**
     * Acquires the site-wide dispatch mutex without blocking.
     *
     * Prefers a MySQL named lock (GET_LOCK) — truly atomic, scoped to this
     * install (see {@see lockName()}), and released automatically if the PHP
     * process dies. When the server does not support named locks, falls back
     * to an option-row lock carrying an OWNERSHIP TOKEN and a lease
     * timestamp ("token|timestamp"): release is a compare-and-delete on the
     * holder's own token, holders renew their lease while working, and only
     * a lock whose lease is verifiably stale is stolen — via
     * compare-and-delete on the exact stale value, so concurrent stealers
     * can't double-free.
     *
     * All fallback reads/writes go straight to the options table —
     * WordPress's option caches could serve this process a value another
     * process changed minutes ago.
     *
     * @return string|null 'mysql' or 'option:<token>', or null when another
     *                     process holds the lock.
     */
    private static function acquireLock(): ?string
    {
        global $wpdb;

        $acquired = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', self::lockName()));
        if ($acquired !== null) {
            return ((int) $acquired === 1) ? 'mysql' : null;
        }

        // Named locks unavailable — token-bearing option-row fallback.
        $token = md5(wp_generate_uuid4() . wp_rand());
        $value = $token . '|' . time();

        if (self::insertLockRow($value)) {
            return 'option:' . $token;
        }

        $held = (string) $wpdb->get_var($wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
            self::LOCK_OPTION
        ));

        $heldTs = (int) substr((string) strrchr($held, '|'), 1);
        if ($held === '' || time() - $heldTs < self::LOCK_TIMEOUT) {
            return null;
        }

        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
            self::LOCK_OPTION,
            $held
        ));
        wp_cache_delete(self::LOCK_OPTION, 'options');

        return self::insertLockRow($value) ? 'option:' . $token : null;
    }

    /**
     * Atomically creates the fallback lock row.
     *
     * @param string $value Lock value ("token|timestamp").
     * @return bool True when this call created the row.
     *
     * @phpstan-impure Each call attempts a real INSERT IGNORE, and its result
     *                 depends on whether the row exists AT THAT MOMENT — the
     *                 caller retries this after deleting a stale lock row, so
     *                 two calls with identical arguments legitimately differ.
     */
    private static function insertLockRow(string $value): bool
    {
        global $wpdb;

        $inserted = $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off')",
            self::LOCK_OPTION,
            $value
        ));
        wp_cache_delete(self::LOCK_OPTION, 'options');

        return $inserted === 1;
    }

    /**
     * Extends the fallback lock's lease while its holder is still working.
     *
     * Called between delivery windows: a multi-endpoint catch-up run can
     * legitimately exceed {@see LOCK_TIMEOUT}, and without renewal another
     * process would mistake the healthy run for a dead one and steal its
     * lock. The UPDATE matches on the ownership token, so a lock that WAS
     * stolen is left alone. MySQL named locks need no lease; they die with
     * the connection.
     *
     * @param string $lock The value acquireLock() returned.
     * @return void
     */
    private static function renewLock(string $lock): void
    {
        if (!str_starts_with($lock, 'option:')) {
            return;
        }

        global $wpdb;

        $token = substr($lock, strlen('option:'));

        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value LIKE %s",
            $token . '|' . time(),
            self::LOCK_OPTION,
            $wpdb->esc_like($token) . '|%'
        ));
        wp_cache_delete(self::LOCK_OPTION, 'options');
    }

    /**
     * Releases the dispatch mutex acquired by {@see acquireLock()}.
     *
     * @param string $lock The value acquireLock() returned ('mysql' or 'option:<token>').
     * @return void
     */
    private static function releaseLock(string $lock): void
    {
        global $wpdb;

        if ($lock === 'mysql') {
            $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', self::lockName()));
            return;
        }

        $token = substr($lock, strlen('option:'));

        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value LIKE %s",
            self::LOCK_OPTION,
            $wpdb->esc_like($token) . '|%'
        ));
        wp_cache_delete(self::LOCK_OPTION, 'options');
    }

    /**
     * The MySQL named-lock name. Named locks are SERVER-wide, not
     * per-database — so the name hashes the database name, table prefix, and
     * site URL together to make it unique per install.
     *
     * @return string Well under MySQL's 64-character limit.
     */
    private static function lockName(): string
    {
        global $wpdb;

        $db = defined('DB_NAME') ? DB_NAME : '';

        return 'cvm_dispatch_' . md5($db . '|' . $wpdb->prefix . '|' . home_url());
    }

    /**
     * Returns the stored retry state map (md5(url) → state), hydrated.
     *
     * Rows written by any earlier version pass through
     * {@see RetryState::fromStoredArray()}, so callers never have to ask what
     * shape a state row happens to be in. Anything that is not an array at all
     * is dropped rather than hydrated into a state with no url.
     *
     * @return array<string, RetryState>
     */
    private static function getRetryStates(): array
    {
        $stored = get_option(self::RETRY_STATE_OPTION, []);

        $states = [];
        foreach (is_array($stored) ? $stored : [] as $key => $state) {
            if (is_string($key) && is_array($state)) {
                $states[$key] = RetryState::fromStoredArray($state);
            }
        }

        return $states;
    }

    /**
     * One endpoint's stored retry state, or null when it has none.
     *
     * @param string $url Endpoint URL.
     * @return RetryState|null
     */
    private static function retryStateFor(string $url): ?RetryState
    {
        return self::getRetryStates()[md5($url)] ?? null;
    }

    /**
     * Drops bookkeeping (last-sent timestamps and retry chains) for endpoints
     * that are no longer configured for analytics delivery, and expires
     * frozen deliveries older than the data retention window. Runs under the
     * dispatch mutex.
     *
     * @param string[] $activeUrls Currently configured analytics endpoint URLs.
     * @return void
     */
    private static function pruneStaleState(array $activeUrls): void
    {
        $activeKeys = array_map('md5', $activeUrls);

        $lastSent = get_option(self::LAST_SENT_OPTION, []);
        if (is_array($lastSent)) {
            update_option(self::LAST_SENT_OPTION, array_intersect_key($lastSent, array_flip($activeKeys)), false);
        }

        $expiry = time() - Options::retentionDays() * DAY_IN_SECONDS;

        foreach (self::getRetryStates() as $key => $state) {
            if (!in_array($key, $activeKeys, true)) {
                self::clearRetry($state->url);
                continue;
            }

            // State written before frozen_at existed falls back to the frozen
            // window's end, which is the closest thing it recorded to "when
            // this delivery stopped being current".
            $frozenAt = $state->frozenAt !== 0 ? $state->frozenAt : $state->windowEnd;
            if ($frozenAt > 0 && $frozenAt < $expiry) {
                self::clearRetry($state->url);
            }
        }
    }

    /**
     * Maximum rows per "top_*" list in the webhook payload.
     *
     * @return int At least 1; default {@see REPORT_ROW_LIMIT}.
     */
    private static function reportRowLimit(): int
    {
        /**
         * Filters how many rows each "top_*" list in the analytics webhook
         * payload may hold.
         *
         * @param int $limit Maximum rows per list.
         */
        return max(1, (int) apply_filters('convermetry_webhook_report_limit', self::REPORT_ROW_LIMIT));
    }
}
