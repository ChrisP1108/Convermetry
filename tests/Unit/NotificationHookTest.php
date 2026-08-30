<?php

declare(strict_types=1);

namespace Convermetry\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Convermetry\Notifications\NotificationDispatcher;
use Convermetry\Notifications\NotificationMailer;
use Convermetry\Notifications\NotificationMessage;
use Convermetry\Notifications\SiteInfo;
use Convermetry\Notifications\NotificationSettings;
use PHPUnit\Framework\TestCase;

/**
 * The notification hooks, and the revalidation that stands behind each of them.
 *
 * Email is the unforgiving surface in this plugin. A webhook delivered to the
 * wrong place can be re-sent; an email accepted by a transport cannot be
 * recalled, and a lead notification's body IS the lead. So every filter here is
 * followed by the same validation the configured path already goes through —
 * addresses re-checked, subjects re-stripped, bodies re-capped, required
 * headers reinstated — and the observational actions are given none of it.
 *
 * Two decisions this file pins that are easy to get wrong later:
 *
 *  - the recipient is frozen at QUEUE time, never per attempt, because one
 *    queue row is one address and retries must not wander between mailboxes;
 *  - "accepted" is the word, never "delivered": wp_mail() returning true means
 *    a local transport took the message, not that anyone received it.
 */
final class NotificationHookTest extends TestCase
{
    private const PLUGIN_DIR = __DIR__ . '/../../';

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        Functions\when('sanitize_email')->alias(static fn(string $v): string => trim($v));
        Functions\when('is_email')->alias(
            static fn(string $v): bool => (bool) filter_var($v, FILTER_VALIDATE_EMAIL)
        );
        Functions\when('sanitize_text_field')->alias(static fn($v): string => trim(strip_tags((string) $v)));
        Functions\when('sanitize_key')->alias(static fn($v): string => strtolower((string) $v));
        Functions\when('esc_url_raw')->returnArg(1);
        Functions\when('home_url')->justReturn('https://example.com');
        Functions\when('get_bloginfo')->justReturn('Example');
        Functions\when('get_option')->justReturn([]);
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * @return array<string, mixed>
     */
    private function settings(array $overrides = []): array
    {
        return array_merge([
            'enabled'    => true,
            'scope'      => 'all',
            'recipients' => ['ops@example.com'],
            'form_rules' => [],
            'subject'    => 'New lead',
        ], $overrides);
    }

    // ------------------------------------------------------- queue decision

    public function testTheQueueDecisionFilterCanSkipASubmission(): void
    {
        Functions\when('apply_filters')->alias(
            static fn(string $hook, mixed $value, mixed ...$rest) => $hook === 'convermetry_should_queue_notification'
                ? false
                : $value
        );

        self::assertNull(NotificationDispatcher::plan(
            $this->settings(),
            ['form_key' => 'elementor:a1', 'provider' => 'elementor', 'form_name' => 'Contact'],
            new SiteInfo('Example', 'https://example.com/', 'https://example.com/wp-admin/admin.php')
        ));
    }

    /**
     * The filter can only narrow what the administrator enabled. With
     * notifications switched off it is never even consulted, so returning true
     * cannot turn them back on.
     */
    public function testTheQueueDecisionFilterCannotOverrideTheMasterSwitch(): void
    {
        $consulted = false;

        Functions\when('apply_filters')->alias(
            static function (string $hook, mixed $value, mixed ...$rest) use (&$consulted) {
                if ($hook === 'convermetry_should_queue_notification') {
                    $consulted = true;
                    return true;
                }

                return $value;
            }
        );

        self::assertNull(NotificationDispatcher::plan(
            $this->settings(['enabled' => false]),
            ['form_key' => 'elementor:a1'],
            new SiteInfo('Example', 'https://example.com/', 'https://example.com/wp-admin/admin.php')
        ));
        self::assertFalse($consulted, 'The master switch is checked before the filter is consulted.');
    }

    // ----------------------------------------------------------- recipients

