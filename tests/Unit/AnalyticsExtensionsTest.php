<?php

declare(strict_types=1);

namespace Convermetry\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Convermetry\Analytics\AnalyticsSectionInterface;
use Convermetry\Analytics\AnalyticsSectionRegistry;
use Convermetry\Analytics\Reports;
use PHPUnit\Framework\TestCase;

/**
 * Third-party analytics sections and the extension data they produce.
 *
 * Two costs are being controlled here. The first is that this data reaches a
 * webhook payload, so it has to be gathered once — at freeze time — and never
 * rebuilt for a retry, or two attempts at the "same" delivery would carry
 * different numbers. Computing it inside buildSummary() is what makes that
 * automatic.
 *
 * The second is that a broken third-party section must not be able to take down
 * a scheduled delivery and the dashboard with it. That means catching Throwable
 * around a section — which is the exact opposite of what Reports does for its
 * own queries, where a failure MUST propagate rather than be mistaken for zeros.
 * The asymmetry is deliberate and this file pins both halves.
 */
final class AnalyticsExtensionsTest extends TestCase
{
    private const PLUGIN_DIR = __DIR__ . '/../../';

    /** @var list<array{0: string, 1: list<mixed>}> */
    private array $fired = [];

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        $this->fired = [];
        AnalyticsSectionRegistry::reset();

