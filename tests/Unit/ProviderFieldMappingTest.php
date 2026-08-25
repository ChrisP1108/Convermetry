<?php

declare(strict_types=1);

namespace Convermetry\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Convermetry\Forms\Providers\ContactForm7Provider;
use Convermetry\Forms\Providers\ElementorProvider;
use Convermetry\Forms\Providers\FluentFormsProvider;
use Convermetry\Forms\Providers\GravityFormsProvider;
use Convermetry\Forms\Providers\NinjaFormsProvider;
use Convermetry\Forms\Providers\WPFormsProvider;
use Convermetry\Forms\SubmissionService;
use PHPUnit\Framework\TestCase;

/**
 * What each provider actually reads out of its own plugin's hook payload.
 *
 * SubmissionFieldsTest pins the normalizer; this pins the seven mappings that
 * feed it — the half that can only be got wrong by misreading a third-party
 * array. It drives the real pipeline as far as the storage boundary and
 * captures the exact descriptor list that would be written to
 * submission_data, so a provider that quietly drops the label (the pre-2.0
 * behavior) fails here.
 *
 * $wpdb is a plain sink: it exists so execution can reach the capture point,
 * and no assertion in this file depends on anything it does. Nothing here
 * claims to verify SQL, migrations, or the delete cascade — those need a real
 * database and are on the manual checklist in tests/bootstrap.php.
 *
 * Formidable Forms is absent on purpose: its handler calls FrmEntry::getOne()
 * and FrmField::get_all_for_form() as hard static dependencies on classes that
 * do not exist without the plugin installed, so its mapping cannot be driven
 * here. Its shape is covered by the normalizer tests plus manual verification.
 */
final class ProviderFieldMappingTest extends TestCase
{
    /** @var array<string, callable> Captured hook callbacks. */
    private array $hooks = [];

    /** @var list<array<string, mixed>>|null The descriptor list handed to storage. */
    private ?array $captured = null;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        $this->hooks    = [];
        $this->captured = null;

        Functions\when('add_action')->alias(function (string $hook, $callback): void {
            $this->hooks[$hook] = $callback;
        });

        Functions\when('sanitize_key')->alias(static fn(string $v): string => strtolower($v));
        Functions\when('sanitize_text_field')->alias(static fn($v): string => trim(strip_tags((string) $v)));
        Functions\when('esc_url_raw')->returnArg(1);
        Functions\when('wp_unslash')->returnArg(1);
        Functions\when('wp_is_mobile')->justReturn(false);
        Functions\when('is_user_logged_in')->justReturn(false);
        Functions\when('wp_generate_uuid4')->justReturn('uuid-1');
        Functions\when('wp_rand')->justReturn(1);
        Functions\when('apply_filters')->returnArg(2);
        Functions\when('do_action')->justReturn(null);
        Functions\when('home_url')->justReturn('https://example.com');
        Functions\when('wp_parse_url')->alias(static fn(string $u, int $c = -1) => parse_url($u, $c));

        // Every gate that would otherwise pull in another subsystem: no
        // analytics event, no IP capture, no webhook endpoints, no exclusions.
        Functions\when('get_option')->alias(static fn(string $key, $default = false) => match ($key) {
            'cvm_settings' => ['track_form_success' => false, 'store_ip_address' => false],
            default        => is_array($default) ? $default : [],
        });

        // The storage boundary. FormSubmissions::insert() encodes each JSON
        // column here; the descriptor list is the one that is a list.
        Functions\when('wp_json_encode')->alias(function ($data, $options = 0, $depth = 512) {
            if (is_array($data) && $data !== [] && array_is_list($data) && isset($data[0]['id'])) {
                $this->captured = $data;
            }

            return json_encode($data, $options, $depth);
        });

        $GLOBALS['wpdb'] = new class {
            public string $prefix = 'wp_';
            public int $insert_id = 1;
            public function prepare(string $sql, ...$args): string
            {
                return $sql;
            }
            public function query(string $sql): int
            {
                return 1;
            }
            public function insert(...$args): int
            {
                return 1;
            }
            public function get_charset_collate(): string
            {
                return '';
            }
        };
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wpdb']);
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * Fires a provider's hook and returns the descriptor list that reached
     * storage.
     *
     * @param string       $hook Hook name the provider registered.
     * @param list<mixed>  $args Hook arguments.
     * @return list<array<string, mixed>>
     */
    private function fire(string $hook, array $args): array
    {
        ($this->hooks[$hook])(...$args);

        self::assertNotNull($this->captured, "No submission_data reached storage for {$hook}");

        return $this->captured;
    }

    // ── Elementor ────────────────────────────────────────────────────────────

