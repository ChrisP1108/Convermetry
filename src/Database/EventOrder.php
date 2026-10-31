<?php
declare(strict_types=1);

namespace Convermetry\Database;

if (!defined('ABSPATH')) exit;

/**
 * Where a confirmed form success sits in event order.
 *
 * Every "did this happen after that?" question the reports ask — each funnel
 * step, and whether a form start was abandoned — is answered on one scale: the
 * events table's id, which is INGESTION order (see the ORDERING note on
 * {@see DatabaseManager}). For events from one browser that is the browser's own
 * order, because a batch keeps its order and batches are sent in turn.
 *
 * A server-confirmed form_success is the exception. The form plugin's server
 * hook writes it while handling the submission itself, and the tracker's batch
 * holding the attempt that caused it — usually with that form's view and start
 * — travels in a separate request at the same moment. The two race, and either
 * can be stored first. Ordered by id alone, a success stored first sorts BEFORE
 * its own attempt, start, and view: a funnel ending submit → success or
 * start → success misses a real completion, and the abandonment report counts a
 * converted start as abandoned. Which request wins is a property of the
 * network, not of anything the visitor did.
 *
 * THE FIX IS CAUSAL, NOT TEMPORAL. The tracker records each attempt with its
 * conversion token in event_value, and the server records the success under
 * that same token as its conversion id. So the attempt that CAUSED a success is
 * known exactly, and a success is positioned at
 *
 *     GREATEST(success.id, attempt.id + 0.5)
 *
 * — never before the attempt that produced it, and otherwise exactly where it
 * was stored. The half step places it after the attempt and before whatever was
 * stored after the attempt, and cannot tie with any real id, so every strict
 * "after" comparison in the reports keeps working unchanged. Positions remain
 * on the id scale goal steps already share through source_event_id.
 *
 * What this deliberately does NOT do:
 *
 *  - Read any timestamp. Neither a browser clock nor created_at decides order.
 *  - Delay a write, or drop the ordering requirement for any step.
 *  - Move anything except a success, and only ever later.
 *  - Guess. A success with no linked attempt keeps its ingestion position,
 *    exactly as before. That covers rows from before attempts carried their
 *    token (all history up to this change — there is nothing to link them by),
 *    pages still serving an older cached tracker, submissions made with
 *    JavaScript off, and sessions with attempt tracking disabled. Ninja Forms
 *    and Elementor Atomic now record the linked attempt in their request hook,
 *    even when the provider bypasses the native submit event.
 *  - Repair an attempt whose own batch arrived late. A batch resent from a
 *    later page sorts where it arrived (DatabaseManager's documented limit); its
 *    success now sorts just after it rather than ahead of it.
 */
final class EventOrder
{
    /**
     * SQL for a form_success row's position on the shared ordering scale.
     *
     * @param string $success Alias of the form_success row.
     * @param string $attempt Alias that {@see attemptJoin()} gave its attempt.
     * @return string
     */
    public static function successPosition(string $success, string $attempt): string
    {
        return "GREATEST({$success}.id, COALESCE({$attempt}.id + 0.5, 0))";
    }

    /**
     * A LEFT JOIN from a form_success row to the browser attempt that carried
     * its conversion token, in the same session.
     *
     * Served by the session_type_id index: the session's form_submit rows are
     * found by (session_id, event_type) before event_value is compared.
     *
     * @param string $events  The events table name.
     * @param string $success Alias of the form_success row.
     * @param string $attempt Alias to give the attempt.
     * @return string
     */
    public static function attemptJoin(string $events, string $success, string $attempt): string
    {
        return " LEFT JOIN {$events} AS {$attempt}"
            . " ON {$attempt}.session_id = {$success}.session_id"
            . " AND {$attempt}.event_type = 'form_submit'"
            . " AND {$attempt}.event_value <> ''"
            . " AND {$attempt}.event_value = {$success}.event_value";
    }
}