    public function testFilteredRecipientsAreRevalidatedAndDeduplicated(): void
    {
        Functions\when('apply_filters')->alias(
            static fn(string $hook, mixed $value, mixed ...$rest) => $hook === 'convermetry_notification_recipients'
                ? ['  sales@example.com ', 'not-an-address', 'SALES@example.com', 'ops@example.com']
                : $value
        );

        $plan = NotificationDispatcher::plan(
            $this->settings(),
            ['form_key' => 'elementor:a1'],
            new SiteInfo('Example', 'https://example.com/', 'https://example.com/wp-admin/admin.php')
        );

        self::assertNotNull($plan);
        self::assertSame(['sales@example.com', 'ops@example.com'], $plan['recipients']);
    }

    public function testTheRecipientCapSurvivesTheFilter(): void
    {
        $many = [];
        for ($i = 0; $i < NotificationSettings::MAX_RECIPIENTS + 15; $i++) {
            $many[] = "person{$i}@example.com";
        }

        Functions\when('apply_filters')->alias(
            static fn(string $hook, mixed $value, mixed ...$rest) => $hook === 'convermetry_notification_recipients'
                ? $many
                : $value
        );

        $plan = NotificationDispatcher::plan(
            $this->settings(),
            ['form_key' => 'elementor:a1'],
            new SiteInfo('Example', 'https://example.com/', 'https://example.com/wp-admin/admin.php')
        );

        self::assertNotNull($plan);
        self::assertCount(NotificationSettings::MAX_RECIPIENTS, $plan['recipients']);
    }

    public function testAFilterThatLeavesNoValidRecipientQueuesNothing(): void
    {
        Functions\when('apply_filters')->alias(
            static fn(string $hook, mixed $value, mixed ...$rest) => $hook === 'convermetry_notification_recipients'
                ? ['nonsense', '', null]
                : $value
        );

        self::assertNull(NotificationDispatcher::plan(
            $this->settings(),
            ['form_key' => 'elementor:a1'],
            new SiteInfo('Example', 'https://example.com/', 'https://example.com/wp-admin/admin.php')
        ));
    }

    // -------------------------------------------------------- message filter

    /** The pre-filter message one queue row renders. */
    private function message(): NotificationMessage
    {
        return new NotificationMessage(
            recipient: 'ops@example.com',
            subject: 'New lead from Contact',
            html: '<div>body</div>',
            headers: NotificationMailer::headers('s1'),
        );
    }

    /**
     * The rule that keeps one queue row bound to one mailbox. Two rows filtered
     * onto the same address would send twice; a retry filtered elsewhere would
     * mail someone the earlier attempts never went to.
     */
    public function testTheRecipientCannotBeChangedByTheMessageFilter(): void
    {
        $result = NotificationMailer::reconcile(
            $this->message(),
            ['recipient' => 'attacker@evil.example', 'subject' => 'x', 'html' => 'y', 'headers' => []],
            's1'
        );

        self::assertSame('ops@example.com', $result->recipient);
    }

    public function testAFilteredSubjectIsStrippedOfHeaderInjectionAndCapped(): void
    {
        $result = NotificationMailer::reconcile(
            $this->message(),
            ['subject' => "Hi\r\nBcc: attacker@evil.example\nX: y"],
            's1'
        );

        self::assertSame('Hi Bcc: attacker@evil.example X: y', $result->subject);
        self::assertStringNotContainsString("\r", $result->subject);
        self::assertStringNotContainsString("\n", $result->subject);
    }

    public function testAnOverlongFilteredSubjectIsTruncatedToTheConfiguredMaximum(): void
    {
        $result = NotificationMailer::reconcile(
            $this->message(),
            ['subject' => str_repeat('a', NotificationSettings::SUBJECT_MAX_LEN + 100)],
            's1'
        );

        self::assertSame(NotificationSettings::SUBJECT_MAX_LEN, mb_strlen($result->subject));
    }

    public function testAnEmptyFilteredSubjectFallsBackRatherThanSendingBlank(): void
    {
        $result = NotificationMailer::reconcile($this->message(), ['subject' => '   '], 's1');

        self::assertSame('New lead from Contact', $result->subject);
    }

    public function testAnOversizedFilteredBodyIsCappedByTheSameRuleAsARenderedOne(): void
    {
        // Comfortably past the 256 KB body cap without reaching into a private
        // constant to say so.
        $huge   = str_repeat('x', 300000);
        $result = NotificationMailer::reconcile($this->message(), ['html' => $huge], 's1');

        self::assertLessThan(strlen($huge), strlen($result->html));
        self::assertStringContainsString('truncated', $result->html);
    }

