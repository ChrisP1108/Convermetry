<?php
declare(strict_types=1);

namespace Convermetry\Notifications;

if (!defined('ABSPATH')) exit;

/**
 * What wp_mail() made of one notification.
 *
 * ACCEPTED IS NOT DELIVERED. {@see $ok} being true means the local mail
 * transport — PHP's mail(), or whatever SMTP plugin has taken over wp_mail() —
 * took the message. It is not proof of delivery, says nothing about whether it
 * reached an inbox, and says nothing about spam foldering. Every string
 * produced from this, and every piece of admin copy describing it, says
 * "accepted"/"handed off" and never "delivered": the Submissions page already
 * has a "Delivered" state for webhooks, where a receiver really did return
 * 2xx, and reusing the word would make the UI lie.
 *
 * {@see $message} is a short failure reason, capped to the queue column's
 * width. It is '' on success. It never contains the rendered body or any
 * submitted value.
 */
final readonly class MailResult
{
    /**
     * @param bool   $ok      Whether the local transport accepted the message.
     * @param string $message Short failure reason; '' on success.
     */
    private function __construct(
        public bool $ok,
        public string $message,
    ) {
    }

    /**
     * The transport accepted the message.
     *
     * @return self
     */
    public static function accepted(): self
    {
        return new self(true, '');
    }

    /**
     * The transport refused it, or threw.
     *
     * @param string $message Short reason, already capped by the caller.
     * @return self
     */
    public static function failed(string $message): self
    {
        return new self(false, $message);
    }
}
