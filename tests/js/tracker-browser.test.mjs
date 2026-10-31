/**
 * The tracker in a REAL browser, against forms whose controls shadow the form's
 * own properties.
 *
 * A form exposes every named control as a property of itself, and the control
 * wins: <input name="method"> makes form.method an element, and so on for
 * "action" and "id". Three defects lived there, none of which the shimmed
 * harness can reproduce because FakeElement has no named-property behaviour:
 *
 *  - "method" made form.method an element and the tracker threw while
 *    initializing (it is deferred, so seeding runs synchronously at start-up):
 *    no events, no window.Convermetry, no correlation for any later form;
 *  - "action" made form.action an element whose string form resolves as a
 *    same-origin path, so an external POST form received the session id,
 *    conversion token and attribution snapshot;
 *  - "id" made form.id an element, and labelling the form threw — losing
 *    form_view, form_start and form_submit.
 *
 * It also pins the browser half of two report fixes: every lifecycle event of
 * a form carries the same label, and an attempt carries the conversion token
 * the server will record its success under.
 *
 * Every request is intercepted by local listeners (see browser-harness.mjs);
 * nothing reaches a real site or database.
 *
 *     composer test:js       (or: node tests/js/tracker-browser.test.mjs)
 */

import { strict as assert } from 'node:assert';
import { findChrome, launchBrowser, openPage, startSite, waitFor } from './browser-harness.mjs';

const ALL_EVENTS = {
    pageview: true,
    click: true,
    form_submit: true,
    form_success: true,
    form_view: true,
    form_start: true,
    form_error: true,
    hover: true,
    scroll_depth: true,
    custom_event: true,
};

const chrome = findChrome();
if (!chrome) {
    console.log('\nTracker in a real browser — SKIPPED: no Chrome found (set CVMTRY_CHROME).\n');
    process.exit(process.env.CVMTRY_REQUIRE_BROWSER === '1' ? 1 : 0);
}

const site = await startSite();
const browser = await launchBrowser(chrome);

let passed = 0;
let fixtures = 0;
const failures = [];

async function test(name, fn) {
    try {
        await fn();
        passed++;
        console.log(`  ✓ ${name}`);
    } catch (error) {
        failures.push({ name, error });
        console.log(`  ✗ ${name}`);
        console.log(`      ${String(error.message).split('\n')[0]}`);
    }
}

/**
 * Registers a page and returns its URL. The tracker loads as WordPress loads
 * it — deferred, in the footer — so start-up runs at readyState 'interactive'
 * and seeds forms synchronously, which is the path the "method" defect broke.
 */
function fixture(body, { head = '' } = {}) {
    const path = `/fixture/${++fixtures}/`;
    const config = { endpoint: `${site.origin}/track`, events: ALL_EVENTS, respectDnt: false, flushIntervalMs: 100 };
    site.pages.set(path, `<!doctype html><html><head><meta charset="utf-8"><title>Fixture ${fixtures}</title>${head}
<script>window.ConvermetryConfig = ${JSON.stringify(config)};</script>
</head><body>${body}
<script src="${site.origin}/tracker.js" defer></script>
</body></html>`);
    return { path, url: site.origin + path, n: fixtures };
}

/** Opens a fixture, runs fn with the page, and always closes it. */
async function withPage(url, fn) {
    const page = await openPage(browser.cdp, url);
    try {
        await fn(page);
    } finally {
        await page.close();
    }
}

/** The correlation values a form currently holds, by field name. Read through
 *  the prototype: the hostile fixture shadows form.querySelectorAll too. */
function correlationFields(page, formId) {
    return page.evaluate((id) => {
        const out = {};
        const form = document.getElementById(id);
        for (const input of Element.prototype.querySelectorAll.call(form, 'input[name^="cvmtry_"]')) {
            out[input.getAttribute('name')] = input.value;
        }
        return out;
    }, formId);
}

/** Presses a button the way a visitor does: the browser picks the submitter. */
function press(page, buttonId) {
    return page.evaluate((id) => document.getElementById(id).click(), buttonId);
}

/** Types into a field, firing the input event a visitor's keystroke fires. */
function type(page, fieldId) {
    return page.evaluate((id) => {
        const field = document.getElementById(id);
        field.value = 'x';
        field.dispatchEvent(new Event('input', { bubbles: true }));
    }, fieldId);
}

