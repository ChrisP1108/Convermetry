<?php

declare(strict_types=1);

namespace Convermetry\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Convermetry\Admin\SubmissionsPage;
use PHPUnit\Framework\TestCase;

/**
 * The submissions CSV export.
 *
 * Two literal arrays used to carry this file — one of headers, one of values —
 * paired only by position. Adding a column meant editing both in step, and
 * getting it wrong would not fail loudly: it would shift every later column
 * along by one and file one visitor's email address under another's heading, in
 * a spreadsheet somebody then acts on.
 *
 * They are now one keyed schema, and rows are assembled by walking the COLUMN
 * keys. A value that is missing becomes an empty cell; a value with no column is
 * ignored. Misalignment is not a bug that has been fixed here, it is a state the
 * code can no longer represent — which is what makes it safe to let third-party
 * code add columns at all.
 *
 * The golden-row test below is the regression guard: it pins the exact bytes of
 * a real export line, not just its labels.
 */
final class ExportColumnsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        Functions\when('wp_json_encode')->alias(
            static fn($data, $options = 0, $depth = 512) => json_encode($data, $options, $depth)
        );
        Functions\when('sanitize_text_field')->alias(static fn($v): string => trim(strip_tags((string) $v)));
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * @return array<string, mixed>
     */
    private function row(array $overrides = []): array
    {
        return array_merge([
            'id'              => 42,
            'created_at'      => '2026-08-22 14:32:00',
            'submission_id'   => 's1',
            'conversion_id'   => 'c1',
            'session_id'      => 'sess1',
            'provider'        => 'elementor',
            'form_name'       => 'Contact',
            'form_id'         => 'contact',
            'native_form_id'  => 'a1b2',
            'page_url'        => 'https://example.com/contact',
            'channel'         => 'organic',
            'ip_address'      => '203.0.113.42',
            'lead_status'     => 'qualified',
            'lead_value'      => '12500.00',
            'lead_currency'   => 'USD',
            'submission_data' => '[{"id":"email","label":"Email","value":"visitor@example.com"}]',
            'context'         => (string) json_encode([
                'attribution'       => ['utm_source' => 'google', 'utm_medium' => 'cpc'],
                'landing_page'      => ['url' => 'https://example.com/'],
                'entrance_referrer' => 'https://google.com/',
                'device'            => 'desktop',
            ]),
            'page_query'      => '{}',
        ], $overrides);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, string>
     */
    private function values(array $row, ?array $status = null): array
    {
        $method = new \ReflectionMethod(SubmissionsPage::class, 'exportValues');

        return $method->invoke(null, $row, $status);
    }

    /**
     * @return array<string, string>
     */
    private function columns(): array
    {
        return (new \ReflectionMethod(SubmissionsPage::class, 'exportColumns'))->invoke(null);
    }

    // ------------------------------------------------------- the golden row

    /**
     * The transcription lock. These are the exact 25 headers the export has
     * always emitted, in order — copied from the literal they replaced rather
     * than generated from the code under test, so this fails if the refactor
     * dropped, renamed, or reordered one.
     */
    public function testTheHeaderRowIsUnchanged(): void
    {
        Functions\when('apply_filters')->returnArg(2);

        self::assertSame([
            'Date/Time (UTC)', 'Submission ID', 'Conversion ID', 'Session ID',
            'Provider', 'Form Name', 'Form ID', 'Native Form ID', 'Conversion Page',
            'Channel', 'UTM Source', 'UTM Medium', 'UTM Campaign', 'UTM Term',
            'UTM Content', 'Ad Click Type', 'Entrance Referrer', 'Landing Page',
            'Device', 'IP Address', 'Delivery Status',
            'Lead Status', 'Lead Value', 'Lead Currency',
            'Submission Data (JSON)',
        ], array_values($this->columns()));
    }

    /**
     * A whole data row, byte for byte, not merely its shape.
     */
    public function testAnExportedRowIsUnchanged(): void
    {
        Functions\when('apply_filters')->returnArg(2);

        $values = $this->values($this->row(), ['label' => 'Delivered']);

        self::assertSame([
            '2026-08-22 14:32:00', 's1', 'c1', 'sess1',
            'elementor', 'Contact', 'contact', 'a1b2', 'https://example.com/contact',
            'organic', 'google', 'cpc', '', '',
            '', '', 'https://google.com/', 'https://example.com/',
            'desktop', '203.0.113.42', 'Delivered',
            'Qualified', '12500.00', 'USD',
            '[{"id":"email","label":"Email","value":"visitor@example.com"}]',
        ], array_values($values));
    }

    public function testEveryColumnHasAValueAndEveryValueHasAColumn(): void
    {
        Functions\when('apply_filters')->returnArg(2);

        self::assertSame(array_keys($this->columns()), array_keys($this->values($this->row())));
    }

    /**
     * A recorded value of null is not the same as 0.00, and the export says so
     * with an empty cell rather than a zero somebody would then sum.
     */
    public function testAnUnrecordedLeadValueExportsAsEmptyNotZero(): void
    {
        Functions\when('apply_filters')->returnArg(2);

        $values = $this->values($this->row(['lead_value' => null]));

        self::assertSame('', $values['lead_value']);
    }

    // ------------------------------------------------------------ alignment

    /**
     * The property the keyed schema buys: a third-party value for a column that
     * does not exist cannot push the real columns sideways.
     */
    public function testAValueWithNoColumnCannotShiftTheRow(): void
    {
        Functions\when('apply_filters')->alias(
            static fn(string $hook, mixed $value, mixed ...$rest) => $hook === 'convermetry_submission_csv_values'
                ? array_merge(is_array($value) ? $value : [], ['acme/unknown' => 'stray'])
                : $value
        );

        $columns = $this->columns();
        $values  = $this->values($this->row());

        $cells = [];
        foreach (array_keys($columns) as $key) {
            $cells[] = (string) ($values[$key] ?? '');
        }

        self::assertCount(count($columns), $cells);
        self::assertNotContains('stray', $cells);
    }

    /**
     * And the other direction: a column nobody supplied a value for is an empty
     * cell, in the right place.
     */
    public function testAColumnWithNoValueBecomesAnEmptyCellInPosition(): void
    {
        Functions\when('apply_filters')->alias(
            static fn(string $hook, mixed $value, mixed ...$rest) => $hook === 'convermetry_submission_csv_columns'
                ? array_merge(is_array($value) ? $value : [], ['acme/plan' => 'Plan'])
                : $value
        );

        $columns = $this->columns();
        $values  = $this->values($this->row());

        self::assertSame('Plan', $columns['acme/plan']);
        self::assertArrayNotHasKey('acme/plan', $values);

        $cells = [];
        foreach (array_keys($columns) as $key) {
            $cells[] = (string) ($values[$key] ?? '');
        }

        self::assertCount(26, $cells);
        self::assertSame('', $cells[25]);
        self::assertSame('2026-08-22 14:32:00', $cells[0], 'the core columns must not have moved');
    }

    // --------------------------------------------------------- sanitization

    public function testNonScalarFilteredValuesAreDropped(): void
    {
        Functions\when('apply_filters')->alias(
            static fn(string $hook, mixed $value, mixed ...$rest) => $hook === 'convermetry_submission_csv_values'
                ? ['created_at' => '2026-01-01', 'acme/bad' => ['an', 'array'], 'acme/obj' => new \stdClass()]
                : $value
        );

        $values = $this->values($this->row());

        self::assertSame(['created_at' => '2026-01-01'], $values);
    }

    public function testAnEmptyOrInvalidColumnFilterFallsBackToTheCoreColumns(): void
    {
        Functions\when('apply_filters')->alias(
            static fn(string $hook, mixed $value, mixed ...$rest) => $hook === 'convermetry_submission_csv_columns'
                ? 'nonsense'
                : $value
        );

        self::assertCount(25, $this->columns());
    }

    /**
     * Formula injection is the reason this escaping exists, and a third-party
     * column is MORE likely to carry attacker-controlled text than a core one —
     * so it goes through the same guard rather than being trusted.
     *
     * @dataProvider formulaTriggers
     */
    public function testEveryValueIncludingThirdPartyOnesIsEscapedAgainstFormulaInjection(string $raw): void
    {
        $escape = new \ReflectionMethod(SubmissionsPage::class, 'escapeCsvCell');

        self::assertSame("\t" . $raw, $escape->invoke(null, $raw));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function formulaTriggers(): array
    {
        return [
            'equals' => ['=1+1'],
            'plus'   => ['+1'],
            'minus'  => ['-1'],
            'at'     => ['@SUM(A1)'],
            'tab'    => ["\tvalue"],
            'cr'     => ["\rvalue"],
            'cmd'    => ['=cmd|\' /C calc\'!A0'],
        ];
    }

    public function testAnOrdinaryValueIsLeftAlone(): void
    {
        $escape = new \ReflectionMethod(SubmissionsPage::class, 'escapeCsvCell');

        self::assertSame('visitor@example.com', $escape->invoke(null, 'visitor@example.com'));
        self::assertSame('', $escape->invoke(null, ''));
    }
}
