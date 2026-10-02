<?php
declare(strict_types=1);

namespace Convermetry\Privacy;

if (!defined('ABSPATH')) exit;

use Convermetry\Database\DatabaseManager;
use Convermetry\Database\FormSubmissions;
use Convermetry\Webhook\DeliveryLog;

/**
 * WordPress personal-data eraser (Tools → Erase Personal Data).
 *
 * For an email address, erases every Convermetry submission whose submitted
 * values contain that address (see {@see PersonalDataMatcher}), and the data
 * linked to it:
 *
 *  1. The submission is deleted through {@see FormSubmissions::deleteSubmission()}
 *     — the same path as the Submissions screen's Delete, so it cancels the
 *     submission's queued webhook deliveries (which hold frozen copies of the
 *     payload), its queued email notifications, and its lead status history,
 *     and fires 'convermetry_submission_deleted' exactly as a manual delete
 *     does. That ordering is deliberate: the queues are emptied before
 *     anything else, so no worker picks the lead up again mid-erasure.
 *  2. Activity Log rows for the submission keep their audit metadata but lose
 *     the request and response bodies that carried the lead
 *     ({@see DeliveryLog::erasePayloadsForSubmission()}).
 *  3. Logged analytics reports that listed the conversion lose its IP address
 *     and session id ({@see DeliveryLog::eraseConversionFromReports()}).
 *  4. The analytics events of the visit lose their IP address
 *     ({@see DatabaseManager::anonymizeForErasure()}); the events themselves
 *     remain as anonymous traffic.
 *
 * WHAT IT CANNOT DO, and says so in its messages rather than implying
 * otherwise: recall a webhook payload a receiver already accepted, recall an
 * email notification already handed to the mail system, or rewrite an
 * analytics report that is frozen and waiting to be retried (its bytes are
 * replayed exactly, under one delivery id, by design).
 *
 * PAGING. Erasure deletes, so an OFFSET would shift under it and skip rows.
 * Instead each page resumes after the highest candidate id the previous page
 * examined, carried between WordPress's page requests in a short-lived
 * transient. Candidates that turn out not to match (the SQL narrowing is a
 * substring search) are passed over, never deleted, and never re-examined.
 */
final class PersonalDataEraser
{
    /** Candidate submissions examined per eraser page. */
    public const int SUBMISSIONS_PER_PAGE = 20;

    /** Transient name prefix for the between-pages cursor. */
    private const string CURSOR_PREFIX = 'cvmtry_privacy_erase_';

    /** How long a cursor survives between two page requests. */
    private const int CURSOR_TTL = HOUR_IN_SECONDS;

    /**
     * Eraser callback.
     *
     * @param string $email Address from the privacy request.
     * @param int    $page  1-based page, advanced by WordPress until done.
     * @return array{items_removed: bool, items_retained: bool, messages: list<string>, done: bool}
     */
    public static function erase(string $email, int $page = 1): array
    {
        $email = PersonalDataMatcher::normalizeEmail($email);

        if ($email === '') {
            return ['items_removed' => false, 'items_retained' => false, 'messages' => [], 'done' => true];
        }

        $state = self::loadState($email, max(1, $page));

        if ($state === null) {
            // The cursor from the previous page is gone (an evicted cache
            // entry). Restarting from the first candidate would be safe for the
            // data — every earlier match is already deleted — but a run of
            // non-matching candidates could then repeat forever. Stop, and say
            // how to finish: a new request resumes cleanly from the start.
            return [
                'items_removed'  => false,
                'items_retained' => true,
                'messages'       => [
                    __('Convermetry\'s erasure was interrupted before it finished. Run the erasure request again to complete it; submissions already erased stay erased.', 'convermetry'),
                ],
                'done'           => true,
            ];
        }

        $candidates = FormSubmissions::privacyCandidates(
            PersonalDataMatcher::likePatterns($email),
            $state['cursor'],
            0,
            self::SUBMISSIONS_PER_PAGE
        );

        $removed  = false;
        $retained = false;

        foreach ($candidates as $row) {
            $state['cursor'] = max($state['cursor'], (int) ($row['id'] ?? 0));

            if (!PersonalDataMatcher::storedDataMatches((string) ($row['submission_data'] ?? ''), $email)) {
                continue;
            }

            $outcome = self::eraseSubmission($row);

            if ($outcome['removed']) {
                $removed = true;
                $state['removed']++;
            } else {
                $retained = true;
                $state['failed']++;
            }

            if ($outcome['frozen_report']) {
                $retained = true;
                $state['frozen']++;
            }

            $state['hosts'] = array_values(array_unique(array_merge($state['hosts'], $outcome['hosts'])));
        }

        $done = count($candidates) < self::SUBMISSIONS_PER_PAGE;

        if ($done) {
            delete_transient(self::cursorKey($email));
        } else {
            set_transient(self::cursorKey($email), $state, self::CURSOR_TTL);
        }

        return [
            'items_removed'  => $removed,
            'items_retained' => $retained,
            'messages'       => $done ? self::messages($state) : [],
            'done'           => $done,
        ];
    }

