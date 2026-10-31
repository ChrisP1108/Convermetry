/** Execute both AJAX transports against the real tracker. No site or database. */
import { strict as assert } from 'node:assert';
import { bootTracker, FakeElement } from './dom-harness.mjs';

const endpoint = 'https://example.test/wp-admin/admin-ajax.php';
const failures = [];
let passed = 0;
async function test(name, fn) {
    try { await fn(); passed++; console.log(`  ✓ ${name}`); }
    catch (error) { failures.push(error); console.log(`  ✗ ${name}\n      ${error.stack}`); }
}
const attempts = (h) => h.trackedEvents().filter((e) => e.type === 'form_submit');
const success = (h) => h.trackedEvents().filter((e) => e.type === 'form_success');
const settle = () => new Promise((resolve) => setImmediate(resolve));
const submit = (h, form) => h.document.dispatchEvent({ type: 'submit', target: form });

function atomicForm(extra = {}) {
    return new FakeElement('form', { 'data-element_type': 'e-form', 'data-id': 'abc123', ...extra });
}
function atomicBody(postId = '42') {
    const body = new FormData();
    body.set('action', 'elementor_pro_atomic_forms_send_form');
    body.set('form_id', 'abc123');
    body.set('post_id', postId);
    body.set('form_name', 'Quote Request');
    return body;
}
async function sendAtomic(h, body, extra = {}) {
    await h.window.fetch(endpoint, { method: 'POST', body, ...extra });
    await settle();
}
function ninjaForm(id = '3', extra = {}) {
    const container = new FakeElement('div', { id: `nf-form-${id}-cont`, class: 'nf-form-cont' });
    return container.appendChild(new FakeElement('form', extra));
}
function ninjaBody(id = '3') {
    return { action: 'nf_ajax_submit', formData: JSON.stringify({ id, settings: { title: 'Contact' }, fields: {}, extra: {} }) };
}
function sendNinja(h, data, extra = {}) {
    const options = { type: 'POST', url: endpoint, data: new URLSearchParams(data).toString(), ...extra };
    for (const prefilter of h.prefilters) prefilter(options, { data }, {});
    return new URLSearchParams(options.data);
}
function assertLinked(event, values, key) {
    assert.ok(event, 'the request records an attempt');
    assert.equal(event.event_value, values.get('cvmtry_conversion_id'));
    assert.equal(event.session_id, values.get('cvmtry_session_id'));
    assert.equal(event.form_key, key);
}

console.log('\nAJAX attempts — request and event share one identity\n');

await test('Atomic without a native event records the server-scoped identity and token', async () => {
    const h = bootTracker({ forms: [atomicForm()] });
    const body = atomicBody();
    await sendAtomic(h, body);
    assert.equal(attempts(h).length, 1);
    assertLinked(attempts(h)[0], body, 'elementor_atomic:42:abc123');
    assert.equal(attempts(h)[0].element_label, 'Quote Request');
    assert.equal(success(h)[0].event_value, attempts(h)[0].event_value);
});

await test('Atomic native submit reserves a token without recording a second attempt or GET fields', async () => {
    const form = atomicForm(); // Provider markup may omit method=post.
    const h = bootTracker({ forms: [form] });
    submit(h, form);
    assert.equal(attempts(h).length, 0, 'the provider request supplies the actual form key');
    assert.equal(form.children.length, 0, 'no correlation fields added to a GET form');
    const body = atomicBody();
    await sendAtomic(h, body);
    assert.equal(attempts(h).length, 1);
    assertLinked(attempts(h)[0], body, 'elementor_atomic:42:abc123');
});

