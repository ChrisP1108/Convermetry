<?php

declare(strict_types=1);

namespace Convermetry\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Convermetry\Support\Extensions;
use PHPUnit\Framework\TestCase;

/**
 * The gate every third-party extension bucket passes through.
 *
 * The load-bearing assertion in this file is {@see
 * testAttachReturnsTheTargetUnchangedWhenNothingSurvives()}: it is what makes
 * "a site with no integrations sends the same payload bytes it always did" a
 * property of one function rather than a rule twelve call sites remember. Every
 * other test here defends a boundary that, if it moved, would let third-party
 * data reach a wire, a page, or a database column it was never meant to.
 */
final class ExtensionsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        // The real wp_json_encode() also escapes slashes and unicode; for these
        // tests only its success/failure and byte length matter.
        Functions\when('wp_json_encode')->alias(
            static fn(mixed $value): string|false => json_encode($value)
        );
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    // ---------------------------------------------------------------- identity

    /**
     * The compatibility guarantee, stated as a test: with no callback the
     * filter returns its empty seed, nothing survives, and the caller's array
     * comes back with the same keys, the same values, and the same ORDER.
     * assertSame on arrays compares order, which is the half that matters for
     * byte-identical JSON.
     */
    public function testAttachReturnsTheTargetUnchangedWhenNothingSurvives(): void
    {
        Functions\when('apply_filters')->returnArg(2);

        $target = ['schema_version' => '1.0', 'source' => 'convermetry', 'analytics' => ['totals' => []]];

        $result = Extensions::attach($target, 'extensions', 'some_filter', 1024, 10);

        self::assertSame($target, $result);
        self::assertArrayNotHasKey('extensions', $result);
    }

    public function testAttachAddsThePropertyOnlyWhenExtensionDataSurvives(): void
    {
        Functions\when('apply_filters')->justReturn(['acme/orders' => ['count' => 3]]);

        $result = Extensions::attach(['source' => 'convermetry'], 'extensions', 'some_filter', 1024, 10);

        self::assertSame(['source' => 'convermetry', 'extensions' => ['acme/orders' => ['count' => 3]]], $result);
    }

    /**
     * A core property of the same name must win. '+' keeps the left operand,
     * so extension data can never replace core data even if a callback tries.
     */
    public function testACorePropertyIsNeverReplacedByExtensionData(): void
    {
        Functions\when('apply_filters')->justReturn(['acme/x' => 1]);

        $result = Extensions::attach(['extensions' => 'core-owned'], 'extensions', 'some_filter', 1024, 10);

        self::assertSame('core-owned', $result['extensions']);
    }

    // -------------------------------------------------------------------- keys

    /**
     * @dataProvider validKeys
     */
    public function testNamespacedKeysAreAccepted(string $key): void
    {
        self::assertTrue(Extensions::isValidKey($key));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function validKeys(): array
    {
        return [
            'simple'      => ['acme/orders'],
            'dotted'      => ['acme.co/order-totals'],
            'underscored' => ['my_plugin/some_metric'],
            'numeric'     => ['vendor9/thing2'],
        ];
    }

    /**
     * The '/' requirement is the whole collision-avoidance argument: no core
     * key in any Convermetry payload, report, or config contains one.
     *
     * @dataProvider invalidKeys
     */
    public function testUnnamespacedAndMalformedKeysAreRejected(mixed $key): void
    {
        self::assertFalse(Extensions::isValidKey($key));
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function invalidKeys(): array
    {
        return [
            'core key'        => ['totals'],
            'no namespace'    => ['orders'],
            'leading slash'   => ['/orders'],
            'trailing slash'  => ['acme/'],
            'two slashes'     => ['acme/x/y'],
            'space'           => ['acme /orders'],
            'empty'           => [''],
            'integer'         => [7],
            'null'            => [null],
            'array'           => [['acme/x']],
        ];
    }

    public function testAKeyThatWouldShadowACoreReportIsDropped(): void
    {
        Functions\when('apply_filters')->justReturn(['totals' => ['pageview' => 999], 'acme/ok' => 1]);

        $result = Extensions::attach(['totals' => ['pageview' => 1]], 'extensions', 'f', 1024, 10);

        self::assertSame(['acme/ok' => 1], $result['extensions']);
        self::assertSame(['pageview' => 1], $result['totals']);
    }

    // ------------------------------------------------------------------ values

    public function testJsonPrimitivesAndArraysSurvive(): void
    {
        $payload = ['acme/x' => ['s' => 'text', 'i' => 1, 'f' => 1.5, 'b' => true, 'n' => null, 'l' => [1, 2]]];

        self::assertSame($payload, Extensions::sanitize($payload, 4096, 10));
    }

    /**
     * Objects are rejected at every depth, JsonSerializable included: honouring
     * one would hand serialization to third-party code that can throw or expose
     * a private field, on a path that runs inside a webhook delivery.
     */
    public function testObjectsAreRejectedEvenWhenJsonSerializable(): void
    {
        $serializable = new class implements \JsonSerializable {
            public function jsonSerialize(): mixed
            {
                return ['looks' => 'fine'];
            }
        };

        self::assertSame([], Extensions::sanitize(['acme/x' => $serializable], 4096, 10));
        self::assertSame([], Extensions::sanitize(['acme/x' => ['nested' => $serializable]], 4096, 10));
        self::assertSame([], Extensions::sanitize(['acme/x' => new \stdClass()], 4096, 10));
    }

    public function testNonFiniteFloatsAreRejected(): void
    {
        self::assertSame([], Extensions::sanitize(['acme/x' => NAN], 4096, 10));
        self::assertSame([], Extensions::sanitize(['acme/x' => INF], 4096, 10));
    }

    public function testNestingBeyondTheDepthBoundIsRejected(): void
    {
        $deep = 'leaf';
        for ($i = 0; $i < Extensions::MAX_DEPTH + 2; $i++) {
            $deep = [$deep];
        }

        self::assertSame([], Extensions::sanitize(['acme/x' => $deep], 65536, 10));
        self::assertSame(['acme/x' => [['ok']]], Extensions::sanitize(['acme/x' => [['ok']]], 65536, 10));
    }

    public function testNonArrayFilterReturnsYieldNothing(): void
    {
        foreach ([null, false, 'string', 42, new \stdClass()] as $bad) {
            self::assertSame([], Extensions::sanitize($bad, 1024, 10));
        }
    }

    // ------------------------------------------------------------------ bounds

    /**
     * Keys are sorted before any cap is applied, so which entries survive
     * truncation depends on the data and not on the order callbacks happened to
     * register in.
     */
    public function testTruncationIsDeterministicAndSorted(): void
    {
        $raw = ['acme/z' => 1, 'acme/a' => 1, 'acme/m' => 1];

        self::assertSame(['acme/a' => 1, 'acme/m' => 1], Extensions::sanitize($raw, 4096, 2));
    }

    public function testTheEncodedSizeCapDropsEntriesUntilTheBucketFits(): void
    {
        $raw = ['acme/a' => str_repeat('x', 40), 'acme/b' => str_repeat('y', 40)];

        $result = Extensions::sanitize($raw, 70, 10);

        self::assertSame(['acme/a'], array_keys($result));
        self::assertLessThanOrEqual(70, strlen((string) json_encode($result)));
    }

    /**
     * One oversized entry yields an empty bucket rather than a silently
     * truncated value — a receiver must never be handed half a record.
     */
    public function testASingleOversizedEntryYieldsNothing(): void
    {
        self::assertSame([], Extensions::sanitize(['acme/a' => str_repeat('x', 5000)], 128, 10));
    }

    /**
     * The tracker config is inlined into every page's HTML, so its budget is
     * deliberately the smallest of the JSON surfaces.
     */
    public function testTheInlineTrackerBudgetIsSmallerThanTheWireBudgets(): void
    {
        self::assertLessThan(Extensions::WEBHOOK_MAX_BYTES, Extensions::TRACKER_MAX_BYTES);
        self::assertLessThan(Extensions::ANALYTICS_MAX_BYTES, Extensions::TRACKER_MAX_BYTES);
        self::assertLessThan(Extensions::WEBHOOK_MAX_KEYS, Extensions::TRACKER_MAX_KEYS);
    }
}
