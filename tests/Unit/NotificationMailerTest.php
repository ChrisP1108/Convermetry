<?php

declare(strict_types=1);

namespace Convermetry\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Convermetry\Notifications\NotificationMailer;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Handing a notification to wp_mail(), and describing the result honestly.
 *
 * The central claim under test: wp_mail() returning true means the LOCAL
 * TRANSPORT accepted the message, not that anything was delivered. The
 * Submissions page already uses "Delivered" for webhooks, where a receiver
 * genuinely returned 2xx, so reusing that word here would make the UI lie.
 */
final class NotificationMailerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        Functions\when('add_action')->justReturn(true);
        Functions\when('remove_action')->justReturn(true);
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    // ── Headers ──────────────────────────────────────────────────────────────

    public function testHeadersDeclareHtmlContentType(): void
    {
        self::assertContains('Content-Type: text/html; charset=UTF-8', NotificationMailer::headers());
    }

    /**
     * Sends are at-least-once and SMTP has no receiver idempotency, so the
     * mailbox gets something to deduplicate on.
     */
    public function testHeadersCarryTheSubmissionIdForRecipientSideDeduplication(): void
    {
        self::assertContains('X-Convermetry-Submission: s123', NotificationMailer::headers('s123'));
    }

    public function testTheSubmissionHeaderIsOmittedWhenThereIsNoId(): void
    {
        foreach (NotificationMailer::headers() as $header) {
            self::assertStringNotContainsString('X-Convermetry-Submission', $header);
        }
    }

    /** Otherwise every out-of-office reply lands on the site's wordpress@ address. */
    public function testHeadersSuppressAutoresponders(): void
    {
        $headers = NotificationMailer::headers();

        self::assertContains('Auto-Submitted: auto-generated', $headers);
        self::assertContains('X-Auto-Response-Suppress: All', $headers);
    }

    public function testHeaderValuesCannotCarryInjectedNewlines(): void
    {
        $headers = NotificationMailer::headers("s1\r\nBcc: attacker@example.test");

        foreach ($headers as $header) {
            self::assertStringNotContainsString("\r", $header);
            self::assertStringNotContainsString("\n", $header);
        }
    }

    /**
     * The global filter would change the content type of every other plugin's
     * mail in the same request, and an early return between adding and
     * removing it would leave the site broken indefinitely. Content-Type is
     * set per-message, on the $headers argument, and this is enforced rather
     * than merely intended.
     */
    public function testTheGlobalContentTypeFilterIsNeverUsed(): void
    {
        Functions\expect('add_filter')->never();

        Functions\when('wp_mail')->justReturn(true);
        NotificationMailer::send('a@example.com', 'Subject', '<p>Body</p>');

        // The class docblock names the filter to explain why it is avoided, so
        // check for an actual registration rather than a mention of the name.
        $source = (string) file_get_contents(__DIR__ . '/../../src/Notifications/NotificationMailer.php');

        self::assertStringNotContainsString('add_filter(', $source);
    }

    // ── Sending ──────────────────────────────────────────────────────────────

    public function testTheRecipientSubjectAndBodyArePassedThrough(): void
    {
        $captured = [];

        Functions\when('wp_mail')->alias(function (...$args) use (&$captured): bool {
            $captured = $args;

            return true;
        });

        NotificationMailer::send('sales@example.com', 'New lead', '<p>Body</p>', 's1');

        self::assertSame('sales@example.com', $captured[0]);
        self::assertSame('New lead', $captured[1]);
        self::assertSame('<p>Body</p>', $captured[2]);
        self::assertIsArray($captured[3]);
    }

    public function testASubjectCarryingNewlinesIsCleanedBeforeSending(): void
    {
        $captured = '';

        Functions\when('wp_mail')->alias(function ($to, $subject, ...$rest) use (&$captured): bool {
            $captured = $subject;

            return true;
        });

        NotificationMailer::send('a@example.com', "New lead\r\nBcc: attacker@example.test", '<p>x</p>');

        self::assertStringNotContainsString("\r", $captured);
        self::assertStringNotContainsString("\n", $captured);
    }

    public function testASuccessfulSendReportsOk(): void
    {
        Functions\when('wp_mail')->justReturn(true);

        self::assertSame(['ok' => true, 'message' => ''], NotificationMailer::send('a@example.com', 's', 'b'));
    }

    public function testAFailedSendReportsFailure(): void
    {
        Functions\when('wp_mail')->justReturn(false);

        $result = NotificationMailer::send('a@example.com', 's', 'b');

        self::assertFalse($result['ok']);
        self::assertNotSame('', $result['message']);
    }

    /**
     * A replacement mailer returning null or a WP_Error must be treated as a
     * failure. Treating a non-boolean as success would delete the queue row
     * for a message that was never accepted.
     *
     * @dataProvider nonBooleanReturns
     */
    public function testANonBooleanReturnIsTreatedAsFailure(mixed $returned): void
    {
        self::assertFalse(NotificationMailer::interpret($returned)['ok']);
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function nonBooleanReturns(): array
    {
        return [
            'null'   => [null],
            'zero'   => [0],
            'string' => ['sent'],
            'array'  => [[]],
            'object' => [new \stdClass()],
        ];
    }

    // ── Failure reporting ────────────────────────────────────────────────────

    /**
     * Core swallows PHPMailer exceptions and simply returns false after firing
     * wp_mail_failed. Without capturing that event, every failure would read
     * the same and the admin would have nothing to debug with.
     */
    public function testTheWpMailFailedReasonIsSurfaced(): void
    {
        $error = new class {
            public function get_error_message(): string
            {
                return 'SMTP connect() failed';
            }
        };

        NotificationMailer::captureFailure($error);

        self::assertSame(
            ['ok' => false, 'message' => 'SMTP connect() failed'],
            NotificationMailer::interpret(false, null, 'SMTP connect() failed')
        );
    }

    public function testAThrowingMailerOverrideIsCaught(): void
    {
        Functions\when('wp_mail')->alias(static function (): bool {
            throw new RuntimeException('Postmark rejected the request');
        });

        $result = NotificationMailer::send('a@example.com', 's', 'b');

        self::assertFalse($result['ok']);
        self::assertStringContainsString('Postmark rejected the request', $result['message']);
    }

    /** Leaving the listener attached would leak into every later wp_mail(). */
    public function testTheFailureListenerIsAlwaysRemovedEvenWhenTheMailerThrows(): void
    {
        $removed = 0;

        Functions\when('remove_action')->alias(function (string $hook) use (&$removed): bool {
            if ($hook === 'wp_mail_failed') {
                $removed++;
            }

            return true;
        });

        Functions\when('wp_mail')->alias(static function (): bool {
            throw new RuntimeException('boom');
        });

        NotificationMailer::send('a@example.com', 's', 'b');

        self::assertSame(1, $removed, 'The listener must be detached on the throwing path too');
    }

    public function testFailureMessagesAreCappedToTheColumnWidth(): void
    {
        $result = NotificationMailer::interpret(false, null, str_repeat('x', 500));

        self::assertSame(191, mb_strlen($result['message']));
    }

    public function testFailureMessagesAreCollapsedToASingleLine(): void
    {
        $result = NotificationMailer::interpret(false, null, "line one\nline two");

        self::assertSame('line one line two', $result['message']);
    }

    // ── Honesty about what "sent" means ──────────────────────────────────────

    /**
     * A documentation test with teeth: if anyone ever writes "delivered" into
     * this class's user-facing strings, this fails.
     */
    public function testTheClassNeverClaimsDelivery(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../../src/Notifications/NotificationMailer.php');

        // Extract single-quoted strings that are returned to callers.
        preg_match_all("/'message'\s*=>\s*'([^']*)'/", $source, $matches);

        foreach ($matches[1] as $message) {
            self::assertStringNotContainsStringIgnoringCase(
                'delivered',
                $message,
                'wp_mail() success is acceptance by the local transport, not delivery'
            );
        }
    }
}
