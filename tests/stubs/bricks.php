<?php

/**
 * A stand-in for the object Bricks hands a custom form action.
 *
 * Convermetry never names a Bricks class — Bricks is a theme, its PHP API is not
 * published as a package, and {@see \Convermetry\Forms\Bricks\BricksFormsBridge}
 * duck-types the object it is given. So this stub exists only so the suite has
 * something with the documented shape to hand the action callback, and it is
 * deliberately NOT in a Bricks namespace: nothing in the plugin would notice if
 * it were.
 *
 * The shape mirrors what Bricks Academy documents for the form object passed to
 * `bricks/form/action/{form_action}`:
 *
 *   get_settings()        the form element's settings, including the ordered
 *                         'fields' repeater of {id, type, label, …}
 *   get_fields()          submitted values keyed 'form-field-<field id>', plus
 *                         'postId', 'formId' and 'referrer'
 *   get_uploaded_files()  file metadata grouped by 'form-field-<field id>',
 *                         each entry carrying 'file' (the physical path) and
 *                         'url'
 *   get_field_value($id)  one submitted value
 *   set_result($result)   the action's outcome
 *
 * WHAT A GREEN SUITE HERE PROVES: that Convermetry holds up its end of that
 * documented contract. It does not prove any particular Bricks build implements
 * it identically — that is a live-site check, and the README records it as
 * unverified.
 */

declare(strict_types=1);

namespace Convermetry\Tests\Stubs;

final class BricksForm
{
    /** @var list<array<string, mixed>> Every result this action set. */
    public array $results = [];

    /**
     * @param array<string, mixed> $settings Form element settings.
     * @param array<string, mixed> $fields   Submitted values plus metadata.
     * @param array<string, mixed> $files    Uploaded file metadata.
     */
    public function __construct(
        private readonly array $settings = [],
        private readonly array $fields = [],
        private readonly array $files = [],
    ) {
    }

    /** @return array<string, mixed> */
    public function get_settings(): array
    {
        return $this->settings;
    }

    /** @return array<string, mixed> */
    public function get_fields(): array
    {
        return $this->fields;
    }

    /** @return array<string, mixed> */
    public function get_uploaded_files(): array
    {
        return $this->files;
    }

    public function get_field_value(string $fieldId): mixed
    {
        return $this->fields['form-field-' . $fieldId] ?? null;
    }

    /**
     * @param array<string, mixed> $result
     * @return void
     */
    public function set_result(array $result): void
    {
        $this->results[] = $result;
    }
}

/**
 * A form object whose accessors are missing or throw — the shape a Bricks that
 * renamed or broke a method would present.
 */
final class BrokenBricksForm
{
    /** @var list<array<string, mixed>> */
    public array $results = [];

    /** @return array<string, mixed> */
    public function get_fields(): array
    {
        return ['formId' => 'ab12cd', 'postId' => 7];
    }

    /** @return array<string, mixed> */
    public function get_settings(): array
    {
        throw new \RuntimeException('Bricks could not assemble the settings.');
    }

    /**
     * @param array<string, mixed> $result
     * @return void
     */
    public function set_result(array $result): void
    {
        $this->results[] = $result;
    }
}
