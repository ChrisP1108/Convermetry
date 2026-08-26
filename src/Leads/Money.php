<?php
declare(strict_types=1);

namespace Convermetry\Leads;

if (!defined('ABSPATH')) exit;

/**
 * Monetary amounts, as exact decimal STRINGS.
 *
 * Every value in this plugin that represents money — a lead's value, a goal's
 * value, an attributed revenue total — is carried as a string like "12500.00"
 * and stored in a DECIMAL(13,2) column. It is never a PHP float, at any point,
 * including in transit between the form and the database.
 *
 * That is not fastidiousness. A lead worth 0.10 recorded ten thousand times must
 * total exactly 1000.00, and binary floating point cannot represent 0.10 at all:
 * summing it ten thousand times in PHP yields 1000.0000000001589. The drift is
 * tiny and it is also permanent, and it surfaces in a revenue figure somebody
 * reports to a client. Because summation happens in SQL over a DECIMAL column,
 * keeping the PHP side a string means the value never passes through a format
 * that could round it.
 *
 * PARSING IS DELIBERATELY FORGIVING ABOUT PRESENTATION AND STRICT ABOUT VALUE.
 * Administrators type what they see on an invoice — "$12,500.00", "12 500",
 * "€1.234,56" — so currency symbols, spaces, and thousands separators are
 * stripped. But anything that is not then a plain number is rejected outright
 * rather than being coerced: PHP would happily read "12abc" as 12, and silently
 * recording a twelve-pound lead because someone fat-fingered a field is worse
 * than refusing the input and saying so.
 */
final class Money
{
    /** Digits allowed to the left of the decimal point (DECIMAL(13,2) → 11). */
    private const int MAX_INTEGER_DIGITS = 11;

    /** Digits stored to the right of the decimal point. */
    private const int SCALE = 2;

    /**
     * Parses an administrator-supplied amount into an exact decimal string.
     *
     * @param mixed $raw Submitted value: a string, an int, a float, or null.
     * @return string|null A canonical "-?digits.dd" string, or null when the
     *                     input was empty or is not a usable amount. Null means
     *                     "no value recorded", which every report treats as a
     *                     different fact from "0.00".
     */
    public static function parse(mixed $raw): ?string
    {
        if ($raw === null || is_bool($raw) || is_array($raw)) {
            return null;
        }

        // A float arriving here is already imprecise, but it is a legitimate
        // input from a filter or a WP-CLI call. Render it at the storage scale
        // rather than through a locale-dependent string cast, which can emit
        // scientific notation ("1.0E+15") that the parse below would reject.
        $value = is_float($raw)
            ? number_format($raw, self::SCALE, '.', '')
            : trim((string) $raw);

        if ($value === '') {
            return null;
        }

        // Strip PRESENTATION ONLY, character class by character class, so that
        // anything left over is a genuine problem with the input rather than
        // something this parser quietly discarded. A blanket [^0-9.,] strip
        // would have turned "12abc" into 12 — recording a twelve-pound lead
        // because someone fat-fingered a field, which is precisely the failure
        // this method exists to prevent.
        // A currency CODE is recognized in two forms, and the distinction is
        // what keeps "12abc" from parsing. Separated by whitespace it may be any
        // case ("1,234.56 usd"); written flush against the digits it must be
        // uppercase ("1234.56USD"), because at that point the only thing
        // distinguishing a currency code from a typo is that codes are written
        // in capitals.
        $value = (string) preg_replace('~^\s*[A-Za-z]{3}(?=\s)|(?<=\s)[A-Za-z]{3}\s*$~', '', $value);
        $value = (string) preg_replace('~[\s\x{00A0}\x{202F}\x{2007}]+~u', '', $value);
        $value = (string) preg_replace('~\p{Sc}~u', '', $value);
        $value = (string) preg_replace('~^[A-Z]{3}(?=[-+\d.,])|(?<=[\d.,])[A-Z]{3}$~', '', $value);

        $negative = str_starts_with($value, '-');
        if ($negative || str_starts_with($value, '+')) {
            $value = substr($value, 1);
        }

        // Whatever survived must now be digits and separators, and must contain
        // at least one digit — "." and "," alone are not amounts.
        if (preg_match('~^[0-9.,]+$~', $value) !== 1 || preg_match('~[0-9]~', $value) !== 1) {
            return null;
        }

        $value = self::normalizeSeparators($value);

        [$integer, $fraction] = array_pad(explode('.', $value, 2), 2, '');

        $integer  = ltrim($integer, '0');
        $fraction = substr($fraction . '00', 0, self::SCALE);

        if ($integer === '') {
            $integer = '0';
        }

        // Beyond the column's capacity the database would either error or
        // truncate. Refusing is the only answer that cannot silently misstate an
        // amount.
        if (strlen($integer) > self::MAX_INTEGER_DIGITS) {
            return null;
        }

        $result = $integer . '.' . $fraction;

        // Negative zero is still zero, and "-0.00" in a report reads as a bug.
        if ($negative && $result !== '0.00') {
            $result = '-' . $result;
        }

        return $result;
    }