/** The request a submission produced, once it arrives. */
function submission(port, path) {
    return waitFor(
        () => site.requests.find((request) => request.port === port && request.path === path),
        `a submission to :${port}${path}`
    );
}

/** The names of every cvmtry_ field a submission carried, in body or URL. */
function correlationNamesIn(request) {
    const params = new URLSearchParams(request.method === 'GET' ? request.query : request.body);
    return [...params.keys()].filter((name) => name.startsWith('cvmtry_'));
}

/** Events of one type delivered from one page, once at least `count` arrived. */
function delivered(pageUrl, type, count = 1) {
    return waitFor(() => {
        const events = site.trackedEvents(pageUrl).filter((event) => event.type === type);
        return events.length >= count ? events : null;
    }, `${count} ${type} event(s) from ${pageUrl}`);
}

console.log(`\nTracker in a real browser (${chrome.split('/').pop()}) — named controls shadowing form properties\n`);

// ───────────────────────────── 1. start-up: a control named "method" ─────────

await test('a recognized form with a control named "method" does not stop the tracker starting', async () => {
    const { url } = fixture(`
        <form id="first" method="post" action="/submit/first" data-cvmtry-form-key="custom:first">
            <input name="method" value="phone"><input name="email">
        </form>
        <form id="second" method="post" action="/submit/second" data-cvmtry-form-key="custom:second">
            <input name="email">
        </form>`);

    await withPage(url, async (page) => {
        assert.deepEqual(page.exceptions, [], 'the tracker must initialize without throwing');
        assert.equal(await page.evaluate(() => typeof (window.Convermetry && window.Convermetry.track)), 'function',
            'window.Convermetry must be defined — initialization ran to the end');
        assert.ok((await correlationFields(page, 'first')).cvmtry_conversion_id, 'its real method is post');
        assert.ok((await correlationFields(page, 'second')).cvmtry_conversion_id, 'the form after it is seeded');
        await delivered(url, 'pageview');
    });
});

await test('a form whose controls shadow every property the tracker reads is still handled', async () => {
    const { url } = fixture(`
        <form id="hostile" name="Hostile" method="post" action="/submit/hostile" data-cvmtry-form-key="custom:hostile">
            <input name="method" value="get"><input name="action" value="https://evil.example/"><input name="id" value="7">
            <input name="className"><input name="tagName"><input name="getAttribute"><input name="hasAttribute">
            <input name="querySelector"><input name="querySelectorAll"><input name="closest"><input name="matches">
            <input name="appendChild"><input name="email">
        </form>
        <form id="neighbor" method="post" action="/submit/neighbor" data-cvmtry-form-key="custom:neighbor">
            <input name="email">
        </form>`);

    await withPage(url, async (page) => {
        assert.deepEqual(page.exceptions, [], 'the tracker must initialize without throwing');
        assert.equal(await page.evaluate(() => typeof (window.Convermetry && window.Convermetry.track)), 'function',
            'window.Convermetry must be defined — initialization ran to the end');

        const hostile = await correlationFields(page, 'hostile');
        assert.match(hostile.cvmtry_conversion_id || '', /^c[a-f0-9]{16}$/,
            'its REAL method is post and its real action same-origin, so it is correlated');
        assert.ok(hostile.cvmtry_session_id && hostile.cvmtry_context);

        const neighbor = await correlationFields(page, 'neighbor');
        assert.match(neighbor.cvmtry_conversion_id || '', /^c[a-f0-9]{16}$/, 'the form after it is still seeded');

        await delivered(url, 'pageview');
    });
});

await test('a control named "method" cannot turn a GET form into a POST one', async () => {
    const { url } = fixture(`
        <form id="search" method="get" action="/search/" data-cvmtry-form-key="custom:search">
            <input name="method" value="post"><input name="q">
        </form>
        <form id="later" method="post" data-cvmtry-form-key="custom:later"><input name="email"></form>`);

    await withPage(url, async (page) => {
        assert.deepEqual(page.exceptions, []);
        assert.deepEqual(await correlationFields(page, 'search'), {}, 'a GET form never carries correlation data');
        assert.ok((await correlationFields(page, 'later')).cvmtry_conversion_id, 'the next form is unaffected');
    });
});