    /**
     * Content-Type keeps the mail HTML, the two auto-response headers stop
     * out-of-office bounces at the site's own address, and the submission header
     * is the only thing a mailbox can deduplicate an at-least-once send on. A
     * callback that drops them changes nothing.
     */
    public function testTheRequiredHeadersAreReinstatedAfterFiltering(): void
    {
        $result = NotificationMailer::reconcile(
            $this->message(),
            ['headers' => ['X-Custom: yes']],
            's1'
        );

        self::assertSame([
            'Content-Type: text/html; charset=UTF-8',
            'Auto-Submitted: auto-generated',
            'X-Auto-Response-Suppress: All',
            'X-Convermetry-Submission: s1',
            'X-Custom: yes',
        ], $result->headers);
    }

    public function testAFilterCannotOverrideAProtectedHeader(): void
    {
        $result = NotificationMailer::reconcile(
            $this->message(),
            ['headers' => [
                'content-type: text/plain',
                'Auto-Submitted: no',
                'X-Convermetry-Submission: forged',
                'X-Fine: yes',
            ]],
            's1'
        );

        self::assertContains('Content-Type: text/html; charset=UTF-8', $result->headers);
        self::assertContains('X-Convermetry-Submission: s1', $result->headers);
        self::assertNotContains('content-type: text/plain', $result->headers);
        self::assertNotContains('X-Convermetry-Submission: forged', $result->headers);
        self::assertContains('X-Fine: yes', $result->headers);
    }

    public function testInjectedNewlinesInAFilteredHeaderAreNeutralized(): void
    {
        $result = NotificationMailer::reconcile(
            $this->message(),
            ['headers' => ["X-Custom: a\r\nBcc: attacker@evil.example"]],
            's1'
        );

        foreach ($result->headers as $header) {
            self::assertStringNotContainsString("\r", $header);
            self::assertStringNotContainsString("\n", $header);
        }
    }

    public function testANonArrayFilterReturnLeavesTheMessageUntouched(): void
    {
        $original = $this->message();

        self::assertSame($original, NotificationMailer::reconcile($original, 'nonsense', 's1'));
        self::assertSame($original, NotificationMailer::reconcile($original, null, 's1'));
    }

    // ------------------------------------------------------ source contract

    /**
     * Source-contract: the commit boundaries. Each of these actions claims a
     * state change already happened, so each must follow the write that makes it
     * true. Asserted structurally because driving the worker needs a $wpdb whose
     * return values the assertions would then depend on.
     */
    public function testEachTerminalNotificationActionFollowsItsCommit(): void
    {
        $source = (string) file_get_contents(self::PLUGIN_DIR . 'src/Notifications/NotificationQueue.php');

        $processRow = substr($source, (int) strpos($source, 'private static function processRow'), 8000);
        $accepted   = strpos($processRow, "do_action('convermetry_notification_accepted'");

        self::assertIsInt($accepted);
        self::assertIsInt(
            strrpos(substr($processRow, 0, $accepted), '$wpdb->delete('),
            'accepted must follow the queue row delete'
        );

        $chain     = substr($source, (int) strpos($source, 'private static function rescheduleOrAbandon'), 4000);
        $abandoned = strpos($chain, "do_action('convermetry_notification_abandoned'");
        $retry     = strpos($chain, "do_action('convermetry_notification_retry_scheduled'");
        $update    = strpos($chain, '$wpdb->update(');

        self::assertIsInt($abandoned);
        self::assertIsInt($retry);
        self::assertIsInt($update);
        self::assertGreaterThan($update, $retry, 'retry_scheduled must follow the queue row update');
    }

    /**
     * The vocabulary itself is load-bearing: a hook named "delivered" would
     * invite integrations to treat a transport handoff as proof of receipt.
     */
    public function testNoNotificationHookClaimsDelivery(): void
    {
        $source = (string) file_get_contents(self::PLUGIN_DIR . 'src/Notifications/NotificationQueue.php');

        preg_match_all("~'(convermetry_notification_[a-z_]+)'~", $source, $matches);

        self::assertNotEmpty($matches[1]);
        foreach (array_unique($matches[1]) as $hook) {
            self::assertStringNotContainsString('delivered', $hook, "{$hook} overstates what wp_mail() proves.");
        }
    }
}
