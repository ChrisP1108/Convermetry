<?php

declare(strict_types=1);

namespace Convermetry\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Convermetry\Support\ClientIp;
use PHPUnit\Framework\TestCase;

/**
 * The tracking hooks, and the two boundaries they must not cross.
 *
 * The tracking endpoint is public and unauthenticated: anyone on the internet
 * can POST to it. So no hook here is ever handed the raw request — the
 * should-track filter runs last in sanitization, after every value has been
 * whitelisted, bounded, and stripped of query strings, and the rate-limit
 * action carries no identity at all.
 *
 * The second boundary is the IP split. Convermetry resolves the visitor's
 * address twice for different purposes: once as a rate-limit identity, and once
 * as a value to store. Only the stored one is filterable, because anonymizing
 * the rate-limit identity would collapse every visitor into a single bucket and
 * hand anyone the ability to exhaust the site-wide limit.
 */
final class TrackingHookTest extends TestCase
{
    private const PLUGIN_DIR = __DIR__ . '/../../';

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        ClientIp::resetCache();

        Functions\when('sanitize_text_field')->alias(static fn($v): string => trim(strip_tags((string) $v)));
        Functions\when('wp_unslash')->returnArg(1);

        $_SERVER['REMOTE_ADDR'] = '203.0.113.42';
    }

    protected function tearDown(): void
    {
        ClientIp::resetCache();
        unset($_SERVER['REMOTE_ADDR']);
        Monkey\tearDown();
        parent::tearDown();
    }

    private function storageEnabled(): void
    {
        Functions\when('get_option')->justReturn(['store_ip_address' => true, 'respect_dnt' => false]);
    }

    // ---------------------------------------------------------- stored_ip

    public function testTheStoredIpFilterCanPseudonymizeWhatIsPersisted(): void
    {
        $this->storageEnabled();
        Functions\when('apply_filters')->alias(
            static fn(string $hook, mixed $value, mixed ...$rest) => $hook === 'convermetry_stored_ip'
                ? '203.0.113.0'
                : $value
        );

        self::assertSame('203.0.113.0', ClientIp::forStorage());
    }

    public function testTheStoredIpFilterMaySuppressStorageEntirely(): void
    {
        $this->storageEnabled();
        Functions\when('apply_filters')->alias(
            static fn(string $hook, mixed $value, mixed ...$rest) => $hook === 'convermetry_stored_ip' ? '' : $value
        );

        self::assertSame('', ClientIp::forStorage());
    }

    /**
     * A non-address in the ip_address column would corrupt exports and any
     * downstream geo lookup, so anything that is not an IP becomes ''.
     *
     * @dataProvider invalidAddresses
     */
    public function testAFilteredValueThatIsNotAnAddressIsDiscarded(mixed $returned): void
    {
        $this->storageEnabled();
        Functions\when('apply_filters')->alias(
            static fn(string $hook, mixed $value, mixed ...$rest) => $hook === 'convermetry_stored_ip'
                ? $returned
                : $value
        );

        self::assertSame('', ClientIp::forStorage());
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function invalidAddresses(): array
    {
        return [
            'hash'      => ['d41d8cd98f00b204e9800998ecf8427e'],
            'truncated' => ['203.0.113'],
            'label'     => ['anonymous'],
            'sql'       => ["203.0.113.1'; DROP TABLE"],
            'zero'      => [0],
            'true'      => [true],
        ];
    }

    public function testValidIpv6Survives(): void
    {
        $this->storageEnabled();
        Functions\when('apply_filters')->alias(
            static fn(string $hook, mixed $value, mixed ...$rest) => $hook === 'convermetry_stored_ip'
                ? '2001:db8::1'
                : $value
        );

        self::assertSame('2001:db8::1', ClientIp::forStorage());
    }

    /**
     * The boundary that keeps the rate limiter honest. get() is what the
     * tracking endpoint charges against; it must be unaffected by a filter whose
     * whole purpose is to blur the stored value.
     */
    public function testTheStoredIpFilterDoesNotAffectTheRateLimitIdentity(): void
    {
        $this->storageEnabled();
        Functions\when('apply_filters')->alias(
            static fn(string $hook, mixed $value, mixed ...$rest) => $hook === 'convermetry_stored_ip'
                ? '203.0.113.0'
                : $value
        );

        self::assertSame('203.0.113.0', ClientIp::forStorage());
        self::assertSame('203.0.113.42', ClientIp::get(), 'Rate limiting must still see the real address.');
    }

    /**
     * The privacy gates run first, so a callback is never handed an address the
     * site already decided not to keep.
     */
    public function testTheFilterNeverSeesAnAddressTheSettingsAlreadySuppressed(): void
    {
        Functions\when('get_option')->justReturn(['store_ip_address' => false, 'respect_dnt' => false]);

        $seen = 'not-called';
        Functions\when('apply_filters')->alias(
            static function (string $hook, mixed $value, mixed ...$rest) use (&$seen) {
                if ($hook === 'convermetry_stored_ip') {
                    $seen = $value;
                }

                return $value;
            }
        );

        self::assertSame('', ClientIp::forStorage());
        self::assertSame('not-called', $seen, 'Storage is off: the filter is not reached at all.');
    }

    // ------------------------------------------------------ source contract

    /**
     * Source-contract. The whole safety argument for exposing anonymous
     * tracking input to a hook is that it runs LAST, after the whitelist. If it
     * moved above the type check or the URL normalization, a callback would be
     * handed attacker-controlled values.
     */
    public function testTheEventFilterRunsAfterEverySanitizationStep(): void
    {
        $source = (string) file_get_contents(self::PLUGIN_DIR . 'src/Api/TrackingController.php');
        $method = substr($source, (int) strpos($source, 'private static function sanitizeEvent'), 7000);

        $filter = strpos($method, "apply_filters('convermetry_should_track_event'");
        self::assertIsInt($filter);

        foreach ([
            'Options::isTypeEnabled($type)',
            'self::normalizePageUrl(',
            'self::scalarString(',
            'self::campaignValue(',
        ] as $step) {
            $pos = strpos($method, $step);
            self::assertIsInt($pos, "{$step} not found in sanitizeEvent()");
            self::assertGreaterThan($pos, $filter, "the filter must run after {$step}");
        }
    }

    /**
     * Source-contract. The raw decoded request body must never reach a hook.
     */
    public function testNoHookIsHandedTheRawRequestBody(): void
    {
        $source = (string) file_get_contents(self::PLUGIN_DIR . 'src/Api/TrackingController.php');

        preg_match_all('~(?:do_action|apply_filters)\(\s*\'(convermetry_[a-z_]+)\'([^;]*);~s', $source, $matches);

        self::assertNotEmpty($matches[1]);
        foreach ($matches[2] as $index => $args) {
            // A count of the raw arrays is a number, not their contents.
            $args = (string) preg_replace('~count\([^)]*\)~', 'N', $args);

            // Word-bounded: $batchId is a whitelisted 8-40 character token, not
            // the $batch array it shares a prefix with.
            self::assertSame(
                0,
                preg_match('~\$(?:body|events|batch|event|request)\b~', $args),
                "{$matches[1][$index]} is handed raw anonymous input."
            );
        }
    }

    /**
     * Source-contract. A rate limiter that reported every caller's address
     * would be a visitor log with extra steps.
     */
    public function testTheRateLimitActionCarriesNoAddressOrHashOfOne(): void
    {
        $source = (string) file_get_contents(self::PLUGIN_DIR . 'src/Api/TrackingController.php');

        $start = strpos($source, "do_action('convermetry_tracking_rate_limited'");
        self::assertIsInt($start);

        $call = substr($source, $start, 200);

        self::assertStringNotContainsString('$ip', $call);
        self::assertStringNotContainsString('md5(', $call);
        self::assertStringNotContainsString('clientIp', $call);
        self::assertStringNotContainsString('ClientIp::', $call);
    }

    /**
     * Source-contract. One action per batch, never one per event: the endpoint
     * accepts 25 events per request and is the hottest path in the plugin.
     */
    public function testTheBatchActionFiresOncePerBatchNotPerEvent(): void
    {
        $source = (string) file_get_contents(self::PLUGIN_DIR . 'src/Api/TrackingController.php');
        $method = substr($source, (int) strpos($source, 'public static function handleTrack'), 6000);

        $action = strpos($method, "do_action('convermetry_tracking_batch_recorded'");
        $loop   = strpos($method, 'foreach ($events as $index => $event)');
        $insert = strpos($method, 'DatabaseManager::insertEvents(');

        self::assertIsInt($action);
        self::assertIsInt($loop);
        self::assertIsInt($insert);
        self::assertGreaterThan($loop, $action, 'the action must sit outside the per-event loop');
        self::assertGreaterThan($insert, $action, 'the action must follow the write it reports');
    }
}
