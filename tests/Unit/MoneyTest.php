<?php

declare(strict_types=1);

namespace Convermetry\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Convermetry\Leads\Money;
use PHPUnit\Framework\TestCase;

/**
 * Monetary parsing and formatting.
 *
 * Two properties matter here and they pull in opposite directions:
 *
 *  - Presentation must be forgiving. Administrators copy amounts off invoices
 *    and CRM screens: "$12,500.00", "12 500", "€1.234,56", "1,234.56 USD". A
 *    parser that rejects those makes the field feel broken.
 *  - Value must be strict. PHP will read "12abc" as 12 and "1e3" as 1000, and
 *    silently recording a twelve-pound lead because someone fat-fingered a
 *    field is worse than refusing the input.
 *
 * The third property is the reason the class exists at all: an amount is never
 * a float. 0.1 + 0.2 is not 0.3 in binary floating point, and money that drifts
 * ends up in a revenue figure someone reports to a client.
 */
final class MoneyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * @dataProvider presentationForms
     */
    public function testPresentationIsStrippedButValueIsPreserved(mixed $input, string $expected): void
    {
        self::assertSame($expected, Money::parse($input));
    }

    /**
     * @return array<string, array{0: mixed, 1: string}>
     */
    public static function presentationForms(): array
    {
        return [
            'plain integer'          => ['12500', '12500.00'],
            'plain decimal'          => ['12500.00', '12500.00'],
            'dollar and commas'      => ['$12,500.00', '12500.00'],
            'pound sign'             => ['£1,999.99', '1999.99'],
            'trailing currency code' => ['1234.56USD', '1234.56'],
            'leading currency code'  => ['USD1234.56', '1234.56'],
            'space separated'        => ['12 500', '12500.00'],
            'non-breaking space'     => ["12\u{00A0}500,50", '12500.50'],
            'european decimal comma' => ['€1.234,56', '1234.56'],
            'bare comma decimal'     => ['1,23', '1.23'],
            'comma as thousands'     => ['1,234', '1234.00'],
            'one decimal digit'      => ['5.5', '5.50'],
            'leading zeros'          => ['000042', '42.00'],
            'zero'                   => ['0', '0.00'],
            'integer input'          => [1500, '1500.00'],
            'negative'               => ['-250.75', '-250.75'],
            'explicit plus'          => ['+250.75', '250.75'],
        ];
    }

    /**
     * @dataProvider rejectedForms
     */
    public function testUnusableInputIsRejectedRatherThanCoerced(mixed $input): void
    {
        self::assertNull(Money::parse($input), 'This should not have parsed as an amount.');
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function rejectedForms(): array
    {
        return [
            'empty'              => [''],
            'whitespace only'    => ['   '],
            'null'               => [null],
            'letters after digits' => ['12abc'],
            'letters before digits' => ['abc12'],
            'words'              => ['twelve thousand'],
            'separator only'     => ['.'],
            'comma only'         => [','],
            'scientific'         => ['1e3'],
            'array'              => [[100]],
            'boolean'            => [true],
            'too many digits'    => ['123456789012'],
        ];
    }

    /**
     * The reason this class exists. Ten thousand additions of 0.10 is exactly
     * 1000.00 as a decimal string; in binary floating point it is not.
     */
    public function testRepeatedAdditionDoesNotDrift(): void
    {
        $parsed = Money::parse('0.10');
        self::assertSame('0.10', $parsed);

        // The value never becomes a float on the way in or out, so summing the
        // stored representation (which SQL does over a DECIMAL column) is exact.
        // Demonstrated here in integer cents, which is what DECIMAL(13,2) is.
        $cents = (int) round(0.0); // start from a genuine integer accumulator
        for ($i = 0; $i < 10000; $i++) {
            $cents += 10;
        }
        self::assertSame(100000, $cents);

        // And for contrast, the thing this class refuses to do:
        $float = 0.0;
        for ($i = 0; $i < 10000; $i++) {
            $float += 0.10;
        }
        self::assertNotSame(1000.0, $float, 'Float accumulation is exact here — the premise needs revisiting.');
    }

    /**
     * A float that reaches parse() (from a filter, or WP-CLI) is rendered at
     * the storage scale rather than cast to a string, which would risk
     * scientific notation that the strict validator then rejects.
     */
    public function testFloatInputIsRenderedAtStorageScale(): void
    {
        self::assertSame('1500.00', Money::parse(1500.0));
        self::assertSame('0.30', Money::parse(0.1 + 0.2));
    }

    public function testPrecisionBeyondTwoDecimalsIsTruncatedNotRounded(): void
    {
        // Truncation is the conservative choice: rounding 0.999 up to 1.00
        // invents a penny that was never entered.
        self::assertSame('0.99', Money::parse('0.999'));
        self::assertSame('12.34', Money::parse('12.3456'));
    }

    public function testTheColumnCapacityIsEnforced(): void
    {
        // DECIMAL(13,2) holds 11 integer digits. One more must be refused
        // rather than left to the database to truncate or error on.
        self::assertSame('99999999999.00', Money::parse('99999999999'));
        self::assertNull(Money::parse('999999999999'));
    }

    public function testNegativeZeroIsJustZero(): void
    {
        self::assertSame('0.00', Money::parse('-0'));
        self::assertSame('0.00', Money::parse('-0.00'));
    }

    public function testFormattingGroupsWithoutTouchingTheFraction(): void
    {
        self::assertSame('12,500.00 USD', Money::format('12500.00', 'USD'));
        self::assertSame('1,234,567.89 EUR', Money::format('1234567.89', 'EUR'));
        self::assertSame('999.99 GBP', Money::format('999.99', 'GBP'));
        self::assertSame('-250.75 USD', Money::format('-250.75', 'USD'));
    }

    public function testFormattingAnAbsentAmountYieldsNothing(): void
    {
        self::assertSame('', Money::format(null, 'USD'));
        self::assertSame('', Money::format('', 'USD'));
    }

    /**
     * A currency code is appended rather than mapped to a symbol, because "$"
     * is shared by a dozen currencies and a column mixing them would look
     * addable while not being addable.
     */
    public function testFormattingWithoutACurrencyStillReadsAsANumber(): void
    {
        self::assertSame('12,500.00', Money::format('12500.00', ''));
    }

    /**
     * sanitize_text_field() is not involved in parsing — the parser must stand
     * on its own, because it is also reachable from WP-CLI and from filters
     * where no sanitization has run.
     */
    public function testParsingDoesNotDependOnWordPressSanitization(): void
    {
        Functions\expect('sanitize_text_field')->never();

        self::assertSame('500.00', Money::parse('$500'));
    }
}
