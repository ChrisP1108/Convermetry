/**
 * The form lifecycle events the Forms screen reports on, EXECUTED against the
 * real tracker source.
 *
 * The engagement report groups form_view, form_start, form_submit and
 * form_success by form_key and ignores rows without one, so an event that
 * leaves it out is silently missing from its column. form_submit did, which
 * kept the Attempts column at zero.
 *
 *     composer test:js       (or: node tests/js/form-events.test.mjs)
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

/** A hand-built form declared for the custom-form API. */
function declaredForm(attributes = {}) {
    return new FakeElement('form', { method: 'post', 'data-cvm-form-key': 'mysite:callback', ...attributes });
}

console.log('\nForm lifecycle events — executed against the real tracker source\n');

test('a submit attempt carries the form key the Forms screen counts by', () => {
    const form = declaredForm();
    const harness = bootTracker({ forms: [form] });

    harness.document.dispatchEvent({ type: 'submit', target: form });

    const submits = harness.trackedEvents().filter((event) => event.type === 'form_submit');
    assert.equal(submits.length, 1, 'one attempt is recorded');
    assert.equal(submits[0].form_key, 'mysite:callback');
});

test('a Formidable form is keyed the way the server records it', () => {
    // Formidable 6 markup: a hyphenated class and a hidden form_id input.
    const form = new FakeElement('form', { method: 'post', id: 'form_contact-form', class: 'frm-show-form ' });
    const formId = form.appendChild(new FakeElement('input', { type: 'hidden', name: 'form_id' }));
    formId.value = '7';
    const harness = bootTracker({ forms: [form] });

    harness.document.dispatchEvent({ type: 'submit', target: form });

    const submit = harness.trackedEvents().find((event) => event.type === 'form_submit');
    assert.equal(submit.form_key, 'formidable:7', 'the server records Formidable as formidable:<form id>');
});

test('a Ninja Forms form is keyed from its wrapper', () => {
    // Ninja Forms 3 puts the id on <div id="nf-form-3-cont">, not on the <form>.
    const wrapper = new FakeElement('div', { id: 'nf-form-3-cont', class: 'nf-form-cont' });
    const form = wrapper.appendChild(new FakeElement('form'));
    const harness = bootTracker({ forms: [form] });

    harness.document.dispatchEvent({ type: 'submit', target: form });

    const submit = harness.trackedEvents().find((event) => event.type === 'form_submit');
    assert.equal(submit.form_key, 'ninjaforms:3');
});

test('a data-cvm-ignore form records no attempt at all', () => {
    const form = declaredForm({ 'data-cvm-ignore': '' });
    const harness = bootTracker({ forms: [form] });

    harness.document.dispatchEvent({ type: 'submit', target: form });

    assert.equal(harness.trackedEvents().filter((event) => event.type === 'form_submit').length, 0);
});

console.log(`\n  ${passed} passed, ${failures.length} failed\n`);
if (failures.length > 0) {
    process.exit(1);
}
