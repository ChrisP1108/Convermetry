/**
 * The smallest browser the tracker will run in.
 *
 * assets/js/tracker.js is a plain IIFE that feature-tests almost everything it
 * touches, so a genuine DOM is not needed to execute it — only the handful of
 * globals it actually reads. This shim provides those, evaluates the real
 * tracker source inside a vm context, and hands back the hooks a test needs:
 * the tracked events it tried to send, and a way to dispatch the CustomEvents a
 * form plugin fires.
 *
 * Deliberately minimal and deliberately not a DOM library. Anything the tracker
 * feature-tests for and does not find (IntersectionObserver, MutationObserver,
 * jQuery) is simply absent, which is a real browser state the tracker already
 * has to survive.
 */

import { readFileSync } from 'node:fs';
import { createContext, runInContext } from 'node:vm';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const HERE = dirname(fileURLToPath(import.meta.url));
const TRACKER = join(HERE, '..', '..', 'assets', 'js', 'tracker.js');

/** One element, with just enough surface for the tracker's queries. */
export class FakeElement {
    constructor(tagName, attributes = {}) {
        this.tagName = tagName.toUpperCase();
        this.attributes = { ...attributes };
        this.children = [];
        this.parent = null;
        this.value = '';
    }

    get id() {
        return this.attributes.id || '';
    }

    get method() {
        return this.attributes.method || (this.tagName === 'FORM' ? 'get' : '');
    }

    get className() {
        return this.attributes.class || '';
    }

    get type() {
        return this.attributes.type || '';
    }

    set type(value) {
        this.attributes.type = value;
    }

    get name() {
        return this.attributes.name || '';
    }

    set name(value) {
        this.attributes.name = value;
    }

    getAttribute(name) {
        return Object.prototype.hasOwnProperty.call(this.attributes, name) ? this.attributes[name] : null;
    }

    setAttribute(name, value) {
        this.attributes[name] = String(value);
    }

    hasAttribute(name) {
        return Object.prototype.hasOwnProperty.call(this.attributes, name);
    }

    appendChild(child) {
        child.parent = this;
        this.children.push(child);
        return child;
    }

    /** Supports only input[name="…"], which is all the tracker asks for. */
    querySelectorAll(selector) {
        const match = /^input\[name="(.+)"\]$/.exec(selector);
        if (!match) {
            return [];
        }
        return this.children.filter((child) => child.tagName === 'INPUT' && child.name === match[1]);
    }

    querySelector(selector) {
        return this.querySelectorAll(selector)[0] || null;
    }

    matches() {
        return false;
    }

    closest() {
        return null;
    }

    contains(node) {
        return node === this;
    }
}

/** A minimal Storage. */
function makeStorage() {
    const data = new Map();
    return {
        getItem: (k) => (data.has(k) ? data.get(k) : null),
        setItem: (k, v) => void data.set(k, String(v)),
        removeItem: (k) => void data.delete(k),
    };
}

/** A node that dispatches events to registered listeners. */
function makeEventTarget(self) {
    const listeners = new Map();

    self.addEventListener = (type, handler) => {
        if (!listeners.has(type)) {
            listeners.set(type, []);
        }
        listeners.get(type).push(handler);
    };
    self.removeEventListener = (type, handler) => {
        const list = listeners.get(type) || [];
        const at = list.indexOf(handler);
        if (at > -1) {
            list.splice(at, 1);
        }
    };
    self.dispatchEvent = (event) => {
        for (const handler of (listeners.get(event.type) || []).slice()) {
            handler(event);
        }
        return true;
    };

    return self;
}

/**
 * Boots the real tracker in a shimmed browser.
 *
 * @param {object} options
 * @param {FakeElement[]} options.forms Forms present in the document.
 * @param {object} options.config Overrides merged into ConvermetryConfig.
 * @returns {object} The harness: { window, document, forms, sent, dispatch, trackedEvents }
 */
