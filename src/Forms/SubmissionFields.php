<?php
declare(strict_types=1);

namespace Convermetry\Forms;

if (!defined('ABSPATH')) exit;

/**
 * The canonical shape of a submission's field data, and the one place that
 * knows how to produce it.
 *
 * Every submission recorded from schema 2.0 onward stores an ORDERED LIST of
 * field descriptors:
 *
 *     [
 *         {"id": "email",     "label": "Email address",       "value": "john@example.com"},
 *         {"id": "interests", "label": "Services of interest", "value": ["Tax planning", "Retirement"]}
 *     ]
 *
 * The contract:
 *
 *  - 'id' is the provider-native field id or key, always a string, never empty.
 *  - 'label' is the human-readable label captured at submission time, always a
 *    string; it falls back to the id when no distinct label can be obtained
 *    reliably (Contact Form 7 and Fluent Forms expose no dependable label in
 *    their hook payloads).
 *  - 'value' is a sanitized string or a list of sanitized strings. Nothing
 *    else — no nested objects, no arbitrary depth.
 *
 * WHY A LIST AND NOT A MAP. The historical format keyed by label, so two
 * fields both labelled "Name" silently collapsed into one and the field's
 * stable id was lost. A list preserves provider order, preserves duplicates,
 * and keeps the id for automation while keeping the label for humans. Nothing
 * in this class may key or deduplicate by label, and there is deliberately no
 * label => value helper: adding one would reintroduce the collision.
 *
 * THREE INPUT SHAPES are accepted, because three kinds of caller exist:
 *
 *  1. The descriptor list above — what the bundled providers now build.
 *  2. A historical associative map ('Email' => 'a@b.com') — what every row
 *     stored before 2.0 holds, and what still arrives from third-party
 *     integrations.
 *  3. The public custom-form API's map (['email' => $email, ...]) — the same
 *     shape as (2); each key becomes both the id and the label.
 *
 * Detection between (1) and (2/3) is strict: see {@see self::isDescriptorList()}.
 */
final class SubmissionFields
{
    /**
     * Prefix marking Convermetry's own correlation fields, which must never
     * reach storage, payloads, logs, CSV exports, or notification emails.
     */
    private const string INTERNAL_PREFIX = 'cvm_';

    /**
     * Normalizes any accepted input shape into the canonical descriptor list.
     *
     * @param array<mixed> $fields Descriptor list, or a historical/custom-API map.
     * @return list<array{id: string, label: string, value: string|list<string>}>
     */
    public static function normalize(array $fields): array
    {
        return self::isDescriptorList($fields)
            ? self::fromDescriptors($fields)
            : self::fromLegacyMap($fields);
    }

