<?php
declare(strict_types=1);

namespace Convermetry\Support;

if (!defined('ABSPATH')) exit;

/**
 * The verified result of queuing one submission's rows.
 *
 * Both queues used to return a bare count of rows they believed they had
 * written, which collapsed three different outcomes into one number:
 *
 *  - a row genuinely inserted,
 *  - a row the unique index suppressed because it was already queued,
 *  - a row the database REFUSED to write.
 *
 * Only the first incremented the count, so a failed insert was indistinguishable
 * from a duplicate. SubmissionService then discarded the count entirely and
 * reported queued=true regardless, which meant a submission could be recorded,
 * its delivery row could fail to persist, and the caller was told the webhook
 * was on its way. For a lead-generation plugin that is a silently lost lead.
 *
 * $failedKeys carries the endpoint keys whose rows are known NOT to exist, so a
 * repair pass can re-insert exactly those and nothing else. Re-queuing anything
 * broader would risk re-sending a delivery a worker had already completed and
 * deleted.
 */
final readonly class QueueOutcome
{
    /**
     * @param int          $expected   Rows that should exist for this submission.
     * @param int          $inserted   Rows this call genuinely created.
     * @param int          $duplicate  Rows that already existed (verified present).
     * @param int          $failed     Rows verified ABSENT after a refused write.
     * @param list<string> $failedKeys Endpoint keys behind $failed, for targeted repair.
     */
    public function __construct(
        public int $expected = 0,
        public int $inserted = 0,
        public int $duplicate = 0,
        public int $failed = 0,
        public array $failedKeys = [],
    ) {
    }

    /**
     * Nothing was expected, so nothing is outstanding.
     */
    public static function nothingToQueue(): self
    {
        return new self();
    }

    /**
     * Rows that are durably present: created now, or confirmed already there.
     */
    public function durable(): int
    {
        return $this->inserted + $this->duplicate;
    }

    /**
     * Whether at least one row is durably queued.
     *
     * This — not "the call returned" — is what may be reported as queued=true.
     */
    public function queuedAnything(): bool
    {
        return $this->durable() > 0;
    }

    /**
     * Whether every expected row is durably present.
     *
     * False means a repair pass is owed, even when some rows landed: a partial
     * failure silently drops one destination while the others succeed.
     */
    public function isComplete(): bool
    {
        return $this->failed === 0 && $this->durable() >= $this->expected;
    }

    /**
     * Safe scalar context for {@see Errors::storage()}. Carries counts and
     * opaque endpoint keys only — never a URL, secret, or submitted field.
     *
     * @return array<string, mixed>
     */
    public function telemetry(): array
    {
        return [
            'expected'  => $this->expected,
            'inserted'  => $this->inserted,
            'duplicate' => $this->duplicate,
            'failed'    => $this->failed,
        ];
    }
}
