<?php

declare(strict_types=1);

namespace Convermetry\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Convermetry\Forms\FormProviderInterface;
use Convermetry\Forms\FormSettings;
use Convermetry\Forms\Providers\ContactForm7Provider;
use Convermetry\Forms\Providers\ElementorProvider;
use Convermetry\Forms\Providers\FluentFormsProvider;
use Convermetry\Forms\Providers\GravityFormsProvider;
use Convermetry\Forms\Providers\WPFormsProvider;
use Convermetry\Forms\SubmissionService;
use PHPUnit\Framework\TestCase;

/**
 * Provider integrations exercised through their REGISTERED hook callbacks.
 *
 * Calling SubmissionService directly would never run the providers' private
 * handlers, so the spam guards inside them would go untested. These tests
 * capture what each provider passes to add_action() and invoke that closure,
 * which is the same path WordPress takes.
 *
 * They also pin the hook NAMES. That matters because the spam reasoning is
 * per-hook: Contact Form 7 needs no guard only because 'wpcf7_mail_sent' fires
 * after mail was sent, and spam never gets that far. Move it to an earlier hook
 * and that reasoning silently becomes false, so the name is part of the
 * contract, not an implementation detail.
 *
 * Not covered here — it needs the real plugins installed: whether Fluent Forms
 * and WPForms can deliver a spam-flagged submission to their chosen hooks at
 * all. See those providers' registerHooks() docblocks.
 */
final class ProviderHookTest extends TestCase
{
    /** @var array<string, callable> Hook name → registered callback. */
    private array $hooks = [];

    /** @var array<int, string> Provider keys seen entering SubmissionService::record(). */
    private array $recorded = [];

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        $this->hooks    = [];
        $this->recorded = [];

        Functions\when('add_action')->alias(function (string $hook, $callback, $priority = 10, $args = 1): bool {
            $this->hooks[$hook] = $callback;
            return true;
        });

        // sanitize_key() is the first statement in SubmissionService::record(),
        // and nothing on a provider's path to it calls sanitize_key — so a
        // recorded call means the pipeline was genuinely entered.
        Functions\when('sanitize_key')->alias(function ($value) {
            $this->recorded[] = (string) $value;
            return (string) $value;
        });

        Functions\when('sanitize_text_field')->alias(static fn($value) => (string) $value);

        // The form under test is configured as excluded, so record() returns
        // immediately after the sanitize_key() spy has proven it was reached.
        // That keeps these tests about the provider handlers rather than
        // dragging in correlation, payload building, and the delivery stack.
        Functions\when('get_option')->alias(static function (string $key, $default = false) {
            if ($key === FormSettings::OPTION_KEY) {
                return ['gravityforms:7' => ['excluded' => true]];
            }

            return $default === false ? [] : $default;
        });
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * @return array<string, array{FormProviderInterface, string, string}>
     */
    public static function providerHooks(): array
    {
        return [
            'Gravity Forms' => [new GravityFormsProvider(), 'gform_after_submission', 'gravityforms'],
            'Contact Form 7' => [new ContactForm7Provider(), 'wpcf7_mail_sent', 'contactform7'],
            'WPForms' => [new WPFormsProvider(), 'wpforms_process_complete', 'wpforms'],
            'Elementor' => [new ElementorProvider(), 'elementor_pro/forms/new_record', 'elementor'],
            'Fluent Forms' => [new FluentFormsProvider(), 'fluentform/submission_inserted', 'fluentforms'],
        ];
    }

    /**
     * @dataProvider providerHooks
     */
    public function testProviderRegistersItsDocumentedHook(
        FormProviderInterface $provider,
        string $expectedHook,
        string $expectedKey
    ): void {
        $provider->registerHooks(new SubmissionService());

        self::assertArrayHasKey(
            $expectedHook,
            $this->hooks,
            $provider->getLabel() . ' must register ' . $expectedHook . ' — the spam behaviour documented '
            . 'for this provider is specific to that hook'
        );
        self::assertSame($expectedKey, $provider->getKey());
    }