    /**
     * The biggest fidelity win: Elementor's hook has always carried 'title',
     * and the old map keyed by the opaque field id and threw the label away.
     */
    public function testElementorKeepsBothTheFieldIdAndTheTitle(): void
    {
        (new ElementorProvider())->registerHooks(new SubmissionService());

        $record = new class {
            /** @return mixed */
            public function get_form_settings(string $key): mixed
            {
                return match ($key) {
                    'form_name' => 'Contact Form',
                    'id'        => '7ac3d1f',
                    default     => '',
                };
            }
            /** @return mixed */
            public function get(string $key): mixed
            {
                return $key === 'fields' ? [
                    'field_a1b2c3' => ['id' => 'field_a1b2c3', 'title' => 'Email address', 'value' => 'john@example.com'],
                    'message'      => ['id' => 'message', 'title' => 'Your message', 'value' => 'Hello'],
                ] : null;
            }
        };

        $fields = $this->fire('elementor_pro/forms/new_record', [$record, null]);

        self::assertSame([
            ['id' => 'field_a1b2c3', 'label' => 'Email address', 'value' => 'john@example.com'],
            ['id' => 'message',      'label' => 'Your message',  'value' => 'Hello'],
        ], $fields);
    }

    public function testElementorFallsBackToTheIdWhenNoTitleIsSet(): void
    {
        (new ElementorProvider())->registerHooks(new SubmissionService());

        $record = new class {
            public function get_form_settings(string $key): mixed
            {
                return $key === 'form_name' ? 'Contact Form' : 'w1';
            }
            public function get(string $key): mixed
            {
                return $key === 'fields' ? ['field_x' => ['value' => 'v']] : null;
            }
        };

        self::assertSame(
            [['id' => 'field_x', 'label' => 'field_x', 'value' => 'v']],
            $this->fire('elementor_pro/forms/new_record', [$record, null])
        );
    }

    // ── Gravity Forms ────────────────────────────────────────────────────────

    public function testGravityFormsKeepsTheFieldIdAndLabel(): void
    {
        (new GravityFormsProvider())->registerHooks(new SubmissionService());

        $email = (object) ['id' => 1, 'label' => 'Email address'];
        $note  = (object) ['id' => 4, 'label' => ''];

        $fields = $this->fire('gform_after_submission', [
            ['id' => '99', '1' => 'john@example.com', '4' => 'Some note'],
            ['id' => '7', 'title' => 'Contact', 'fields' => [$email, $note]],
        ]);

        self::assertSame([
            ['id' => '1', 'label' => 'Email address', 'value' => 'john@example.com'],
            // No label on the field: the id is the honest fallback.
            ['id' => '4', 'label' => '4', 'value' => 'Some note'],
        ], $fields);
    }

    /** Duplicate labels are exactly what the old map silently collapsed. */
    public function testGravityFormsKeepsTwoFieldsSharingALabel(): void
    {
        (new GravityFormsProvider())->registerHooks(new SubmissionService());

        $fields = $this->fire('gform_after_submission', [
            ['id' => '99', '1' => 'Ada', '2' => 'Grace'],
            ['id' => '7', 'title' => 'Contact', 'fields' => [
                (object) ['id' => 1, 'label' => 'Name'],
                (object) ['id' => 2, 'label' => 'Name'],
            ]],
        ]);

        self::assertCount(2, $fields);
        self::assertSame(['Ada', 'Grace'], array_column($fields, 'value'));
        self::assertSame(['1', '2'], array_column($fields, 'id'));
    }

    /** Compound-input behavior is unchanged: sub-values join with a space. */
    public function testGravityFormsCompoundFieldValueBehaviorIsRetained(): void
    {
        (new GravityFormsProvider())->registerHooks(new SubmissionService());

        $name = (object) [
            'id'     => 1,
            'label'  => 'Full name',
            'inputs' => [['id' => '1.3'], ['id' => '1.6']],
        ];

        $fields = $this->fire('gform_after_submission', [
            ['id' => '99', '1.3' => 'Ada', '1.6' => 'Lovelace'],
            ['id' => '7', 'title' => 'Contact', 'fields' => [$name]],
        ]);

        self::assertSame([['id' => '1', 'label' => 'Full name', 'value' => 'Ada Lovelace']], $fields);
    }

    // ── WPForms ──────────────────────────────────────────────────────────────

    public function testWpFormsKeepsTheFieldIdAndName(): void
    {
        (new WPFormsProvider())->registerHooks(new SubmissionService());

        $fields = $this->fire('wpforms_process_complete', [
            [
                1 => ['id' => 1, 'name' => 'Email address', 'value' => 'john@example.com'],
                2 => ['id' => 2, 'name' => '', 'value' => 'Hello'],
            ],
            [],
            ['id' => '412', 'settings' => ['form_title' => 'Contact']],
        ]);

        self::assertSame([
            ['id' => '1', 'label' => 'Email address', 'value' => 'john@example.com'],
            ['id' => '2', 'label' => '2', 'value' => 'Hello'],
        ], $fields);
    }