    /**
     * Reduces mixed thousands/decimal separators to a single '.' decimal point.
     *
     * No rule can be right in every locale from the digits alone — "1.234" is
     * one thousand two hundred and thirty four in German and one-point-two-three-four
     * in English. These three rules resolve every form that is actually
     * unambiguous, and pick the convention this plugin already writes in for the
     * one form that is not:
     *
     *  1. BOTH separators present: the last one is the decimal point and the
     *     other is thousands. "12,500.00" → 12500.00; "1.234,56" → 1234.56.
     *     Never ambiguous — no locale uses the same character for both.
     *  2. ONE separator, appearing more than once: all thousands.
     *     "1.234.567" → 1234567.
     *  3. ONE separator, appearing once:
     *     - a dot is the decimal point, whatever follows it. "0.999" → 0.99.
     *       This is the convention the plugin stores and displays in, so a bare
     *       dot is read the way the rest of the UI writes it.
     *     - a comma is the decimal point only when one or two digits follow,
     *       and thousands otherwise. "1,23" → 1.23; "1,234" → 1234. Comma gets
     *       the digit-count test because it is genuinely used for both, whereas
     *       treating a lone dot as thousands would misread "0.999" as 999.
     *
     * @param string $value Digits, dots, and commas only (at least one digit).
     * @return string Digits with at most one '.'.
     */
    private static function normalizeSeparators(string $value): string
    {
        $dots   = substr_count($value, '.');
        $commas = substr_count($value, ',');

        if ($dots === 0 && $commas === 0) {
            return $value;
        }

        if ($dots > 0 && $commas > 0) {
            return self::splitAt($value, max((int) strrpos($value, '.'), (int) strrpos($value, ',')));
        }

        if ($dots + $commas > 1) {
            return str_replace([',', '.'], '', $value);
        }

        $position = $dots === 1 ? (int) strrpos($value, '.') : (int) strrpos($value, ',');

        if ($dots === 1) {
            return self::splitAt($value, $position);
        }

        $following = strlen($value) - $position - 1;

        return ($following >= 1 && $following <= self::SCALE)
            ? self::splitAt($value, $position)
            : str_replace(',', '', $value);
    }

    /**
     * Treats the separator at $position as the decimal point, discarding every
     * earlier separator as a thousands mark.
     *
     * @param string $value    Digits and separators.
     * @param int    $position Index of the decimal separator.
     * @return string
     */
    private static function splitAt(string $value, int $position): string
    {
        return str_replace([',', '.'], '', substr($value, 0, $position))
            . '.'
            . substr($value, $position + 1);
    }

    /**
     * Formats a stored decimal string for display alongside its currency.
     *
     * The currency code is appended rather than mapped to a symbol. Symbol
     * mapping is a genuine trap: "$" is used by a dozen currencies, so rendering
     * an AUD lead as "$1,000" next to a USD one produces a column that looks
     * addable and is not. The code removes the ambiguity, which matters more
     * here than looking like a shop.
     *
     * @param string|null $amount   A decimal string from {@see parse()}, or null.
     * @param string      $currency ISO 4217 code, or ''.
     * @return string A display string, or '' when there is no amount.
     */
    public static function format(?string $amount, string $currency): string
    {
        if ($amount === null || $amount === '') {
            return '';
        }

        $negative = str_starts_with($amount, '-');
        $digits   = ltrim($amount, '-');

        [$integer, $fraction] = array_pad(explode('.', $digits, 2), 2, '00');

        // Grouped WITHOUT number_format(), which takes a float. The whole point
        // of this class is that a monetary value never becomes a float, and a
        // formatter that quietly casts one would make that claim false at the
        // exact moment a human reads the number.
        $grouped = strrev(implode(',', str_split(strrev($integer), 3)));

        return trim(
            ($negative ? '-' : '')
            . $grouped . '.' . substr($fraction . '00', 0, self::SCALE)
            . ' ' . $currency
        );
    }
}
