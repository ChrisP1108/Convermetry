<?php
declare(strict_types=1);

namespace Convermetry\Support;

if (!defined('ABSPATH')) exit;

/**
 * The stored {key, value} pair lists behind custom webhook headers and URL
 * query parameters — global ones on the Webhooks page, per-form ones on the
 * Forms page.
 *
 * THESE STAY ARRAYS. A name and a value with no behaviour is the shape the
 * admin UI edits, the shape the option stores, and the shape a request is
 * composed from; wrapping two strings in a class would add a hop and take
 * nothing away.
 *
 * What they lacked was a guaranteed shape. Four readers each coerced the two
 * keys themselves, in slightly different ways, while every return type
 * declared `array{key: string, value: string}` about rows that could hold
 * anything at all — the option is administrator-editable, and reachable from
 * WP-CLI and from any filter on option reads. Coercing in exactly one place is
 * what makes those declarations true.
 */
final class KeyValuePairs
{
    /** Longest header or query-parameter name accepted from the admin screens, in bytes. */
    public const int MAX_NAME_LEN = 256;

    /**
     * Longest value accepted, in bytes. Generous enough for a signed JWT or a
     * long bearer token; bounded so a single row cannot bloat the option.
     */
    public const int MAX_VALUE_LEN = 8192;

    /**
     * Validates the custom-header rows posted by an admin screen.
     *
     * Field-specific rather than sanitize_text_field(): that function strips
     * every %XX sequence and anything shaped like a tag, which silently
     * corrupts legitimate credentials ("Bearer abc%2Fdef", "<urn:id>"). A
     * header is instead VALIDATED for what HTTP allows — the name an RFC 9110
     * token, the value free of control characters — and a row that fails is
     * rejected and counted so the screen can say so, rather than stored in a
     * mangled form or sent as an injected header line.
     *
     * @param mixed $raw The unslashed POST value: absent (null), or a list of
     *                   {key, value} rows.
     * @return array{pairs: list<array{key: string, value: string}>, rejected: int, malformed: bool}
     *         'malformed' is true when the value is not the shape the screen
     *         posts at all; 'pairs' is then empty and must not be stored.
     */
    public static function fromHeaderInput(mixed $raw): array
    {
        return self::fromInput($raw, self::headerName(...), self::headerValue(...));
    }

    /**
     * Validates the URL query-parameter rows posted by an admin screen.
     *
     * Names and values are kept verbatim apart from surrounding whitespace —
     * including '%', '+' and '&', which are legitimate in a value because
     * every parameter is rawurlencode()d when the delivery URL is built. Only
     * control characters and invalid UTF-8 are refused.
     *
     * @param mixed $raw The unslashed POST value: absent (null), or a list of
     *                   {key, value} rows.
     * @return array{pairs: list<array{key: string, value: string}>, rejected: int, malformed: bool}
     */
    public static function fromQueryInput(mixed $raw): array
    {
        return self::fromInput($raw, self::queryName(...), self::queryValue(...));
    }

    /**
     * Shared row walk for the two input validators.
     *
     * A row whose name was left blank is skipped silently — it is an empty row
     * in the editor, not a mistake. A row with a name that fails validation is
     * rejected and counted. A row that is not a {key, value} pair of strings
     * makes the whole list malformed: the editor never posts one, and dropping
     * it quietly would delete a stored pair the admin never removed.
     *
     * @param mixed                     $raw   The unslashed POST value.
     * @param callable(string): ?string $name  Validates a trimmed name; null rejects.
     * @param callable(string): ?string $value Validates a value; null rejects.
     * @return array{pairs: list<array{key: string, value: string}>, rejected: int, malformed: bool}
     */
    private static function fromInput(mixed $raw, callable $name, callable $value): array
    {
        // No rows at all: the editor posts nothing when every row was removed.
        if ($raw === null) {
            return ['pairs' => [], 'rejected' => 0, 'malformed' => false];
        }

        if (!is_array($raw)) {
            return ['pairs' => [], 'rejected' => 0, 'malformed' => true];
        }

        $pairs    = [];
        $rejected = 0;

        foreach ($raw as $row) {
            if (!is_array($row)) {
                return ['pairs' => [], 'rejected' => 0, 'malformed' => true];
            }

            // Every field of a row is a text input; anything nested is not
            // something the editor posts.
            foreach ($row as $field) {
                if (!is_string($field)) {
                    return ['pairs' => [], 'rejected' => 0, 'malformed' => true];
                }
            }

            $rawName  = (string) ($row['key'] ?? '');
            $rawValue = (string) ($row['value'] ?? '');

            $rawName = trim($rawName);
            if ($rawName === '') {
                continue;
            }

            $cleanName  = $name($rawName);
            $cleanValue = $value($rawValue);

            if ($cleanName === null || $cleanValue === null) {
                $rejected++;
                continue;
            }

            $pairs[] = ['key' => $cleanName, 'value' => $cleanValue];
        }

        return ['pairs' => $pairs, 'rejected' => $rejected, 'malformed' => false];
    }

