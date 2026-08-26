<?php
declare(strict_types=1);

namespace Convermetry\Database;

if (!defined('ABSPATH')) exit;

/**
 * One sanitized event on its way into the database, plus the context that the
 * events table itself has nowhere to put.
 *
 * WHY THIS EXISTS
 *
 * {@see DatabaseManager::sanitizeRow()} normalizes every event to the fixed
 * COLUMNS list and drops everything else — deliberately, because bulk inserts
 * serialize rows by that list and a filter that added a key would otherwise
 * shift another row's values into the wrong columns. That normalization is
 * correct and stays.
 *
 * But goal matching needs three things that are NOT columns and must never
 * become columns:
 *
 *  - landingPage — the session's landing page. Wanted on a goal completion so
 *    "completions by landing page" needs no join, but adding it to every event
 *    row would inflate the largest table on the site to serve one report.
 *  - selectorGoals — the goal ids a CSS-selector rule matched in the browser.
 *    Transient by definition: the server re-validates them and then has no
 *    further use for them.
 *  - dynamicValue — a numeric value a custom event supplied.
 *
 * A seam that ran AFTER the insert could not see any of them. Hence an envelope
 * that travels alongside the row: the row stays exactly as narrow as the table,
 * and the ingestion pipeline still has what it needs.
 *
 * eventUid is the durable identity of this event, minted HERE rather than read
 * back from the database. A multi-row INSERT IGNORE cannot reliably report which
 * of its rows were stored or what ids they received, so relying on insert_id
 * arithmetic would have been a guess. The uid is what goal deduplication keys an
 * every-occurrence completion on, so a replayed browser batch collides with its
 * original instead of counting twice.
 */
final class PreparedEvent
{
    /**
     * @param array<string, string> $row             The storable row, already sanitized and truncated.
     * @param int                   $seq             Position in the ORIGINAL browser batch.
     * @param string|null           $batchId         Client batch id, or null for server-side events.
     * @param string                $eventUid        Durable per-event identity (see the class docblock).
     * @param string                $landingPage     The session's landing page, or ''. Never stored on the row.
     * @param list<string>          $selectorGoals   Goal ids a browser CSS selector matched, unvalidated.
     * @param string                $customEventName The custom event's name, or ''.
     * @param string|null           $dynamicValue    A supplied value as a decimal string, or null.
     * @param int|null              $sourceEventId   The stored row's id, filled in after insertion.
     */
    public function __construct(
        public readonly array $row,
        public readonly int $seq,
        public readonly ?string $batchId,
        public readonly string $eventUid,
        public readonly string $landingPage = '',
        public readonly array $selectorGoals = [],
        public readonly string $customEventName = '',
        public readonly ?string $dynamicValue = null,
        public ?int $sourceEventId = null,
    ) {
    }

    /**
     * This event's type.
     *
     * @return string
     */
    public function type(): string
    {
        return $this->row['event_type'] ?? '';
    }

    /**
     * One column from the storable row.
     *
     * @param string $column Column name.
     * @return string
     */
    public function column(string $column): string
    {
        return $this->row[$column] ?? '';
    }

    /**
     * Mints a durable identity for an event.
     *
     * A browser event with a valid batch id gets a DERIVED uid, so the same
     * event replayed in the same batch derives the same uid and its goal
     * completion collides with the original. Everything else gets a random uid,
     * which honestly degrades to "this is a distinct occurrence":
     *
     *  - server-side events (cvm_track_event(), provider hooks) carry no batch
     *    id by design and cannot be replayed, so a random uid is exact;
     *  - a browser batch whose id was missing or malformed cannot be
     *    deduplicated by any means, and inventing a stable-looking uid from the
     *    row's contents would collapse genuinely separate occurrences instead —
     *    over-counting is recoverable, under-counting is invisible.
     *
     * @param string|null $batchId Client batch id, or null.
     * @param int         $seq     Position in the original batch.
     * @return string A 32-character hex identity.
     */
    public static function mintUid(?string $batchId, int $seq): string
    {
        if ($batchId !== null && $batchId !== '') {
            return md5($batchId . '|' . $seq);
        }

        return md5(wp_generate_uuid4() . wp_rand());
    }
}
