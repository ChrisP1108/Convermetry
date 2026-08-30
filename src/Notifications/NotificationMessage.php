<?php
declare(strict_types=1);

namespace Convermetry\Notifications;

if (!defined('ABSPATH')) exit;

/**
 * One rendered notification email, addressed to one recipient.
 *
 * One queue row is one address. The recipient is chosen and deduplicated when
 * the notification is queued and is NOT re-decidable afterwards: letting a
 * per-attempt filter rewrite it would mean two rows could collapse onto one
 * mailbox, or a retry chain could wander to a different address than the
 * attempt before it. {@see NotificationMailer::reconcile()} enforces that, and
 * this object is why it can — the recipient is carried separately from the
 * three things a filter may change.
 *
 * Unlike a webhook, an email has no frozen body: a retry re-renders and
 * re-filters, so one of these is built per attempt.
 *
 * {@see toArray()} is the shape the public 'convermetry_notification_message'
 * filter receives and returns, and it is unchanged.
 *
 * CARRIES PERSONAL DATA: {@see $html} is the lead — the visitor's submitted
 * field values, rendered.
 */
final readonly class NotificationMessage
{
    /**
     * @param string       $recipient A validated recipient address. Not filterable.
     * @param string       $subject   Rendered subject, header-injection stripped and capped.
     * @param string       $html      Rendered HTML body (PII).
     * @param list<string> $headers   Full header lines, protocol headers first.
     */
    public function __construct(
        public string $recipient,
        public string $subject,
        public string $html,
        public array $headers,
    ) {
    }

    /**
     * The filter-facing array form.
     *
     * @return array{recipient: string, subject: string, html: string, headers: list<string>}
     */
    public function toArray(): array
    {
        return [
            'recipient' => $this->recipient,
            'subject'   => $this->subject,
            'html'      => $this->html,
            'headers'   => $this->headers,
        ];
    }
}