await test('Atomic submission ignores a seed token and subsequent submissions get fresh tokens', async () => {
    const form = atomicForm({ method: 'post', 'data-cvmtry-form-key': 'elementor_atomic:42:abc123' });
    const h = bootTracker({ forms: [form] });
    const seed = form.querySelector('input[name="cvmtry_conversion_id"]').value;
    const first = atomicBody();
    const second = atomicBody();
    await sendAtomic(h, first);
    await sendAtomic(h, second);
    assert.notEqual(first.get('cvmtry_conversion_id'), seed);
    assert.notEqual(first.get('cvmtry_conversion_id'), second.get('cvmtry_conversion_id'));
    assert.equal(attempts(h).length, 2);
});

await test('Atomic retry preserves token, session, attribution and only records one success', async () => {
    const h = bootTracker();
    const body = atomicBody();
    await sendAtomic(h, body);
    const original = [...body.entries()];
    await sendAtomic(h, body);
    assert.deepEqual([...body.entries()], original);
    assert.equal(attempts(h).length, 1);
    assert.equal(success(h).length, 1);
});

await test('Atomic network failure can retry without creating another attempt', async () => {
    let fail = true;
    const h = bootTracker({ fetchResponse: (url, init) => {
        if (init.body instanceof FormData && fail) { fail = false; throw new Error('network failed'); }
        return { ok: true, status: 200, json: async () => ({}), clone: () => ({ json: async () => ({ success: true }) }) };
    } });
    const body = atomicBody();
    await assert.rejects(sendAtomic(h, body), /network failed/);
    const token = body.get('cvmtry_conversion_id');
    const originalSession = body.get('cvmtry_session_id');
    h.window.localStorage.setItem('cvmtry_session', 'expired.0');
    h.window.Convermetry.track('another-session');
    await sendAtomic(h, body);
    assert.equal(body.get('cvmtry_conversion_id'), token);
    assert.equal(attempts(h).length, 1);
    assert.equal(success(h).length, 1);
    assert.equal(success(h)[0].session_id, originalSession, 'a late confirmation belongs to its original attempt');
    assert.equal(body.get('cvmtry_session_id'), originalSession);
});

await test('a native submit pending beyond session expiry starts its request in the new session', async () => {
    const form = atomicForm();
    const h = bootTracker({ forms: [form] });
    submit(h, form);
    const oldSession = h.window.localStorage.getItem('cvmtry_session').split('.')[0];
    h.window.localStorage.setItem('cvmtry_session', oldSession + '.0');
    const body = atomicBody();
    await sendAtomic(h, body);
    assertLinked(attempts(h)[0], body, 'elementor_atomic:42:abc123');
    assert.notEqual(attempts(h)[0].session_id, oldSession);
});

await test('a new Atomic native submit resets even a reused payload', async () => {
    const form = atomicForm();
    const h = bootTracker({ forms: [form] });
    const body = atomicBody();
    submit(h, form);
    await sendAtomic(h, body);
    const first = body.get('cvmtry_conversion_id');
    submit(h, form);
    await sendAtomic(h, body);
    assert.notEqual(body.get('cvmtry_conversion_id'), first);
    assert.equal(attempts(h).length, 2);
    assert.equal(success(h).length, 2);
});

await test('Atomic form keys distinguish documents and preserve the server fallback without post_id', async () => {
    const h = bootTracker();
    for (const postId of ['42', '43', '']) await sendAtomic(h, atomicBody(postId));
    assert.deepEqual(attempts(h).map((e) => e.form_key), ['elementor_atomic:42:abc123', 'elementor_atomic:43:abc123', 'elementor_atomic:abc123']);
});

await test('Atomic GET and external requests create neither correlation fields nor attempts', async () => {
    const h = bootTracker();
    const get = atomicBody();
    const external = atomicBody();
    await sendAtomic(h, get, { method: 'GET' });
    await h.window.fetch('https://external.example/collect', { method: 'POST', body: external });
    assert.equal(get.has('cvmtry_conversion_id'), false);
    assert.equal(external.has('cvmtry_conversion_id'), false);
    assert.equal(attempts(h).length, 0);
});

