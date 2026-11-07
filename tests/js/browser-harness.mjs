/**
 * A real browser for the tracker: headless Chrome, driven over the DevTools
 * protocol, with no dependency beyond Node.
 *
 * Why not the shim in dom-harness.mjs: a <form> exposes its named controls as
 * properties of itself, and a control WINS over the form's own property of the
 * same name — <input name="method"> makes form.method an element. FakeElement
 * is a plain class and cannot reproduce that, and the defects it would hide are
 * exactly the ones these tests exist for. So this launches the browser the
 * tracker actually runs in, and the tracker loads the way WordPress loads it:
 * deferred, in the footer.
 *
 * NOTHING LEAVES THE MACHINE. Pages, the tracker, the analytics endpoint and
 * every form destination are served by local listeners on 127.0.0.1, which
 * record each request — so analytics batches and form submissions are both
 * intercepted and inspected, and no WordPress database is involved. The
 * "external" site is a second listener on another PORT: a different origin,
 * which is all the tracker's same-origin rule looks at.
 *
 * Chrome is found through CVMTRY_CHROME, then a Playwright browser cache, then
 * the usual install locations. The page profile is a fresh temporary
 * directory, removed on close. CVMTRY_TRACKER points the pages at another
 * build of the tracker, which is how a regression test is shown to fail
 * against the code it guards.
 */