    /**
     * Whether an array is already a descriptor list.
     *
     * Deliberately strict: the array must be list-keyed AND every entry must
     * be an array carrying a scalar 'id'. A permissive test — sniffing only
     * the first entry, say — would misclassify a custom-form map whose values
     * happen to be arrays containing an 'id' key, and silently discard the
     * caller's data. One bad entry disqualifies the whole array, which then
     * takes the legacy-map path where nothing is lost.
     *
     * @param array<mixed> $fields Candidate array.
     * @return bool
     */
    public static function isDescriptorList(array $fields): bool
    {
        if ($fields === [] || !array_is_list($fields)) {
            return false;
        }

        foreach ($fields as $entry) {
            if (!is_array($entry) || !isset($entry['id']) || !is_scalar($entry['id'])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether a decoded submission_data column holds a pre-2.0 associative
     * map, which must keep emitting schema 1.0 with its original shape.
     *
     * An empty array is NOT a legacy map: '{}' and '[]' both decode to [], so
     * treating it as legacy would pin every field-less submission to the old
     * schema forever. Empty data is emitted as an empty 2.0 list instead.
     *
     * @param mixed $decoded Decoded submission_data.
     * @return bool
     */
    public static function isLegacyMap(mixed $decoded): bool
    {
        return is_array($decoded) && $decoded !== [] && !self::isDescriptorList($decoded);
    }

    /**
     * Reads any stored submission_data JSON as a descriptor list, whatever
     * shape the row actually holds.
     *
     * This is the read path for historical rows: they are normalized on
     * demand, never rewritten in bulk.
     *
     * @param string $json Stored submission_data column.
     * @return list<array{id: string, label: string, value: string|list<string>}>
     */
    public static function fromStoredJson(string $json): array
    {
        if ($json === '' || !json_validate($json)) {
            return [];
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? self::normalize($decoded) : [];
    }

    /**
     * Ordered label/value pairs for display surfaces.
     *
     * Returns a LIST, not a map, so two fields sharing a label stay two rows.
     *
     * @param list<array{id: string, label: string, value: string|list<string>}> $descriptors Normalized fields.
     * @return list<array{label: string, value: string}>
     */
    public static function toDisplayPairs(array $descriptors): array
    {
        $out = [];
        foreach ($descriptors as $field) {
            $out[] = [
                'label' => (string) ($field['label'] ?? ''),
                'value' => self::flatten($field['value'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * Renders a descriptor value as a single display string.
     *
     * @param mixed $value Descriptor value (string, list of strings, or junk).
     * @return string
     */
    public static function flatten(mixed $value): string
    {
        if (is_array($value)) {
            return implode(', ', array_map(
                static fn(mixed $item): string => is_scalar($item) ? (string) $item : '',
                $value
            ));
        }

        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * Whether a field id belongs to Convermetry's internal correlation fields.
     *
     * @param string $id Field id.
     * @return bool
     */
    public static function isInternalId(string $id): bool
    {
        return str_starts_with(strtolower($id), self::INTERNAL_PREFIX);
    }

    /**
     * Normalizes an already-structured descriptor list.
     *
     * @param array<mixed> $fields Descriptor list.
     * @return list<array{id: string, label: string, value: string|list<string>}>
     */
    private static function fromDescriptors(array $fields): array
    {
        $out = [];

        foreach ($fields as $entry) {
            $id = sanitize_text_field((string) $entry['id']);
            if ($id === '' || self::isInternalId($id)) {
                continue;
            }

            $rawLabel = isset($entry['label']) && is_scalar($entry['label'])
                ? sanitize_text_field((string) $entry['label'])
                : '';

            $out[] = [
                'id'    => $id,
                // No distinct label available (or a blank one) means the id is
                // the most honest thing to show a human.
                'label' => $rawLabel !== '' ? $rawLabel : $id,
                'value' => self::sanitizeValue($entry['value'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * Converts a historical or custom-API map into descriptors.
     *
     * The old format carried one name per field and no id, so that name
     * becomes both: id = label = key. This is what keeps
     * convermetry_submit_form(['email' => $email]) working unchanged.
     *
     * @param array<mixed> $fields Associative field map.
     * @return list<array{id: string, label: string, value: string|list<string>}>
     */
    private static function fromLegacyMap(array $fields): array
    {
        $out = [];

        foreach ($fields as $key => $value) {
            $id = sanitize_text_field((string) $key);
            if ($id === '' || self::isInternalId($id)) {
                continue;
            }

            $out[] = [
                'id'    => $id,
                'label' => $id,
                'value' => self::sanitizeValue($value),
            ];
        }

        return $out;
    }

    /**
     * Sanitizes a descriptor value into a string or a list of strings.
     *
     * Arrays are reindexed with array_values(): the canonical value type is a
     * LIST, and a multi-select's sub-keys are not part of the contract.
     * Anything non-scalar becomes an empty string rather than nested data.
     *
     * @param mixed $value Raw provider value.
     * @return string|list<string>
     */
    private static function sanitizeValue(mixed $value): string|array
    {
        if (is_array($value)) {
            return array_map(
                static fn(mixed $item): string => sanitize_text_field(is_scalar($item) ? (string) $item : ''),
                array_values($value)
            );
        }

        return sanitize_text_field(is_scalar($value) ? (string) $value : '');
    }
}