    /**
     * Contact Form 7 carries no spam guard on purpose. That is only safe while
     * it hooks a post-mail event, so this pins the distinction: it must not
     * move to a pre-send hook, where spam would reach the pipeline.
     */
    public function testContactForm7StaysOnAPostMailHook(): void
    {
        (new ContactForm7Provider())->registerHooks(new SubmissionService());

        self::assertArrayHasKey('wpcf7_mail_sent', $this->hooks);
        foreach (['wpcf7_before_send_mail', 'wpcf7_submit', 'wpcf7_mail_failed'] as $preSend) {
            self::assertArrayNotHasKey($preSend, $this->hooks);
        }
    }

    /** Fluent Forms registers both hook spellings; dedup is the handler's job. */
    public function testFluentFormsRegistersBothHookSpellings(): void
    {
        (new FluentFormsProvider())->registerHooks(new SubmissionService());

        self::assertArrayHasKey('fluentform/submission_inserted', $this->hooks);
        self::assertArrayHasKey('fluentform_submission_inserted', $this->hooks);
    }

    /**
     * The regression: gform_after_submission also fires for spam-flagged
     * entries, which were previously recorded as leads, counted as conversions,
     * and delivered to every endpoint.
     */
    public function testGravityFormsSpamEntryNeverReachesThePipeline(): void
    {
        // Fails on main — see plan Phase 1e (finding 3). GravityFormsProvider has
        // no status check at all, and Gravity Forms runs gform_after_submission
        // for entries it classifies as spam during processing, so spam becomes a
        // conversion, a submission row, and an outbound lead. Remove this skip as
        // part of the fix; the assertion below is already the correct spec.
        self::markTestSkipped(
            'Phase 1e (finding 3): GravityFormsProvider does not check $entry["status"], '
            . 'so spam entries reach the submission pipeline.'
        );

        $this->fireGravityForms(['id' => '7', 'status' => 'spam', '1' => 'spam@example.com']);

        self::assertSame([], $this->recorded, 'A spam entry must not enter the submission pipeline');
    }

    public function testGravityFormsValidEntryReachesThePipeline(): void
    {
        $this->fireGravityForms(['id' => '7', 'status' => 'active', '1' => 'real@example.com']);

        self::assertSame(['gravityforms'], $this->recorded);
    }

    /** An entry with no status at all is a normal submission, not spam. */
    public function testGravityFormsEntryWithoutStatusIsTreatedAsValid(): void
    {
        $this->fireGravityForms(['id' => '7', '1' => 'real@example.com']);

        self::assertSame(['gravityforms'], $this->recorded);
    }

    /**
     * @dataProvider malformedGravityFormsPayloads
     */
    public function testGravityFormsRejectsMalformedPayloads(mixed $entry, mixed $form): void
    {
        $provider = new GravityFormsProvider();
        $provider->registerHooks(new SubmissionService());
        ($this->hooks['gform_after_submission'])($entry, $form);

        self::assertSame([], $this->recorded);
    }

    /**
     * @return array<string, array{mixed, mixed}>
     */
    public static function malformedGravityFormsPayloads(): array
    {
        return [
            'entry not an array' => ['not-an-array', ['id' => '7', 'fields' => []]],
            'form not an array'  => [['id' => '1'], null],
            'form without id'    => [['id' => '1'], ['fields' => []]],
        ];
    }

    /**
     * Invokes the real registered gform_after_submission closure.
     *
     * @param array<string, mixed> $entry Gravity Forms entry.
     * @return void
     */
    private function fireGravityForms(array $entry): void
    {
        $field        = new \stdClass();
        $field->id    = 1;
        $field->label = 'Email';

        $provider = new GravityFormsProvider();
        $provider->registerHooks(new SubmissionService());

        ($this->hooks['gform_after_submission'])($entry, ['id' => '7', 'title' => 'Contact', 'fields' => [$field]]);
    }
}
