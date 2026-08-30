<?php
declare(strict_types=1);

namespace Convermetry\Support;

if (!defined('ABSPATH')) exit;

/**
 * The single gate every third-party "extensions" bucket passes through.
 *
 * Convermetry lets integrations attach their own data to four surfaces — a
 * webhook payload, the analytics summary, the frontend tracker config, and a
 * submission's stored context — plus the delivery-log REST item. All five use
 * the same rule set, held here rather than repeated at each call site, because
 * the compatibility guarantee they share is exact:
 *
 *  - **Nothing registered means nothing changes.** {@see attach()} returns the
 *    caller's array *unchanged by identity* — same keys, same values, same
 *    order — whenever the filtered result is empty. That is what keeps payload
 *    bytes, tracker config, and report shapes byte-identical on a site with no
 *    integrations, and it is a property of this one function instead of a rule
 *    twelve call sites have to remember.
 *  - **A core key can never be shadowed.** The merge uses `+`, whose left
 *    operand wins, and every extension key must contain a '/' — no core key
 *    anywhere in the plugin does.
 *  - **Only JSON primitives survive.** Objects are rejected outright, including
 *    JsonSerializable ones: what a receiver gets must be decidable here, not by
 *    an arbitrary serializer that can throw, recurse, or leak a private field.
 *  - **Every surface is bounded** in nesting depth, key count, and encoded
 *    bytes, with per-surface caps — 32 KB is reasonable in a webhook body and
 *    absurd in an inline <script> tag on every page view.
 *
 * Truncation is deterministic: keys are sorted before any cap is applied, so
 * the same input always yields the same output rather than depending on the
 * order callbacks happened to register in.
 */
final class Extensions
{
    /** Maximum nesting depth accepted inside any extension value. */
    public const int MAX_DEPTH = 8;

    /** Cap for a webhook payload's extensions property (bytes / top-level keys). */
    public const int WEBHOOK_MAX_BYTES = 32768;
    public const int WEBHOOK_MAX_KEYS  = 50;

    /** Cap for the analytics summary's extensions property. */
    public const int ANALYTICS_MAX_BYTES = 32768;
    public const int ANALYTICS_MAX_KEYS  = 50;

    /**
     * Cap for the frontend tracker config. Deliberately the smallest of the
     * three JSON surfaces: this one is inlined into every page's HTML.
     */
    public const int TRACKER_MAX_BYTES = 8192;
    public const int TRACKER_MAX_KEYS  = 20;

    /** Cap for a submission's stored analytics context. */
    public const int CONTEXT_MAX_BYTES = 8192;
    public const int CONTEXT_MAX_KEYS  = 20;

    /** Cap for one delivery-log REST item's extensions property. */
    public const int API_ITEM_MAX_BYTES = 4096;
    public const int API_ITEM_MAX_KEYS  = 10;

    /**
     * Applies one extension filter and merges the survivors into $target.
     *
     * The filter is always called — never gated on has_filter(), which would
     * bypass WordPress's global 'all' hook — and is seeded with an empty array
     * so a callback that ignores its input cannot smuggle core data back in.
     *
     * @param array<string, mixed> $target   The array to attach to.
     * @param string               $property Property name to attach under (e.g. 'extensions').
     * @param non-empty-string     $filter   Filter hook name. Every call site passes a literal;
     *                                       apply_filters() requires a non-empty name, and an
     *                                       empty one would silently register nothing.
     * @param int                  $maxBytes Encoded-size cap for this surface.
     * @param int                  $maxKeys  Top-level key cap for this surface.
     * @param mixed                ...$args  Extra arguments passed to the filter after the seed.
     * @return array<string, mixed> $target unchanged when nothing survives.
     */
    public static function attach(
        array $target,
        string $property,
        string $filter,
        int $maxBytes,
        int $maxKeys,
        mixed ...$args
    ): array {
        $extensions = self::sanitize(apply_filters($filter, [], ...$args), $maxBytes, $maxKeys);

        if ($extensions === []) {
            return $target;
        }

        // '+' keeps the left operand's value on collision, so a core property
        // of this name is never replaced by extension data.
        return $target + [$property => $extensions];
    }

    /**
     * Whether a key is a usable extension key.
     *
     * Extension keys are namespaced 'vendor/thing'. The '/' is the whole point:
     * no core key in any Convermetry payload, report, or config contains one, so
     * a namespaced key cannot collide with a present or future core key.
     *
     * @param mixed $key Candidate key.
     * @return bool
     */
    public static function isValidKey(mixed $key): bool
    {
        if (!is_string($key)) {
            return false;
        }

        return (bool) preg_match('~^[a-z0-9][a-z0-9._\-]{0,39}/[a-z0-9][a-z0-9._\-]{0,63}$~i', $key);
    }

    /**
     * Normalizes a filtered extension bucket: namespaced keys only, JSON
     * primitives only, bounded depth, then a deterministic key-count and
     * encoded-byte cap.
     *
     * @param mixed $raw      Whatever the filter returned.
     * @param int   $maxBytes Encoded-size cap.
     * @param int   $maxKeys  Top-level key cap.
     * @return array<string, mixed> Empty when nothing survives.
     */
    public static function sanitize(mixed $raw, int $maxBytes, int $maxKeys): array
    {
        if (!is_array($raw) || $raw === []) {
            return [];
        }

        $clean = [];
        foreach ($raw as $key => $value) {
            if (self::isValidKey($key) && self::isJsonSafe($value, 1)) {
                $clean[$key] = $value;
            }
        }

        if ($clean === []) {
            return [];
        }

        // Sort before capping so truncation depends on the data, not on the
        // order callbacks happened to register in.
        ksort($clean);

        if (count($clean) > $maxKeys) {
            $clean = array_slice($clean, 0, $maxKeys, true);
        }

        // Drop from the end until the whole bucket encodes within budget. A
        // single oversized entry therefore yields an empty bucket rather than a
        // silently truncated one.
        while ($clean !== []) {
            $encoded = wp_json_encode($clean);

            if (is_string($encoded) && strlen($encoded) <= $maxBytes) {
                return $clean;
            }

            array_pop($clean);
        }

        return [];
    }

    /**
     * Whether a value is representable as JSON without surprises.
     *
     * Objects are rejected at every depth, JsonSerializable included: honouring
     * one would hand serialization to third-party code that can throw, recurse,
     * or expose a private field, on a path that runs inside a webhook delivery.
     *
     * @param mixed $value Candidate value.
     * @param int   $depth Current nesting depth (1 = a top-level value).
     * @return bool
     */
    private static function isJsonSafe(mixed $value, int $depth): bool
    {
        if ($value === null || is_bool($value) || is_int($value) || is_string($value)) {
            return true;
        }

        if (is_float($value)) {
            return is_finite($value);
        }

        if (!is_array($value)) {
            return false;
        }

        if ($depth >= self::MAX_DEPTH) {
            return false;
        }

        // No key-type check: PHP array keys are int|string by construction, so
        // there is no third case to reject. The value is what needs checking.
        foreach ($value as $item) {
            if (!self::isJsonSafe($item, $depth + 1)) {
                return false;
            }
        }

        return true;
    }
}
