<?php
declare(strict_types=1);

namespace Convermetry\Forms;

if (!defined('ABSPATH')) exit;

/**
 * Immutable value object describing the outcome of a form submission run
 * through Convermetry.
 *
 * Returned by {@see SubmissionService} and the public
 * convermetry_submit_form() helper so callers can inspect the outcome —
 * do_action() callers get fire-and-forget semantics instead.
 */
final readonly class SubmissionResult
{
    /**
     * @param bool   $ok               True when the submission was recorded and every attempted
     *                                 delivery succeeded (or deliveries were queued for background
     *                                 processing). False on any failure.
     * @param string $submissionId     The submission's globally unique id ('' when nothing was recorded).
     * @param string $conversionId     The conversion id shared with analytics ('' when nothing was recorded).
     * @param int    $status           HTTP status of the last synchronous delivery (0 for early exits,
     *                                 transport errors, and queued/background deliveries).
     * @param string $msg              User-facing error description when $ok is false; '' on success.
     * @param mixed  $data             The last endpoint's response body for synchronous deliveries —
     *                                 JSON-decoded when valid JSON, raw string otherwise; null for
     *                                 early exits and background deliveries. Not for public display.
     * @param bool   $queued           True when deliveries were queued for background processing
     *                                 rather than sent synchronously.
     * @param list<array{url: string, endpoint_url: string, headers: array<string, string>, body: string, label: string}> $failedDeliveries
     *                                 One entry per endpoint whose SYNCHRONOUS dispatch failed — the
     *                                 exact request that was sent, so external callers can implement
     *                                 their own retry logic. Always empty for early exits and for
     *                                 background (queued) deliveries, whose retries Convermetry
     *                                 manages itself.
     *
     *                                 DELIBERATELY ARRAYS, not objects. convermetry_submit_form()
     *                                 returns this result to third-party code, and the entry shape
     *                                 is documented API that callers already index by key. A typed
     *                                 object here would be a nicer internal representation and a
     *                                 breaking change for every consumer, which is a bad trade for
     *                                 a value that is read once and re-sent.
     */
    public function __construct(
        public bool $ok,
        public string $submissionId = '',
        public string $conversionId = '',
        public int $status = 0,
        public string $msg = '',
        public mixed $data = null,
        public bool $queued = false,
        public array $failedDeliveries = [],
    ) {
    }
}
