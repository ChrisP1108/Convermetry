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
final class SubmissionResult
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
     * @param array  $failedDeliveries One entry per endpoint whose SYNCHRONOUS dispatch failed, each
     *                                 array{url: string, endpoint_url: string, headers: array<string,string>, body: string, label: string}
     *                                 — the exact request that was sent, so external callers can
     *                                 implement their own retry logic. Always empty for early exits
     *                                 and for background (queued) deliveries, whose retries
     *                                 Convermetry manages itself.
     */
    public function __construct(
        public readonly bool $ok,
        public readonly string $submissionId = '',
        public readonly string $conversionId = '',
        public readonly int $status = 0,
        public readonly string $msg = '',
        public readonly mixed $data = null,
        public readonly bool $queued = false,
        public readonly array $failedDeliveries = [],
    ) {
    }
}