// ─────────────────────── 2. destination: a control named "action" ───────────

await test('an external POST form with a control named "action" never receives correlation data', async () => {
    const { url, n } = fixture(`
        <form id="ext" method="post" action="${site.externalOrigin}/collect/${fixtures + 1}" data-cvmtry-form-key="custom:ext">
            <input name="action" value="/same-origin-looking/"><input name="email" value="a@example.com">
            <button id="go" type="submit">Send</button>
        </form>`);

    await withPage(url, async (page) => {
        assert.deepEqual(await correlationFields(page, 'ext'), {}, 'nothing is seeded into an external form');

        await press(page, 'go');
        const sent = await submission(site.externalPort, `/collect/${n}`);
        assert.deepEqual(correlationNamesIn(sent), [], 'the serialized external submission carries no cvmtry_ field');
        assert.match(sent.body, /email=a%40example\.com/, "the visitor's own data is untouched");

        const [attempt] = await delivered(url, 'form_submit');
        assert.equal(attempt.event_value, undefined, 'an attempt whose token never travelled carries none');
    });
});

await test('a relative action is resolved by the browser and correlated, and the attempt carries the token that travelled', async () => {
    const { url, n } = fixture(`
        <form id="rel" method="post" action="/submit/${fixtures + 1}" data-cvmtry-form-key="custom:rel">
            <input name="action" value="${site.externalOrigin}/elsewhere"><input name="email" value="b@example.com">
            <button id="go" type="submit">Send</button>
        </form>`);

    await withPage(url, async (page) => {
        await press(page, 'go');
        const sent = await submission(site.sitePort, `/submit/${n}`);
        const params = new URLSearchParams(sent.body);
        assert.deepEqual(correlationNamesIn(sent).sort(), ['cvmtry_context', 'cvmtry_conversion_id', 'cvmtry_session_id']);

        const [attempt] = await delivered(url, 'form_submit');
        assert.equal(attempt.event_value, params.get('cvmtry_conversion_id'),
            'form_submit carries the token the server will record the success under');
        assert.equal(attempt.form_key, 'custom:rel');
    });
});

await test('an empty action posts back to the page itself, so it is correlated', async () => {
    const { url, path } = fixture(`
        <form id="empty" method="post" action="" data-cvmtry-form-key="custom:empty">
            <input name="action" value="${site.externalOrigin}/elsewhere"><button id="go" type="submit">Send</button>
        </form>`);

    await withPage(url, async (page) => {
        assert.ok((await correlationFields(page, 'empty')).cvmtry_conversion_id);
        await press(page, 'go');
        const sent = await waitFor(
            () => site.requests.find((r) => r.port === site.sitePort && r.path === path && r.method === 'POST'),
            'the POST back to the page'
        );
        assert.equal(correlationNamesIn(sent).length, 3);
    });
});

await test('a <base href> on another origin makes a relative action external', async () => {
    const { url, n } = fixture(`
        <form id="based" method="post" action="/submit/${fixtures + 1}" data-cvmtry-form-key="custom:based">
            <button id="go" type="submit">Send</button>
        </form>`, { head: `<base href="${site.externalOrigin}/">` });

    await withPage(url, async (page) => {
        assert.deepEqual(await correlationFields(page, 'based'), {});
        await press(page, 'go');
        const sent = await submission(site.externalPort, `/submit/${n}`);
        assert.deepEqual(correlationNamesIn(sent), [], 'resolved against the base URL, the destination is external');
    });
});

await test('a <base href> does not move an empty action, which still posts to the page', async () => {
    const { url } = fixture(`
        <form id="based-empty" method="post" data-cvmtry-form-key="custom:based-empty"><input name="x"></form>`,
    { head: `<base href="${site.externalOrigin}/">` });

    await withPage(url, async (page) => {
        assert.ok((await correlationFields(page, 'based-empty')).cvmtry_conversion_id);
    });
});

