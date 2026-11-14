<?php

declare(strict_types=1);

namespace Convermetry\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Convermetry\Forms\SubmissionFields;
use PHPUnit\Framework\TestCase;

/**
 * The canonical submission-field shape.
 *
 * Regression origin: submission_data was a flat label => value map, so
 * providers had to throw away either the stable field id (Gravity Forms,
 * WPForms, Ninja, Formidable keyed by label) or the human label (Elementor
 * keyed by id). Two fields sharing a label collapsed into one, and Elementor
 * leads were unreadable because their ids are opaque.
 *
 * Every rule below is a rule the old format broke.
 */
final class SubmissionFieldsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        // The plugin's sanitizer strips tags and control characters; identity
        // is enough to test shape, and the escaping tests live elsewhere.
        Functions\when('sanitize_text_field')->alias(static fn(string $v): string => trim(strip_tags($v)));
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    // ── Legacy maps ──────────────────────────────────────────────────────────

    /**
     * The public custom-form API's long-standing shape. Callers passing this
     * must keep working without changing a line.
     */
    public function testLegacyMapBecomesDescriptorsWithIdEqualToLabel(): void
    {
        $out = SubmissionFields::normalize(['email' => 'john@example.com', 'name' => 'John Doe']);

        self::assertSame([
            ['id' => 'email', 'label' => 'email', 'value' => 'john@example.com'],
            ['id' => 'name',  'label' => 'name',  'value' => 'John Doe'],
        ], $out);
    }

    public function testLegacyMapPreservesInsertionOrder(): void
    {
        $out = SubmissionFields::normalize(['z' => '1', 'a' => '2', 'm' => '3']);

        self::assertSame(['z', 'a', 'm'], array_column($out, 'id'));
    }

    public function testLegacyMapMultiValueFieldBecomesAListOfStrings(): void
    {
        $out = SubmissionFields::normalize(['interests' => ['Tax planning', 'Retirement']]);

        self::assertSame(['Tax planning', 'Retirement'], $out[0]['value']);
    }

    public function testLegacyMapNumericKeysAreKept(): void
    {
        $out = SubmissionFields::normalize(['1' => 'a@b.com', '2.3' => 'Ada']);

        self::assertSame(['1', '2.3'], array_column($out, 'id'));
    }

    // ── Descriptor lists ─────────────────────────────────────────────────────

    public function testDescriptorListIsPreservedWithIdLabelAndValue(): void
    {
        $out = SubmissionFields::normalize([
            ['id' => 'email',     'label' => 'Email address',        'value' => 'john@example.com'],
            ['id' => 'interests', 'label' => 'Services of interest', 'value' => ['Tax planning', 'Retirement']],
        ]);

        self::assertSame([
            ['id' => 'email',     'label' => 'Email address',        'value' => 'john@example.com'],
            ['id' => 'interests', 'label' => 'Services of interest', 'value' => ['Tax planning', 'Retirement']],
        ], $out);
    }

    /**
     * Contact Form 7 and Fluent Forms cannot supply a reliable label, and a
     * blank label from any provider is no better than none.
     *
     * @dataProvider blankLabels
     */
    public function testLabelFallsBackToTheIdWhenNoDistinctLabelExists(mixed $label): void
    {
        $out = SubmissionFields::normalize([['id' => 'your-email', 'label' => $label, 'value' => 'a@b.com']]);

        self::assertSame('your-email', $out[0]['label']);
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function blankLabels(): array
    {
        return [
            'empty string' => [''],
            'whitespace'   => ['   '],
            'missing'      => [null],
            'array'        => [['not', 'scalar']],
        ];
    }

    /**
     * THE bug the descriptor list exists to fix. A map keyed by label silently
     * dropped the second "Name"; a list must keep both, in order.
     */
    public function testDuplicateLabelsRemainSeparateAndOrdered(): void
    {
        $out = SubmissionFields::normalize([
            ['id' => '1', 'label' => 'Name', 'value' => 'Ada'],
            ['id' => '2', 'label' => 'Name', 'value' => 'Grace'],
            ['id' => '3', 'label' => 'Name', 'value' => 'Alan'],
        ]);

        self::assertCount(3, $out);
        self::assertSame(['Ada', 'Grace', 'Alan'], array_column($out, 'value'));
        self::assertSame(['1', '2', '3'], array_column($out, 'id'));
    }

    public function testEntriesWithAnEmptyIdAreSkipped(): void
    {
        $out = SubmissionFields::normalize([
            ['id' => '',   'label' => 'Orphan', 'value' => 'x'],
            ['id' => '  ', 'label' => 'Blank',  'value' => 'y'],
            ['id' => 'ok', 'label' => 'Kept',   'value' => 'z'],
        ]);

        self::assertSame([['id' => 'ok', 'label' => 'Kept', 'value' => 'z']], $out);
    }

    public function testNonScalarValuesBecomeEmptyStringsRatherThanNestedData(): void
    {
        $out = SubmissionFields::normalize([
            ['id' => 'a', 'label' => 'A', 'value' => (object) ['x' => 1]],
            ['id' => 'b', 'label' => 'B', 'value' => ['ok', ['nested']]],
        ]);

        self::assertSame('', $out[0]['value']);
        self::assertSame(['ok', ''], $out[1]['value'], 'Nested arrays flatten to empty, never to structure');
    }

    public function testArrayValuesAreReindexedIntoAList(): void
    {
        $out = SubmissionFields::normalize([
            ['id' => 'names', 'label' => 'Names', 'value' => ['first' => 'Ada', 'last' => 'Lovelace']],
        ]);

        self::assertSame(['Ada', 'Lovelace'], $out[0]['value']);
        self::assertTrue(array_is_list($out[0]['value']));
    }

    // ── Strict shape detection ───────────────────────────────────────────────

    public function testAnAssociativeArrayCarryingAnIdKeyIsALegacyMapNotADescriptor(): void
    {
        // A custom-form caller with a field literally named "id".
        $out = SubmissionFields::normalize(['id' => 'ABC-123', 'email' => 'a@b.com']);

        self::assertSame([
            ['id' => 'id',    'label' => 'id',    'value' => 'ABC-123'],
            ['id' => 'email', 'label' => 'email', 'value' => 'a@b.com'],
        ], $out);
    }

    /**
     * Sniffing only the first entry would misread this and silently discard
     * the caller's remaining data.
     */
    public function testAListWhoseEntriesAreNotAllDescriptorsIsTreatedAsALegacyMap(): void
    {
        $out = SubmissionFields::normalize([
            ['id' => 'a', 'label' => 'A', 'value' => 'x'],
            'plain string',
        ]);

        self::assertCount(2, $out);
        self::assertSame(['0', '1'], array_column($out, 'id'), 'Falls back to the map path, keyed by position');
    }

    public function testAListOfArraysWithoutIdsIsTreatedAsALegacyMap(): void
    {
        $out = SubmissionFields::normalize([['label' => 'A', 'value' => 'x']]);

        self::assertSame('0', $out[0]['id']);
    }

    public function testAnEmptyArrayNormalizesToAnEmptyList(): void
    {
        self::assertSame([], SubmissionFields::normalize([]));
    }

    // ── Internal field stripping ─────────────────────────────────────────────

    /**
     * Correlation fields must never reach storage, payloads, logs, CSV, or
     * email — from EITHER input shape.
     */
    public function testInternalCvmtryFieldsAreStrippedFromALegacyMap(): void
    {
        $out = SubmissionFields::normalize([
            'cvmtry_conversion_id' => 'c1',
            'CVMTRY_Session_Id'    => 's1',
            'cvmtry_context'       => '{}',
            'email'             => 'a@b.com',
        ]);

        self::assertSame([['id' => 'email', 'label' => 'email', 'value' => 'a@b.com']], $out);
    }

    public function testInternalCvmtryFieldsAreStrippedFromADescriptorList(): void
    {
        $out = SubmissionFields::normalize([
            ['id' => 'cvmtry_conversion_id', 'label' => 'Conversion', 'value' => 'c1'],
            ['id' => 'CVMTRY_SESSION_ID',    'label' => 'Session',    'value' => 's1'],
            ['id' => 'email',             'label' => 'Email',      'value' => 'a@b.com'],
        ]);

        self::assertSame([['id' => 'email', 'label' => 'Email', 'value' => 'a@b.com']], $out);
    }

    public function testFieldsMerelyContainingCvmtryAreNotStripped(): void
    {
        $out = SubmissionFields::normalize(['my_cvmtry_note' => 'keep', 'cvmtryx' => 'keep']);

        self::assertCount(2, $out);
    }

    // ── Stored-row reading ───────────────────────────────────────────────────

    public function testFromStoredJsonReadsAHistoricalMap(): void
    {
        $out = SubmissionFields::fromStoredJson('{"Email":"a@b.com","Phone":"555"}');

        self::assertSame([
            ['id' => 'Email', 'label' => 'Email', 'value' => 'a@b.com'],
            ['id' => 'Phone', 'label' => 'Phone', 'value' => '555'],
        ], $out->toArray());
        self::assertCount(2, $out);
        self::assertSame('a@b.com', $out->byId('Email')?->displayValue());
    }

    public function testFromStoredJsonReadsADescriptorList(): void
    {
        $out = SubmissionFields::fromStoredJson('[{"id":"email","label":"Email address","value":"a@b.com"}]');

        self::assertSame([['id' => 'email', 'label' => 'Email address', 'value' => 'a@b.com']], $out->toArray());
        self::assertSame('Email address', $out->all()[0]->label);
    }

    /**
     * @dataProvider unusableStoredJson
     */
    public function testFromStoredJsonToleratesUnusableColumns(string $json): void
    {
        $out = SubmissionFields::fromStoredJson($json);

        self::assertTrue($out->isEmpty());
        self::assertSame([], $out->toArray());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unusableStoredJson(): array
    {
        return [
            'empty'      => [''],
            'malformed'  => ['{"a":'],
            'scalar'     => ['5'],
            'null'       => ['null'],
            'empty map'  => ['{}'],
            'empty list' => ['[]'],
        ];
    }

    // ── Schema branching ─────────────────────────────────────────────────────

    public function testIsLegacyMapRecognisesAHistoricalRow(): void
    {
        self::assertTrue(SubmissionFields::isLegacyMap(['Email' => 'a@b.com']));
    }

    public function testIsLegacyMapRejectsADescriptorList(): void
    {
        self::assertFalse(SubmissionFields::isLegacyMap([['id' => 'e', 'label' => 'E', 'value' => 'x']]));
    }

    /**
     * '{}' and '[]' both decode to []. Treating that as legacy would pin every
     * field-less submission to schema 1.0 forever.
     */
    public function testIsLegacyMapRejectsEmptyData(): void
    {
        self::assertFalse(SubmissionFields::isLegacyMap([]));
        self::assertFalse(SubmissionFields::isLegacyMap(null));
        self::assertFalse(SubmissionFields::isLegacyMap('string'));
    }

    // ── Display helpers ──────────────────────────────────────────────────────

    public function testToDisplayPairsKeepsDuplicateLabelsAsSeparateRows(): void
    {
        $pairs = SubmissionFields::parse([
            ['id' => '1', 'label' => 'Name', 'value' => 'Ada'],
            ['id' => '2', 'label' => 'Name', 'value' => 'Grace'],
        ])->toDisplayPairs();

        self::assertSame([
            ['label' => 'Name', 'value' => 'Ada'],
            ['label' => 'Name', 'value' => 'Grace'],
        ], $pairs);
    }

    public function testToDisplayPairsFlattensListValues(): void
    {
        $pairs = SubmissionFields::parse([
            ['id' => 'i', 'label' => 'Interests', 'value' => ['Tax planning', 'Retirement']],
        ])->toDisplayPairs();

        self::assertSame('Tax planning, Retirement', $pairs[0]['value']);
    }

    /**
     * @dataProvider flattenCases
     */
    public function testFlatten(mixed $value, string $expected): void
    {
        self::assertSame($expected, SubmissionFields::flatten($value));
    }

    /**
     * @return array<string, array{mixed, string}>
     */
    public static function flattenCases(): array
    {
        return [
            'string'    => ['Ada', 'Ada'],
            'list'      => [['a', 'b'], 'a, b'],
            'empty list'=> [[], ''],
            'int'       => [42, '42'],
            'bool'      => [true, '1'],
            'object'    => [(object) [], ''],
            'null'      => [null, ''],
        ];
    }

    // ── Values are stored as text ────────────────────────────────────────────

    /**
     * The message-fidelity scenario: what the visitor typed into a textarea is
     * what is stored, exported and sent. sanitize_text_field() joined the
     * lines, turned "<" into "&lt;", deleted the tag-like text and every "%"
     * followed by two hex digits ("100%accuracy" became "100curacy").
     */
    public function testAValueIsStoredAsTheVisitorTypedIt(): void
    {
        $message = "Hi team,\nOur budget is < \$5k & we need 100%accuracy.\n\n<b>bold</b>  two  spaces\tand tab\nThanks";

        $out = SubmissionFields::normalize([['id' => 'message', 'label' => 'Message', 'value' => $message]]);

        self::assertSame($message, $out[0]['value']);
    }

    /**
     * Windows and old Mac line endings read the same as Unix ones, and the
     * control characters no text field can hold are removed; tab and line
     * feed are kept, and so is the spacing inside the value. Only surrounding
     * whitespace is trimmed.
     */
    public function testLineEndingsAreNormalizedAndControlCharactersRemoved(): void
    {
        $out = SubmissionFields::normalize([
            'crlf'     => "  line one\r\nline two\rline three  ",
            'controls' => "a\x00b\x07c\x1Bd\x7Fe\tf",
            'list'     => ["one\r\ntwo", " \x0Bthree "],
        ]);

        self::assertSame("line one\nline two\nline three", $out[0]['value']);
        self::assertSame("abcde\tf", $out[1]['value']);
        self::assertSame(["one\ntwo", 'three'], $out[2]['value']);
    }

    /**
     * A byte sequence that is not UTF-8 is removed and the rest of the value
     * kept. sanitize_text_field() returned "" for the whole value.
     */
    public function testInvalidUtf8IsRemovedAndTheRestKept(): void
    {
        $out = SubmissionFields::normalize(['note' => "caf\xE9 & cr\xC3\xA8me <ok>"]);

        self::assertSame('caf & crème <ok>', $out[0]['value']);
    }

    /**
     * A stored row is cleaned again when it is read back, and that changes
     * nothing: the Submissions screen, CSV export and every redelivery read the
     * same text that was stored.
     */
    public function testAStoredValueReadsBackUnchanged(): void
    {
        $message = "Line one\n<i>&amp; %ac</i>\n  indented";

        $stored = (string) json_encode(SubmissionFields::normalize(['message' => $message]));
        $read   = SubmissionFields::fromStoredJson($stored)->toArray();

        self::assertSame($message, $read[0]['value']);
    }
}
