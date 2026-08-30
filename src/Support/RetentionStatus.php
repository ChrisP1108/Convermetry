<?php
declare(strict_types=1);

namespace Convermetry\Support;

if (!defined('ABSPATH')) exit;

/**
 * How one store's retention pass ended.
 *
 * The four values are announced verbatim as the $outcomeCode argument of
 * 'convermetry_retention_cleanup_completed', so they are public API.
 * {@see Retention}'s COMPLETED / TRUNCATED / QUERY_FAILED / LOCK_LOST
 * constants remain the names call sites use and are now defined from these
 * cases, so the constant and the enum can never disagree.
 *
 * The distinction that matters is between Completed and everything else:
 * only Completed means the table is genuinely drained down to the cutoff.
 * The other three all mean rows older than the administrator's retention
 * window are still on disk, which is why {@see RetentionOutcome::$moreRemain}
 * is derived from this rather than tracked separately.
 */
enum RetentionStatus: string
{
    /** The pruned table is fully drained down to the cutoff. */
    case Completed = 'completed';

    /** The pass stopped on its chunk or time budget with older rows remaining. */
    case Truncated = 'truncated';

    /** A delete query failed; how many rows remain is unknown. */
    case QueryFailed = 'query_failed';

    /** The pass lost its cleanup lease to another worker and stopped early. */
    case LockLost = 'lock_lost';

    /**
     * Whether rows older than the cutoff are still expected on disk.
     *
     * Only a drained table answers no. A failed query is counted as "rows
     * remain" deliberately: assuming the optimistic answer would let a store
     * that cannot delete look permanently clean.
     *
     * @return bool
     */
    public function moreRemain(): bool
    {
        return $this !== self::Completed;
    }
}
