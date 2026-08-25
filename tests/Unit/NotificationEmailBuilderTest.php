<?php

declare(strict_types=1);

namespace Convermetry\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Convermetry\Analytics\SubmissionContext;
use Convermetry\Notifications\EmailBuilder;
use Convermetry\Notifications\NotificationSettings;
use PHPUnit\Framework\TestCase;

/**
 * What actually goes in a notification email.
 *
 * This is where most of the feature's risk lives: an email leaves the site and
 * cannot be recalled, so the rules about what is omitted, what is escaped, and
 * what the message claims are all pinned here rather than left to review.
 */
final class NotificationEmailBuilderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        Functions\when('esc_html')->alias(
            static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8')
        );
        Functions\when('esc_url')->alias(
            static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8')
        );
        Functions\when('sanitize_text_field')->alias(static fn($v): string => trim(strip_tags((string) $v)));
        Functions\when('wp_date')->alias(
            static fn(string $format, ?int $ts = null): string => gmdate($format, $ts ?? time())
        );
        Functions\when('wp_json_encode')->alias(
            static fn($data, $options = 0, $depth = 512) => json_encode($data, $options, $depth)
        );
        Functions\when('add_query_arg')->alias(static function (array $args, string $url): string {
            return $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($args);
        });
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    /** @return array<string, string> */
    private function siteInfo(): array
    {
        return [
            'site_name' => 'Example Co',
            'home_url'  => 'https://example.com/',
            'admin_url' => 'https://example.com/wp-admin/admin.php',
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function submission(array $overrides = []): array
    {
        return array_merge([
            'submission_id'   => 's5f2a9c1b8e0d21f06c5',
            'provider'        => 'gravityforms',
            'form_name'       => 'Contact Form',
            'form_id'         => 'contact-form-01',
            'page_url'        => 'https://example.com/contact',
            'ip_address'      => '203.0.113.9',
            'created_at'      => '2026-08-22 14:32:00',
            'submission_data' => (string) json_encode([
                ['id' => 'name',  'label' => 'Full name',     'value' => 'John Doe'],
                ['id' => 'email', 'label' => 'Email address', 'value' => 'john@example.com'],
            ]),
        ], $overrides);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function context(array $overrides = []): array
    {
        return SubmissionContext::withDefaults(array_merge([
            'channel'            => 'Paid Search',
            'attribution'        => ['utm_source' => 'google', 'utm_medium' => 'cpc', 'utm_campaign' => 'retirement'],
            'landing_page'       => ['url' => 'https://example.com/retirement/'],
            'device'             => 'desktop',
            'pageview_count'     => 4,
            'session_started_at' => '2026-08-22T14:20:11+00:00',
            'recent_pages'       => ['https://example.com/contact', 'https://example.com/retirement/'],
        ], $overrides));
    }

    /**
     * @param array<string, bool> $include
     * @return array<string, mixed>
     */
    private function snapshot(array $include = [], string $subject = 'New {form_name} on {site_name}'): array
    {
        return NotificationSettings::normalizeSnapshot([
            'v'         => 1,
            'subject'   => $subject,
            'site_name' => 'Example Co',
            'form_key'  => 'gravityforms:7',
            'include'   => array_merge(
                ['fields' => true, 'analytics' => true, 'journey' => false, 'ip' => false],
                $include
            ),
        ]);
    }

    // ── Subject ──────────────────────────────────────────────────────────────

    public function testSubjectSubstitutesEveryDocumentedToken(): void
    {
        $subject = EmailBuilder::subject(
            '{site_name}|{form_name}|{provider}|{channel}|{submission_id}|{form_id}|{campaign}|{date}',
            $this->submission(),
            $this->context(),
            $this->siteInfo()
        );

        self::assertSame(
            'Example Co|Contact Form|gravityforms|Paid Search|s5f2a9c1b8e0d21f06c5|contact-form-01|retirement|2026-08-22',
            $subject
        );
    }

    /**
     * The header-injection regression. Form names come from third-party form
     * plugins and are typed by whoever built the form, so this is a reachable
     * input — and it is why cleanup happens AFTER substitution, not only at
     * save time.
     */
    public function testCarriageReturnsInjectedThroughTheFormNameAreStripped(): void
    {
        $subject = EmailBuilder::subject(
            'New {form_name}',
            $this->submission(['form_name' => "Contact\r\nBcc: attacker@example.test"]),
            $this->context(),
            $this->siteInfo()
        );

        self::assertStringNotContainsString("\r", $subject);
        self::assertStringNotContainsString("\n", $subject);
        self::assertSame('New Contact Bcc: attacker@example.test', $subject);
    }

    public function testNewlinesInTheTemplateItselfAreStripped(): void
    {
        $subject = EmailBuilder::subject(
            "Lead\nBcc: attacker@example.test",
            $this->submission(),
            $this->context(),
            $this->siteInfo()
        );

        self::assertStringNotContainsString("\n", $subject);
    }

    public function testNullBytesAreStripped(): void
    {
        $subject = EmailBuilder::subject("Lead\x00injected", $this->submission(), $this->context(), $this->siteInfo());

        self::assertStringNotContainsString("\x00", $subject);
    }

    public function testSubjectFallsBackWhenTheTemplateRendersEmpty(): void
    {
        $subject = EmailBuilder::subject(
            '{form_name}',
            $this->submission(['form_name' => '']),
            $this->context(),
            $this->siteInfo()
        );

        self::assertSame('New form submission', $subject);
    }

    public function testSubjectIsTruncated(): void
    {
        $subject = EmailBuilder::subject(str_repeat('x', 400), $this->submission(), $this->context(), $this->siteInfo());

        self::assertSame(NotificationSettings::SUBJECT_MAX_LEN, mb_strlen($subject));
    }

    public function testUnknownTokensAreLeftAsLiteralText(): void
    {
        $subject = EmailBuilder::subject('{evil_token}', $this->submission(), $this->context(), $this->siteInfo());

        self::assertSame('{evil_token}', $subject);
    }

    // ── Body: escaping ───────────────────────────────────────────────────────

    /**
     * Markup in a value is caught twice, and both layers are worth pinning.
     *
     * sanitize_text_field() strips the tags when the field is normalized, so a
     * <script> never even reaches the builder. That is the layer that would
     * disappear silently if normalization changed, hence the second test below
     * covering characters that DO survive sanitizing and must still be escaped
     * on the way into the HTML.
     */
    public function testTagsInFieldValuesDoNotReachTheEmail(): void
    {
        $html = EmailBuilder::body(
            $this->submission(['submission_data' => (string) json_encode([
                ['id' => 'msg', 'label' => 'Message', 'value' => '<script>alert(1)</script>'],
            ])]),
            $this->context(),
            $this->snapshot(),
            $this->siteInfo()
        );

        self::assertStringNotContainsString('<script', $html);
        self::assertStringNotContainsString('</script', $html);
    }

    public function testCharactersThatSurviveSanitizingAreHtmlEscaped(): void
    {
        $html = EmailBuilder::body(
            $this->submission(['submission_data' => (string) json_encode([
                ['id' => 'msg', 'label' => 'Q & A', 'value' => 'Tom & Jerry said "hi"'],
            ])]),
            $this->context(),
            $this->snapshot(),
            $this->siteInfo()
        );

        self::assertStringContainsString('Tom &amp; Jerry', $html);
        self::assertStringContainsString('&quot;hi&quot;', $html);
        self::assertStringContainsString('Q &amp; A', $html);
        self::assertStringNotContainsString('Tom & Jerry', $html);
    }

    public function testHtmlInFieldLabelsIsEscaped(): void
    {
        $html = EmailBuilder::body(
            $this->submission(['submission_data' => (string) json_encode([
                ['id' => 'a', 'label' => '<img src=x onerror=alert(1)>', 'value' => 'v'],
            ])]),
            $this->context(),
            $this->snapshot(),
            $this->siteInfo()
        );

        self::assertStringNotContainsString('<img src=x', $html);
    }

    public function testUrlsInTheJourneyAreEscaped(): void
    {
        $html = EmailBuilder::body(
            $this->submission(),
            $this->context(['recent_pages' => ['https://example.com/"><script>alert(1)</script>']]),
            $this->snapshot(['journey' => true]),
            $this->siteInfo()
        );

        self::assertStringNotContainsString('<script>', $html);
    }

    /**
     * A cheap structural guard: the builder must never regress into an
     * ob_start()/echo style, which would trip beStrictAboutOutputDuringTests
     * across the whole suite from a distance.
     */
    public function testTheBuilderEmitsNothing(): void
    {
        ob_start();
        EmailBuilder::body($this->submission(), $this->context(), $this->snapshot(), $this->siteInfo());

        self::assertSame('', (string) ob_get_clean());
    }

    // ── Body: fields ─────────────────────────────────────────────────────────

    public function testEachFieldBecomesItsOwnRow(): void
    {
        $html = EmailBuilder::body($this->submission(), $this->context(), $this->snapshot(), $this->siteInfo());

        self::assertStringContainsString('Full name', $html);
        self::assertStringContainsString('John Doe', $html);
        self::assertStringContainsString('Email address', $html);
        self::assertStringContainsString('john@example.com', $html);
    }

    public function testDuplicateLabelsRenderAsSeparateRows(): void
    {
        $fields = EmailBuilder::fields($this->submission(['submission_data' => (string) json_encode([
            ['id' => '1', 'label' => 'Name', 'value' => 'Ada'],
            ['id' => '2', 'label' => 'Name', 'value' => 'Grace'],
        ])]));

        self::assertSame(
            [['label' => 'Name', 'value' => 'Ada'], ['label' => 'Name', 'value' => 'Grace']],
            $fields
        );
    }

    public function testMultiValueFieldsAreFlattened(): void
    {
        $fields = EmailBuilder::fields($this->submission(['submission_data' => (string) json_encode([
            ['id' => 'i', 'label' => 'Interests', 'value' => ['Tax planning', 'Retirement']],
        ])]));

        self::assertSame('Tax planning, Retirement', $fields[0]['value']);
    }

    /** Historical rows still hold the pre-2.0 map and must render identically. */
    public function testHistoricalMapRowsRender(): void
    {
        $fields = EmailBuilder::fields($this->submission([
            'submission_data' => '{"Email":"john@example.com","Phone":"555"}',
        ]));

        self::assertSame(
            [['label' => 'Email', 'value' => 'john@example.com'], ['label' => 'Phone', 'value' => '555']],
            $fields
        );
    }

    /**
     * The rule that matters most. A credential-looking field is omitted
     * ENTIRELY — not shown as [REDACTED], which would only announce to every
     * recipient that a secret exists.
     *
     * @dataProvider sensitiveFieldNames
     */
    public function testFieldsThatLookLikeCredentialsAreOmittedEntirely(string $id, string $label): void
    {
        $html = EmailBuilder::body(
            $this->submission(['submission_data' => (string) json_encode([
                ['id' => 'email', 'label' => 'Email address', 'value' => 'john@example.com'],
                ['id' => $id,     'label' => $label,          'value' => 'sup3rs3cret'],
            ])]),
            $this->context(),
            $this->snapshot(),
            $this->siteInfo()
        );

        self::assertStringNotContainsString('sup3rs3cret', $html, 'The secret VALUE must appear nowhere');
        self::assertStringNotContainsString('[REDACTED]', $html, 'Omit the row, do not advertise it');
        self::assertStringContainsString('john@example.com', $html, 'Ordinary fields still render');
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function sensitiveFieldNames(): array
    {
        return [
            'id password'      => ['password', 'Choose something'],
            'label password'   => ['field_9', 'Password'],
            'human api key'    => ['field_3', 'API Key'],
            'token'            => ['user_token', 'Reference'],
            'client secret'    => ['field_1', 'Client Secret'],
            'authorization'    => ['authorization', 'Auth value'],
        ];
    }

    public function testAnOrdinaryFieldNamedAuthorIsNotMistakenForACredential(): void
    {
        $fields = EmailBuilder::fields($this->submission(['submission_data' => (string) json_encode([
            ['id' => 'author', 'label' => 'Author', 'value' => 'Jane Doe'],
        ])]));

        self::assertSame([['label' => 'Author', 'value' => 'Jane Doe']], $fields);
    }

    public function testLongValuesAreTruncatedForDisplay(): void
    {
        $fields = EmailBuilder::fields($this->submission(['submission_data' => (string) json_encode([
            ['id' => 'msg', 'label' => 'Message', 'value' => str_repeat('a', 5000)],
        ])]));

        self::assertLessThan(5000, mb_strlen($fields[0]['value']));
        self::assertStringEndsWith('…', $fields[0]['value']);
    }

    // ── Body: toggles ────────────────────────────────────────────────────────

    public function testTheFieldsSectionIsOmittedWhenTurnedOff(): void
    {
        $html = EmailBuilder::body(
            $this->submission(),
            $this->context(),
            $this->snapshot(['fields' => false]),
            $this->siteInfo()
        );

        self::assertStringNotContainsString('Submitted fields', $html);
        self::assertStringNotContainsString('john@example.com', $html);
    }

    public function testTheAnalyticsSectionIsOmittedWhenTurnedOff(): void
    {
        $html = EmailBuilder::body(
            $this->submission(),
            $this->context(),
            $this->snapshot(['analytics' => false]),
            $this->siteInfo()
        );

        self::assertStringNotContainsString('Analytics &amp; attribution', $html);
        self::assertStringNotContainsString('Paid Search', $html);
    }

    public function testTheAnalyticsSectionCarriesEveryDocumentedValue(): void
    {
        $html = EmailBuilder::body($this->submission(), $this->context(), $this->snapshot(), $this->siteInfo());

        foreach (['Paid Search', 'google', 'cpc', 'retirement', 'desktop', 'https://example.com/retirement/'] as $needle) {
            self::assertStringContainsString($needle, $html, "Missing analytics value: {$needle}");
        }

        self::assertStringContainsString('Conversion page', $html);
    }

    public function testTheJourneyIsAbsentByDefault(): void
    {
        $html = EmailBuilder::body($this->submission(), $this->context(), $this->snapshot(), $this->siteInfo());

        self::assertStringNotContainsString('Recent pages', $html);
    }

    public function testTheJourneyAppearsWhenEnabled(): void
    {
        $html = EmailBuilder::body(
            $this->submission(),
            $this->context(),
            $this->snapshot(['journey' => true]),
            $this->siteInfo()
        );

        self::assertStringContainsString('Recent pages', $html);
    }

    /** sessionSummary() stores newest-first; a journey reads forward. */
    public function testTheJourneyIsOrderedOldestFirst(): void
    {
        $items = EmailBuilder::journeyItems($this->context([
            'recent_pages' => ['/third', '/second', '/first'],
        ]));

        self::assertSame(['/first', '/second', '/third'], $items);
    }

    public function testTheIpIsAbsentByDefault(): void
    {
        $html = EmailBuilder::body($this->submission(), $this->context(), $this->snapshot(), $this->siteInfo());

        self::assertStringNotContainsString('203.0.113.9', $html);
    }

    public function testTheIpAppearsWhenExplicitlyEnabled(): void
    {
        $html = EmailBuilder::body(
            $this->submission(),
            $this->context(),
            $this->snapshot(['ip' => true]),
            $this->siteInfo()
        );

        self::assertStringContainsString('203.0.113.9', $html);
        self::assertStringContainsString('IP address', $html);
    }

    // ── Body: missing analytics ──────────────────────────────────────────────

    public function testAnUncorrelatedSubmissionSaysSoExplicitly(): void
    {
        $html = EmailBuilder::body(
            $this->submission(),
            SubmissionContext::withDefaults([]),
            $this->snapshot(),
            $this->siteInfo()
        );

        self::assertStringContainsString('Analytics context was unavailable', $html);
    }

    /**
     * The subtle one. The IP is resolved server-side, independently of the
     * tracker, so a server-to-server submission has an address and no
     * attribution at all — which is precisely when the explanation is needed.
     * Counting the IP as "analytics present" would suppress it.
     */
    public function testTheIpDoesNotCountAsAnalyticsPresence(): void
    {
        $html = EmailBuilder::body(
            $this->submission(),
            SubmissionContext::withDefaults([]),
            $this->snapshot(['ip' => true]),
            $this->siteInfo()
        );

        self::assertStringContainsString('Analytics context was unavailable', $html);
        self::assertStringContainsString('203.0.113.9', $html);
    }

    public function testHasAnalyticsIsFalseForAnEmptyRowSet(): void
    {
        self::assertFalse(EmailBuilder::hasAnalytics([]));
        self::assertTrue(EmailBuilder::hasAnalytics([['label' => 'Channel', 'value' => 'Email']]));
    }

    // ── Link + footer ────────────────────────────────────────────────────────

    public function testTheBodyLinksToThisSubmissionInWpAdmin(): void
    {
        $url = EmailBuilder::detailUrl($this->submission(), $this->siteInfo());

        self::assertStringContainsString('page=convermetry-submissions', $url);
        self::assertStringContainsString('cvm_search=s5f2a9c1b8e0d21f06c5', $url);
    }

    public function testTheFooterStatesTheRetentionImplication(): void
    {
        $html = EmailBuilder::body($this->submission(), $this->context(), $this->snapshot(), $this->siteInfo());

        self::assertStringContainsString('retention', $html);
    }

    // ── Test message ─────────────────────────────────────────────────────────

    public function testTheTestMessageIsMarkedAsATest(): void
    {
        self::assertStringStartsWith('[Test] ', EmailBuilder::testMessage($this->snapshot(), $this->siteInfo())['subject']);
    }

    /**
     * A test send must never be able to expose a real lead — not the most
     * recent one, not any one. The synthetic row is the only input.
     */
    public function testTheTestMessageUsesSyntheticDataOnly(): void
    {
        $message = EmailBuilder::testMessage($this->snapshot(['ip' => true]), $this->siteInfo());

        self::assertStringContainsString('Test Person', $message['html']);
        self::assertStringContainsString('test@example.com', $message['html']);
        // RFC 5737 documentation range.
        self::assertStringContainsString('203.0.113.42', $message['html']);
        self::assertStringNotContainsString('john@example.com', $message['html']);
    }

    public function testTheTestMessageHonorsTheContentToggles(): void
    {
        $off = EmailBuilder::testMessage($this->snapshot(['journey' => false]), $this->siteInfo());
        $on  = EmailBuilder::testMessage($this->snapshot(['journey' => true]), $this->siteInfo());

        self::assertStringNotContainsString('Recent pages', $off['html']);
        self::assertStringContainsString('Recent pages', $on['html']);
    }

    public function testTheTestMessageShowsAnalyticsSoTheToggleIsVisible(): void
    {
        $message = EmailBuilder::testMessage($this->snapshot(), $this->siteInfo());

        self::assertStringContainsString('Paid Search', $message['html']);
    }
}