    /**
     * Erases one matched submission and its linked data.
     *
     * @param array<string, mixed> $row A cvmtry_form_submissions row.
     * @return array{removed: bool, frozen_report: bool, hosts: list<string>}
     */
    public static function eraseSubmission(array $row): array
    {
        $id           = (int) ($row['id'] ?? 0);
        $submissionId = (string) ($row['submission_id'] ?? '');
        $conversionId = (string) ($row['conversion_id'] ?? '');
        $sessionId    = (string) ($row['session_id'] ?? '');
        $createdAt    = (string) ($row['created_at'] ?? '');

        // Where the lead already went, read before anything is changed. The
        // endpoint URL column itself is kept by the log redaction, but asking
        // first keeps this independent of that.
        $hosts = [];
        foreach (DeliveryLog::forSubmission($submissionId) as $attempt) {
            if ((int) ($attempt['success'] ?? 0) === 1) {
                $host = wp_parse_url((string) ($attempt['endpoint_url'] ?? ''), PHP_URL_HOST);
                if (is_string($host) && $host !== '') {
                    $hosts[] = $host;
                }
            }
        }

        FormSubmissions::deleteSubmission($id);

        $removed = FormSubmissions::get($id) === null;

        $marker = (string) wp_json_encode([
            'erased'    => 'personal_data_erasure',
            'erased_at' => gmdate('c'),
        ]);

        DeliveryLog::erasePayloadsForSubmission($submissionId, $marker);

        $time = strtotime($createdAt . ' UTC');
        DeliveryLog::eraseConversionFromReports(
            $conversionId,
            gmdate('Y-m-d H:i:s', ($time === false ? 0 : $time) - DAY_IN_SECONDS),
            $marker
        );

        DatabaseManager::anonymizeForErasure($sessionId, $conversionId, $createdAt);

        return [
            'removed'       => $removed,
            'frozen_report' => self::frozenReportMentions($conversionId),
            'hosts'         => array_values(array_unique($hosts)),
        ];
    }

    /**
     * Whether an analytics report frozen for retry mentions a conversion.
     *
     * Such a report is re-sent byte-for-byte under its original delivery id —
     * the guarantee receivers deduplicate on — so it is reported as retained
     * rather than rewritten.
     *
     * @param string $conversionId Conversion id.
     * @return bool
     */
    private static function frozenReportMentions(string $conversionId): bool
    {
        if ($conversionId === '') {
            return false;
        }

        $states = get_option('cvmtry_webhook_retry_state', []);

        foreach (is_array($states) ? $states : [] as $state) {
            if (is_array($state) && is_string($state['body'] ?? null) && str_contains($state['body'], $conversionId)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The summary shown on the Erase Personal Data screen once erasure is done.
     *
     * @param array{cursor: int, removed: int, failed: int, frozen: int, hosts: list<string>} $state
     * @return list<string>
     */
    private static function messages(array $state): array
    {
        $messages = [];

        if ($state['failed'] > 0) {
            $messages[] = sprintf(
                /* translators: %d: number of form submissions that could not be deleted. */
                _n(
                    'Convermetry could not delete %d matching form submission. Try the erasure again, and check the database error log if it persists.',
                    'Convermetry could not delete %d matching form submissions. Try the erasure again, and check the database error log if it persists.',
                    $state['failed'],
                    'convermetry'
                ),
                $state['failed']
            );
        }

        if ($state['removed'] === 0) {
            return $messages;
        }

        if ($state['hosts'] !== []) {
            $messages[] = sprintf(
                /* translators: %s: comma-separated list of webhook endpoint host names. */
                __('Convermetry had already delivered this person\'s form submissions to these webhook destinations: %s. Those copies are held by the receiving systems and are not affected by this erasure; erase them there.', 'convermetry'),
                implode(', ', $state['hosts'])
            );
        }

        if ($state['frozen'] > 0) {
            $messages[] = __('An analytics report that lists one of this person\'s conversions (with its IP address) is waiting to be retried. It will be re-sent exactly as it was frozen; ask the receiving system to erase it.', 'convermetry');
        }

        $messages[] = __('If Convermetry email notifications were enabled when these submissions arrived, copies may remain in the recipients\' mailboxes. Sent emails cannot be recalled.', 'convermetry');
        $messages[] = __('Entries that your form plugin stores itself are not removed by Convermetry. Use that plugin\'s own privacy tools, if it provides them.', 'convermetry');

        return $messages;
    }

    /**
     * The between-pages state for this address: fresh on page 1, null when a
     * later page finds its cursor missing.
     *
     * @param string $email Normalized address.
     * @param int    $page  1-based page.
     * @return array{cursor: int, removed: int, failed: int, frozen: int, hosts: list<string>}|null
     */
    private static function loadState(string $email, int $page): ?array
    {
        $fresh = ['cursor' => 0, 'removed' => 0, 'failed' => 0, 'frozen' => 0, 'hosts' => []];

        if ($page === 1) {
            delete_transient(self::cursorKey($email));

            return $fresh;
        }

        $stored = get_transient(self::cursorKey($email));

        if (!is_array($stored)) {
            return null;
        }

        return [
            'cursor'  => max(0, (int) ($stored['cursor'] ?? 0)),
            'removed' => max(0, (int) ($stored['removed'] ?? 0)),
            'failed'  => max(0, (int) ($stored['failed'] ?? 0)),
            'frozen'  => max(0, (int) ($stored['frozen'] ?? 0)),
            'hosts'   => array_values(array_filter(
                is_array($stored['hosts'] ?? null) ? $stored['hosts'] : [],
                'is_string'
            )),
        ];
    }

    /**
     * Transient key for one address. Hashed: the key is visible in the options
     * table, and the address itself is the thing being erased.
     *
     * @param string $email Normalized address.
     * @return string
     */
    private static function cursorKey(string $email): string
    {
        return self::CURSOR_PREFIX . md5($email);
    }
}
