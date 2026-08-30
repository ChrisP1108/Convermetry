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