await test("a submit button's formaction to another origin takes the seeded fields back out", async () => {
    const { url, n } = fixture(`
        <form id="override" method="post" action="/submit/local" data-cvmtry-form-key="custom:override">
            <input name="email" value="c@example.com">
            <button id="away" type="submit" formaction="${site.externalOrigin}/collect/${fixtures + 1}">Elsewhere</button>
        </form>`);

    await withPage(url, async (page) => {
        assert.ok((await correlationFields(page, 'override')).cvmtry_conversion_id, 'seeded for its own destination');
        await press(page, 'away');
        const sent = await submission(site.externalPort, `/collect/${n}`);
        assert.deepEqual(correlationNamesIn(sent), [], 'the button sent it elsewhere, so nothing of ours went with it');
        await delivered(url, 'form_submit');
    });
});

await test("a submit button's formmethod=get keeps correlation data out of the query string", async () => {
    const { url, n } = fixture(`
        <form id="getter" method="post" action="/submit/${fixtures + 1}" data-cvmtry-form-key="custom:getter">
            <input name="q" value="term"><button id="get" type="submit" formmethod="get">Search</button>
        </form>`);

    await withPage(url, async (page) => {
        await press(page, 'get');
        const sent = await submission(site.sitePort, `/submit/${n}`);
        assert.equal(sent.method, 'GET');
        assert.deepEqual(correlationNamesIn(sent), []);
        assert.match(sent.query, /q=term/);
    });
});

await test('an ordinary submit button on the same kind of form still carries the fields', async () => {
    const { url, n } = fixture(`
        <form id="plain" method="post" action="/submit/${fixtures + 1}" data-cvmtry-form-key="custom:plain">
            <button id="send" type="submit">Send</button>
            <button id="away" type="submit" formaction="${site.externalOrigin}/never">Elsewhere</button>
        </form>`);

    await withPage(url, async (page) => {
        await press(page, 'send');
        const sent = await submission(site.sitePort, `/submit/${n}`);
        assert.equal(correlationNamesIn(sent).length, 3);
    });
});

await test('an action changed by script after seeding is checked again at submit', async () => {
    const { url, n } = fixture(`
        <form id="moved" method="post" action="/submit/start" data-cvmtry-form-key="custom:moved">
            <button id="go" type="submit">Send</button>
        </form>`);

    await withPage(url, async (page) => {
        assert.ok((await correlationFields(page, 'moved')).cvmtry_conversion_id);
        await page.evaluate((target) => document.getElementById('moved').setAttribute('action', target),
            `${site.externalOrigin}/collect/${n}`);
        await press(page, 'go');
        const sent = await submission(site.externalPort, `/collect/${n}`);
        assert.deepEqual(correlationNamesIn(sent), []);
    });
});

// ─────────────── 3. lifecycle events: a control named "id" (and labels) ──────

await test('form_view, form_start and form_submit all arrive, under one label, whatever the controls are called', async () => {
    const { url } = fixture(`
        <form method="post" data-cvmtry-form-key="custom:unnamed">
            <input name="id" value="5"><input id="unnamed-field" name="email"><button id="unnamed-go" type="submit">Go</button>
        </form>
        <form method="post" data-cvmtry-form-key="custom:footer" data-cvmtry-form-name="Footer Feedback">
            <input name="id" value="6"><input id="footer-field" name="email"><button id="footer-go" type="submit">Go</button>
        </form>
        <form id="contact-form" method="post" data-cvmtry-form-key="custom:contact">
            <input name="id" value="7"><input name="name" value="Ada"><input id="contact-field" name="email">
            <button id="contact-go" type="submit">Go</button>
        </form>
        <script>
            // An AJAX form plugin: the page stays, so all three forms can be submitted.
            document.addEventListener('submit', function (e) { e.preventDefault(); });
        </script>`);

    await withPage(url, async (page) => {
        for (const prefix of ['unnamed', 'footer', 'contact']) {
            await type(page, `${prefix}-field`);
            await press(page, `${prefix}-go`);
        }

        await delivered(url, 'form_submit', 3);
        await delivered(url, 'form_start', 3);
        await delivered(url, 'form_view', 3);
        assert.deepEqual(page.exceptions, []);

        const expected = { 'custom:unnamed': 'form', 'custom:footer': 'Footer Feedback', 'custom:contact': 'contact-form' };
        for (const [formKey, label] of Object.entries(expected)) {
            const events = site.trackedEvents(url).filter((event) => event.form_key === formKey);
            assert.deepEqual(
                events.map((event) => event.type).sort(),
                ['form_start', 'form_submit', 'form_view'],
                `${formKey}: every lifecycle event is recorded once`
            );
            for (const event of events) {
                assert.equal(event.element_label, label, `${formKey} ${event.type} is labelled "${label}"`);
            }
        }
        assert.ok(site.trackedEvents(url).every((event) => !String(event.element_label || '').includes('[object')),
            'no element description ever becomes a label');
    });
});

