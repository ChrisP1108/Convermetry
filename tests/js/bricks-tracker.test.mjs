/**
 * The Bricks Builder half of the tracker, EXECUTED rather than read.
 *
 * The PHP suite pins this contract against the tracker's source, which is the
 * best a PHP unit suite with no browser can do. This file goes one step
 * further: it boots the real tracker in a shimmed browser, dispatches the
 * CustomEvents Bricks documents, and checks what actually happens to the
 * FormData and to the conversion token.
 *
 * What it still cannot prove: that Bricks itself dispatches these events with
 * these payloads, or that mutating event.detail.formData reaches the request
 * Bricks sends. Those are properties of Bricks, not of this code, and remain a
 * live-site check.
 *
 *     composer test:js       (or: node tests/js/bricks-tracker.test.mjs)
 */

import { strict as assert } from 'node:assert';
import { bootTracker, FakeElement } from './dom-harness.mjs';

let passed = 0;
const failures = [];

function test(name, fn) {
    try {
        fn();
        passed++;
        console.log(`  ✓ ${name}`);
    } catch (error) {
        failures.push({ name, error });
        console.log(`  ✗ ${name}`);
        console.log(`      ${error.message.split('\n')[0]}`);
    }
}

/** A Bricks form as the server renders it: brxe id plus the tracking key. */
function bricksForm(elementId, attributes = {}) {
    return new FakeElement('form', {
        id: `brxe-${elementId}`,
        class: 'brxe-form',
        method: 'post',
        'data-cvm-form-key': `bricks:${elementId}`,
        ...attributes,
    });
}

/** The conversion token that travelled in one submit event's FormData. */
function tokenIn(formData) {
    return formData.get('cvm_conversion_id');
}

console.log('\nBricks tracker — executed against the real tracker source\n');

// ---------------------------------------------------------------- transport

test('the prepared FormData carries all three correlation values', () => {
    const form = bricksForm('ab12cd');
    const harness = bootTracker({ forms: [form] });
    const formData = new FormData();

    harness.dispatch('bricks/form/submit', { elementId: 'ab12cd', formData });

    assert.match(
        tokenIn(formData),
        /^c[a-f0-9]{16}$/,
        'a conversion token must travel with the request'
    );
    assert.match(formData.get('cvm_session_id'), /^[a-f0-9]{16,64}$/);
    assert.ok(formData.get('cvm_context'), 'the attribution snapshot must travel');
    assert.doesNotThrow(() => JSON.parse(formData.get('cvm_context')));
});

test('nothing is written into the form-field-<id> namespace', () => {
    const form = bricksForm('ab12cd');
    const harness = bootTracker({ forms: [form] });
    const formData = new FormData();
    formData.set('form-field-15bc57', 'Ada');

    harness.dispatch('bricks/form/submit', { elementId: 'ab12cd', formData });

    const keys = [...formData.keys()];
    assert.deepEqual(
        keys.filter((key) => key.startsWith('form-field-')),
        ['form-field-15bc57'],
        "the tracker must not add to Bricks' submitted fields"
    );
    assert.ok(keys.includes('cvm_conversion_id'), 'the values travel top level instead');
});

test('a data-cvm-ignore form is left completely alone', () => {
    const form = bricksForm('ab12cd', { 'data-cvm-ignore': '' });
    const harness = bootTracker({ forms: [form] });
    const formData = new FormData();

    harness.dispatch('bricks/form/submit', { elementId: 'ab12cd', formData });

    assert.deepEqual([...formData.keys()], [], 'an opted-out form sends nothing of ours');
});

test('a detail without an amendable body changes nothing and does not throw', () => {
    const harness = bootTracker({ forms: [bricksForm('ab12cd')] });

    assert.doesNotThrow(() => {
        harness.dispatch('bricks/form/submit', { elementId: 'ab12cd' });
        harness.dispatch('bricks/form/submit', { elementId: 'ab12cd', formData: {} });
        harness.dispatch('bricks/form/submit', {});
        harness.dispatch('bricks/form/submit', null);
    });
});

