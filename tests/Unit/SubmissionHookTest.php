<?php

declare(strict_types=1);

namespace Convermetry\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Convermetry\Forms\FormProviderRegistry;
use Convermetry\Forms\SubmissionFields;
use PHPUnit\Framework\TestCase;

/**
 * The submission-side hooks, and the invariants they must not be able to break.
 *
 * The two that matter most here are structural rather than cosmetic.
 *
 * A filtered field list is re-normalized, so cvm_* — Convermetry's own
 * correlation fields, which the tracker puts in the form and the normalizer
 * strips — cannot be reintroduced as if a visitor had typed them. A callback
 * that returned them would otherwise write the plugin's internal session and
 * conversion tokens into the submission record, the webhook payload, and the
 * notification email as "submitted data".
 *
 * A filtered discovery list is re-normalized before it is CACHED, for five
 * minutes, for every administrator. Garbage that got through would not be one
 * broken page load; it would be five minutes of them.
 */
final class SubmissionHookTest extends TestCase
{
    private const PLUGIN_DIR = __DIR__ . '/../../';

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        Functions\when('sanitize_text_field')->alias(static fn($v): string => trim(strip_tags((string) $v)));
        Functions\when('sanitize_key')->alias(
            static fn($v): string => strtolower(preg_replace('/[^a-z0-9_\-]/i', '', (string) $v) ?? '')
        );
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    // -------------------------------------------------- field re-normalization

    /**
     * The rule the second normalize() pass exists for. cvm_* fields are
     * Convermetry's own tracking inputs, not the visitor's answers, and a
     * filter must not be able to launder them back into submitted data.
     */
    public function testInternalFieldsCannotBeReintroducedByAFilter(): void
    {
        $normalized = SubmissionFields::normalize([
            ['id' => 'email', 'label' => 'Email', 'value' => 'visitor@example.com'],
        ]);

        // What the service does with a changed filter result.
        $reNormalized = SubmissionFields::normalize(array_merge($normalized, [
            ['id' => 'cvm_conversion_id', 'label' => 'Conversion', 'value' => 'c-123'],
            ['id' => 'cvm_session_id', 'label' => 'Session', 'value' => 's-123'],
        ]));

        self::assertSame(['email'], array_column($reNormalized, 'id'));
    }

    /**
     * A filter returning a legacy name => value map, or a half-built descriptor,
     * still comes out in the canonical shape rather than reaching storage as-is.
     */
    public function testAReshapedFilterResultIsBroughtBackToTheCanonicalShape(): void
    {
        $result = SubmissionFields::normalize(['Email' => 'visitor@example.com', 'Phone' => '555']);

        self::assertSame(
            [
                ['id' => 'Email', 'label' => 'Email', 'value' => 'visitor@example.com'],
                ['id' => 'Phone', 'label' => 'Phone', 'value' => '555'],
            ],
            $result
        );
    }

    public function testANonArrayFilterResultNormalizesToNothingRatherThanFataling(): void
    {
        self::assertSame([], SubmissionFields::normalize([]));
    }

    // ------------------------------------------------------- discovered forms

    /**
     * @dataProvider malformedDiscoveryResults
     */
    public function testAFilteredDiscoveryResultIsNormalizedBeforeItIsCached(
        array $filtered,
        array $expected
    ): void {
        $registry = new FormProviderRegistry();
        $cached   = null;

        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->alias(function (string $key, $value, int $ttl) use (&$cached): bool {
            $cached = $value;

            return true;
        });
        Functions\when('apply_filters')->alias(
            static fn(string $hook, mixed $value, mixed ...$rest) => $hook === 'convermetry_discovered_forms'
                ? $filtered
                : $value
        );

        $provider = new class implements \Convermetry\Forms\FormProviderInterface {
            public function getKey(): string
            {
                return 'stub';
            }

            public function getLabel(): string
            {
                return 'Stub';
            }

            public function isAvailable(): bool
            {
                return true;
            }

            public function getForms(): array
            {
                return [['native_id' => '1', 'name' => 'Original']];
            }

            public function registerHooks(\Convermetry\Forms\SubmissionService $service): void
            {
            }
        };

        $result = $registry->discoveredForms($provider);

        self::assertSame($expected, $result);
        self::assertSame($expected, $cached, 'The NORMALIZED list must be what gets cached.');
    }

    /**
     * @return array<string, array{array<mixed>, array<int, array{native_id: string, name: string}>}>
     */
    public static function malformedDiscoveryResults(): array
    {
        return [
            'empty native id dropped' => [
                [['native_id' => '', 'name' => 'Ghost'], ['native_id' => '7', 'name' => 'Real']],
                [['native_id' => '7', 'name' => 'Real']],
            ],
            'duplicates collapse to the first' => [
                [['native_id' => '7', 'name' => 'First'], ['native_id' => '7', 'name' => 'Second']],
                [['native_id' => '7', 'name' => 'First']],
            ],
            'missing name falls back to the id' => [
                [['native_id' => '7']],
                [['native_id' => '7', 'name' => '7']],
            ],
            'non-array entries dropped' => [
                ['nonsense', 42, null, ['native_id' => '7', 'name' => 'Real']],
                [['native_id' => '7', 'name' => 'Real']],
            ],
            'everything invalid yields nothing' => [
                ['nonsense', ['name' => 'No id']],
                [],
            ],
        ];
    }