        Functions\when('wp_json_encode')->alias(
            static fn($data, $options = 0, $depth = 512) => json_encode($data, $options, $depth)
        );
        Functions\when('do_action')->alias(function (string $hook, mixed ...$args): void {
            $this->fired[] = [$hook, $args];
        });
    }

    protected function tearDown(): void
    {
        AnalyticsSectionRegistry::reset();
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * @param array<string, mixed>|\Throwable $result
     */
    private function section(string $key, array|\Throwable $result): AnalyticsSectionInterface
    {
        return new class ($key, $result) implements AnalyticsSectionInterface {
            /**
             * @param array<string, mixed>|\Throwable $result
             */
            public function __construct(
                private readonly string $key,
                private readonly array|\Throwable $result
            ) {
            }

            public function getKey(): string
            {
                return $this->key;
            }

            public function getLabel(): string
            {
                return 'Section ' . $this->key;
            }

            public function getDescription(): string
            {
                return '';
            }

            public function summarize(string $start, string $end, int $limit): array
            {
                if ($this->result instanceof \Throwable) {
                    throw $this->result;
                }

                return $this->result;
            }

            public function render(array $summary): void
            {
            }
        };
    }

    /**
     * @param AnalyticsSectionInterface[] $sections
     */
    private function register(array $sections): void
    {
        Functions\when('apply_filters')->alias(
            static fn(string $hook, mixed $value, mixed ...$rest) => $hook === 'convermetry_analytics_sections'
                ? $sections
                : $value
        );
    }

    // ------------------------------------------------------------ zero cost

    /**
     * The cost of this feature on a site that does not use it: nothing runs.
     */
    public function testNothingRegisteredMeansNoExtensionData(): void
    {
        Functions\when('apply_filters')->returnArg(2);

        self::assertSame([], Reports::extensionSummaries('2026-08-01 00:00:00', '2026-08-08 00:00:00', 10));
    }

    /**
     * The literal return array in buildSummary() must keep exactly its
     * fourteen sections, with 'extensions' attached conditionally afterwards —
     * folding it into the literal would emit an empty property on every site.
     */
    public function testTheSummaryLiteralStillHasExactlyFourteenSections(): void
    {
        $source = (string) file_get_contents(self::PLUGIN_DIR . 'src/Analytics/Reports.php');
        $start  = strpos($source, 'public static function buildSummary');
        self::assertIsInt($start);

        $literal = substr($source, $start, (int) strpos($source, 'extensionSummaries($start', $start) - $start);

        self::assertSame(14, preg_match_all("~^\s{12}'[a-z_]+'\s*=>~m", $literal));
        self::assertMatchesRegularExpression(
            '~\$extensions === \[\] \? \$summary : \$summary \+ \[\'extensions\' => \$extensions\]~',
            $source,
            "The attach must be conditional, or every site grows an empty 'extensions' property."
        );
    }

    // ----------------------------------------------------------- collection

    public function testRegisteredSectionsAreCollectedUnderTheirNamespacedKeys(): void
    {
        $this->register([
            $this->section('acme/subscriptions', ['active' => 12]),
            $this->section('acme/refunds', ['count' => 3]),
        ]);

        self::assertSame(
            ['acme/refunds' => ['count' => 3], 'acme/subscriptions' => ['active' => 12]],
            Reports::extensionSummaries('2026-08-01 00:00:00', '2026-08-08 00:00:00', 10)
        );
    }

    public function testASectionThatThrowsIsDroppedAndAnnouncedWhileItsSiblingsSurvive(): void
    {
        $this->register([
            $this->section('acme/broken', new \RuntimeException('database went away')),
            $this->section('acme/fine', ['ok' => true]),
        ]);

        $result = Reports::extensionSummaries('2026-08-01 00:00:00', '2026-08-08 00:00:00', 10);

        self::assertSame(['acme/fine' => ['ok' => true]], $result);

        self::assertSame('convermetry_analytics_report_failed', $this->fired[0][0]);
        self::assertSame(
            ['analytics_section', 'acme/broken', '2026-08-01 00:00:00', '2026-08-08 00:00:00', \RuntimeException::class],
            $this->fired[0][1]
        );
    }

    /**
     * The class name, never the message: a database exception quotes the
     * failing statement, which on these tables means row values.
     */
    public function testTheFailureActionCarriesNoExceptionMessage(): void
    {
        $this->register([$this->section('acme/broken', new \RuntimeException('SELECT email FROM wp_cvm_events'))]);

        Reports::extensionSummaries('2026-08-01 00:00:00', '2026-08-08 00:00:00', 10);

        self::assertStringNotContainsString('SELECT', (string) json_encode($this->fired[0][1]));
        self::assertStringNotContainsString('email', (string) json_encode($this->fired[0][1]));
    }

    // ------------------------------------------------------------- registry

    public function testTheRegistryDropsAnythingThatIsNotASection(): void
    {
        $this->register([$this->section('acme/ok', []), new \stdClass(), 'not an object', 42]);

        self::assertSame(['acme/ok'], array_keys(AnalyticsSectionRegistry::all()));
    }

    /**
     * A section keyed 'totals' would shadow a core report inside
     * analytics.extensions and confuse every receiver reading it.
     */
    public function testASectionWhoseKeyCouldShadowACoreReportIsRejected(): void
    {
        $this->register([
            $this->section('totals', ['fake' => 1]),
            $this->section('no-namespace', ['fake' => 1]),
            $this->section('acme/real', ['ok' => 1]),
        ]);

        self::assertSame(['acme/real'], array_keys(AnalyticsSectionRegistry::all()));
    }

    public function testTheRegistryIsResolvedOnlyOncePerRequest(): void
    {
        $calls = 0;

        Functions\when('apply_filters')->alias(
            static function (string $hook, mixed $value, mixed ...$rest) use (&$calls) {
                if ($hook === 'convermetry_analytics_sections') {
                    $calls++;
                }

                return $value;
            }
        );

        AnalyticsSectionRegistry::all();
        AnalyticsSectionRegistry::all();

        self::assertSame(1, $calls);
    }

    // ---------------------------------------------------------- the filter

    public function testExtensionDataIsBoundedBeforeItReachesTheWire(): void
    {
        Functions\when('apply_filters')->alias(
            static fn(string $hook, mixed $value, mixed ...$rest) => match ($hook) {
                'convermetry_analytics_sections'   => [],
                'convermetry_analytics_extensions' => [
                    'acme/ok'        => ['n' => 1],
                    'not_namespaced' => ['n' => 2],
                    'acme/object'    => new \stdClass(),
                ],
                default => $value,
            }
        );

        self::assertSame(
            ['acme/ok' => ['n' => 1]],
            Reports::extensionSummaries('2026-08-01 00:00:00', '2026-08-08 00:00:00', 10)
        );
    }

    /**
     * Source-contract: the freeze-time guarantee. The data is gathered inside
     * buildSummary(), which a frozen retry never calls again — so a retry
     * resends the numbers it froze rather than fresh ones.
     */
    public function testExtensionDataIsGatheredInsideTheSummaryBuildAndNowhereElse(): void
    {
        foreach (['src/Webhook/AnalyticsDispatcher.php', 'src/Webhook/PayloadBuilder.php'] as $file) {
            self::assertStringNotContainsString(
                'extensionSummaries(',
                (string) file_get_contents(self::PLUGIN_DIR . $file),
                "{$file} must not gather extension data itself — buildSummary() owns that, once per freeze."
            );
        }
    }

    /**
     * Source-contract for the asymmetry. Core report failures must keep
     * propagating; only third-party sections are caught.
     */
    public function testOnlyThirdPartySectionsAreCaughtNotCoreQueries(): void
    {
        $source = (string) file_get_contents(self::PLUGIN_DIR . 'src/Analytics/Reports.php');

        $catches = preg_match_all('~catch\s*\(\\\\?Throwable~', $source);
        self::assertSame(1, $catches, 'Exactly one catch, around a third-party section.');

        $helper = substr($source, (int) strpos($source, 'public static function extensionSummaries'), 2000);
        self::assertStringContainsString('$section->summarize(', $helper);
        self::assertStringContainsString('catch (\Throwable', $helper);
    }
}