test('a form the tracker cannot find still gets correlated', () => {
    // An id the DOM does not expose — a form rendered before the attribute
    // existed, or replaced by Bricks' own success markup.
    const harness = bootTracker({ forms: [] });
    const formData = new FormData();

    harness.dispatch('bricks/form/submit', { elementId: 'zz99zz', formData });

    assert.match(tokenIn(formData), /^c[a-f0-9]{16}$/);
});

// ------------------------------------------------------------------- tokens

test('the token the native submit listener minted is reused, not replaced', () => {
    const form = bricksForm('ab12cd');
    const harness = bootTracker({ forms: [form] });

    // The native submit event fires first, in capture phase, and refreshes the
    // form's hidden correlation fields for this attempt.
    harness.document.dispatchEvent({ type: 'submit', target: form });
    const hidden = form.querySelector('input[name="cvm_conversion_id"]');
    assert.ok(hidden, 'a Bricks form is recognised by its rendered tracking key');

    const formData = new FormData();
    harness.dispatch('bricks/form/submit', { elementId: 'ab12cd', formData });

    assert.equal(
        tokenIn(formData),
        hidden.value,
        'ONE token per attempt: the AJAX body and the hidden field must agree'
    );
});

test('a second attempt gets a new token rather than re-reporting the first', () => {
    const form = bricksForm('ab12cd');
    const harness = bootTracker({ forms: [form] });

    harness.document.dispatchEvent({ type: 'submit', target: form });
    const first = new FormData();
    harness.dispatch('bricks/form/submit', { elementId: 'ab12cd', formData: first });

    harness.document.dispatchEvent({ type: 'submit', target: form });
    const second = new FormData();
    harness.dispatch('bricks/form/submit', { elementId: 'ab12cd', formData: second });

    assert.notEqual(tokenIn(first), tokenIn(second), 'two attempts are two conversions');
});

test('a submit event Bricks fires without a preceding native submit still mints once', () => {
    const harness = bootTracker({ forms: [bricksForm('ab12cd')] });

    const first = new FormData();
    const second = new FormData();
    harness.dispatch('bricks/form/submit', { elementId: 'ab12cd', formData: first });
    harness.dispatch('bricks/form/submit', { elementId: 'ab12cd', formData: second });

    assert.notEqual(tokenIn(first), tokenIn(second));
});

// ---------------------------------------------------------------- outcomes

test('success reports the conversion under the token the server received', () => {
    const form = bricksForm('ab12cd');
    const harness = bootTracker({ forms: [form] });
    const formData = new FormData();

    harness.dispatch('bricks/form/submit', { elementId: 'ab12cd', formData });
    harness.dispatch('bricks/form/success', { elementId: 'ab12cd', formData, res: { success: true } });

    const conversions = harness.trackedEvents().filter((event) => event.type === 'form_success');
    assert.equal(conversions.length, 1, 'exactly one conversion');
    assert.equal(
        conversions[0].event_value,
        tokenIn(formData),
        'the browser and the server must report the SAME conversion id'
    );
});

test('preparing a request is an attempt, not a conversion', () => {
    const harness = bootTracker({ forms: [bricksForm('ab12cd')] });

    harness.dispatch('bricks/form/submit', { elementId: 'ab12cd', formData: new FormData() });

    assert.equal(
        harness.trackedEvents().filter((event) => event.type === 'form_success').length,
        0
    );
});

test('a repeated success reports nothing rather than a second conversion', () => {
    const form = bricksForm('ab12cd');
    const harness = bootTracker({ forms: [form] });
    const formData = new FormData();

    harness.dispatch('bricks/form/submit', { elementId: 'ab12cd', formData });
    harness.dispatch('bricks/form/success', { elementId: 'ab12cd', formData });
    harness.dispatch('bricks/form/success', { elementId: 'ab12cd', formData });

    const conversions = harness.trackedEvents().filter((event) => event.type === 'form_success');
    assert.equal(conversions.length, 1, 'one submission is one conversion event');
    assert.equal(conversions[0].event_value, tokenIn(formData));
});

test('a success with no attempt of ours in flight invents no conversion', () => {
    const harness = bootTracker({ forms: [bricksForm('ab12cd')] });

    harness.dispatch('bricks/form/success', { elementId: 'ab12cd', formData: new FormData() });
    harness.runTimers();

    assert.equal(
        harness.trackedEvents().filter((event) => event.type === 'form_success').length,
        0,
        'the server already recorded this conversion; a second one would double count'
    );
});