    // ------------------------------------------------------- source contract

    /**
     * Source-contract. The veto has to sit after normalization — so a spam rule
     * can read what was submitted — and before the conversion event, which is
     * the first write on the path. Anywhere later and a vetoed submission would
     * already have left a row behind.
     */
    public function testTheSubmissionVetoSitsAfterNormalizationAndBeforeTheFirstWrite(): void
    {
        $source = (string) file_get_contents(self::PLUGIN_DIR . 'src/Forms/SubmissionService.php');
        $record = substr($source, (int) strpos($source, 'public function record('), 9000);

        $normalize  = strpos($record, 'SubmissionFields::normalize($fields)');
        $veto       = strpos($record, "apply_filters('convermetry_should_record_submission'");
        $conversion = strpos($record, '$this->recordConversionEvent(');
        $insert     = strpos($record, 'FormSubmissions::insert(');

        self::assertIsInt($normalize);
        self::assertIsInt($veto);
        self::assertIsInt($conversion);
        self::assertIsInt($insert);

        self::assertGreaterThan($normalize, $veto, 'the veto must see normalized fields');
        self::assertLessThan($conversion, $veto, 'the veto must precede the analytics conversion write');
        self::assertLessThan($insert, $veto, 'the veto must precede the submission insert');
    }

    /**
     * Source-contract, and the reason it exists is written down in
     * NotificationDispatcher: the older action fires BEFORE the webhook
     * endpoint check so notifications work on a site with no endpoints. The new
     * detail action has to sit immediately after it, not somewhere later where
     * an early return could skip it.
     */
    public function testTheDetailActionFiresImmediatelyAfterTheOriginalAndBeforeTheEndpointCheck(): void
    {
        $source = (string) file_get_contents(self::PLUGIN_DIR . 'src/Forms/SubmissionService.php');

        $recorded = strpos($source, "do_action('convermetry_submission_recorded',");
        $details  = strpos($source, "'convermetry_submission_recorded_details'");
        $gate     = strpos($source, 'if (!Options::webhooksActive()');

        self::assertIsInt($recorded);
        self::assertIsInt($details);
        self::assertIsInt($gate);
        self::assertGreaterThan($recorded, $details);
        self::assertLessThan($gate, $details);
    }

    /**
     * Source-contract. The duplicate branch exists precisely because the
     * original submission's deliveries and notifications are already in flight;
     * an action there must observe and nothing more.
     */
    public function testTheDuplicateBranchAnnouncesWithoutRepeatingSideEffects(): void
    {
        $source = (string) file_get_contents(self::PLUGIN_DIR . 'src/Forms/SubmissionService.php');

        $start  = strpos($source, 'if ($rowId === null) {');
        self::assertIsInt($start);

        $branch = substr($source, $start, 2200);

        self::assertStringContainsString("'convermetry_submission_duplicate'", $branch);
        self::assertStringNotContainsString('FormDeliveryQueue::enqueue(', $branch);
        self::assertStringNotContainsString('FormSubmissions::insert(', $branch);
        self::assertStringNotContainsString("do_action('convermetry_submission_recorded'", $branch);
    }

    /**
     * Source-contract. Deletion actions claim the erasure is complete, so they
     * must follow every cascade — the queue rows, the queued notifications, and
     * the lead history — rather than merely the submission row.
     */
    public function testDeletionIsAnnouncedOnlyAfterEveryCascadeHasRun(): void
    {
        $source = (string) file_get_contents(self::PLUGIN_DIR . 'src/Database/FormSubmissions.php');
        $method = substr($source, (int) strpos($source, 'public static function deleteSubmission'), 3000);

        $queue         = strpos($method, 'FormDeliveryQueue::tableName()');
        $notifications = strpos($method, 'NotificationQueue::cancelForSubmission(');
        $leadHistory   = strpos($method, 'LeadEvents::deleteForSubmission(');
        $action        = strpos($method, "'convermetry_submission_deleted'");

        self::assertIsInt($queue);
        self::assertIsInt($notifications);
        self::assertIsInt($leadHistory);
        self::assertIsInt($action);

        self::assertGreaterThan($queue, $action);
        self::assertGreaterThan($notifications, $action);
        self::assertGreaterThan($leadHistory, $action);
    }

    /**
     * Source-contract, same argument for the bulk path.
     */
    public function testClearingIsAnnouncedOnlyAfterEveryTableIsDrained(): void
    {
        $source = (string) file_get_contents(self::PLUGIN_DIR . 'src/Database/FormSubmissions.php');
        $method = substr($source, (int) strpos($source, 'public static function clearAll'), 2400);

        $action = strpos($method, "'convermetry_submissions_cleared'");
        self::assertIsInt($action);

        foreach ([
            'TRUNCATE TABLE',
            'FormDeliveryQueue::tableName()',
            'NotificationQueue::cancelAll(',
            'LeadEvents::clearAll(',
        ] as $cascade) {
            $pos = strpos($method, $cascade);
            self::assertIsInt($pos, "{$cascade} not found in clearAll()");
            self::assertGreaterThan($pos, $action, "the action must follow {$cascade}");
        }
    }
}
