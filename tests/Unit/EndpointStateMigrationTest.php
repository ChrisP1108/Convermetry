<?php

declare(strict_types=1);

namespace Convermetry\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Convermetry\Settings\Options;
use Convermetry\Webhook\AnalyticsDispatcher;
use PHPUnit\Framework\TestCase;

/**
 * Re-keying per-endpoint analytics state onto permanent endpoint ids.
 *
 * Regression origin: last-success markers, retry-state keys, and retry cron
 * arguments were all derived from md5(endpoint URL). Editing a URL therefore
 * reset the delivery window, made pruning treat the endpoint as deleted, and
 * left a cron event that wp_unschedule_event() could no longer match — so it
 * could not be cancelled, yet still fired.
 */
final class EndpointStateMigrationTest extends TestCase
{
    private const OLD_URL = 'https://receiver.test/hook';

    /** @var array<string, mixed> */
    private array $options = [];

    /** @var array<int, array{ts: int, hook: string, args: array<int, mixed>}> */
    private array $cron = [];

    private int $uuidSeq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        // Not yet implemented on main — see plan Phase 1a (finding 10), the
        // prerequisite for 1b-1d. Endpoint state is still keyed by md5(url), so
        // editing an endpoint's URL makes pruneStaleState() silently discard its
        // undelivered frozen payload. The guard removes itself once these land.
        if (
            !method_exists(AnalyticsDispatcher::class, 'migrateEndpointState')
            || !method_exists(Options::class, 'ensureEndpointIds')
        ) {
            self::markTestSkipped(
                'Phase 1a (finding 10): durable endpoint ids are not implemented on main. '
                . 'Endpoint state is keyed by md5(url), so a URL edit orphans and then discards it.'
            );
        }

        Monkey\setUp();

        $this->uuidSeq = 0;
        $this->cron    = [];
        $this->options = [
            Options::WEBHOOK_OPTION_KEY => [
                'endpoints'     => [[
                    'url'       => self::OLD_URL,
                    'label'     => 'Main',
                    'secret'    => 'own-secret',
                    'analytics' => true,
                    'forms'     => true,
                ]],
                'shared_secret' => 'shared-secret',
            ],
            'cvm_webhook_last_sent'   => [md5(self::OLD_URL) => 1750000000],
            'cvm_webhook_retry_state' => [md5(self::OLD_URL) => [
                'url'           => self::OLD_URL,
                'attempt'       => 2,
                'scheduled_for' => 1760000000,
                'delivery_id'   => 'abc',
                'body'          => '{"a":1}',
                'exhausted'     => false,
                'frozen_at'     => 1750000000,
            ]],
        ];

        $this->cron[] = ['ts' => 1760000000, 'hook' => AnalyticsDispatcher::RETRY_HOOK, 'args' => [self::OLD_URL]];

        Functions\when('get_option')->alias(fn(string $k, $d = false) => $this->options[$k] ?? $d);
        Functions\when('update_option')->alias(function (string $k, $v, $a = null): bool {
            $this->options[$k] = $v;
            return true;
        });
        Functions\when('wp_generate_uuid4')->alias(fn(): string => sprintf('uuid-%04d', ++$this->uuidSeq));
        Functions\when('wp_schedule_single_event')->alias(function (int $ts, string $hook, array $args = []): bool {
            $this->cron[] = ['ts' => $ts, 'hook' => $hook, 'args' => $args];
            return true;
        });
        Functions\when('wp_unschedule_event')->alias(function (int $ts, string $hook, array $args = []): bool {
            foreach ($this->cron as $i => $e) {
                if ($e['ts'] === $ts && $e['hook'] === $hook && $e['args'] === $args) {
                    unset($this->cron[$i]);
                    $this->cron = array_values($this->cron);
                    return true;
                }
            }
            return false;
        });
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function endpointId(): string
    {
        // Property access, not an array offset: Options::endpoints() returns
        // WebhookEndpoint objects. The durable id this suite is waiting on
        // (Phase 1a) will be a property on that object, so this is written the
        // way it will need to read once the feature lands.
        return Options::endpoints()[0]->id;
    }

    /** @return array<int, array<int, mixed>> */
    private function cronArgs(): array
    {
        return array_column($this->cron, 'args');
    }

    public function testMigrationAssignsIdsAndRekeysBothStateMaps(): void
    {
        AnalyticsDispatcher::migrateEndpointState();
        $id = $this->endpointId();

        self::assertNotSame('', $id);
        self::assertSame([$id => 1750000000], $this->options['cvm_webhook_last_sent']);
        self::assertSame([$id], array_keys($this->options['cvm_webhook_retry_state']));
    }

    public function testPendingCronIsRescheduledFromUrlToId(): void
    {
        AnalyticsDispatcher::migrateEndpointState();

        self::assertSame([[$this->endpointId()]], $this->cronArgs());
    }

    /**
     * The regression this whole change exists for.
     */
    public function testEditingTheUrlPreservesMarkerChainAndCron(): void
    {
        AnalyticsDispatcher::migrateEndpointState();
        $id = $this->endpointId();

        $settings                                 = $this->options[Options::WEBHOOK_OPTION_KEY];
        $settings['endpoints'][0]['url']          = 'https://receiver.test/v2-hook';
        $this->options[Options::WEBHOOK_OPTION_KEY] = $settings;

        self::assertSame($id, $this->endpointId(), 'The id must survive a URL edit');
        self::assertArrayHasKey($id, $this->options['cvm_webhook_last_sent'], 'Delivery window must not reset');
        self::assertArrayHasKey($id, $this->options['cvm_webhook_retry_state'], 'Retry chain must survive');
        self::assertSame([[$id]], $this->cronArgs(), 'Cron event must remain addressable');
        self::assertSame('own-secret', Options::secretForId($id), 'Must not fall through to the shared secret');
    }

    public function testMigrationIsIdempotent(): void
    {
        AnalyticsDispatcher::migrateEndpointState();
        $snapshot = [$this->options['cvm_webhook_last_sent'], $this->options['cvm_webhook_retry_state'], $this->cronArgs()];

        AnalyticsDispatcher::migrateEndpointState();

        self::assertSame(
            $snapshot,
            [$this->options['cvm_webhook_last_sent'], $this->options['cvm_webhook_retry_state'], $this->cronArgs()]
        );
    }

    public function testStateForAnUnconfiguredEndpointIsDropped(): void
    {
        $this->options['cvm_webhook_last_sent'][md5('https://gone.test/hook')]   = 123;
        $this->options['cvm_webhook_retry_state'][md5('https://gone.test/hook')] = ['url' => 'https://gone.test/hook'];

        AnalyticsDispatcher::migrateEndpointState();

        self::assertSame([$this->endpointId()], array_keys($this->options['cvm_webhook_last_sent']));
        self::assertSame([$this->endpointId()], array_keys($this->options['cvm_webhook_retry_state']));
    }

    public function testExistingIdsAreNeverRegenerated(): void
    {
        AnalyticsDispatcher::migrateEndpointState();
        $id = $this->endpointId();

        Options::ensureEndpointIds();

        self::assertSame($id, $this->endpointId());
    }
}
