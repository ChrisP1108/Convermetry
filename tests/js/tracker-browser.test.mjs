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
    let timer;
    try {
        // A DevTools call can go unanswered forever. That has to fail the test it
        // belongs to rather than stall the whole run.
        await Promise.race([
            fn(),
            new Promise((_, reject) => {
                timer = setTimeout(() => reject(new Error('Timed out after 60 s')), 60000);
            }),
        ]);
        passed++;
        console.log(`  ✓ ${name}`);
    } catch (error) {
        failures.push({ name, error });
        console.log(`  ✗ ${name}`);
        console.log(`      ${String(error.message).split('\n')[0]}`);
    } finally {
        clearTimeout(timer);
    }
}

/**
 * Registers a page and returns its URL. The tracker loads as WordPress loads
 * it — deferred, in the footer — so start-up runs at readyState 'interactive'
 * and seeds forms synchronously, which is the path the "method" defect broke.
 */
function fixture(body, { head = '', config: overrides = {} } = {}) {
    const path = `/fixture/${++fixtures}/`;
    const config = { endpoint: `${site.origin}/track`, events: ALL_EVENTS, respectDnt: false, flushIntervalMs: 100, ...overrides };
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

// ───────────────── 8. page views the browser shows without a load ───────────

/** A page that reports to the local listener: what it says, in arrival order. */
function probes() {
    return site.requests.filter((request) => request.path === '/probe').map((request) => request.query.slice(1));
}

/**
 * A page that prerenders `target` and, unless told otherwise, opens it.
 *
 * Opened with NO DevTools session on the tab: an attached one disables
 * prerendering (PrerenderingDisabledByDevTools), so the pages drive themselves
 * and everything is read from what reaches the listener. The tracker loads as
 * it does in production, and the browser's real activation runs.
 */
async function prerender(body, { open = true } = {}) {
    const say = `(what) => fetch('/probe?' + what, { keepalive: true })`;
    const target = fixture(body, { head: `<script>
        const say = ${say};
        say('loaded&prerendering=' + !!document.prerendering);
        document.addEventListener('prerenderingchange', () => say('activated'));
        document.addEventListener('DOMContentLoaded', () => window.Convermetry && window.Convermetry.track('early_call', { value: 7 }));
    </script>` });
    const rules = JSON.stringify({ prerender: [{ source: 'list', urls: [target.path], eagerness: 'immediate' }] });
    const start = fixture('', { head: `<script type="speculationrules">${rules}</script><script>
        const say = ${say};
        setTimeout(() => say('ready-to-open'), 1500);
        ${open ? `setTimeout(() => { location.href = ${JSON.stringify(target.path)}; }, 2000);` : ''}
    </script>` });

    const seenBefore = site.requests.length;
    const { browserContextId } = await browser.cdp.send('Target.createBrowserContext');
    await browser.cdp.send('Target.createTarget', { url: start.url, browserContextId });
    const heard = () => site.requests.slice(seenBefore).filter((r) => r.path === '/probe').map((r) => r.query.slice(1));
    await waitFor(() => heard().includes('ready-to-open'), 'the start page to be ready', 10000);

    return {
        url: target.url,
        heard,
        close: () => browser.cdp.send('Target.disposeBrowserContext', { browserContextId }),
    };
}

await test('a prerendered page the visitor never opens records nothing', async () => {
    const page = await prerender('<p>Never opened.</p>', { open: false });
    try {
        if (!page.heard().includes('loaded&prerendering=true')) {
            console.log('      (this Chrome declined to prerender; not conclusive)');
            return;
        }
        await new Promise((resolve) => setTimeout(resolve, 1200));
        assert.deepEqual(site.trackedEvents(page.url), [], 'no pageview, no goal, nothing at all');
    } finally {
        await page.close();
    }
});

await test('a prerendered page counts once when opened, and a call made while it loaded is kept', async () => {
    const page = await prerender('<p>Opened.</p>');
    try {
        if (!page.heard().includes('loaded&prerendering=true')) {
            console.log('      (this Chrome declined to prerender; not conclusive)');
            return;
        }
        assert.deepEqual(site.trackedEvents(page.url), [], 'still nothing while it is only prerendered');
        await waitFor(() => page.heard().includes('activated'), 'the page to be activated', 10000);
        await delivered(page.url, 'pageview');
        const calls = await delivered(page.url, 'custom_event');
        await new Promise((resolve) => setTimeout(resolve, 500));
        const events = site.trackedEvents(page.url);
        assert.equal(events.filter((event) => event.type === 'pageview').length, 1);
        assert.equal(calls.length, 1, 'Convermetry.track() made during the prerender is replayed once');
        assert.equal(calls[0].name, 'early_call');
    } finally {
        await page.close();
    }
});

/** Two pages that link to each other, kept eligible for the back/forward cache. */
function restorablePages(body = '') {
    const second = fixture('<a id="back-link" href="#">Page two</a>');
    const first = fixture(`<a id="next" href="${second.path}">Next</a>${body}`);
    site.cacheablePages.add(first.path);
    site.cacheablePages.add(second.path);
    return { first, second };
}

/** Marks the page, so a restored one can be told from one that was reloaded. */
function markLoaded(page) {
    return page.evaluate(() => {
        window.__loadMark = true;
    });
}

/** Whether this page is the same document as before, or was loaded anew. */
function wasRestored(page) {
    return page.evaluate(() => window.__loadMark === true);
}

/**
 * Leaves the page from a later task, so the evaluate that asks for it has already
 * been answered. A DevTools call still open while the browser swaps documents may
 * never get a reply, which is also why these tests wait on what reaches the
 * listener instead of asking the page where it is.
 */
function goTo(page, direction) {
    return page.evaluate((go) => {
        setTimeout(() => history.go(go), 0);
    }, direction);
}

function follow(page, linkId) {
    return page.evaluate((id) => {
        setTimeout(() => document.getElementById(id).click(), 0);
    }, linkId);
}

await test('Back and Forward count as page views when the browser restores from its cache', async () => {
    const { first, second } = restorablePages();

    await withPage(first.url, async (page) => {
        await delivered(first.url, 'pageview');
        await markLoaded(page);
        await follow(page, 'next');
        await delivered(second.url, 'pageview');

        await goTo(page, -1);
        await delivered(first.url, 'pageview', 2);
        if (!(await wasRestored(page))) {
            console.log('      (this Chrome did not use its back/forward cache; not conclusive)');
            return;
        }

        await goTo(page, 1);
        await delivered(second.url, 'pageview', 2);

        // The page being left runs its exit handlers just after the restored one
        // starts, sees that page's batch waiting in the tab's shared store, and
        // resends it. That is at-least-once delivery working as documented, so
        // this counts what the server keeps rather than what crossed the wire.
        await new Promise((resolve) => setTimeout(resolve, 400));
        const views = (url) => site.storedEvents(url).filter((event) => event.type === 'pageview').length;
        assert.equal(views(first.url), 2, 'exactly one for the load and one for the restore');
        assert.equal(views(second.url), 2);
        assert.deepEqual(page.exceptions, []);
    });
});

await test('coming back to a form through the cache and starting again is a second form_start', async () => {
    const { first, second } = restorablePages(`
        <form id="again" method="post" action="/submit/again" data-cvmtry-form-key="custom:again"><input id="again-email" name="email"></form>`);

    await withPage(first.url, async (page) => {
        await type(page, 'again-email');
        await delivered(first.url, 'form_start');
        await markLoaded(page);
        await follow(page, 'next');
        await delivered(second.url, 'pageview');
        await goTo(page, -1);
        await delivered(first.url, 'pageview', 2);
        if (!(await wasRestored(page))) {
            console.log('      (this Chrome did not use its back/forward cache; not conclusive)');
            return;
        }
        await type(page, 'again-email');
        await delivered(first.url, 'form_start', 2);
        await delivered(first.url, 'form_view', 2);
    });
});

await test('a page view starts its scroll milestones over after a restore', async () => {
    const { url } = fixture('<p>Short page.</p>');

    await withPage(url, async (page) => {
        await delivered(url, 'scroll_depth');
        await page.evaluate(() => window.dispatchEvent(new PageTransitionEvent('pageshow', { persisted: true })));
        await delivered(url, 'scroll_depth', 2);
        await delivered(url, 'pageview', 2);
    });
});

await test('an ordinary pageshow is not a second page view', async () => {
    const { url } = fixture('<p>Loaded normally.</p>');

    await withPage(url, async (page) => {
        await delivered(url, 'pageview');
        await page.evaluate(() => window.dispatchEvent(new PageTransitionEvent('pageshow', { persisted: false })));
        await new Promise((resolve) => setTimeout(resolve, 400));
        assert.equal(site.trackedEvents(url).filter((event) => event.type === 'pageview').length, 1);
    });
});

// ─────────────────────── 9. a batch has to fit the request ───────────────────

/** Every /track request delivered from one page, as raw bodies. */
function batchBodies(pageUrl) {
    return site.requests
        .filter((request) => request.path === '/track' && request.body.includes(pageUrl))
        .map((request) => request.body);
}

/** Presses a button `count` times in one go, filling batches past the flush size. */
function pressRepeatedly(page, id, count) {
    return page.evaluate((button, times) => {
        for (let i = 0; i < times; i++) {
            document.getElementById(button).click();
        }
    }, id, count);
}

await test('a very long page title does not cost the batch its events', async () => {
    const { path, n } = fixture('<button id="go">Go</button>');
    const url = site.origin + path;
    site.pages.set(path, site.pages.get(path).replace(`<title>Fixture ${n}</title>`, `<title>${'H'.repeat(3400)}</title>`));

    await withPage(url, async (page) => {
        await pressRepeatedly(page, 'go', 22);
        const clicks = await delivered(url, 'click', 22);
        assert.equal(clicks.length, 22, 'all of them, once');
        assert.ok(clicks.every((event) => event.page_title.length === 255), 'cut to the width the server stores');
    });
});

await test('events that cannot fit one request are split across several and none are lost', async () => {
    // The longest utm values and title the tracker keeps, in three-byte characters:
    // about 4 KB an event, so a flush of 20 is well over the 64 KB limit.
    const wide = '語'.repeat(190);
    const utm = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_id', 'utm_term', 'utm_content']
        .map((name) => `${name}=${encodeURIComponent(wide)}`).join('&');
    const { path, n } = fixture('<button id="go">Go</button>');
    const url = site.origin + path;
    site.pages.set(path, site.pages.get(path).replace(`<title>Fixture ${n}</title>`, `<title>${wide}</title>`));

    await withPage(`${url}?${utm}`, async (page) => {
        await pressRepeatedly(page, 'go', 20);
        await waitFor(() => site.storedEvents(url).filter((event) => event.type === 'click').length === 20,
            'every click, retries included', 20000);
    });

    const bodies = batchBodies(url);
    assert.ok(bodies.length >= 2, 'more than one request was needed');
    assert.ok(bodies.every((body) => Buffer.byteLength(body) <= 65536), 'no request over the server limit');
    assert.ok(new Set(bodies.map((body) => JSON.parse(body).batch_id)).size >= 2, 'under separate batch ids');
});

await test('text cut at a limit never splits an emoji, which would make the server refuse the batch', async () => {
    // The emoji straddles each limit, so a plain slice leaves half of it behind.
    const title = `${'t'.repeat(254)}😀 tail`;
    const label = `${'b'.repeat(119)}😀 tail`;
    const { path, n } = fixture(`<button id="go">${label}</button>`);
    const url = site.origin + path;
    site.pages.set(path, site.pages.get(path).replace(`<title>Fixture ${n}</title>`, `<title>${title}</title>`));

    await withPage(url, async (page) => {
        await page.evaluate(() => document.getElementById('go').click());
        const [click] = await delivered(url, 'click');
        assert.equal(click.page_title, 't'.repeat(254));
        assert.equal(click.element_label, 'b'.repeat(119));
    });

    for (const body of batchBodies(url)) {
        // PHP's json_decode() fails the whole body on an unpaired surrogate escape.
        assert.doesNotMatch(body, /\\ud[89ab][0-9a-f]{2}(?!\\ud[c-f][0-9a-f]{2})/i);
    }
});

// ─────────────────────── 10. a middle-click opens a new tab ──────────────────

const CTA_GOALS = { config: { selectorGoals: { g_demo: '[data-demo-cta]' } } };

/** Keeps a synthetic click on a link from navigating this page away. Runs in
 *  the bubbling phase, after the tracker's capture-phase listener has seen it. */
const STAY_HERE = '<script>document.addEventListener("click", (e) => { if (e.target.closest("a")) e.preventDefault(); });</script>';

/** Dispatches one mouse event of a type and button at an element. */
function mouse(page, id, type, button) {
    return page.evaluate((target, kind, which) => {
        document.getElementById(target).dispatchEvent(new MouseEvent(kind, { button: which, bubbles: true, cancelable: true }));
    }, id, type, button);
}

/**
 * Presses a sentinel button and waits for its click to arrive. Events leave in
 * the order they were tracked, so once the sentinel is in, anything tracked
 * before it has arrived too: what is absent was never recorded.
 */
async function clicksBeforeSentinel(page, url) {
    await press(page, 'sentinel');
    await waitFor(() => site.trackedEvents(url).some((event) => event.type === 'click' && event.element_label === 'Sentinel'),
        'the sentinel click');
    return site.trackedEvents(url).filter((event) => event.type === 'click' && event.element_label !== 'Sentinel');
}

await test('a middle-click on a link records the click and completes its selector goal', async () => {
    const { url } = fixture(`<a id="cta" href="/pricing/?ref=mail#plans" data-demo-cta>See pricing</a>
        <button id="sentinel">Sentinel</button>${STAY_HERE}`, CTA_GOALS);

    await withPage(url, async (page) => {
        await mouse(page, 'cta', 'auxclick', 1);
        const clicks = await clicksBeforeSentinel(page, url);
        assert.equal(clicks.length, 1);
        assert.equal(clicks[0].element_tag, 'a');
        assert.equal(clicks[0].target_url, `${site.origin}/pricing/`, 'the destination, without its query or fragment');
        assert.deepEqual(clicks[0].selector_goals, ['g_demo']);
        assert.deepEqual(page.exceptions, []);
    });
});

await test("a visitor's real middle-click is recorded once", async () => {
    const { url } = fixture(`<a id="cta" href="/pricing/" data-demo-cta style="display:inline-block;padding:20px">See pricing</a>
        <button id="sentinel">Sentinel</button>`, CTA_GOALS);

    await withPage(url, async (page) => {
        const { x, y } = await page.evaluate(() => {
            const box = document.getElementById('cta').getBoundingClientRect();
            return { x: box.left + box.width / 2, y: box.top + box.height / 2 };
        });
        for (const type of ['mousePressed', 'mouseReleased']) {
            await page.send('Input.dispatchMouseEvent', { type, x, y, button: 'middle', buttons: type === 'mousePressed' ? 4 : 0, clickCount: 1 });
        }
        const clicks = await clicksBeforeSentinel(page, url);
        assert.equal(clicks.length, 1, 'the browser reports it as auxclick, and it is counted exactly once');
        assert.deepEqual(clicks[0].selector_goals, ['g_demo']);
    });
});

await test('a right-click, and a middle-click on a button, record nothing', async () => {
    const { url } = fixture(`<a id="cta" href="/pricing/" data-demo-cta>See pricing</a>
        <button id="plain" data-demo-cta>Not a link</button>
        <button id="sentinel">Sentinel</button>${STAY_HERE}`, CTA_GOALS);

    await withPage(url, async (page) => {
        await mouse(page, 'cta', 'auxclick', 2);
        await mouse(page, 'plain', 'auxclick', 1);
        assert.deepEqual(await clicksBeforeSentinel(page, url), [],
            'the right button opens a menu, and a middle-click activates nothing but a link');
    });
});

await test('one activation reported as both click and auxclick is counted once', async () => {
    const { url } = fixture(`<a id="cta" href="/pricing/" data-demo-cta>See pricing</a>
        <button id="sentinel">Sentinel</button>${STAY_HERE}`, CTA_GOALS);

    await withPage(url, async (page) => {
        // What an older Firefox delivered for one middle-click.
        await mouse(page, 'cta', 'click', 1);
        await mouse(page, 'cta', 'auxclick', 1);
        const clicks = await clicksBeforeSentinel(page, url);
        assert.equal(clicks.length, 1);
    });
});

// ──────────── 11. a library that changes how JSON.stringify sees arrays ──────

/** What Prototype.js 1.6 defines: an Array's JSON is a STRING. */
const PROTOTYPE_JS = `<script>Array.prototype.toJSON = function () {
    return '[' + this.map(function (v) { return JSON.stringify(v); }).join(', ') + ']';
};</script>`;

await test('Array.prototype.toJSON from an old library does not turn the events into a string', async () => {
    const { url, path } = fixture('<button id="go">Go</button>', { head: PROTOTYPE_JS });

    await withPage(url, async (page) => {
        await press(page, 'go');
        await delivered(url, 'click');
        for (const body of site.requests.filter((r) => r.path === '/track' && r.body.includes(path)).map((r) => r.body)) {
            assert.ok(Array.isArray(JSON.parse(body).events), 'the endpoint refuses a batch whose events are a string');
        }
        assert.equal(await page.evaluate(() => [1, 2].toJSON()), '[1, 2]', "the page's own toJSON is put back");
        assert.deepEqual(page.exceptions, []);
    });
});

await test('a batch kept for retry is stored as an array under such a library, and survives the round trip', async () => {
    const { url, path } = fixture('<button id="go">Go</button>', {
        head: PROTOTYPE_JS,
        config: { endpoint: `${site.origin}/track-unavailable` },
    });

    await withPage(url, async (page) => {
        await press(page, 'go');
        await waitFor(() => site.requests.some((r) => r.path === '/track-unavailable' && r.body.includes(path)),
            'a send to the unavailable endpoint');

        const stored = await page.evaluate(() => {
            const root = JSON.parse(sessionStorage.getItem('cvmtry_pending'));
            return Object.values(root.batches).map((batch) => Array.isArray(batch.events) && batch.events.length);
        });
        assert.ok(stored.length > 0, 'the failed batch is kept for a retry');
        assert.ok(stored.every((count) => count > 0), 'every kept batch still holds an array of events');
    });
});

// ─────────────────────── 12. a stored session id it did not mint ─────────────

/** Puts a value in the session store before the tracker reads it. */
function storedSession(value) {
    return `<script>localStorage.setItem('cvmtry_session', ${JSON.stringify(value)} + '.' + Date.now());</script>`;
}

await test('a malformed stored session id is replaced, never sent', async () => {
    const { url } = fixture('<p>Tampered.</p>', { head: storedSession('not-a-hex-id') });

    await withPage(url, async (page) => {
        const [view] = await delivered(url, 'pageview');
        assert.match(view.session_id, /^[a-f0-9]{32}$/, 'a fresh id, not the server-side remnant "aed"');
        const kept = await page.evaluate(() => localStorage.getItem('cvmtry_session'));
        assert.equal(kept.split('.')[0], view.session_id, 'and it is the one persisted from now on');
    });
});

await test('a stored session id the tracker minted is kept', async () => {
    const id = '0123456789abcdef0123456789abcdef';
    const { url } = fixture('<p>Returning.</p>', { head: storedSession(id) });

    await withPage(url, async () => {
        const [view] = await delivered(url, 'pageview');
        assert.equal(view.session_id, id);
    });
});

// ─────────── 13. fields with no name, or a name in another script ────────────

await test('fields with no name or id, and a non-ASCII name, report distinct field ids', async () => {
    const { url } = fixture(`
        <form id="signup" method="post" action="/submit/signup" data-cvmtry-form-key="custom:signup">
            <input type="email" required>
            <input name="邮箱" required>
            <input type="text" required>
            <button id="signup-send">Send</button>
        </form>`);

    await withPage(url, async (page) => {
        await press(page, 'signup-send');
        const errors = await delivered(url, 'form_error', 3);
        assert.deepEqual(errors.map((event) => event.field_id), ['email#1', '邮箱', 'text#3'],
            'each field is its own row in the friction report');
        assert.deepEqual(errors.map((event) => event.field_type), ['email', 'text', 'text']);
    });
});

// ──────────────────────────────────────────────────────────────────────────────

await browser.close();
await site.close();

console.log(`\n  ${passed} passed, ${failures.length} failed\n`);
if (failures.length > 0) {
    process.exit(1);
}
