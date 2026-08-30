<?php
declare(strict_types=1);

namespace Convermetry\Support;

if (!defined('ABSPATH')) exit;

/**
 * The result of one store's retention pass.
 *
 * Immutable, and derived rather than assembled: {@see Retention::outcome()}
 * computes it from the two values a chunked delete loop already holds when it
 * exits, so the deletion behaviour those loops encode is untouched.
 *
 * 'more_remain' is not a field a caller can set independently — it follows
 * from the status ({@see RetentionStatus::moreRemain()}), which is what stops
 * the two from ever contradicting each other in a hook payload.
 */
final readonly class RetentionOutcome
{
    /**
     * @param int             $deleted Rows deleted across the whole pass.
     * @param RetentionStatus $status  How the pass ended.
     */
    public function __construct(
        public int $deleted,
        public RetentionStatus $status,
    ) {
    }

    /**
     * Whether rows older than the cutoff remain.
     *
     * @return bool
     */
    public function moreRemain(): bool
    {
        return $this->status->moreRemain();
    }

    /**
     * Whether a delete query failed during the pass.
     *
     * @return bool
     */
    public function queryFailed(): bool
    {
        return $this->status === RetentionStatus::QueryFailed;
    }

    /**
     * The hook-facing array form.
     *
     * Kept because 'convermetry_retention_cleanup_completed' publishes these
     * three values as separate scalar arguments, and because the shape was the
     * documented one before this object existed.
     *
     * @return array{deleted: int, outcome: string, more_remain: bool}
     */
    public function toArray(): array
    {
        return [
            'deleted'     => $this->deleted,
            'outcome'     => $this->status->value,
            'more_remain' => $this->moreRemain(),
        ];
    }
}
