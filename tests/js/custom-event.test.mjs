/**
 * Convermetry.track(): which values travel with a custom event.
 *
 * Regression origin: the value went through Number() and was kept whenever the
 * result was finite, so null, "", " " and false became 0, true became 1 and [7]
 * became 7. A dynamic-value goal then recorded those instead of its own value:
 * a page that passed an empty price field recorded a 0.00 conversion.
 *
 * Executes the real tracker in the shimmed browser. No site or database.
 *
 *     composer test:js       (or: node tests/js/custom-event.test.mjs)
 */
import { strict as assert } from 'node:assert';
import { bootTracker } from './dom-harness.mjs';

const failures = [];
let passed = 0;
function test(name, fn) {
    try { fn(); passed++; console.log(`  ✓ ${name}`); }
    catch (error) { failures.push(error); console.log(`  ✗ ${name}\n      ${error.stack}`); }
}

/** The custom event one call produced, as it left for the server. */
function sent(...args) {
    const h = bootTracker();
    h.window.Convermetry.track(...args);
    const events = h.trackedEvents().filter((event) => event.type === 'custom_event');
    assert.equal(events.length, 1, 'one call, one custom event');
    return events[0];
}

console.log('\nConvermetry.track() — custom event values\n');

test('a value that is not a number is not sent, so the goal keeps its own', () => {
    const notNumbers = {
        'null': null,
        'an empty string': '',
        'a blank string': ' ',
        'true': true,
        'false': false,
        'an array': [7],
        'an empty array': [],
        'an object': { amount: 5 },
        'text': 'abc',
        'a number followed by text': '12abc',
        'a hexadecimal string': '0x10',
        'NaN': NaN,
        'Infinity': Infinity,
        '-Infinity': -Infinity,
        'a string too large for a number': '1e999',
        'the string "Infinity"': 'Infinity',
        'undefined': undefined,
    };
    for (const [label, value] of Object.entries(notNumbers)) {
        const event = sent('booking', { value });
        assert.equal(event.name, 'booking', label);
        assert.ok(!('value' in event), `${label} must not be sent as a value (got ${JSON.stringify(event.value)})`);
    }
});

test('a finite number, or a string that is one once trimmed, is sent as a string', () => {
    const numbers = [
        [0, '0'],
        [250, '250'],
        ['1e3', '1000'],
        ['  42.5  ', '42.5'],
        [12.345, '12.345'],
        ['.5', '0.5'],
        ['+7', '7'],
    ];
    for (const [value, expected] of numbers) {
        assert.equal(sent('booking', { value }).value, expected, `${JSON.stringify(value)}`);
    }
});

test('a negative value is still sent; the server is what ignores it', () => {
    assert.equal(sent('booking', { value: -5 }).value, '-5');
    assert.equal(sent('booking', { value: ' -5 ' }).value, '-5');
});

test('a call with no data, or null data, sends the name alone', () => {
    assert.deepEqual([sent('booking'), sent('booking', null)].map((event) => 'value' in event), [false, false]);
});

console.log(`\n  ${passed} passed, ${failures.length} failed\n`);
if (failures.length > 0) {
    process.exit(1);
}