test('an error records a form_error, no conversion, and nothing from the response', () => {
    const form = bricksForm('ab12cd');
    const harness = bootTracker({ forms: [form] });
    const formData = new FormData();
    formData.set('form-field-15bc57', 'ada@example.test');

    harness.dispatch('bricks/form/submit', { elementId: 'ab12cd', formData });
    harness.dispatch('bricks/form/error', {
        elementId: 'ab12cd',
        formData,
        res: { message: 'Submission failed for ada@example.test', code: 500 },
    });
    harness.runTimers();

    const events = harness.trackedEvents();
    const errors = events.filter((event) => event.type === 'form_error');

    assert.equal(errors.length, 1);
    assert.equal(errors[0].form_key, 'bricks:ab12cd', 'the authoritative key is reported');
    assert.equal(
        events.filter((event) => event.type === 'form_success').length,
        0,
        'a rejected submission is not a conversion'
    );
    assert.ok(
        !JSON.stringify(events).includes('ada@example.test'),
        "Bricks' response body must never reach an analytics event"
    );
});

test('a failed attempt drops its token, so a later success cannot claim it', () => {
    const form = bricksForm('ab12cd');
    const harness = bootTracker({ forms: [form] });
    const first = new FormData();

    harness.dispatch('bricks/form/submit', { elementId: 'ab12cd', formData: first });
    harness.dispatch('bricks/form/error', { elementId: 'ab12cd', formData: first });

    const second = new FormData();
    harness.dispatch('bricks/form/submit', { elementId: 'ab12cd', formData: second });
    harness.dispatch('bricks/form/success', { elementId: 'ab12cd', formData: second });

    const conversions = harness.trackedEvents().filter((event) => event.type === 'form_success');
    assert.equal(conversions.length, 1);
    assert.equal(conversions[0].event_value, tokenIn(second), 'the failed attempt is not converted');
});

// ------------------------------------------------------------------- gates

test('error tracking respects the site owner\'s switch', () => {
    const harness = bootTracker({
        forms: [bricksForm('ab12cd')],
        config: {
            events: {
                pageview: true,
                click: true,
                form_submit: true,
                form_success: true,
                form_view: false,
                form_start: false,
                form_error: false,
                hover: false,
                scroll_depth: false,
                custom_event: true,
            },
        },
    });

    harness.dispatch('bricks/form/error', { elementId: 'ab12cd', formData: new FormData() });
    harness.runTimers();

    assert.equal(
        harness.trackedEvents().filter((event) => event.type === 'form_error').length,
        0
    );
});

test('an opted-out form reports neither conversions nor errors', () => {
    const form = bricksForm('ab12cd', { 'data-cvm-ignore': '' });
    const harness = bootTracker({ forms: [form] });

    harness.dispatch('bricks/form/submit', { elementId: 'ab12cd', formData: new FormData() });
    harness.dispatch('bricks/form/success', { elementId: 'ab12cd', formData: new FormData() });
    harness.dispatch('bricks/form/error', { elementId: 'ab12cd', formData: new FormData() });
    harness.runTimers();

    const events = harness.trackedEvents();
    assert.equal(events.filter((event) => event.type === 'form_success').length, 0);
    assert.equal(events.filter((event) => event.type === 'form_error').length, 0);
});

test('a malformed element id never reaches a DOM selector', () => {
    const harness = bootTracker({ forms: [bricksForm('ab12cd')] });

    assert.doesNotThrow(() => {
        for (const id of ['"], [x="', '../../etc', '', null, {}, 'a'.repeat(200)]) {
            harness.dispatch('bricks/form/submit', { elementId: id, formData: new FormData() });
            harness.dispatch('bricks/form/success', { elementId: id, formData: new FormData() });
        }
    });
});

console.log(`\n${passed} passed, ${failures.length} failed\n`);

if (failures.length > 0) {
    for (const { name, error } of failures) {
        console.error(`FAILED: ${name}\n${error.stack}\n`);
    }
    process.exit(1);
}