// Provider transports own their attempts: real native events, real serialized
// requests, and a local endpoint standing in for the provider's response.
for (const provider of ['atomic', 'ninja']) {
    await test(`${provider}: native and AJAX-only attempts, retries and new submissions stay linked`, async () => {
        const markup = provider === 'atomic'
            ? '<form id="provider-form" data-element_type="e-form" data-id="abc123"><input name="method"><input name="id"></form>'
            : '<div id="nf-form-3_2-cont" class="nf-form-cont"><form id="provider-form"><input name="method"><input name="id"></form></div>';
        const { url } = fixture(markup, { head: `<script>
            window.ninjaPrefilters = [];
            window.jQuery = function () { return { on: function () {} }; };
            window.jQuery.ajaxPrefilter = function (fn) { window.ninjaPrefilters.push(fn); };
        </script>` });
        await withPage(url, async (page) => {
            await page.evaluate(() => {
                document.getElementById('provider-form').addEventListener('submit', (event) => event.preventDefault());
            });

            const send = (native, retry) => page.evaluate(async (kind, fireNative, reusePayload) => {
                if (fireNative) document.getElementById('provider-form').requestSubmit();
                let payload = reusePayload && window.providerPayload;
                if (!payload && kind === 'atomic') {
                    payload = new FormData();
                    payload.set('action', 'elementor_pro_atomic_forms_send_form');
                    payload.set('post_id', '42');
                    payload.set('form_id', 'abc123');
                    payload.set('form_name', 'Quote Request');
                } else if (!payload) {
                    payload = { action: 'nf_ajax_submit', formData: JSON.stringify({ id: '3_2', settings: { title: 'Contact' }, fields: {}, extra: {} }) };
                }
                window.providerPayload = payload;
                let body = payload;
                if (kind === 'ninja') {
                    const options = { type: 'POST', url: location.origin + '/ajax-success', data: new URLSearchParams(payload).toString() };
                    window.ninjaPrefilters.forEach((fn) => fn(options, { data: payload }, {}));
                    body = options.data;
                }
                await fetch('/ajax-success', { method: 'POST', body });
                const values = kind === 'atomic' ? body : new URLSearchParams(body);
                return { token: values.get('cvmtry_conversion_id'), session: values.get('cvmtry_session_id') };
            }, provider, native, retry);

            const first = await send(false, false);
            const retried = await send(false, true);
            const second = await send(true, true); // A new native submit reuses the body object.
            const third = await send(false, false); // Another AJAX-only submission.
            await delivered(url, 'form_submit', 3);
            if (provider === 'atomic') await delivered(url, 'form_success', 3);
            assert.deepEqual(retried, first, 'retry retains its original token and session');
            assert.equal(new Set([first.token, second.token, third.token]).size, 3);
            const attempts = site.trackedEvents(url).filter((event) => event.type === 'form_submit');
            assert.equal(attempts.length, 3, 'native listener and transport never double-count');
            for (const [index, request] of [first, second, third].entries()) {
                assert.equal(attempts[index].event_value, request.token);
                assert.equal(attempts[index].session_id, request.session);
                assert.equal(attempts[index].form_key, provider === 'atomic' ? 'elementor_atomic:42:abc123' : 'ninjaforms:3');
                assert.ok(site.requests.some((entry) => entry.path === '/ajax-success' && entry.body.includes(request.token)), 'token really travelled to the local endpoint');
            }
            if (provider === 'atomic') {
                assert.equal(site.trackedEvents(url).filter((event) => event.type === 'form_success').length, 3);
            }
            assert.deepEqual(await correlationFields(page, 'provider-form'), {}, 'GET markup never receives hidden correlation fields');
            assert.deepEqual(page.exceptions, []);
        });
    });
}

// ──────────────────────────────────────────────────────────────────────────────

await browser.close();
await site.close();

console.log(`\n  ${passed} passed, ${failures.length} failed\n`);
if (failures.length > 0) {
    process.exit(1);
}
