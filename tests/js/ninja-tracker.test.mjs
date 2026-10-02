/**
 * The Ninja Forms correlation hook, EXECUTED against the real tracker source.
 *
 * Ninja Forms posts one formData JSON document to admin-ajax instead of
 * serializing its <form>, so the tracker adds the three correlation values to
 * that jQuery request through a prefilter. This file boots the tracker with a
 * stand-in jQuery, runs the prefilter it registered against request options
 * shaped like the ones Ninja Forms' submit controller builds, and checks what
 * would be sent.
 *
 * What it cannot prove: that Ninja Forms still submits through jQuery.ajax with
 * this action name. That is a property of Ninja Forms, and remains a live-site
 * check.
 *
 *     composer test:js       (or: node tests/js/ninja-tracker.test.mjs)
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

/** Request options as jQuery hands them to a prefilter: data already a string. */
function ninjaRequest({ formId = '3', action = 'nf_ajax_submit', type = 'POST', url = 'https://example.test/wp-admin/admin-ajax.php' } = {}) {
    const data = {
        action,
        security: 'abc123',
        formData: JSON.stringify({ id: formId, fields: { 1: { id: 1, value: 'Ada' } }, settings: {}, extra: {} }),
    };
    const options = {
        type,
        url,
        data: new URLSearchParams(data).toString(),
    };
    return { options, originalOptions: { type, url, data } };
}

/** Runs every prefilter the tracker registered, as jQuery.ajax would. */
function runPrefilters(harness, request) {
    for (const prefilter of harness.prefilters) {
        prefilter(request.options, request.originalOptions, {});
    }
    return new URLSearchParams(request.options.data);
}

console.log('\nNinja Forms tracker — executed against the real tracker source\n');

test('the tracker registers a jQuery prefilter when jQuery is present', () => {
    const harness = bootTracker({ jquery: true });
    assert.equal(harness.prefilters.length, 1);
});

test('a Ninja Forms submission carries all three correlation values as top-level fields', () => {
    const harness = bootTracker({ jquery: true });
    const sent = runPrefilters(harness, ninjaRequest());

    assert.match(sent.get('cvmtry_conversion_id'), /^c[a-f0-9]{16}$/);
    assert.match(sent.get('cvmtry_session_id'), /^[a-f0-9]{16,64}$/);
    assert.doesNotThrow(() => JSON.parse(sent.get('cvmtry_context')));
    assert.equal(sent.get('action'), 'nf_ajax_submit', 'Ninja Forms\' own fields are untouched');
    assert.equal(sent.get('security'), 'abc123');
});

test('the correlation values stay out of the formData document Ninja Forms stores', () => {
    const harness = bootTracker({ jquery: true });
    const sent = runPrefilters(harness, ninjaRequest());
    const formData = JSON.parse(sent.get('formData'));

    assert.deepEqual(formData.extra, {});
    assert.ok(!JSON.stringify(formData).includes('cvmtry_'));
});

test('each attempt gets a fresh conversion token and the same session', () => {
    const harness = bootTracker({ jquery: true });
    const first = runPrefilters(harness, ninjaRequest());
    const second = runPrefilters(harness, ninjaRequest());

    assert.notEqual(first.get('cvmtry_conversion_id'), second.get('cvmtry_conversion_id'));
    assert.equal(first.get('cvmtry_session_id'), second.get('cvmtry_session_id'));
});

test('any other admin-ajax request is left alone', () => {
    const harness = bootTracker({ jquery: true });
    const request = ninjaRequest({ action: 'heartbeat' });
    const before = request.options.data;
    runPrefilters(harness, request);

    assert.equal(request.options.data, before);
});

test('a request to another origin is left alone', () => {
    const harness = bootTracker({ jquery: true });
    const request = ninjaRequest({ url: 'https://elsewhere.example/wp-admin/admin-ajax.php' });
    const before = request.options.data;
    runPrefilters(harness, request);

    assert.equal(request.options.data, before);
});

test('a GET request is left alone, so nothing lands in a URL', () => {
    const harness = bootTracker({ jquery: true });
    const request = ninjaRequest({ type: 'GET' });
    const before = request.options.data;
    runPrefilters(harness, request);

    assert.equal(request.options.data, before);
});

test('a data-cvmtry-ignore form container is left alone', () => {
    const container = new FakeElement('div', { id: 'nf-form-3-cont', 'data-cvmtry-ignore': '' });
    const harness = bootTracker({ forms: [container], jquery: true });
    const request = ninjaRequest({ formId: '3' });
    const before = request.options.data;
    runPrefilters(harness, request);

    assert.equal(request.options.data, before);
});

test('unparseable formData still correlates and does not throw', () => {
    const harness = bootTracker({ jquery: true });
    const request = ninjaRequest();
    request.originalOptions.data.formData = '{not json';

    const sent = runPrefilters(harness, request);
    assert.match(sent.get('cvmtry_conversion_id'), /^c[a-f0-9]{16}$/);
});

test('without jQuery nothing is registered and the tracker still boots', () => {
    const harness = bootTracker();
    assert.equal(harness.prefilters.length, 0);
});

console.log(`\n  ${passed} passed, ${failures.length} failed\n`);
if (failures.length > 0) {
    process.exit(1);
}