    public function testWpFormsMultiValueFieldBecomesAList(): void
    {
        (new WPFormsProvider())->registerHooks(new SubmissionService());

        $fields = $this->fire('wpforms_process_complete', [
            [1 => ['id' => 1, 'name' => 'Interests', 'value' => ['Tax planning', 'Retirement']]],
            [],
            ['id' => '412', 'settings' => ['form_title' => 'Contact']],
        ]);

        self::assertSame(['Tax planning', 'Retirement'], $fields[0]['value']);
    }

    // ── Ninja Forms ──────────────────────────────────────────────────────────

    public function testNinjaFormsKeepsTheFieldIdAndLabel(): void
    {
        (new NinjaFormsProvider())->registerHooks(new SubmissionService());

        $fields = $this->fire('ninja_forms_after_submission', [[
            'form_id'  => '5',
            'settings' => ['title' => 'Contact'],
            'fields'   => [
                10 => ['id' => 10, 'key' => 'email_1', 'label' => 'Email address', 'type' => 'email', 'value' => 'a@b.com'],
                11 => ['id' => 11, 'key' => 'send',    'label' => 'Send',          'type' => 'submit', 'value' => ''],
            ],
        ]]);

        self::assertSame(
            [['id' => '10', 'label' => 'Email address', 'value' => 'a@b.com']],
            $fields,
            'Submit buttons carry no lead data and stay excluded'
        );
    }

    public function testNinjaFormsFallsBackToTheKeyWhenThereIsNoLabel(): void
    {
        (new NinjaFormsProvider())->registerHooks(new SubmissionService());

        $fields = $this->fire('ninja_forms_after_submission', [[
            'form_id'  => '5',
            'settings' => ['title' => 'Contact'],
            'fields'   => [10 => ['id' => 10, 'key' => 'email_1', 'label' => '', 'type' => 'email', 'value' => 'a@b.com']],
        ]]);

        self::assertSame([['id' => '10', 'label' => 'email_1', 'value' => 'a@b.com']], $fields);
    }

    // ── Contact Form 7 ───────────────────────────────────────────────────────

    /**
     * CF7 exposes no reliable label — reading one would mean parsing the
     * form's markup, which is not a public API. The tag name is used for both,
     * and that fallback is documented rather than hidden.
     */
    public function testContactForm7UsesThePostedNameAsBothIdAndLabel(): void
    {
        (new ContactForm7Provider())->registerHooks(new SubmissionService());

        if (!class_exists('WPCF7_Submission')) {
            eval('class WPCF7_Submission {
                public static $data = [];
                public static function get_instance() { return new self(); }
                public function get_posted_data() { return self::$data; }
            }');
        }

        \WPCF7_Submission::$data = [
            'your-email'   => 'john@example.com',
            'your-message' => 'Hello',
            '_wpcf7_nonce' => 'abc',
        ];

        $contactForm = new class {
            public function id(): string
            {
                return '88';
            }
            public function title(): string
            {
                return 'Contact';
            }
        };

        self::assertSame([
            ['id' => 'your-email',   'label' => 'your-email',   'value' => 'john@example.com'],
            ['id' => 'your-message', 'label' => 'your-message', 'value' => 'Hello'],
        ], $this->fire('wpcf7_mail_sent', [$contactForm]), 'CF7 internals prefixed with _ stay excluded');
    }

    // ── Fluent Forms ─────────────────────────────────────────────────────────

    public function testFluentFormsUsesTheSubmittedKeyAsBothIdAndLabel(): void
    {
        (new FluentFormsProvider())->registerHooks(new SubmissionService());

        $fields = $this->fire('fluentform/submission_inserted', [
            42,
            ['email' => 'john@example.com', 'message' => 'Hello', '_wp_http_referer' => '/x'],
            (object) ['id' => 3, 'title' => 'Contact'],
        ]);

        self::assertSame([
            ['id' => 'email',   'label' => 'email',   'value' => 'john@example.com'],
            ['id' => 'message', 'label' => 'message', 'value' => 'Hello'],
        ], $fields);
    }

    // ── Cross-cutting ────────────────────────────────────────────────────────

    /**
     * Correlation fields ride along in the POST body of most providers. They
     * must never reach submission_data, whichever provider carried them.
     */
    public function testInternalCorrelationFieldsNeverReachStorage(): void
    {
        (new FluentFormsProvider())->registerHooks(new SubmissionService());

        $fields = $this->fire('fluentform/submission_inserted', [
            42,
            [
                'email'             => 'john@example.com',
                'cvm_conversion_id' => 'c1',
                'cvm_session_id'    => 's1',
                'cvm_context'       => '{}',
            ],
            (object) ['id' => 3, 'title' => 'Contact'],
        ]);

        self::assertSame([['id' => 'email', 'label' => 'email', 'value' => 'john@example.com']], $fields);
    }
}
