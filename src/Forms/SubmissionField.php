<?php
declare(strict_types=1);

namespace Convermetry\Forms;

if (!defined('ABSPATH')) exit;

/**
 * One field of one form submission.
 *
 * The typed form of a schema 2.0 field descriptor:
 *
 *     {"id": "email", "label": "Email address", "value": "john@example.com"}
 *
 * The contract, unchanged from when this was an array:
 *
 *  - {@see $id} is the provider-native field id or key, never empty.
 *  - {@see $label} is the human-readable label captured at submission time; it
 *    falls back to the id when no distinct label can be obtained reliably
 *    (Contact Form 7 and Fluent Forms expose no dependable label in their hook
 *    payloads), so it is never empty either.
 *  - {@see $value} is a sanitized string or a LIST of sanitized strings —
 *    nothing else, no nested objects, no arbitrary depth.
 *
 * CARRIES PERSONAL DATA. This is the visitor's submitted value, sanitized and
 * with Convermetry's own cvm_* fields already stripped, but otherwise exactly
 * what they typed.
 *
 * {@see toArray()} produces the wire/storage descriptor. It stays an array on
 * the way out because that array IS schema 2.0 — it is what is stored in the
 * submission row, what receivers get as submission_data, and what the
 * 'convermetry_submission_fields' filter has always been handed.
 */
final readonly class SubmissionField
{
    /**
     * @param string             $id    Provider-native field id; never empty.
     * @param string             $label Human-readable label; never empty.
     * @param string|list<string> $value Sanitized value, or list of them.
     */
    public function __construct(
        public string $id,
        public string $label,
        public string|array $value,
    ) {
    }

    /**
     * The value rendered as a single display string.
     *
     * A list joins on ", ". This is for humans — the Submissions detail panel,
     * a notification email, a CSV cell — and is deliberately lossy: a value
     * containing a comma is indistinguishable from two values afterwards, so
     * nothing that needs the structure back may use it.
     *
     * @return string
     */
    public function displayValue(): string
    {
        return is_array($this->value) ? implode(', ', $this->value) : $this->value;
    }

    /**
     * The descriptor, exactly as schema 2.0 defines it.
     *
     * @return array{id: string, label: string, value: string|list<string>}
     */
    public function toArray(): array
    {
        return ['id' => $this->id, 'label' => $this->label, 'value' => $this->value];
    }
}
