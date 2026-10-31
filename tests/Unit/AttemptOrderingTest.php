<?php

declare(strict_types=1);

namespace Convermetry\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Convermetry\Api\TrackingController;
use Convermetry\Funnels\StepCompiler;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The link that orders a confirmed success after its own attempt.
 *
 * The browser's submit attempt and the server's confirmation are independent
 * requests, and either can be stored first. Ordered by row id alone, a success
 * stored first sat before its attempt and the funnel missed a real completion.
 * The attempt now carries its conversion token, and a success step's position
 * is never earlier than the attempt with the same token. These tests pin the
 * two halves that need no database: what the endpoint keeps of the token, and
 * the SQL a success step is positioned by. The ordering itself is executed
 * against MySQL in tests/Integration/FunnelQueryTest.
 */
final class AttemptOrderingTest extends TestCase
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
        Functions\when('get_option')->justReturn(['track_form_submit' => true]);
        Functions\when('home_url')->justReturn('https://example.com');
        Functions\when('site_url')->justReturn('https://example.com');
        Functions\when('apply_filters')->alias(static fn(string $hook, mixed $value, mixed ...$rest): mixed => $value);
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function sanitizeAttempt(mixed $eventValue): ?array
    {
        /** @var array<string, mixed>|null $result */
        $result = (new ReflectionMethod(TrackingController::class, 'sanitizeEvent'))->invoke(null, [
            'type'        => 'form_submit',
            'page_url'    => 'https://example.com/contact/',
            'form_key'    => 'gravityforms:7',
            'event_value' => $eventValue,
        ], 'desktop');

        return $result;
    }

    public function testAnAttemptKeepsItsConversionToken(): void
    {
        $sanitized = $this->sanitizeAttempt('c0123456789abcdef');

        self::assertNotNull($sanitized);
        self::assertSame('c0123456789abcdef', $sanitized['data']['event_value']);
    }

    /**
     * Anything not shaped like a conversion id is dropped rather than stored:
     * the value exists only to be matched against one.
     *
     * @dataProvider notATokens
     */
    public function testAnythingElseInAnAttemptsValueIsNotStored(mixed $value): void
    {
        $sanitized = $this->sanitizeAttempt($value);

        self::assertNotNull($sanitized, 'The attempt itself is still recorded.');
        self::assertSame('', $sanitized['data']['event_value']);
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function notATokens(): array
    {
        return [
            'empty'        => [''],
            'too short'    => ['c123'],
            'an email'     => ['ada@example.com'],
            'free text'    => ['please call me'],
            'markup'       => ['<b>c0123456789abcdef</b>'],
            'not a scalar' => [['c0123456789abcdef']],
        ];
    }

    public function testASuccessStepIsPositionedNoEarlierThanItsAttempt(): void
    {
        $step = ['type' => 'form_success', 'value' => 'gravityforms:7'];

        self::assertSame(
            'GREATEST(e2.id, COALESCE(e2_attempt.id + 0.5, 0))',
            StepCompiler::positionColumn($step, 'e2')
        );

        $join = StepCompiler::positionJoin($step, 'e2', 'wp_cvmtry_events');
        self::assertStringContainsString('LEFT JOIN wp_cvmtry_events AS e2_attempt', $join);
        self::assertStringContainsString('e2_attempt.session_id = e2.session_id', $join, 'Never across sessions.');
        self::assertStringContainsString("e2_attempt.event_type = 'form_submit'", $join);
        self::assertStringContainsString('e2_attempt.event_value = e2.event_value', $join, 'Linked by token only.');
    }

    /**
     * Only a success moves. Every other step keeps exactly the position it had,
     * and needs no join.
     */
    public function testNoOtherStepChangesPosition(): void
    {
        foreach (['page', 'form_view', 'form_start', 'form_submit'] as $type) {
            self::assertSame('e1.id', StepCompiler::positionColumn(['type' => $type], 'e1'));
            self::assertSame('', StepCompiler::positionJoin(['type' => $type], 'e1', 'wp_cvmtry_events'));
        }

        self::assertSame('e1.source_event_id', StepCompiler::positionColumn(['type' => 'goal'], 'e1'));
        self::assertSame('', StepCompiler::positionJoin(['type' => 'goal'], 'e1', 'wp_cvmtry_events'));
    }
}
