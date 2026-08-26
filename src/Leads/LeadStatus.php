<?php
declare(strict_types=1);

namespace Convermetry\Leads;

if (!defined('ABSPATH')) exit;

/**
 * The lead qualification vocabulary.
 *
 * Six statuses, and deliberately only six. This is the point where a marketing
 * analytics plugin is most likely to drift into being a CRM, and the boundary
 * is worth stating plainly: Convermetry records the OUTCOME of a lead so that
 * marketing performance can be measured against it. It does not manage the
 * work of pursuing that lead. There are no assignees, no pipeline stages, no
 * follow-up dates, no activity notes — every one of those would be a worse
 * version of a tool the site already has, and none of them changes the answer
 * to "which campaign produced valuable leads?".
 *
 * The statuses fall into three groups, which is what the reports actually read:
 *
 *   new                     not yet assessed — the default, and honest about it
 *   qualified / won         a real lead; 'won' additionally means it converted
 *   unqualified / lost / spam   not a real lead, or did not convert
 *
 * 'spam' is separate from 'unqualified' on purpose. Unqualified means a genuine
 * person who was not a fit; spam means the submission was never a lead at all.
 * Merging them would inflate every denominator with bot traffic and quietly
 * depress the qualification rate of whichever channel attracts the most junk.
 *
 * PURE. No database, no WordPress state — {@see LeadService} owns persistence.
 */
final class LeadStatus
{
    /** The status every submission starts in. */
    public const string DEFAULT = 'new';

    /**
     * Every valid status, in the order they are offered in the UI.
     *
     * @var string[]
     */
    public const array ALL = ['new', 'qualified', 'unqualified', 'won', 'lost', 'spam'];

    /**
     * Statuses that count as a qualified lead.
     *
     * 'won' is included: a lead that converted was self-evidently qualified,
     * and requiring someone to pass through 'qualified' first to be counted
     * would under-report every site whose users mark outcomes in one step.
     *
     * @var string[]
     */
    public const array QUALIFIED = ['qualified', 'won'];

    /**
     * Statuses that count as won business.
     *
     * @var string[]
     */
    public const array WON = ['won'];

    /**
     * Statuses excluded from lead totals entirely.
     *
     * Only spam. An unqualified or lost lead was still a lead the marketing
     * produced, and removing them from the denominator would make every channel
     * look better the more bad leads it sent.
     *
     * @var string[]
     */
    public const array EXCLUDED_FROM_TOTALS = ['spam'];

    /**
     * Human-readable labels.
     *
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            'new'         => 'New',
            'qualified'   => 'Qualified',
            'unqualified' => 'Unqualified',
            'won'         => 'Won',
            'lost'        => 'Lost',
            'spam'        => 'Spam',
        ];
    }

    /**
     * The label for one status, falling back to the raw value.
     *
     * @param string $status Machine value.
     * @return string
     */
    public static function label(string $status): string
    {
        return self::labels()[$status] ?? $status;
    }

    /**
     * Whether a value is a recognized status.
     *
     * @param mixed $status Candidate status.
     * @return bool
     */
    public static function isValid(mixed $status): bool
    {
        return is_string($status) && in_array($status, self::ALL, true);
    }

    /**
     * Coerces any value to a valid status.
     *
     * An unrecognized value becomes the default rather than being stored:
     * status drives report grouping, so an unknown value would create a silent
     * bucket that appears in no total and no filter.
     *
     * @param mixed $status Candidate status.
     * @return string
     */
    public static function normalize(mixed $status): string
    {
        return self::isValid($status) ? (string) $status : self::DEFAULT;
    }

    /**
     * Whether a status counts as a qualified lead.
     *
     * @param string $status Machine value.
     * @return bool
     */
    public static function isQualified(string $status): bool
    {
        return in_array($status, self::QUALIFIED, true);
    }

    /**
     * Whether a status counts as won business.
     *
     * @param string $status Machine value.
     * @return bool
     */
    public static function isWon(string $status): bool
    {
        return in_array($status, self::WON, true);
    }

    /**
     * Whether a status is counted in lead totals.
     *
     * @param string $status Machine value.
     * @return bool
     */
    public static function countsAsLead(string $status): bool
    {
        return !in_array($status, self::EXCLUDED_FROM_TOTALS, true);
    }

    /**
     * The CSS modifier used for a status chip.
     *
     * Reuses the existing delivery-status chip styling rather than introducing
     * a parallel set: a chip is a chip, and two visual vocabularies for the same
     * component would drift.
     *
     * @param string $status Machine value.
     * @return string
     */
    public static function chipClass(string $status): string
    {
        return match ($status) {
            'won'                  => 'cvm-status-delivered',
            'qualified'            => 'cvm-status-partial',
            'lost', 'unqualified'  => 'cvm-status-failed',
            'spam'                 => 'cvm-status-not_sent',
            default                => 'cvm-status-pending',
        };
    }
}
