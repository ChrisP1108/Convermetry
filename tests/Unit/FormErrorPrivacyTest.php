<?php

declare(strict_types=1);

namespace Convermetry\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Convermetry\Api\TrackingController;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Form abandonment analytics must never record what a visitor typed.
 *
 * This is the hardest privacy line in the release. Abandonment reporting is
 * useful precisely because it describes the fields people struggle with — and
 * the struggle happens in fields containing an email address, a phone number, a
 * name, or a message. An implementation that recorded "the phone field failed
 * validation with value 07700 900461" would be far more useful and completely
 * unacceptable.
 *
 * The defence is structural rather than a blocklist: a form_error event is
 * REBUILT from three whitelisted pieces (field id, field type, error category)
 * and everything else in the request is discarded by construction. A blocklist
 * would need extending every time a browser or a form plugin invents a new
 * property; a whitelist is wrong only if someone adds to it deliberately.
 *
 * These tests exercise the sanitizer directly, because that is the single choke
 * point every write path goes through.
 */
final class FormErrorPrivacyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        Functions\when('sanitize_key')->alias(
            static fn(string $key): string => strtolower((string) preg_replace('/[^a-z0-9_\-]/i', '', $key))
        );
        Functions\when('sanitize_text_field')->alias(static fn(string $v): string => trim(strip_tags($v)));
        Functions\when('wp_parse_url')->alias(static fn(string $u, int $c = -1) => parse_url($u, $c));
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * Runs one raw event through the endpoint's sanitizer.
     *
     * @param array<string, mixed> $event Raw event from a request body.
     * @return array<string, mixed>|null
     */
    private function sanitize(array $event): ?array
    {
        // Options::isTypeEnabled() and allowedHosts() are the only WordPress
        // state sanitizeEvent() reaches for.
        Functions\when('get_option')->justReturn([
            'track_form_error'   => true,
            'track_custom_event' => true,
            'track_pageview'     => true,
        ]);
        Functions\when('home_url')->justReturn('https://example.com');
        Functions\when('site_url')->justReturn('https://example.com');
        Functions\when('apply_filters')->alias(
            static fn(string $hook, mixed $value, mixed ...$rest): mixed => $value
        );

        $method = new ReflectionMethod(TrackingController::class, 'sanitizeEvent');

        /** @var array<string, mixed>|null $result */
        $result = $method->invoke(null, $event, 'desktop');

        return $result;
    }

    /**
     * The payload a careless or malicious client might send. None of the
     * value-bearing keys may survive into the stored data.
     *
     * @return array<string, mixed>
     */
    private function hostileErrorEvent(): array
    {
        return [
            'type'       => 'form_error',
            'page_url'   => 'https://example.com/contact/',
            'form_key'   => 'gravityforms:7',
            'field_id'   => 'phone',
            'field_type' => 'tel',
            'error_type' => 'required',
            // Everything below is what must not be recorded.
            'value'         => '07700 900461',
            'field_value'   => 'ada@example.com',
            'email'         => 'ada@example.com',
            'message'       => 'Please call me about the retirement consultation',
            'password'      => 'hunter2',
            'element_label' => 'Ada Lovelace',
            'event_value'   => 'ada@example.com',
            'target_url'    => 'https://example.com/?email=ada@example.com',
        ];
    }

    public function testNoValueBearingKeySurvivesSanitization(): void
    {
        $sanitized = $this->sanitize($this->hostileErrorEvent());

        self::assertNotNull($sanitized);

        $serialized = strtolower((string) json_encode($sanitized['data']));

        foreach ([
            '07700',
            'ada@example.com',
            'retirement consultation',
            'hunter2',
            'ada lovelace',
        ] as $secret) {
            self::assertStringNotContainsString(
                strtolower($secret),
                $serialized,
                "A visitor-entered value reached the stored event: {$secret}"
            );
        }
    }

    public function testOnlyTheWhitelistedFieldMetadataIsKept(): void
    {
        $sanitized = $this->sanitize($this->hostileErrorEvent());

        self::assertNotNull($sanitized);

        self::assertSame('phone', $sanitized['data']['element_label'], 'field id');
        self::assertSame('tel', $sanitized['data']['element_tag'], 'field type');
        self::assertSame('required', $sanitized['data']['event_value'], 'error category');
        self::assertSame('', $sanitized['data']['target_url']);
    }

    /**
     * The keys a client invents must simply not exist in the result. Asserting
     * on the exact key set is what makes this a whitelist test rather than a
     * "we remembered to strip the ones we thought of" test.
     */
    public function testUnknownKeysAreDroppedEntirely(): void
    {
        $sanitized = $this->sanitize($this->hostileErrorEvent());

        self::assertNotNull($sanitized);

        foreach (['value', 'field_value', 'email', 'message', 'password'] as $key) {
            self::assertArrayNotHasKey($key, $sanitized['data'], "Unknown key '{$key}' was carried through.");
        }
    }

    /**
     * An error category outside the known list becomes 'invalid' rather than
     * being stored verbatim — otherwise the category column would be a free-text
     * field that reporting later renders.
     */
    public function testUnknownErrorCategoriesCollapseToInvalid(): void
    {
        foreach (['made_up', 'ada@example.com', '<script>alert(1)</script>', ''] as $reported) {
            $event               = $this->hostileErrorEvent();
            $event['error_type'] = $reported;

            $sanitized = $this->sanitize($event);

            self::assertNotNull($sanitized);
            self::assertSame(
                'invalid',
                $sanitized['data']['event_value'],
                "Unrecognized error category '{$reported}' was not normalized."
            );
        }
    }

    /**
     * @dataProvider knownErrorCategories
     */
    public function testKnownErrorCategoriesAreKept(string $category): void
    {
        $event               = $this->hostileErrorEvent();
        $event['error_type'] = $category;

        $sanitized = $this->sanitize($event);

        self::assertNotNull($sanitized);
        self::assertSame($category, $sanitized['data']['event_value']);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function knownErrorCategories(): array
    {
        return [
            'required'      => ['required'],
            'type mismatch' => ['type_mismatch'],
            'pattern'       => ['pattern'],
            'too short'     => ['too_short'],
            'too long'      => ['too_long'],
            'range'         => ['range'],
            'step'          => ['step'],
        ];
    }

    /**
     * A field id is a developer-chosen name. Restricting its characters and
     * length means an implementation that mistakenly sent a typed value would be
     * stripped to something unrecognizable rather than quietly storing it — and
     * the length bound alone rules out message bodies.
     */
    public function testFieldIdentifiersAreRestrictedAndBounded(): void
    {
        $event             = $this->hostileErrorEvent();
        $event['field_id'] = 'Please call me about my account, my email is ada@example.com and my number is 07700900461';

        $sanitized = $this->sanitize($event);

        self::assertNotNull($sanitized);

        $stored = $sanitized['data']['element_label'];

        self::assertLessThanOrEqual(64, strlen($stored));
        self::assertStringNotContainsString('@', $stored, 'An email address survived the field-id filter.');
        self::assertStringNotContainsString(' ', $stored, 'Free text survived the field-id filter.');
    }

    /**
     * A custom event's payload is not storage. Only its name can match a goal,
     * and only a numeric value is read — so an object of arbitrary properties
     * cannot become a row of arbitrary text.
     */
    public function testCustomEventPayloadIsNotStoredAsText(): void
    {
        $sanitized = $this->sanitize([
            'type'     => 'custom_event',
            'page_url' => 'https://example.com/contact/',
            'name'     => 'appointment_booked',
            'value'    => '250.00',
            // Not read by anything.
            'notes'         => 'caller said she is retiring in March',
            'email'         => 'ada@example.com',
            'element_label' => 'should be overwritten by name',
        ]);

        self::assertNotNull($sanitized);
        self::assertSame('appointment_booked', $sanitized['data']['element_label']);
        self::assertSame('250.00', $sanitized['data']['goal_value']);

        $serialized = (string) json_encode($sanitized['data']);

        self::assertStringNotContainsString('retiring in March', $serialized);
        self::assertStringNotContainsString('ada@example.com', $serialized);
    }
}