export function bootTracker({ forms = [], config = {} } = {}) {
    const sent = [];
    const timers = [];

    const documentElement = new FakeElement('html');
    const document = makeEventTarget({
        readyState: 'interactive',
        referrer: '',
        title: 'Test page',
        visibilityState: 'visible',
        documentElement,
        body: new FakeElement('body'),
        createElement: (tag) => new FakeElement(tag),
        getElementById: (id) => forms.find((form) => form.id === id) || null,
        querySelector: (selector) => document.querySelectorAll(selector)[0] || null,
        querySelectorAll: (selector) => {
            if (selector === 'form') {
                return forms;
            }
            const attr = /^\[([a-z-]+)="(.*)"\]$/.exec(selector);
            if (attr) {
                return forms.filter((form) => form.getAttribute(attr[1]) === attr[2]);
            }
            return [];
        },
    });

    const window = makeEventTarget({
        ConvermetryConfig: {
            endpoint: 'https://example.test/wp-json/convermetry/v1/track',
            events: {
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
            },
            respectDnt: false,
            ...config,
        },
        localStorage: makeStorage(),
        sessionStorage: makeStorage(),
        location: {
            origin: 'https://example.test',
            pathname: '/contact/',
            search: '',
            hash: '',
            href: 'https://example.test/contact/',
        },
        scrollY: 0,
        innerWidth: 1280,
        innerHeight: 800,
    });

    const navigator = {
        doNotTrack: '0',
        userAgent: 'harness',
        sendBeacon: (url, body) => {
            sent.push({ transport: 'beacon', url, body });
            return true;
        },
    };

    const context = {
        window,
        document,
        navigator,
        location: window.location,
        localStorage: window.localStorage,
        sessionStorage: window.sessionStorage,
        console,
        URL,
        FormData,
        Blob,
        WeakMap,
        WeakSet,
        JSON,
        Math,
        Date,
        String,
        Number,
        Object,
        Array,
        Error,
        isFinite,
        parseInt,
        parseFloat,
        encodeURIComponent,
        decodeURIComponent,
        // Timers are collected and never actually run: the tracker's periodic
        // flush and hover dwell are irrelevant here, and a live interval would
        // keep the process alive.
        setTimeout: (fn, ms) => {
            timers.push({ fn, ms });
            return timers.length;
        },
        clearTimeout: () => {},
        setInterval: (fn, ms) => {
            timers.push({ fn, ms, repeating: true });
            return timers.length;
        },
        clearInterval: () => {},
    };

    context.globalThis = context;
    window.fetch = (url, init) => {
        sent.push({ transport: 'fetch', url, body: init && init.body });
        return Promise.resolve({
            ok: true,
            status: 204,
            clone: () => ({ json: () => Promise.resolve({ success: true }) }),
            json: () => Promise.resolve({}),
        });
    };
    context.fetch = window.fetch;

    createContext(context);
    runInContext(readFileSync(TRACKER, 'utf8'), context, { filename: 'tracker.js' });

    /** Every event the tracker has tried to send, flattened. */
    const trackedEvents = () =>
        sent.flatMap(({ body }) => {
            const raw = typeof body === 'string' ? body : (body && body.__text) || '';
            try {
                return JSON.parse(raw).events || [];
            } catch {
                return [];
            }
        });

    return {
        window,
        document,
        forms,
        sent,
        timers,
        trackedEvents,
        /**
         * Runs the tracker's periodic flush, as the browser's timer would.
         *
         * Needed because track() only queues: a conversion flushes itself, but
         * an event like form_error waits for the interval. Without this a test
         * would see "no event recorded" and read it as a bug.
         */
        runTimers: () => {
            for (const timer of timers.filter((entry) => entry.repeating)) {
                timer.fn();
            }
        },
        /** Fires a CustomEvent-shaped event at the document, as a plugin would. */
        dispatch: (type, detail, target) =>
            document.dispatchEvent({ type, detail, target: target || document }),
    };
}
