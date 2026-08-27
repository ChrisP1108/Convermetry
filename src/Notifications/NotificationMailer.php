<?php
declare(strict_types=1);

namespace Convermetry\Notifications;

if (!defined('ABSPATH')) exit;

use Throwable;

/**
 * Hands one rendered notification to WordPress's mail system.
 *
 * WHAT A SUCCESS HERE MEANS. wp_mail() returning true means the local mail
 * transport ACCEPTED the message — PHP's mail(), or whatever SMTP plugin has
 * taken over wp_mail(). It is not proof of delivery, says nothing about
 * whether the message reached an inbox, and says nothing about spam
 * foldering. Every string this class produces, and every piece of admin copy
 * describing it, must say "accepted"/"handed off" and never "delivered" — the
 * Submissions page already has a "Delivered" state for webhooks, where a
 * receiver really did return 2xx, and reusing the word would make the UI lie.
 *
 * Delivery is intentionally not routed through DeliveryLog: its MESSAGE_TYPES
 * vocabulary covers the two webhook message types and log() blanks anything
 * else, so an email row would land with an empty message_type and pollute the
 * Activity Log's filters and its REST contract. Only a short failure reason is
 * kept, on the queue row — never the rendered body, never submitted values.
 */
final class NotificationMailer
{
    /** Longest failure message retained, matching the queue column width. */
    private const int MAX_ERROR_LEN = 191;

    /** The most recent wp_mail_failed message, captured during a send. */
    private static string $reported = '';

    /**
     * Per-message headers.
     *
     * Content-Type is set HERE, on wp_mail()'s $headers argument, and never
     * through the global 'wp_mail_content_type' filter. That filter changes
     * the format of every other plugin's mail sent during the same request,
     * and a fatal or an early return between adding and removing it would
     * leave the site sending HTML-typed plain text indefinitely.
     *
     * Auto-Submitted and X-Auto-Response-Suppress stop out-of-office
     * autoresponders bouncing back at the site's wordpress@ address, which is
     * a real support-ticket generator on shared inboxes.
     *
     * @param string $submissionId Submission id, for recipient-side dedup.
     * @return list<string>
     */
    public static function headers(string $submissionId = ''): array
    {
        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            'Auto-Submitted: auto-generated',
            'X-Auto-Response-Suppress: All',
        ];

        if ($submissionId !== '') {
            // Sends are at-least-once (a worker that dies between a successful
            // wp_mail() and the row delete re-sends), and SMTP has no receiver
            // idempotency, so give the mailbox something to dedup on.
            $headers[] = 'X-Convermetry-Submission: ' . self::headerSafe($submissionId);
        }