import { spawn } from 'node:child_process';
import { createServer } from 'node:http';
import { existsSync, mkdtempSync, readFileSync, readdirSync, rmSync } from 'node:fs';
import { homedir, platform, tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const HERE = dirname(fileURLToPath(import.meta.url));
const TRACKER = process.env.CVMTRY_TRACKER || join(HERE, '..', '..', 'assets', 'js', 'tracker.js');

/** The Chrome to drive, or null when none is installed. */
export function findChrome() {
    if (process.env.CVMTRY_CHROME) {
        return existsSync(process.env.CVMTRY_CHROME) ? process.env.CVMTRY_CHROME : null;
    }

    const candidates = [];

    for (const cache of [join(homedir(), 'Library', 'Caches', 'ms-playwright'), join(homedir(), '.cache', 'ms-playwright')]) {
        if (!existsSync(cache)) {
            continue;
        }
        const builds = readdirSync(cache).filter((dir) => /^chromium-\d+$/.test(dir)).sort().reverse();
        for (const build of builds) {
            for (const platformDir of readdirSync(join(cache, build))) {
                const root = join(cache, build, platformDir);
                candidates.push(
                    join(root, 'Google Chrome for Testing.app', 'Contents', 'MacOS', 'Google Chrome for Testing'),
                    join(root, 'Chromium.app', 'Contents', 'MacOS', 'Chromium'),
                    join(root, 'chrome')
                );
            }
        }
    }

    candidates.push(
        '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
        '/Applications/Chromium.app/Contents/MacOS/Chromium',
        '/usr/bin/google-chrome',
        '/usr/bin/google-chrome-stable',
        '/usr/bin/chromium',
        '/usr/bin/chromium-browser'
    );

    return candidates.find((path) => existsSync(path)) || null;
}

/**
 * The DevTools protocol over --remote-debugging-pipe: JSON messages, each
 * terminated by a NUL byte, written to the browser's fd 3 and read from fd 4.
 */
class Cdp {
    constructor(input, output) {
        this.input = input;
        this.nextId = 0;
        this.pending = new Map();
        this.listeners = new Set();

        let buffer = Buffer.alloc(0);
        output.on('data', (chunk) => {
            buffer = Buffer.concat([buffer, chunk]);
            let end;
            while ((end = buffer.indexOf(0)) !== -1) {
                const message = JSON.parse(buffer.subarray(0, end).toString('utf8'));
                buffer = buffer.subarray(end + 1);
                this.receive(message);
            }
        });
    }

    send(method, params = {}, sessionId = undefined) {
        const id = ++this.nextId;
        const message = sessionId ? { id, method, params, sessionId } : { id, method, params };
        this.input.write(JSON.stringify(message) + '\0');
        return new Promise((resolve, reject) => this.pending.set(id, { resolve, reject, method }));
    }

    receive(message) {
        if (message.id !== undefined) {
            const call = this.pending.get(message.id);
            this.pending.delete(message.id);
            if (call) {
                message.error ? call.reject(new Error(`${call.method}: ${message.error.message}`)) : call.resolve(message.result);
            }
            return;
        }
        for (const listener of [...this.listeners]) {
            listener(message);
        }
    }

    /** Subscribes to protocol events; returns the unsubscribe function. */
    on(listener) {
        this.listeners.add(listener);
        return () => this.listeners.delete(listener);
    }

    /** The next event of one method for one session. */
    once(method, sessionId) {
        return new Promise((resolve) => {
            const off = this.on((message) => {
                if (message.method === method && message.sessionId === sessionId) {
                    off();
                    resolve(message.params);
                }
            });
        });
    }
}

/** Starts headless Chrome with a throwaway profile. */
export async function launchBrowser(executable) {
    const profile = mkdtempSync(join(tmpdir(), 'cvmtry-chrome-'));
    const args = [
        '--headless=new',
        '--remote-debugging-pipe',
        `--user-data-dir=${profile}`,
        '--no-first-run',
        '--no-default-browser-check',
        '--disable-background-networking',
        '--disable-component-update',
        '--disable-default-apps',
        '--disable-extensions',
        '--disable-sync',
        '--metrics-recording-only',
        '--mute-audio',
        '--window-size=1280,900',
        'about:blank',
    ];
    if (platform() === 'linux') {
        args.push('--no-sandbox'); // CI runners; the pages are local fixtures.
    }

    const child = spawn(executable, args, { stdio: ['ignore', 'ignore', 'ignore', 'pipe', 'pipe'] });
    const exited = new Promise((resolve) => child.once('exit', resolve));
    const cdp = new Cdp(child.stdio[3], child.stdio[4]);

    await cdp.send('Browser.getVersion');

    return {
        cdp,
        async close() {
            try {
                await Promise.race([cdp.send('Browser.close'), exited]);
            } catch {
                child.kill('SIGKILL');
            }
            await Promise.race([exited, new Promise((resolve) => setTimeout(resolve, 3000))]);
            rmSync(profile, { recursive: true, force: true });
        },
    };
}

/**
 * Two local listeners sharing one request log: the site under test and an
 * "external" origin on another port. Fixture pages are registered by path.
 */
export async function startSite() {
    const requests = [];
    const pages = new Map();
    const cacheablePages = new Set();

    const handler = (port) => (req, res) => {
        const chunks = [];
        req.on('data', (chunk) => chunks.push(chunk));
        req.on('end', () => {
            const url = new URL(req.url, `http://${req.headers.host}`);
            requests.push({
                port,
                method: req.method,
                path: url.pathname,
                query: url.search,
                purpose: req.headers['sec-purpose'] || '',
                body: Buffer.concat(chunks).toString('utf8'),
            });

            if (req.method === 'GET' && url.pathname === '/tracker.js') {
                res.writeHead(200, { 'Content-Type': 'text/javascript', 'Cache-Control': 'no-store' });
                res.end(readFileSync(TRACKER));
            } else if (url.pathname === '/track') {
                // The real endpoint's limit: TrackingController refuses a larger body.
                const tooLarge = Buffer.concat(chunks).length > 65536;
                res.writeHead(tooLarge ? 413 : 202, { 'Content-Type': 'application/json' });
                res.end(tooLarge ? '{"error":"payload_too_large"}' : '{"stored":0}');
            } else if (url.pathname === '/track-unavailable') {
                // An endpoint that is down: the tracker keeps the batch and backs off.
                res.writeHead(503, { 'Content-Type': 'application/json' });
                res.end('{"error":"unavailable"}');
            } else if (req.method === 'POST' && url.pathname === '/ajax-success') {
                res.writeHead(200, { 'Content-Type': 'application/json' });
                res.end('{"success":true}');
            } else if (req.method === 'GET' && pages.has(url.pathname)) {
                // no-store keeps a page out of the back/forward cache, so a page a
                // test wants restored from it is served without.
                const caching = cacheablePages.has(url.pathname) ? {} : { 'Cache-Control': 'no-store' };
                res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8', ...caching });
                res.end(pages.get(url.pathname));
            } else {
                res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
                res.end('<!doctype html><meta charset="utf-8"><title>Received</title><p>Received.</p>');
            }
        });
    };

    const listen = () => new Promise((resolve) => {
        const server = createServer();
        server.listen(0, '127.0.0.1', () => {
            server.on('request', handler(server.address().port));
            resolve(server);
        });
    });

    const site = await listen();
    const external = await listen();
    const sitePort = site.address().port;
    const externalPort = external.address().port;

    return {
        origin: `http://127.0.0.1:${sitePort}`,
        externalOrigin: `http://127.0.0.1:${externalPort}`,
        sitePort,
        externalPort,
        requests,
        pages,
        cacheablePages,

        /** Every event the tracker delivered from one page, in arrival order. */
        trackedEvents(pageUrl) {
            return requests
                .filter((request) => request.port === sitePort && request.path === '/track')
                .flatMap((request) => {
                    try {
                        return JSON.parse(request.body).events || [];
                    } catch {
                        return [];
                    }
                })
                .filter((event) => event.page_url === pageUrl);
        },

        /**
         * The events the server would store from one page. Delivery is
         * at-least-once and the server keeps one row per (batch id, position),
         * so a batch sent twice counts once here, as it does there.
         */
        storedEvents(pageUrl) {
            const rows = new Map();
            for (const request of requests) {
                if (request.port !== sitePort || request.path !== '/track') {
                    continue;
                }
                try {
                    const { batch_id: batchId, events } = JSON.parse(request.body);
                    (events || []).forEach((event, position) => rows.set(`${batchId}:${position}`, event));
                } catch {
                    // Not a batch.
                }
            }
            return [...rows.values()].filter((event) => event.page_url === pageUrl);
        },

        close: () => Promise.all([site, external].map((server) => new Promise((resolve) => {
            server.closeAllConnections();
            server.close(resolve);
        }))),
    };
}

/** Opens a URL in a fresh browser context (its own storage) and waits for load. */
export async function openPage(cdp, url) {
    const { browserContextId } = await cdp.send('Target.createBrowserContext');
    const { targetId } = await cdp.send('Target.createTarget', { url: 'about:blank', browserContextId });
    const { sessionId } = await cdp.send('Target.attachToTarget', { targetId, flatten: true });

    // Uncaught exceptions are collected: a tracker that throws while
    // initializing is the failure several of these tests are about.
    const exceptions = [];
    const off = cdp.on((message) => {
        if (message.sessionId === sessionId && message.method === 'Runtime.exceptionThrown') {
            const details = message.params.exceptionDetails;
            exceptions.push((details.exception && details.exception.description) || details.text);
        }
    });

    await cdp.send('Runtime.enable', {}, sessionId);
    await cdp.send('Page.enable', {}, sessionId);

    const loaded = cdp.once('Page.loadEventFired', sessionId);
    await cdp.send('Page.navigate', { url }, sessionId);
    await loaded;

    return {
        exceptions,

        /** Runs fn(...args) in the page and returns its JSON-serializable result. */
        async evaluate(fn, ...args) {
            const result = await cdp.send('Runtime.evaluate', {
                expression: `(${fn})(...${JSON.stringify(args)})`,
                returnByValue: true,
                awaitPromise: true,
            }, sessionId);
            if (result.exceptionDetails) {
                const details = result.exceptionDetails;
                throw new Error(`In page: ${(details.exception && details.exception.description) || details.text}`);
            }
            return result.result.value;
        },

        /** Sends one DevTools command to this page — Input.dispatchMouseEvent,
         *  say, for a click the browser treats as the visitor's own. */
        send(method, params = {}) {
            return cdp.send(method, params, sessionId);
        },

        async close() {
            off();
            await cdp.send('Target.disposeBrowserContext', { browserContextId });
        },
    };
}

/** Polls until predicate() returns something truthy, and returns it. */
export async function waitFor(predicate, message, timeout = 5000) {
    const deadline = Date.now() + timeout;
    for (;;) {
        const value = await predicate();
        if (value) {
            return value;
        }
        if (Date.now() > deadline) {
            throw new Error(`Timed out waiting for ${message}`);
        }
        await new Promise((resolve) => setTimeout(resolve, 25));
    }
}
