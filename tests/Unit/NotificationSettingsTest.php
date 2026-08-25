<?php

declare(strict_types=1);

namespace Convermetry\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Convermetry\Notifications\NotificationDispatcher;
use Convermetry\Notifications\NotificationSettings;
use Convermetry\Settings\Options;
use PHPUnit\Framework\TestCase;

/**
 * Notification configuration: sanitizing, the form-rule matrix, the enqueue
 * guard chain, and the frozen snapshot.
 *
 * The guard ORDER assertions are the ones that matter most. "Notifications are
 * off" has to win over every other setting, and the only way to be sure of
 * that without a database is to keep the whole decision in a pure function and
 * test it directly.
 */
final class NotificationSettingsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        Functions\when('sanitize_key')->alias(
            static fn(string $v): string => strtolower(preg_replace('/[^a-z0-9_\-]/i', '', $v) ?? '')
        );
        Functions\when('sanitize_text_field')->alias(static fn($v): string => trim(strip_tags((string) $v)));
        Functions\when('sanitize_email')->alias(static fn(string $v): string => trim($v));
        Functions\when('is_email')->alias(
            static fn(string $v) => filter_var($v, FILTER_VALIDATE_EMAIL) !== false ? $v : false
        );
        Functions\when('get_option')->justReturn([]);
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    // ── Recipients ───────────────────────────────────────────────────────────

    public function testInvalidAddressesAreDropped(): void
    {
        self::assertSame(
            ['ok@example.com'],
            NotificationSettings::sanitizeRecipients(['ok@example.com', 'not-an-email', '', '@nope', 'a@b'])
        );
    }

    public function testDuplicatesAreRemovedCaseInsensitively(): void
    {
        self::assertSame(
            ['Sales@Example.com'],
            NotificationSettings::sanitizeRecipients(['Sales@Example.com', 'sales@example.com', 'SALES@EXAMPLE.COM'])
        );
    }

    /**
     * The local part of an address is case-sensitive per RFC 5321 and some
     * ticketing systems route on it, so dedup compares lowercased but stores
     * what the admin typed.
     */
    public function testDeduplicationPreservesTheFirstSeenSpelling(): void
    {
        $out = NotificationSettings::sanitizeRecipients(['Bob.Smith@example.com', 'bob.smith@example.com']);

        self::assertSame(['Bob.Smith@example.com'], $out);
    }

    public function testRecipientListIsCapped(): void
    {
        $many = [];
        for ($i = 0; $i < NotificationSettings::MAX_RECIPIENTS + 10; $i++) {
            $many[] = "user{$i}@example.com";
        }

        self::assertCount(NotificationSettings::MAX_RECIPIENTS, NotificationSettings::sanitizeRecipients($many));
    }

    /**
     * @dataProvider separatedTextareaValues
     */
    public function testASeparatedTextareaValueIsAccepted(string $raw): void
    {
        self::assertSame(
            ['a@example.com', 'b@example.com'],
            NotificationSettings::sanitizeRecipients($raw)
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function separatedTextareaValues(): array
    {
        return [
            'newlines'   => ["a@example.com\nb@example.com"],
            'crlf'       => ["a@example.com\r\nb@example.com"],
            'commas'     => ['a@example.com, b@example.com'],
            'semicolons' => ['a@example.com; b@example.com'],
            'mixed'      => ["a@example.com,\n b@example.com\n"],
        ];
    }

    public function testNonScalarRecipientEntriesAreIgnored(): void
    {
        self::assertSame(
            ['a@example.com'],
            NotificationSettings::sanitizeRecipients([['nested'], null, 'a@example.com'])
        );
    }

    // ── Subject ──────────────────────────────────────────────────────────────

    public function testEmptySubjectFallsBackToTheDefaultTemplate(): void
    {
        self::assertSame(
            'New {form_name} submission on {site_name}',
            NotificationSettings::sanitizeSubject('   ')
        );
    }

    public function testSubjectNewlinesAreStrippedAtSaveTime(): void
    {
        $subject = NotificationSettings::sanitizeSubject("New lead\r\nBcc: attacker@example.test");

        self::assertStringNotContainsString("\r", $subject);
        self::assertStringNotContainsString("\n", $subject);
        self::assertSame('New lead Bcc: attacker@example.test', $subject);
    }

    public function testSubjectIsCapped(): void
    {
        self::assertSame(
            NotificationSettings::SUBJECT_MAX_LEN,
            mb_strlen(NotificationSettings::sanitizeSubject(str_repeat('x', 500)))
        );
    }

    // ── Form rules ───────────────────────────────────────────────────────────

    public function testUnknownFormRulesAreDropped(): void
    {
        self::assertSame(
            ['gravityforms:7' => 'enabled'],
            NotificationSettings::sanitizeFormRules([
                'gravityforms:7' => 'enabled',
                'wpforms:1'      => 'maybe',
                'elementor:Form' => '',
            ])
        );
    }

    /** 'inherit' IS the absence of a rule, so it is never persisted. */
    public function testInheritRulesAreNotPersisted(): void
    {
        self::assertSame([], NotificationSettings::sanitizeFormRules(['gravityforms:7' => 'inherit']));
    }

    public function testFormKeysWithoutAProviderPrefixAreRejected(): void
    {
        self::assertSame([], NotificationSettings::sanitizeFormRules(['7' => 'enabled']));
    }

    /** Elementor keys by form NAME, which may contain spaces and capitals. */
    public function testElementorStyleFormKeysSurvive(): void
    {
        self::assertSame(
            ['elementor:Contact Form' => 'disabled'],
            NotificationSettings::sanitizeFormRules(['elementor:Contact Form' => 'disabled'])
        );
    }

    /**
     * @dataProvider notifyMatrix
     */
    public function testShouldNotifyMatrix(string $scope, string $rule, bool $expected): void
    {
        $settings = ['scope' => $scope, 'forms' => $rule === 'inherit' ? [] : ['gravityforms:7' => $rule]];

        self::assertSame($expected, NotificationSettings::shouldNotify($settings, 'gravityforms:7'));
    }

    /**
     * @return array<string, array{string, string, bool}>
     */
    public static function notifyMatrix(): array
    {
        return [
            'all + inherit'        => ['all', 'inherit', true],
            'all + enabled'        => ['all', 'enabled', true],
            'all + disabled'       => ['all', 'disabled', false],
            'selected + inherit'   => ['selected', 'inherit', false],
            'selected + enabled'   => ['selected', 'enabled', true],
            'selected + disabled'  => ['selected', 'disabled', false],
        ];
    }

    public function testAnUnknownScopeBehavesAsAll(): void
    {
        self::assertTrue(NotificationSettings::shouldNotify(['scope' => 'nonsense', 'forms' => []], 'x:1'));
    }

    // ── The enqueue guard chain ──────────────────────────────────────────────

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function settings(array $overrides = []): array
    {
        return array_merge([
            'enabled'           => true,
            'recipients'        => ['sales@example.com'],
            'subject'           => 'New {form_name}',
            'scope'             => 'all',
            'forms'             => [],
            'include_fields'    => true,
            'include_analytics' => true,
            'include_journey'   => false,
            'include_ip'        => false,
        ], $overrides);
    }

    /** @return array<string, mixed> */
    private function identity(): array
    {
        return ['id' => 1, 'form_key' => 'gravityforms:7', 'provider' => 'gravityforms', 'form_name' => 'Contact'];
    }

    /** @return array<string, string> */
    private function siteInfo(): array
    {
        return ['site_name' => 'Example Co', 'home_url' => 'https://example.com/', 'admin_url' => 'https://example.com/wp-admin/admin.php'];
    }

    /**
     * The guard-order test: everything else is configured to notify, and the
     * master switch alone must still stop it.
     */
    public function testPlanReturnsNullWhenTheMasterSwitchIsOff(): void
    {
        $settings = $this->settings([
            'enabled'    => false,
            'forms'      => ['gravityforms:7' => 'enabled'],
            'recipients' => ['a@example.com', 'b@example.com', 'c@example.com'],
        ]);

        self::assertNull(NotificationDispatcher::plan($settings, $this->identity(), $this->siteInfo()));
    }

    public function testPlanReturnsNullWhenTheFormIsDisabled(): void
    {
        $settings = $this->settings(['forms' => ['gravityforms:7' => 'disabled']]);

        self::assertNull(NotificationDispatcher::plan($settings, $this->identity(), $this->siteInfo()));
    }

    public function testPlanReturnsNullWhenNoRecipientsSurviveValidation(): void
    {
        $settings = $this->settings(['recipients' => ['not-an-email', '']]);

        self::assertNull(NotificationDispatcher::plan($settings, $this->identity(), $this->siteInfo()));
    }

    public function testPlanReturnsRecipientsAndSnapshotWhenEligible(): void
    {
        $plan = NotificationDispatcher::plan($this->settings(), $this->identity(), $this->siteInfo());

        self::assertNotNull($plan);
        self::assertSame(['sales@example.com'], $plan['recipients']);
        self::assertSame('gravityforms:7', $plan['snapshot']['form_key']);
    }

    /**
     * Recipients are re-validated in plan(), not trusted from the option: an
     * address written by WP-CLI or a migration must never reach wp_mail().
     */
    public function testPlanRevalidatesStoredRecipients(): void
    {
        $settings = $this->settings(['recipients' => ['good@example.com', 'garbage', 'good@example.com']]);

        $plan = NotificationDispatcher::plan($settings, $this->identity(), $this->siteInfo());

        self::assertSame(['good@example.com'], $plan['recipients']);
    }

    // ── Snapshot ─────────────────────────────────────────────────────────────

    public function testSnapshotHoldsExactlyTheFrozenKeys(): void
    {
        $snapshot = NotificationSettings::snapshot($this->settings(), 'gravityforms:7', $this->siteInfo());

        self::assertSame(['v', 'subject', 'site_name', 'form_key', 'include'], array_keys($snapshot));
        self::assertSame(['fields', 'analytics', 'journey', 'ip'], array_keys($snapshot['include']));
    }

    /**
     * The snapshot is stored in a queue row that outlives the request. It must
     * never become a second copy of lead data, or of the recipient list.
     */
    public function testSnapshotCarriesNoRecipientsAndNoLeadData(): void
    {
        $encoded = (string) json_encode(
            NotificationSettings::snapshot($this->settings(), 'gravityforms:7', $this->siteInfo())
        );

        self::assertStringNotContainsString('sales@example.com', $encoded);
        self::assertStringNotContainsString('recipient', $encoded);
        self::assertStringNotContainsString('submission_data', $encoded);
    }

    /**
     * The TEMPLATE is frozen, not a rendered subject — rendering needs the
     * submission, which is deliberately fetched fresh at send time.
     */
    public function testSnapshotFreezesTheTemplateNotARenderedSubject(): void
    {
        $snapshot = NotificationSettings::snapshot($this->settings(), 'gravityforms:7', $this->siteInfo());

        self::assertSame('New {form_name}', $snapshot['subject']);
    }

    public function testSnapshotFreezesTheContentToggles(): void
    {
        $snapshot = NotificationSettings::snapshot(
            $this->settings(['include_ip' => true, 'include_fields' => false]),
            'gravityforms:7',
            $this->siteInfo()
        );

        self::assertTrue($snapshot['include']['ip']);
        self::assertFalse($snapshot['include']['fields']);
    }

    /**
     * A row queued by an older version, processed after an upgrade, must not
     * hit a missing index — and a toggle that did not exist when the message
     * was queued carries no consent, so it defaults OFF.
     *
     * @dataProvider malformedSnapshots
     */
    public function testNormalizeSnapshotToleratesAnythingStored(mixed $raw): void
    {
        $snapshot = NotificationSettings::normalizeSnapshot($raw);

        self::assertSame(['v', 'subject', 'site_name', 'form_key', 'include'], array_keys($snapshot));
        self::assertFalse($snapshot['include']['ip']);
        self::assertFalse($snapshot['include']['journey']);
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function malformedSnapshots(): array
    {
        return [
            'null'           => [null],
            'scalar'         => ['garbage'],
            'empty'          => [[]],
            'missing include'=> [['v' => 1, 'subject' => 'x']],
            'scalar include' => [['include' => 'yes']],
        ];
    }

    public function testNormalizeSnapshotKeepsKnownValues(): void
    {
        $snapshot = NotificationSettings::normalizeSnapshot([
            'v'         => 1,
            'subject'   => 'New {form_name}',
            'site_name' => 'Example Co',
            'form_key'  => 'wpforms:3',
            'include'   => ['fields' => true, 'analytics' => false, 'journey' => true, 'ip' => true],
            'unknown'   => 'dropped',
        ]);

        self::assertSame('New {form_name}', $snapshot['subject']);
        self::assertSame('wpforms:3', $snapshot['form_key']);
        self::assertTrue($snapshot['include']['journey']);
        self::assertFalse($snapshot['include']['analytics']);
        self::assertArrayNotHasKey('unknown', $snapshot);
    }

    // ── Full sanitize ────────────────────────────────────────────────────────

    /**
     * The single most important default in this feature: upgrading must never
     * start mailing a site's leads to an inbox nobody chose.
     */
    public function testNotificationsAreDisabledByDefault(): void
    {
        self::assertFalse(NotificationSettings::sanitize([])['enabled']);
    }

    /**
     * The shipped defaults, which apply on a site that has never saved this
     * page. Fields and the analytics summary are on; both privacy-sensitive
     * toggles are off.
     */
    public function testShippedDefaults(): void
    {
        $defaults = Options::notificationDefaults();

        self::assertFalse($defaults['enabled']);
        self::assertTrue($defaults['include_fields']);
        self::assertTrue($defaults['include_analytics']);
        self::assertFalse($defaults['include_journey']);
        self::assertFalse($defaults['include_ip']);
        self::assertSame([], $defaults['recipients']);
        self::assertSame('all', $defaults['scope']);
    }

    /**
     * sanitize() reads a submitted FORM, where an unchecked box is simply
     * absent — so an empty POST means "every box unchecked", not "restore the
     * defaults". Conflating the two would make it impossible to ever turn a
     * default-on toggle off.
     */
    public function testAnEmptyPostMeansEveryBoxUnchecked(): void
    {
        $clean = NotificationSettings::sanitize([]);

        self::assertFalse($clean['include_fields']);
        self::assertFalse($clean['include_analytics']);
        self::assertFalse($clean['include_journey']);
        self::assertFalse($clean['include_ip']);
    }

    public function testIncludeTogglesCoerceToBooleans(): void
    {
        $clean = NotificationSettings::sanitize(['include_ip' => '1', 'include_fields' => '0']);

        self::assertTrue($clean['include_ip']);
        self::assertFalse($clean['include_fields']);
    }

    public function testUnknownScopeFallsBackToAll(): void
    {
        self::assertSame('all', NotificationSettings::sanitize(['scope' => 'sideways'])['scope']);
    }

    public function testSanitizeEmitsOnlyTheKnownShape(): void
    {
        $clean = NotificationSettings::sanitize(['enabled' => '1', 'evil' => 'payload']);

        self::assertArrayNotHasKey('evil', $clean);
        self::assertSame([
            'enabled', 'recipients', 'subject', 'scope', 'forms',
            'include_fields', 'include_analytics', 'include_journey', 'include_ip',
        ], array_keys($clean));
    }
}
