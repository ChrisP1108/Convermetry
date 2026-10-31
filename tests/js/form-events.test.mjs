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
    return new FakeElement('form', { method: 'post', 'data-cvmtry-form-key': 'mysite:callback', ...attributes });
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

test('Ninja Forms views and starts are keyed from its wrapper', () => {
    // Ninja Forms 3 puts the id on <div id="nf-form-3-cont">, not on the <form>.
    const wrapper = new FakeElement('div', { id: 'nf-form-3-cont', class: 'nf-form-cont' });
    const form = wrapper.appendChild(new FakeElement('form'));
    const harness = bootTracker({ forms: [form] });

    harness.document.dispatchEvent({ type: 'input', target: { form } });
    harness.runTimers();

    const start = harness.trackedEvents().find((event) => event.type === 'form_start');
    assert.equal(start.form_key, 'ninjaforms:3');
});

test('an attempt is labelled like the form\'s view and start, honoring data-cvmtry-form-name', () => {
    const form = declaredForm({ name: 'contact-form', 'data-cvmtry-form-name': 'Footer Feedback' });
    const harness = bootTracker({ forms: [form] });

    harness.document.dispatchEvent({ type: 'submit', target: form });

    const submit = harness.trackedEvents().find((event) => event.type === 'form_submit');
    assert.equal(submit.element_label, 'Footer Feedback', 'the attempt must not fall back to the name attribute');
});

test('an attempt carries the conversion token that was put into the form', () => {
    const form = declaredForm();
    const harness = bootTracker({ forms: [form] });

    harness.document.dispatchEvent({ type: 'submit', target: form });

    const submit = harness.trackedEvents().find((event) => event.type === 'form_submit');
    const travelled = form.querySelector('input[name="cvmtry_conversion_id"]').value;
    assert.match(travelled, /^c[a-f0-9]{16}$/);
    assert.equal(submit.event_value, travelled, 'the server records the success under this same token');
});

test('a form that is not correlated sends an attempt without a token', () => {
    const form = new FakeElement('form', { method: 'get', 'data-cvmtry-form-key': 'mysite:search' });
    const harness = bootTracker({ forms: [form] });

    harness.document.dispatchEvent({ type: 'submit', target: form });

    const submit = harness.trackedEvents().find((event) => event.type === 'form_submit');
    assert.equal(submit.event_value, undefined);
    assert.equal(form.querySelector('input[name="cvmtry_conversion_id"]'), null);
});

test('a data-cvmtry-ignore form records no attempt at all', () => {
    const form = declaredForm({ 'data-cvmtry-ignore': '' });
    const harness = bootTracker({ forms: [form] });

    harness.document.dispatchEvent({ type: 'submit', target: form });

    assert.equal(harness.trackedEvents().filter((event) => event.type === 'form_submit').length, 0);
});

console.log(`\n  ${passed} passed, ${failures.length} failed\n`);
if (failures.length > 0) {
    process.exit(1);
}
