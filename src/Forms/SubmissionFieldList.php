<?php
declare(strict_types=1);

namespace Convermetry\Forms;

if (!defined('ABSPATH')) exit;

use Countable;
use IteratorAggregate;
use Traversable;

/**
 * A submission's fields, in the order the form produced them.
 *
 * A LIST, never a map. The historical format keyed by label, so two fields
 * both labelled "Name" silently collapsed into one and the field's stable id
 * was lost. Ordering, duplicates, and ids all survive here, and this class
 * deliberately offers no label => value lookup: adding one would reintroduce
 * exactly the collision the list format exists to prevent. If you need a
 * specific field, {@see byId()} is the addressable accessor, and it returns
 * the FIRST match because a provider may legitimately emit an id twice.
 *
 * Iterable and countable, so `foreach` and `count()` work on it the way they
 * worked on the array it replaced. That is the whole reason it is a collection
 * rather than a plain `list<SubmissionField>`: the display surfaces iterate it,
 * the notification builder counts it, and the export flattens it, so there is
 * real shared behaviour to hold — {@see toDisplayPairs()} in particular was
 * previously a static helper that every caller had to remember to route
 * through.
 *
 * @implements IteratorAggregate<int, SubmissionField>
 */
final readonly class SubmissionFieldList implements Countable, IteratorAggregate
{
    /**
     * @param list<SubmissionField> $fields Ordered fields.
     */
    private function __construct(private array $fields)
    {
    }

    /**
     * Builds a list from already-normalized fields.
     *
     * @param list<SubmissionField> $fields Ordered fields.
     * @return self
     */
    public static function of(array $fields): self
    {
        return new self($fields);
    }

    /**
     * An empty list.
     *
     * @return self
     */
    public static function empty(): self
    {
        return new self([]);
    }

    /**
     * Normalizes any accepted input shape — a descriptor list, a historical
     * associative map, or the public custom-form API's map — into typed
     * fields. {@see SubmissionFields} owns the rules; this is the entry point.
     *
     * @param array<mixed> $fields Raw fields in any accepted shape.
     * @return self
     */
    public static function fromMixed(array $fields): self
    {
        return SubmissionFields::parse($fields);
    }

    /**
     * The fields, as a plain list.
     *
     * @return list<SubmissionField>
     */
    public function all(): array
    {
        return $this->fields;
    }

    /**
     * The first field with this id, or null.
     *
     * @param string $id Field id.
     * @return SubmissionField|null
     */
    public function byId(string $id): ?SubmissionField
    {
        foreach ($this->fields as $field) {
            if ($field->id === $id) {
                return $field;
            }
        }

        return null;
    }

    /**
     * Whether the submission carried no fields at all.
     *
     * @return bool
     */
    public function isEmpty(): bool
    {
        return $this->fields === [];
    }

    /**
     * How many fields the submission carried.
     *
     * @return int
     */
    public function count(): int
    {
        return count($this->fields);
    }

    /**
     * @return Traversable<int, SubmissionField>
     */
    public function getIterator(): Traversable
    {
        return new \ArrayIterator($this->fields);
    }

    /**
     * Ordered label/value pairs for display surfaces.
     *
     * Returns a LIST, not a map, so two fields sharing a label stay two rows.
     *
     * @return list<array{label: string, value: string}>
     */
    public function toDisplayPairs(): array
    {
        return array_map(
            static fn(SubmissionField $field): array => [
                'label' => $field->label,
                'value' => $field->displayValue(),
            ],
            $this->fields
        );
    }

    /**
     * The schema 2.0 descriptor list, for storage and the wire.
     *
     * @return list<array{id: string, label: string, value: string|list<string>}>
     */
    public function toArray(): array
    {
        return array_map(static fn(SubmissionField $field): array => $field->toArray(), $this->fields);
    }
}