    /**
     * A header name, or null when it is not an RFC 9110 token.
     *
     * @param string $name Trimmed candidate name.
     * @return string|null
     */
    private static function headerName(string $name): ?string
    {
        return strlen($name) <= self::MAX_NAME_LEN && preg_match('/^[!#$%&\'*+.^_`|~0-9A-Za-z-]+$/', $name) === 1
            ? $name
            : null;
    }

    /**
     * A header value with surrounding whitespace removed, or null when it
     * holds a control character (CR and LF above all — they would end the
     * header line), is not valid UTF-8, or is too long. A tab inside the
     * value is legal HTTP and kept.
     *
     * @param string $value Candidate value.
     * @return string|null
     */
    private static function headerValue(string $value): ?string
    {
        $value = trim($value, " \t");

        if (strlen($value) > self::MAX_VALUE_LEN || !mb_check_encoding($value, 'UTF-8')) {
            return null;
        }

        return preg_match('/[\x00-\x08\x0A-\x1F\x7F]/', $value) === 1 ? null : $value;
    }

    /**
     * A query-parameter name, or null when it holds a control character, is
     * not valid UTF-8, or is too long.
     *
     * @param string $name Trimmed candidate name.
     * @return string|null
     */
    private static function queryName(string $name): ?string
    {
        if (strlen($name) > self::MAX_NAME_LEN || !mb_check_encoding($name, 'UTF-8')) {
            return null;
        }

        return preg_match('/[\x00-\x1F\x7F]/', $name) === 1 ? null : $name;
    }

    /**
     * A query-parameter value with surrounding whitespace removed, or null
     * when it holds a control character, is not valid UTF-8, or is too long.
     *
     * @param string $value Candidate value.
     * @return string|null
     */
    private static function queryValue(string $value): ?string
    {
        $value = trim($value);

        if (strlen($value) > self::MAX_VALUE_LEN || !mb_check_encoding($value, 'UTF-8')) {
            return null;
        }

        return preg_match('/[\x00-\x1F\x7F]/', $value) === 1 ? null : $value;
    }

    /**
     * Normalizes a stored pair list.
     *
     * Rows with a blank key are KEPT, not dropped: the admin pages render this
     * list straight back into their editors, and silently deleting a
     * half-filled row somebody is still typing into would be its own bug.
     * {@see toMap()} skips them when a request is actually composed.
     *
     * @param mixed $raw The stored value, whatever it turns out to be.
     * @return list<array{key: string, value: string}>
     */
    public static function normalize(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $pair) {
            if (!is_array($pair)) {
                continue;
            }

            $out[] = [
                'key'   => is_scalar($pair['key'] ?? null) ? trim((string) $pair['key']) : '',
                'value' => is_scalar($pair['value'] ?? null) ? (string) $pair['value'] : '',
            ];
        }

        return $out;
    }

    /**
     * Flattens a normalized pair list into an associative map, skipping blank
     * keys. Later duplicates override earlier ones, which is what gives the
     * documented precedence order (global, then page, then per-form, then
     * runtime) its meaning.
     *
     * @param list<array{key: string, value: string}> $pairs Normalized pairs.
     * @return array<string, string>
     */
    public static function toMap(array $pairs): array
    {
        $map = [];
        foreach ($pairs as $pair) {
            if ($pair['key'] !== '') {
                $map[$pair['key']] = $pair['value'];
            }
        }

        return $map;
    }
}