        return $headers;
    }

    /**
     * Header names a convermetry_notification_message callback may not set.
     *
     * Content-Type carries the same argument as {@see headers()}; the two
     * auto-response headers exist to stop out-of-office bounces at the site's
     * own address; and the submission header is the only idempotency signal a
     * receiving mailbox gets for an at-least-once send. All four are restored
     * after filtering, so a callback that drops or rewrites one changes nothing.
     */
    private const array PROTECTED_HEADERS = [
        'content-type',
        'auto-submitted',
        'x-auto-response-suppress',
        'x-convermetry-submission',
    ];

    /**
     * Re-validates a filtered notification message.
     *
     * The recipient is deliberately NOT filterable and is always the original:
     * one queue row is one address, chosen when the notification was queued and
     * deduplicated on it. Letting a per-attempt filter rewrite it would mean two
     * rows could collapse onto one mailbox, or a retry chain could wander to a
     * different address than the attempt before it.
     *
     * @param array{recipient: string, subject: string, html: string, headers: list<string>} $original Pre-filter message.
     * @param mixed                                                                          $filtered Whatever the filter returned.
     * @param string                                                                         $submissionId Submission id, for the dedup header.
     * @return array{recipient: string, subject: string, html: string, headers: list<string>}
     */
    public static function reconcile(array $original, mixed $filtered, string $submissionId = ''): array
    {
        if (!is_array($filtered)) {
            return $original;
        }

        // Subject: header-injection strip and length cap reapplied, exactly as
        // EmailBuilder::subject() does, because a filtered subject has not been
        // through it.
        $subject = is_scalar($filtered['subject'] ?? null) ? (string) $filtered['subject'] : $original['subject'];
        $subject = trim((string) preg_replace('/\s+/', ' ', (string) preg_replace('/[\r\n\t\x00]+/', ' ', $subject)));
        $subject = mb_substr($subject, 0, NotificationSettings::SUBJECT_MAX_LEN);
        if ($subject === '') {
            $subject = $original['subject'];
        }

        // Body: the size cap is reapplied, so a filter cannot produce a message
        // large enough for the transport to reject or truncate mid-tag.
        $html = is_string($filtered['html'] ?? null) ? EmailBuilder::capBody($filtered['html']) : $original['html'];

        $headers = [];
        foreach (is_array($filtered['headers'] ?? null) ? $filtered['headers'] : [] as $header) {
            if (!is_scalar($header)) {
                continue;
            }

            $header = self::headerSafe(trim((string) $header));
            $name   = strtolower(trim((string) strstr($header, ':', true)));

            if ($name !== '' && !in_array($name, self::PROTECTED_HEADERS, true)) {
                $headers[] = $header;
            }
        }

        return [
            'recipient' => $original['recipient'],
            'subject'   => $subject,
            'html'      => $html,
            // Required headers reinstated at the front, whatever the filter did.
            'headers'   => array_merge(self::headers($submissionId), $headers),
        ];
    }

    /**
     * Sends one message to one recipient.
     *
     * One wp_mail() call per recipient, never a combined To/Cc list, so no
     * recipient ever learns who else is on the internal notification list.
     *
     * @param string            $to           A validated recipient address.
     * @param string            $subject      Rendered subject.
     * @param string            $html         Rendered HTML body.
     * @param string            $submissionId Submission id, for the dedup header.
     * @param list<string>|null $headers      Reconciled headers; null builds the standard set.
     * @return array{ok: bool, message: string}
     */
    public static function send(
        string $to,
        string $subject,
        string $html,
        string $submissionId = '',
        ?array $headers = null
    ): array {
        self::$reported = '';

        // Core swallows PHPMailer exceptions and simply returns false after
        // firing wp_mail_failed. Without listening, every failure would read
        // "wp_mail() reported a failure" and leave the admin nothing to debug.
        add_action('wp_mail_failed', [self::class, 'captureFailure']);

        try {
            $returned = wp_mail($to, self::headerSafe($subject), $html, $headers ?? self::headers($submissionId));
            $error    = null;
        } catch (Throwable $e) {
            // Replacement mailers (WP Mail SMTP, SES, Postmark) do throw.
            $returned = false;
            $error    = $e;
        } finally {
            remove_action('wp_mail_failed', [self::class, 'captureFailure']);
        }

        return self::interpret($returned, $error, self::$reported);
    }

    /**
     * Records the reason from a wp_mail_failed event.
     *
     * @param mixed $error A WP_Error, in practice.
     * @return void
     */
    public static function captureFailure(mixed $error): void
    {
        if (is_object($error) && method_exists($error, 'get_error_message')) {
            self::$reported = (string) $error->get_error_message();
        }
    }

    /**
     * Turns a wp_mail() outcome into the queue's result shape.
     *
     * Anything that is not exactly true is a failure — including the null or
     * WP_Error a third-party wp_mail() override might return. Treating a
     * non-boolean as success would silently delete a queue row for a message
     * that was never accepted.
     *
     * @param mixed          $returned What wp_mail() returned.
     * @param Throwable|null $error    A caught mailer exception, if any.
     * @param string         $reported The captured wp_mail_failed message.
     * @return array{ok: bool, message: string}
     */
    public static function interpret(mixed $returned, ?Throwable $error = null, string $reported = ''): array
    {
        if ($error !== null) {
            return ['ok' => false, 'message' => self::cap('Mailer error: ' . $error->getMessage())];
        }

        if ($returned === true) {
            // Accepted by the local transport. NOT delivered.
            return ['ok' => true, 'message' => ''];
        }

        if ($reported !== '') {
            return ['ok' => false, 'message' => self::cap($reported)];
        }

        return ['ok' => false, 'message' => 'wp_mail() reported a failure.'];
    }

    /**
     * Strips CR/LF/NUL from a value destined for a mail header.
     *
     * The subject is already cleaned by {@see EmailBuilder::subject()}; this is
     * the second, unconditional guard, because header injection is the one
     * failure here that turns a notification into an open relay.
     *
     * @param string $value Header value.
     * @return string
     */
    private static function headerSafe(string $value): string
    {
        return trim((string) preg_replace('/[\r\n\x00]+/', ' ', $value));
    }

    /**
     * Caps a failure message to the queue column width.
     *
     * @param string $message Failure message.
     * @return string
     */
    private static function cap(string $message): string
    {
        $clean = trim((string) preg_replace('/\s+/', ' ', $message));

        return mb_substr($clean, 0, self::MAX_ERROR_LEN);
    }
}