await test('Atomic opt-out and disabled attempt tracking are honored', async () => {
    const ignored = bootTracker({ forms: [atomicForm({ 'data-cvmtry-ignore': '' })] });
    const body = atomicBody();
    await sendAtomic(ignored, body);
    assert.equal(body.has('cvmtry_conversion_id'), false);
    assert.equal(attempts(ignored).length, 0);
    const disabled = bootTracker({ config: { events: { form_success: true } } });
    const allowed = atomicBody();
    await sendAtomic(disabled, allowed);
    assert.equal(attempts(disabled).length, 0);
    assert.equal(success(disabled)[0].event_value, allowed.get('cvmtry_conversion_id'));
});

await test('Ninja without a native event records the attempt with its request token', () => {
    const h = bootTracker({ jquery: true });
    const values = sendNinja(h, ninjaBody());
    assert.equal(attempts(h).length, 1);
    assertLinked(attempts(h)[0], values, 'ninjaforms:3');
    assert.equal(attempts(h)[0].element_label, 'Contact');
});

await test('Ninja native submits and AJAX prefilters produce one attempt per submission', () => {
    const form = ninjaForm();
    const h = bootTracker({ forms: [form], jquery: true });
    submit(h, form);
    assert.equal(attempts(h).length, 0);
    const first = sendNinja(h, ninjaBody());
    submit(h, form);
    const second = sendNinja(h, ninjaBody());
    assert.equal(attempts(h).length, 2);
    assert.notEqual(first.get('cvmtry_conversion_id'), second.get('cvmtry_conversion_id'));
    assertLinked(attempts(h)[0], first, 'ninjaforms:3');
    assertLinked(attempts(h)[1], second, 'ninjaforms:3');
    assert.equal(form.children.length, 0);
});

await test('Ninja retries preserve the token, but a new native submit on the same payload resets it', () => {
    const form = ninjaForm();
    const h = bootTracker({ forms: [form], jquery: true });
    const data = ninjaBody();
    submit(h, form);
    const first = sendNinja(h, data);
    const retry = sendNinja(h, data);
    assert.deepEqual([...retry], [...first]);
    assert.equal(attempts(h).length, 1);
    submit(h, form);
    const next = sendNinja(h, data);
    assert.notEqual(next.get('cvmtry_conversion_id'), first.get('cvmtry_conversion_id'));
    assert.equal(attempts(h).length, 2);
});

await test('Ninja repeated embeds normalize the form key as the server does', () => {
    const form = ninjaForm('3_2');
    const h = bootTracker({ forms: [form], jquery: true });
    submit(h, form);
    const values = sendNinja(h, ninjaBody('3_2'));
    assertLinked(attempts(h)[0], values, 'ninjaforms:3');
});

await test('Ninja form-level opt-out is honored even when its wrapper is not opted out', () => {
    const form = ninjaForm('3', { 'data-cvmtry-ignore': '' });
    const h = bootTracker({ forms: [form], jquery: true });
    const values = sendNinja(h, ninjaBody());
    assert.equal(values.has('cvmtry_conversion_id'), false);
    assert.equal(attempts(h).length, 0);
});

await test('re-running a Ninja prefilter on prepared options does not duplicate correlation fields', () => {
    const h = bootTracker({ jquery: true });
    const data = ninjaBody();
    const options = { type: 'POST', url: endpoint, data: new URLSearchParams(data).toString() };
    for (let i = 0; i < 2; i++) h.prefilters[0](options, { data }, {});
    assert.equal(new URLSearchParams(options.data).getAll('cvmtry_conversion_id').length, 1);
    assert.equal(attempts(h).length, 1);
});

await test('Ninja disabled attempt tracking still correlates its server submission', () => {
    const h = bootTracker({ jquery: true, config: { events: { pageview: true } } });
    const values = sendNinja(h, ninjaBody());
    assert.match(values.get('cvmtry_conversion_id'), /^c[a-f0-9]{16}$/);
    assert.equal(attempts(h).length, 0);
});

console.log(`\n  ${passed} passed, ${failures.length} failed\n`);
if (failures.length) process.exitCode = 1;
