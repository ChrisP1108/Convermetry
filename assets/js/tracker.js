/**
 * Convermetry — frontend tracker.
 *
 * Dependency-free visitor interaction tracker. Reads its configuration from
 * window.ConvermetryConfig (printed by ScriptLoader immediately before this
 * script), batches events in memory, and delivers them to the plugin's REST
 * endpoint via fetch (or navigator.sendBeacon on page exit). Every batch is
 * PERSISTED to a bounded sessionStorage store (keyed by batch id; an in-memory
 * store stands in when sessionStorage is unavailable) before it is sent, and
 * removed only when the server acknowledges it (2xx, or a 4xx — other than
 * 429 — that retrying cannot fix) — so a page destroyed before the response
 * arrives leaves the batch behind for the next page in this tab to resend.
 * One batch's success can never discard another's undelivered events.
 * Fetch-delivered batches are therefore at-least-once; the batch id is sent
 * in the request body, and the server stores rows under a unique
 * (batch id, ordinal) key, so a replayed batch whose response was lost is
 * deduplicated instead of double-counting. FRESH page-exit batches handed to
 * navigator.sendBeacon are the exception: an accepted hand-off only means
 * the browser queued the request, so those are treated as delivered
 * (best-effort) — keeping every one persisted would resend every exit batch
 * on the next page. But a batch that already survived a failed or
 * unacknowledged send stays persisted even through an accepted hand-off:
 * its loss is exactly what retrying exists to prevent, and the server-side
 * batch-id dedup absorbs the resend if the beacon did land.
 *
 * A failed retryable send (network error, 5xx, or 429) backs off with
 * increasing delay rather than retrying at the same flat interval every
 * time; a 429 additionally pauses every send from this tab for a short
 * window, since it means the server is asking this visitor's traffic to
 * slow down generally, not just reject one batch.
 *
 * Tracked interactions (each individually toggleable in plugin settings):
 *  - pageview     : one event per page load
 *  - click        : links, buttons, and elements with role="button"
 *  - form_submit  : native form submit events, captured before any handler
 *                   can preventDefault — note these are submission *attempts*
 *  - form_success : CONFIRMED form submissions — recorded only when the form
 *                   plugin reports that the server accepted the submission
 *                   (Elementor Pro, Contact Form 7, WPForms, Gravity Forms),
 *                   or when custom code dispatches a "convermetry:conversion" event.
 *                   Each carries a unique conversion id and a snapshot of the
 *                   session's campaign attribution at conversion time.
 *  - hover        : pointer resting on an interactive element for the
 *                   configured dwell time (once per element per page view);
 *                   add data-cvm-hover to opt any element — images included —
 *                   in explicitly
 *  - scroll_depth : 50 / 100% scroll milestones (once each per page view;
 *                   checked once on load so short pages record 100%)
 *
 * Campaign attribution: all six utm parameters (source/medium/campaign/id/
 * term/content) from a tagged landing URL are stored alongside the session
 * and attached to every event in that session — pageviews and conversions,
 * but also clicks, form attempts, hovers, and scroll milestones, so
 * intermediate funnel steps can be segmented by campaign. Ad-click
 * identifiers (gclid, fbclid, msclkid, …) are recognized too: only the
 * parameter NAME is kept (click_id_type) — the value is a cross-site
 * advertising ID and is never sent — and, when no utm tags are present, the
 * source/medium they imply is filled in (e.g. gclid → google / cpc). The
 * model is last-touch within the session: the most recent tagged landing
 * attributes the visit from that point on; untagged pages inherit it.
 *
 * Untagged acquisition persists too: the referrer the session ENTERED
 * through (e.g. google.com for an organic visit) is stored against the
 * session and sent as session_referrer, so a conversion three pages deep
 * into an organic visit is still classified Organic Search rather than
 * falling back to Direct. Like the campaign, an external re-entry within
 * the session refreshes it (last non-direct touch). A session that entered
 * with no referrer at all (Direct) sends an explicit session_direct marker
 * instead of an absent session_referrer, so the server can tell "verified
 * Direct" apart from "no signal sent" — otherwise mid-session events in a
 * Direct-entrance session would have no way to distinguish themselves from
 * an event that simply never reported its acquisition.
 *
 * Session ↔ submission correlation: at submit time (capture phase, before
 * any AJAX handler serializes the form) three hidden, internal fields are
 * injected/refreshed on the submitting form — cvm_conversion_id (a fresh
 * token per submission attempt), cvm_session_id (the current analytics
 * session), and cvm_context (a compact JSON snapshot of the session's
 * attribution, entrance referrer, landing page, and page URL). The
 * server-side form-provider integrations read those fields, strip them from
 * the submitted data, and record the confirmed conversion under the SAME
 * conversion id this tracker holds for the attempt — so the frontend
 * form_success event and the server-confirmed conversion deduplicate into
 * one conversion, and every webhook-delivered lead carries its full
 * analytics session and campaign attribution. Correlation is token-based;
 * timestamps are never used to match a submission to a session.
 *
 * Privacy: no cookies are set. The session identifier lives in localStorage
 * and rotates after 30 minutes of inactivity, so it groups one visit without
 * becoming a persistent user ID. Tracked URLs are canonicalized to
 * origin + path (utm parameters travel as separate fields; ad-click
 * identifier values are never sent, only which parameter was present);
 * referrers and click/form destinations are stripped of query strings and
 * fragments. Requests are sent without credentials, and visitors sending
 * Do Not Track / Global Privacy Control signals are skipped entirely when
 * the site has enabled that option.
 */
(function () {
    'use strict';

    const config = window.ConvermetryConfig;
    if (!config || !config.endpoint || !config.events) {
        return;
    }

    // Honor browser privacy signals when the site owner opted in.
    if (config.respectDnt && (
        navigator.doNotTrack === '1' ||
        window.doNotTrack === '1' ||
        navigator.globalPrivacyControl === true
    )) {
        return;
    }

    const MAX_BATCH = config.maxBatch || 20;
    const MAX_QUEUE = 100;
    const FLUSH_INTERVAL = config.flushIntervalMs || 5000;
    const HOVER_DWELL = config.hoverDwellMs || 800;
    const SESSION_IDLE_MS = 30 * 60 * 1000;
    const INTERACTIVE = 'a, button, input[type="button"], input[type="submit"], [role="button"]';
    const PENDING_KEY = 'cvm_pending';

    // Retry/backoff timing — internal reliability constants, not exposed as
    // site-owner settings. RETRY_BASE_MS matches the normal flush cadence (a
    // batch's first retry isn't penalized beyond it); RETRY_BASE_MS_429 starts
    // higher because a 429 means the limiter is already saturated. Jitter
    // (see scheduleBackoff()) spreads retries across tabs/sites instead of a
    // synchronized thundering herd when a shared server-side condition (a
    // brief outage) affects many visitors at once.
    const RETRY_BASE_MS = 5000;
    const RETRY_BASE_MS_429 = 30000;
    const RETRY_MAX_MS = 5 * 60 * 1000;

    /** utm parameter names captured from a tagged landing URL. */
    const UTM_KEYS = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_id', 'utm_term', 'utm_content'];

    /** Ad-click identifier parameters → the [source, medium] they imply when
     *  the URL carries no utm tags. Only the parameter NAME is ever sent
     *  (click_id_type); the value is a cross-site advertising identifier and
     *  never leaves the browser. fbclid implies no medium — Facebook adds it
     *  to organic shares too, so paid cannot be assumed. */
    const CLICK_IDS = {
        gclid:     ['google', 'cpc'],
        gbraid:    ['google', 'cpc'],
        wbraid:    ['google', 'cpc'],
        msclkid:   ['bing', 'cpc'],
        ttclid:    ['tiktok', 'paid'],
        twclid:    ['twitter', 'paid'],
        li_fat_id: ['linkedin', 'paid'],
        fbclid:    ['facebook', '']
    };

    /** Every field a stored campaign (and a conversion's snapshot) may carry. */
    const CAMPAIGN_KEYS = UTM_KEYS.concat(['click_id_type']);

    /** Event types a configured goal can be matched against, and therefore the
     *  only ones that carry the session's landing page for goal reporting. */
    const GOAL_ELIGIBLE = { pageview: true, click: true, custom_event: true };

    const queue = [];
    const PAGE_URL = location.origin + location.pathname;
    const REFERRER = normalizedReferrer();
    const CAMPAIGN = readCampaign();

    /** True when this page load entered the site from outside: no referrer
     *  (direct / stripped) or a referrer on another origin. */
    const IS_ENTRANCE = REFERRER === '' ||
        (REFERRER !== location.origin && REFERRER.indexOf(location.origin + '/') !== 0);

    /* ------------------------------------------------------------------ *
     *  Session identity — 30-minute inactivity window, cookie-free
     * ------------------------------------------------------------------ */

    const sessionStore = pickStore();
    let memorySession = null;

    /** Returns the first usable storage, preferring localStorage so the
     *  session spans tabs; sessionStorage keeps it per-tab; null falls back
     *  to an in-memory id for this page view only. */
    function pickStore() {
        const candidates = ['localStorage', 'sessionStorage'];
        for (let i = 0; i < candidates.length; i++) {
            try {
                const store = window[candidates[i]];
                store.setItem('cvm_probe', '1');
                store.removeItem('cvm_probe');
                return store;
            } catch (e) {
                // Blocked or full — try the next one.
            }
        }
        return null;
    }

    /**
     * Returns the current session id, rotating it when the visitor has been
     * inactive for 30+ minutes, and refreshes the activity timestamp — so the
     * session extends as long as events keep occurring.
     */
    function sessionId() {
        const now = Date.now();
        let id = null;

        if (sessionStore) {
            try {
                const raw = sessionStore.getItem('cvm_session');
                if (raw) {
                    const parts = raw.split('.');
                    if (parts.length === 2 && now - parseInt(parts[1], 10) < SESSION_IDLE_MS) {
                        id = parts[0];
                    }
                }
            } catch (e) {
                id = null;
            }
        } else {
            id = memorySession;
        }

        if (!id) {
            id = randomHex(32);
        }

        if (sessionStore) {
            try {
                sessionStore.setItem('cvm_session', id + '.' + now);
            } catch (e) {
                // Storage full or blocked mid-session; keep going in memory.
            }
        }
        memorySession = id;

        return id;
    }

    /**
     * The acquisition attributed to this session — {c: campaign fields,
     * r: entrance referrer} — last-touch within the session.
     *
     * A tagged landing URL wins and is persisted against the session id (the
     * most recent tagged landing re-attributes the session from that point
     * on). An untagged EXTERNAL entrance keeps the session's campaign but
     * refreshes the entrance referrer (last non-direct touch), so organic,
     * social, and referral acquisition survives internal navigation instead
     * of degrading to Direct at conversion time. Untagged internal pageviews
     * inherit whatever the session currently carries.
     */
    function sessionAcquisition(id) {
        let tagged = false;
        for (let i = 0; i < CAMPAIGN_KEYS.length; i++) {
            if (CAMPAIGN[CAMPAIGN_KEYS[i]]) {
                tagged = true;
                break;
            }
        }

        let stored = null;
        if (sessionStore) {
            try {
                stored = JSON.parse(sessionStore.getItem('cvm_campaign'));
            } catch (e) {
                stored = null;
            }
        }
        if (!stored || stored.id !== id || typeof stored.c !== 'object' || !stored.c) {
            stored = null;
        }

        let record;
        // 'l' is the landing page of the session's CURRENT attribution: the
        // page a tagged landing (or external re-entry) arrived on. Inherited
        // untouched across internal navigation, refreshed with the
        // attribution itself (last-touch), and carried into each form
        // submission's analytics context.
        if (tagged) {
            record = { id: id, c: CAMPAIGN, r: IS_ENTRANCE ? REFERRER : (stored ? String(stored.r || '') : ''), l: PAGE_URL };
        } else if (stored && (!IS_ENTRANCE || !REFERRER)) {
            return stored; // Internal navigation or a direct re-entry: inherit as-is.
        } else if (stored) {
            record = { id: id, c: stored.c, r: REFERRER, l: PAGE_URL }; // External re-entry refreshes the referrer.
        } else {
            record = { id: id, c: {}, r: IS_ENTRANCE ? REFERRER : '', l: PAGE_URL };
        }

        if (sessionStore) {
            try {
                sessionStore.setItem('cvm_campaign', JSON.stringify(record));
            } catch (e) {
                // Storage full or blocked — attribution lasts this page only.
            }
        }
        return record;
    }

    /** Generates a random lowercase hex string of the given length. */
    function randomHex(length) {
        let out = '';
        if (window.crypto && window.crypto.getRandomValues) {
            const bytes = new Uint8Array(length / 2);
            window.crypto.getRandomValues(bytes);
            for (let i = 0; i < bytes.length; i++) {
                out += ('0' + bytes[i].toString(16)).slice(-2);
            }
        } else {
            while (out.length < length) {
                out += Math.floor(Math.random() * 16).toString(16);
            }
        }
        return out;
    }

    /* ------------------------------------------------------------------ *
     *  URL hygiene — canonical URLs, separate campaign fields
     * ------------------------------------------------------------------ */

    /** Campaign parameters from the landing URL, as separate fields attached
     *  to pageview and conversion events (the tracked URL itself carries no
     *  query data). */
    function readCampaign() {
        const out = {};
        try {
            const params = new URLSearchParams(location.search);
            for (let i = 0; i < UTM_KEYS.length; i++) {
                const value = params.get(UTM_KEYS[i]);
                if (value) {
                    out[UTM_KEYS[i]] = value.slice(0, 190);
                }
            }
            for (const key in CLICK_IDS) {
                if (CLICK_IDS.hasOwnProperty(key) && params.get(key)) {
                    out.click_id_type = key;
                    if (!out.utm_source) {
                        out.utm_source = CLICK_IDS[key][0];
                        if (!out.utm_medium && CLICK_IDS[key][1]) {
                            out.utm_medium = CLICK_IDS[key][1];
                        }
                    }
                    break;
                }
            }
        } catch (e) {
            // URLSearchParams unavailable — no campaign attribution.
        }
        return out;
    }

    /** This page load's applied acquisition — sessionAcquisition() is run
     *  once per session id so the landing URL's tags (or entrance referrer)
     *  are written into the session exactly once, then kept as a fallback
     *  for when storage becomes unreadable mid-page. */
    let appliedAcquisition = { id: null, record: { c: {}, r: '' } };

    /**
     * The session's current acquisition record. The first event for a given
     * session id applies this page load's landing attribution via
     * sessionAcquisition(); later events RE-READ the stored record instead
     * of trusting a memo — another tab sharing the localStorage session may
     * have re-attributed it (last touch) in the meantime, and a stale memo
     * would keep crediting the old campaign from this tab.
     */
    function currentAcquisition(id) {
        if (appliedAcquisition.id !== id) {
            appliedAcquisition = { id: id, record: sessionAcquisition(id) };
            return appliedAcquisition.record;
        }

        let stored = null;
        if (sessionStore) {
            try {
                stored = JSON.parse(sessionStore.getItem('cvm_campaign'));
            } catch (e) {
                stored = null;
            }
        }
        if (stored && stored.id === id && typeof stored.c === 'object' && stored.c) {
            return stored;
        }
        return appliedAcquisition.record;
    }

    /** A fresh copy of the session's current attribution (campaign fields
     *  plus session_referrer or session_direct), safe to attach to an event
     *  (track() adds page context to the object it is given, so the stored
     *  record itself must never be passed in). */
    function campaignSnapshot() {
        const record = currentAcquisition(sessionId());

        const campaign = record.c || {};
        const out = {};
        for (let i = 0; i < CAMPAIGN_KEYS.length; i++) {
            if (campaign[CAMPAIGN_KEYS[i]]) {
                out[CAMPAIGN_KEYS[i]] = campaign[CAMPAIGN_KEYS[i]];
            }
        }
        if (record.r) {
            out.session_referrer = String(record.r);
        } else if (Object.keys(out).length === 0) {
            // Untagged session confirmed Direct (no external referrer ever
            // recorded) — an explicit marker, not merely an absent
            // session_referrer, so the server can tell "verified Direct"
            // apart from "no signal sent" (e.g. an old cached tracker).
            out.session_direct = '1';
        }
        return out;
    }

    /** Referrer reduced to origin + path; '' when absent or unparsable. */
    function normalizedReferrer() {
        if (!document.referrer) {
            return '';
        }
        try {
            const ref = new URL(document.referrer);
            return ref.origin + ref.pathname;
        } catch (e) {
            return '';
        }
    }

    /** Destination URL with query string and fragment removed (they can carry
     *  tokens or emails); mailto:/tel: destinations are kept whole. */
    function cleanTarget(url) {
        if (!url) {
            return '';
        }
        if (/^(mailto:|tel:)/i.test(url)) {
            return String(url).slice(0, 255);
        }
        return String(url).split('#')[0].split('?')[0];
    }

    /* ------------------------------------------------------------------ *
     *  Event queue and delivery
     * ------------------------------------------------------------------ */

    /** Collapses whitespace and truncates a label to a storable length. */
    function cleanLabel(text) {
        return (text || '').replace(/\s+/g, ' ').trim().slice(0, 120);
    }

    /** Best human-readable label for an element: aria-label > text/value > id. */
    function labelFor(el) {
        return cleanLabel(el.getAttribute('aria-label'))
            || cleanLabel(el.innerText || el.value)
            || cleanLabel(el.id);
    }

    /** True when the element lives inside the WP admin bar (never tracked). */
    function inAdminBar(el) {
        return !!(el.closest && el.closest('#wpadminbar'));
    }

    /**
     * Queues one event if its type is enabled, flushing when the batch is
     * full. Page context, the session id, and the session's attribution
     * snapshot are attached here so callers only supply the event-specific
     * fields — every event type can be segmented by campaign and channel.
     */
    function track(type, data) {
        if (!config.events[type]) {
            return;
        }

        const event = data || {};
        const attribution = campaignSnapshot();
        for (const key in attribution) {
            if (attribution.hasOwnProperty(key) && !event[key]) {
                event[key] = attribution[key];
            }
        }
        event.type = type;
        event.page_url = PAGE_URL;
        event.page_title = document.title || '';
        event.referrer = REFERRER;
        event.session_id = sessionId();

        // The session's landing page rides along on the three event types a
        // goal can be matched against, so a goal completion can record where the
        // visit started without every event in the table carrying a column that
        // only goal reporting reads. The server writes it onto the completion
        // and never onto the event row.
        if (GOAL_ELIGIBLE[type]) {
            const acquisition = currentAcquisition(event.session_id);
            if (acquisition && acquisition.l) {
                event.session_landing = String(acquisition.l);
            }
        }

        queue.push(event);

        if (queue.length >= MAX_BATCH) {
            flush('normal');
        }
    }

    /* Batches persist in a sessionStorage-backed store, keyed by batch id,
     * BEFORE they are sent, so they survive the page being destroyed before
     * the server's response ever arrives (persist-first, remove-on-
     * acknowledgment). Each entry is removed only when ITS OWN send is
     * acknowledged — a single shared store cleared on any success would let
     * batch B's success discard batch A's still-undelivered events when two
     * sends overlap. Total stashed events are bounded by MAX_QUEUE (oldest
     * batches dropped first). When sessionStorage is unavailable (blocked,
     * private mode quirks), an in-memory root takes over: retries then only
     * last this page's lifetime, but failed batches are still resent by
     * later flushes instead of being lost the moment the send fails.
     *
     * The store is a single serialized root — batches (each with its own
     * events plus its own retry attempt count and not-before timestamp) and
     * the tab-wide 429 pause together — not several independently-written
     * pieces. Splitting these across separate keys would let one write
     * succeed while another fails (e.g. quota exceeded in between), leaving
     * a batch persisted with no matching backoff history or a pause that
     * only half-applied; one root means one write settles all of it. */

    /** sessionStorage when usable, else null (in-memory root instead). */
    let pendingStore = (function () {
        try {
            window.sessionStorage.setItem('cvm_probe', '1');
            window.sessionStorage.removeItem('cvm_probe');
            return window.sessionStorage;
        } catch (e) {
            return null;
        }
    })();

    /** In-memory root, used when sessionStorage is unusable — the same shape
     *  as the persisted root so both paths share one implementation. */
    let memoryRoot = { version: 1, globalNotBefore: 0, batches: {} };

    /**
     * Reads the single serialized root. Migrates the pre-existing bare
     * {id: events[]} shape (an already-open tab from before this format
     * existed) into the current shape on first read, rather than assuming
     * the new shape and reading undefined properties off old data.
     *
     * @returns {{version: number, globalNotBefore: number, batches: Object}}
     */
    function readStore() {
        if (!pendingStore) {
            return memoryRoot;
        }
        try {
            const raw = JSON.parse(pendingStore.getItem(PENDING_KEY));
            if (!raw || typeof raw !== 'object' || Array.isArray(raw)) {
                return { version: 1, globalNotBefore: 0, batches: {} };
            }
            if (raw.version === 1 && raw.batches && typeof raw.batches === 'object') {
                return raw;
            }

            const batches = {};
            for (const id in raw) {
                if (Object.prototype.hasOwnProperty.call(raw, id) && Array.isArray(raw[id])) {
                    batches[id] = { events: raw[id], attempts: 0, notBefore: 0 };
                }
            }
            return { version: 1, globalNotBefore: 0, batches: batches };
        } catch (e) {
            return { version: 1, globalNotBefore: 0, batches: {} };
        }
    }

    /** Persists the root, falling back to memory (and staying there for the
     *  rest of this page) if sessionStorage rejects the write. */
    function writeStore(root) {
        if (!pendingStore) {
            memoryRoot = root;
            return;
        }
        try {
            if (Object.keys(root.batches).length === 0 && !root.globalNotBefore) {
                pendingStore.removeItem(PENDING_KEY);
            } else {
                pendingStore.setItem(PENDING_KEY, JSON.stringify(root));
            }
        } catch (e) {
            // sessionStorage filled up or got blocked mid-page — fall back to
            // memory from here on so batches and their backoff metadata keep
            // being tracked together. The server's batch-id dedup absorbs
            // any overlap with entries that did make it into sessionStorage
            // earlier.
            memoryRoot   = root;
            pendingStore = null;
        }
    }

    /** Records/updates one batch's events under its id, preserving that
     *  batch's existing retry metadata if it already had any (this is called
     *  only for genuinely fresh batches — see sendBatch()), and dropping
     *  oldest batches when the total stashed events would exceed MAX_QUEUE. */
    function stashBatch(id, events) {
        const root = readStore();
        const existing = root.batches[id];
        root.batches[id] = {
            events: events,
            attempts: existing ? existing.attempts : 0,
            notBefore: existing ? existing.notBefore : 0
        };

        const ids = Object.keys(root.batches);
        let total = 0;
        for (let i = 0; i < ids.length; i++) {
            total += root.batches[ids[i]].events.length;
        }
        while (total > MAX_QUEUE && ids.length > 1) {
            const oldest = ids.shift();
            total -= root.batches[oldest].events.length;
            delete root.batches[oldest];
        }

        writeStore(root);
    }

    /** Removes one acknowledged batch — events and retry metadata together,
     *  and only that batch — from the store. */
    function unstashBatch(id) {
        const root = readStore();
        if (root.batches[id]) {
            delete root.batches[id];
            writeStore(root);
        }
    }

    /** Whether sends for this batch id are currently paused — either a
     *  tab-wide pause (set by any 429) or this specific batch's own backoff
     *  window. Pure read; never writes, so merely checking eligibility never
     *  triggers a re-serialization of the whole store. */
    function isPaused(id, root) {
        const r = root || readStore();
        const now = Date.now();
        if ((r.globalNotBefore || 0) > now) {
            return true;
        }
        const entry = r.batches[id];
        return !!(entry && entry.notBefore > now);
    }

    /** Computes and records the next backoff delay for one batch after a
     *  retryable failure, folding a 429's tab-wide pause into the same write
     *  when applicable — a 429 means the server is asking every batch from
     *  this tab to slow down, not just this one. When the server sent a
     *  Retry-After value alongside a 429, that's honored directly instead of
     *  the client-guessed exponential value — the server is the one place
     *  that actually knows how long its own limit window is. */
    function scheduleBackoff(id, is429, retryAfterSeconds) {
        const root = readStore();
        const entry = root.batches[id];
        if (!entry) {
            return; // Already removed elsewhere (e.g. a permanent 4xx raced this) — nothing to back off.
        }

        const attempts = (entry.attempts || 0) + 1;
        let notBefore;

        if (is429 && typeof retryAfterSeconds === 'number' && retryAfterSeconds > 0) {
            notBefore = Date.now() + Math.min(RETRY_MAX_MS, retryAfterSeconds * 1000);
        } else {
            const base = is429 ? RETRY_BASE_MS_429 : RETRY_BASE_MS;
            const cap = Math.min(RETRY_MAX_MS, base * Math.pow(2, attempts - 1));
            notBefore = Date.now() + Math.random() * cap;
        }

        entry.attempts = attempts;
        entry.notBefore = notBefore;

        if (is429) {
            root.globalNotBefore = Math.max(root.globalNotBefore || 0, notBefore);
        }

        writeStore(root);
    }

    /** Batch ids in flight right now, so a slow retry isn't sent twice. */
    const inFlight = {};

    /** Batch ids known to have survived a failed or unacknowledged send —
     *  either they failed retryably on THIS page (network error, 5xx, 429,
     *  503) or they were inherited from the store (their sender never saw a
     *  response). These stay persisted even through an accepted page-exit
     *  beacon hand-off: the beacon's response is never observable, and for a
     *  batch that already failed once "accepted" must not mean "delivered" —
     *  the server's batch-id dedup absorbs the resend if the beacon did
     *  land. Fresh exit batches keep best-effort hand-off semantics so
     *  ordinary navigation doesn't replay every batch. */
    const retryPending = {};

    /**
     * Sends one batch under its id.
     *
     * A genuinely fresh batch (never before in the store) is persisted
     * BEFORE the backoff gate is checked, so a visitor who navigates away
     * during a pause never loses data that was only ever in the in-memory
     * queue. A batch already sitting in the store (a retry) is durable
     * already — it is NOT re-stashed on every gate check, so a paused
     * backlog doesn't get the whole store rewritten to sessionStorage on
     * every periodic tick for nothing.
     *
     * The tab-scoped pause applies to every send except one narrow case: the
     * current page's fresh queue contents get a best-effort attempt at
     * lifecycle-exit moments (pagehide/visibilitychange-hidden) regardless
     * of an active pause, since sessionStorage — and the pause recorded in
     * it — persists across ordinary navigation but not past an actual tab
     * close, and this is the one chance to get new data out before that.
     * Already-persisted batches are NOT granted this exception even at those
     * same moments — resending the whole backed-off backlog on every
     * pagehide/visibilitychange would defeat the pause entirely, since those
     * events fire far more often than an actual tab closing.
     *
     * The persisted entry is removed only on acknowledgment: a 2xx response,
     * or a 4xx that retrying cannot fix (429 is rate limiting, not
     * rejection — the batch stays persisted and backs off). A network
     * error, 5xx, or 429 schedules an increasing backoff delay for a later
     * flush (this page or the next one in this tab) to retry.
     *
     * The batch id travels in the request body: the server keys stored rows
     * by (batch id, event ordinal), so a replay of an already-stored batch —
     * delivered, but its response lost — is deduplicated server-side rather
     * than inflating every count.
     *
     * Transport: sendBeacon is used for both lifecycle-exit signals
     * (pagehide/visibilitychange-hidden) AND navigation-interaction signals
     * (click/submit) today — a deliberate, revisitable choice. Switching
     * click/submit to a keepalive fetch would make 429/5xx failures on those
     * paths observable (currently a fresh beacon's "accepted" is trusted as
     * delivered even if the server actually rejected it), but trades that
     * for a different risk: an async fetch response racing a fast
     * navigation can mean the batch gets resent even though it actually
     * succeeded (safe, thanks to server-side dedup, but wasteful). Without a
     * way to measure the real duplicate-request/429 impact on this
     * install, sendBeacon stays in place for both; revisit with real
     * request-level measurement before changing it.
     */
    function sendBatch(id, events, mode) {
        let root = readStore();
        const alreadyPersisted = !!root.batches[id];

        if (!alreadyPersisted) {
            stashBatch(id, events);
            root = readStore();
        }

        const bypassGate = !alreadyPersisted && mode === 'lifecycle-exit';

        if (!bypassGate && isPaused(id, root)) {
            return; // Gated — already durably persisted above if this was fresh; a later flush retries it.
        }

        if (bypassGate) {
            // This fresh batch is being sent despite an active pause; the
            // beacon path below gives no visibility into whether the server
            // actually accepts it, so treat it as already-failed-once up
            // front — it stays persisted rather than being wrongly unstashed
            // on an accepted-but-unconfirmed hand-off.
            retryPending[id] = true;
        }

        inFlight[id] = true;

        const body = JSON.stringify({ batch_id: id, events: events });

        if ((mode === 'lifecycle-exit' || mode === 'navigation-interaction') && navigator.sendBeacon) {
            let accepted = false;
            try {
                accepted = navigator.sendBeacon(
                    config.endpoint,
                    new Blob([body], { type: 'application/json' })
                );
            } catch (e) {
                accepted = false;
            }
            if (accepted) {
                if (!retryPending[id]) {
                    unstashBatch(id);
                }
                delete inFlight[id];
                return;
            }
            // Beacon refused the payload — fall through to a keepalive fetch.
        }

        try {
            fetch(config.endpoint, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: body,
                credentials: 'omit',
                keepalive: true
            }).then(function (response) {
                if (response.ok || (response.status >= 400 && response.status < 500 && response.status !== 429)) {
                    unstashBatch(id); // Acknowledged, or unfixable by retry.
                    delete retryPending[id];
                } else {
                    const is429 = response.status === 429;
                    let retryAfter = null;
                    if (is429 && response.headers && response.headers.get) {
                        const parsed = parseInt(response.headers.get('Retry-After'), 10);
                        retryAfter = isNaN(parsed) ? null : parsed;
                    }
                    scheduleBackoff(id, is429, retryAfter); // 5xx/429 — retryable failure.
                    retryPending[id] = true;
                }
                delete inFlight[id];
            }).catch(function () {
                scheduleBackoff(id, false, null);
                retryPending[id] = true;
                delete inFlight[id]; // Network error — stays persisted.
            });
        } catch (e) {
            scheduleBackoff(id, false, null);
            retryPending[id] = true;
            delete inFlight[id]; // fetch unavailable/threw — stays persisted.
        }
    }

    /**
     * Sends the queued events as a new batch and resends any stashed batches
     * (each under its original id) — including batches left behind by a
     * previous page in this tab.
     */
    function flush(mode) {
        const root = readStore();
        for (const id in root.batches) {
            if (Object.prototype.hasOwnProperty.call(root.batches, id) && !inFlight[id]) {
                // Anything still in the store at resend time was never
                // acknowledged — mark it so an exit-time beacon hand-off
                // can't be its last trace (see sendBatch()), regardless of
                // which page's in-memory state (retryPending resets on
                // navigation) is now processing it.
                retryPending[id] = true;
                sendBatch(id, root.batches[id].events, mode);
            }
        }

        if (queue.length === 0) {
            return;
        }

        // 'b' prefix: purely-numeric keys would be reordered by the JS
        // engine, breaking oldest-first eviction in stashBatch().
        sendBatch('b' + randomHex(12), queue.splice(0, queue.length), mode);
    }

    /* ------------------------------------------------------------------ *
     *  Page views — carry the session's campaign attribution (attached by
     *  track(), like every other event)
     * ------------------------------------------------------------------ */

    track('pageview', {});

    /* ------------------------------------------------------------------ *
     *  Clicks — links, buttons, and role="button" elements
     * ------------------------------------------------------------------ */

    document.addEventListener('click', function (e) {
        const el = e.target && e.target.closest ? e.target.closest(INTERACTIVE) : null;
        if (!el || inAdminBar(el)) {
            return;
        }

        const clickEvent = {
            element_tag: el.tagName.toLowerCase(),
            element_label: labelFor(el),
            target_url: cleanTarget(el.href)
        };

        // CSS-selector goals are the one rule the server cannot evaluate — it
        // has the destination URL but not the DOM. The ids reported here are a
        // CLAIM, not a decision: the server re-checks each one against the
        // actually-configured, actually-enabled selector goals before recording
        // anything, so this channel cannot be used to invent a conversion or to
        // reach a goal of any other kind.
        const goalHits = matchedSelectorGoals(el);
        if (goalHits.length) {
            clickEvent.selector_goals = goalHits;
        }

        track('click', clickEvent);

        // A link click may navigate away immediately — get the batch out now.
        if (el.href) {
            flush('navigation-interaction');
        }
    }, true);

    /* ------------------------------------------------------------------ *
     *  Session ↔ submission correlation — hidden internal fields injected
     *  into forms so the server-side form-provider integrations receive the
     *  analytics session id, a per-attempt conversion token, and the
     *  session's attribution snapshot alongside the submission itself. The
     *  server strips these fields from the submitted data before storing or
     *  delivering it.
     * ------------------------------------------------------------------ */

    const FIELD_CONVERSION = 'cvm_conversion_id';
    const FIELD_SESSION = 'cvm_session_id';
    const FIELD_CONTEXT = 'cvm_context';

    /** Conversion token per form ELEMENT for the current submission attempt,
     *  so the provider's success event reuses the exact token the server
     *  received — one conversion id across both detection paths. */
    const formTokens = (typeof WeakMap === 'function') ? new WeakMap() : null;

    /** Gravity Forms tokens keyed by numeric form id — its confirmation
     *  event only reports the form id, and (with AJAX enabled) the original
     *  form element is replaced by the confirmation markup. */
    const gfTokens = {};

    /** Creates or updates one hidden input on a form. */
    function setHiddenField(form, name, value) {
        let input = null;
        try {
            const candidates = form.querySelectorAll('input[name="' + name + '"]');
            input = candidates.length ? candidates[0] : null;
        } catch (e) {
            input = null;
        }

        if (!input) {
            input = document.createElement('input');
            input.type = 'hidden';
            input.name = name;
            form.appendChild(input);
        }
        input.value = value;
    }

    /** The compact attribution snapshot serialized into cvm_context. */
    function correlationContext() {
        const record = currentAcquisition(sessionId());
        const campaign = record.c || {};
        const out = {};

        for (let i = 0; i < CAMPAIGN_KEYS.length; i++) {
            if (campaign[CAMPAIGN_KEYS[i]]) {
                out[CAMPAIGN_KEYS[i]] = campaign[CAMPAIGN_KEYS[i]];
            }
        }
        if (record.r) {
            out.session_referrer = String(record.r);
        } else if (Object.keys(out).length === 0) {
            out.session_direct = '1';
        }
        out.landing_page = String(record.l || PAGE_URL);
        out.page_url = PAGE_URL;

        return out;
    }

    /**
     * Injects/refreshes the correlation fields on one form and returns the
     * conversion token used. A fresh token is generated per submission
     * attempt (fresh=true, from the submit listener); pre-seeding at page
     * load (fresh=false) reuses any token the form already has so a
     * submission that bypasses the submit event still carries stable fields.
     */
    function ensureCorrelationFields(form, fresh) {
        if (!form || form.tagName !== 'FORM') {
            return null;
        }

        const existing = formTokens ? formTokens.get(form) : null;
        const token = (!fresh && existing) ? existing : ('c' + randomHex(16));
        if (formTokens) {
            formTokens.set(form, token);
        }

        setHiddenField(form, FIELD_CONVERSION, token);
        setHiddenField(form, FIELD_SESSION, sessionId());
        try {
            setHiddenField(form, FIELD_CONTEXT, JSON.stringify(correlationContext()));
        } catch (e) {
            // JSON serialization failed — the token and session still travel.
        }

        const gfMatch = /^gform_(\d+)$/.exec(form.id || '');
        if (gfMatch) {
            gfTokens[gfMatch[1]] = token;
        }

        return token;
    }

    /** Seeds correlation fields into every form currently in the DOM, so
     *  non-AJAX submissions (and plugins that serialize early) carry them
     *  even if the submit-capture refresh never runs. */
    function seedCorrelationFields() {
        const forms = document.querySelectorAll('form');
        for (let i = 0; i < forms.length; i++) {
            if (!inAdminBar(forms[i])) {
                ensureCorrelationFields(forms[i], false);
            }
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', seedCorrelationFields);
    } else {
        seedCorrelationFields();
    }
    window.addEventListener('load', seedCorrelationFields);

    /* ------------------------------------------------------------------ *
     *  Form submissions — captured before any handler can preventDefault.
     *  Recorded at submit time, so these are attempts, not confirmed
     *  successes (client validation or the server may still reject them).
     * ------------------------------------------------------------------ */

    document.addEventListener('submit', function (e) {
        const form = e.target;
        if (!form || form.tagName !== 'FORM' || inAdminBar(form)) {
            return;
        }

        // Refresh the correlation fields with a fresh conversion token for
        // THIS attempt — capture phase runs before the form plugin's own
        // submit handler serializes the form, so the AJAX request carries
        // the fields.
        ensureCorrelationFields(form, true);

        track('form_submit', {
            element_tag: 'form',
            element_label: cleanLabel(form.getAttribute('name') || form.id || form.getAttribute('aria-label')) || 'form',
            target_url: cleanTarget(form.getAttribute('action'))
        });

        // A submit is also the moment jQuery (if used at all) has almost
        // certainly finished loading, even past the fixed attempts below —
        // catches a delayed-jQuery scenario (a defer/async optimizer
        // plugin). Idempotent (see jQueryBound below), costs nothing once
        // already bound.
        bindJQueryFormEvents();

        // The submit may navigate away — get the batch out now.
        flush('navigation-interaction');
    }, true);

    /* ------------------------------------------------------------------ *
     *  Confirmed conversions — recorded only when the form plugin reports
     *  that the SERVER accepted the submission (its success event fires on
     *  the AJAX success response), unlike form_submit attempts above.
     *  Each conversion carries a unique id (event_value) so an at-least-once
     *  redelivery can be deduplicated, plus a snapshot of the session's
     *  campaign attribution at the moment of conversion — the conversion
     *  record is self-contained even days after the tagged landing.
     * ------------------------------------------------------------------ */

    function trackConversion(label, token) {
        // Reuse the submission attempt's correlation token when the success
        // event could be tied back to its form — the server-side provider
        // hook records the conversion under the same id, so the two paths
        // deduplicate into one conversion everywhere.
        const valid = typeof token === 'string' && /^[A-Za-z0-9_.:\-]{8,100}$/.test(token);

        track('form_success', {
            element_tag: 'form',
            element_label: cleanLabel(label) || 'form',
            event_value: valid ? token : 'c' + randomHex(16)
        });
        flush('normal');
    }

    /** The form element a plugin's success event fired on, or null. */
    function eventForm(e) {
        return e.target && e.target.tagName === 'FORM' ? e.target : null;
    }

    /** The correlation token remembered for a form element, or null. */
    function tokenFor(form) {
        return (form && formTokens) ? (formTokens.get(form) || null) : null;
    }

    // Contact Form 7 — native DOM event, fired after the server confirms.
    // The event target is the .wpcf7 wrapper; the form sits inside it.
    document.addEventListener('wpcf7mailsent', function (e) {
        const id = e.detail && e.detail.contactFormId;
        const wrapper = e.target;
        let form = null;
        if (wrapper) {
            form = wrapper.tagName === 'FORM' ? wrapper : (wrapper.querySelector ? wrapper.querySelector('form') : null);
        }
        trackConversion(id ? 'cf7-' + id : 'cf7', tokenFor(form));
    });

    // Custom goals: dispatch from your own code when a conversion completes —
    // document.dispatchEvent(new CustomEvent('convermetry:conversion', {detail: {name: 'appointment_booked'}}))
    // Pass detail.conversion_id to correlate with a server-side record.
    document.addEventListener('convermetry:conversion', function (e) {
        trackConversion(
            (e.detail && e.detail.name) || 'custom',
            (e.detail && e.detail.conversion_id) || null
        );
    });

    // Elementor Pro, WPForms, and Gravity Forms announce success through
    // jQuery events, which plain addEventListener cannot observe. jQuery is
    // present when those plugins run their frontends, but script optimizers
    // can load it AFTER this tracker — so binding is retried at DOM-ready,
    // window load, a couple of timed fallbacks, AND on every native form
    // submit (above), rather than being checked only within a fixed window.
    let jQueryBound = false;

    function bindJQueryFormEvents() {
        if (jQueryBound || !window.jQuery) {
            return;
        }
        jQueryBound = true;

        window.jQuery(document).on('submit_success', function (e) {
            const form = eventForm(e);
            trackConversion((form && (form.getAttribute('name') || form.id)) || 'elementor-form', tokenFor(form));
        });

        window.jQuery(document).on('wpformsAjaxSubmitSuccess', function (e) {
            const form = eventForm(e);
            trackConversion((form && (form.getAttribute('name') || form.id)) || 'wpforms', tokenFor(form));
        });

        window.jQuery(document).on('gform_confirmation_loaded', function (e, formId) {
            trackConversion('gravity-form-' + formId, gfTokens[String(formId)] || null);
        });
    }

    bindJQueryFormEvents();
    if (!jQueryBound) {
        document.addEventListener('DOMContentLoaded', bindJQueryFormEvents);
        window.addEventListener('load', bindJQueryFormEvents);
        setTimeout(bindJQueryFormEvents, 3000);
        setTimeout(bindJQueryFormEvents, 8000);
    }

    /* ------------------------------------------------------------------ *
     *  Form engagement — the path between seeing a form and submitting it
     *
     *  Four signals, each fired at most once per form per page view:
     *
     *    form_view   the form scrolled into view (not merely rendered — a
     *                form in the footer of a long page was never "seen")
     *    form_start  the visitor did something meaningful in it
     *    form_error  a field failed validation
     *    form_submit an attempt (already tracked above)
     *
     *  PRIVACY: none of these ever carries a field's VALUE. form_error reports
     *  the field's id, its type, and which ValidityState flag failed — all
     *  developer-chosen or browser-derived, none of it typed by the visitor.
     *  The server independently rebuilds the event from those three whitelisted
     *  pieces and discards everything else, so this is belt and braces.
     *
     *  FORM IDENTITY: form_key ties these browser observations to the
     *  server-confirmed submission for the same form. It is read from a
     *  data-cvm-form-key attribute the server renders where the form plugin
     *  offers a filter, and otherwise derived from the form's own markup. It is
     *  deliberately '' when neither is available: a wrong key is worse than an
     *  absent one, because it would silently attribute one form's abandonment
     *  to another.
     * ------------------------------------------------------------------ */

    const FORM_ATTR = 'data-cvm-form-key';
    const MAX_ERRORS_PER_FORM = 10;

    /** Per-form state for this page view: {viewed, started, errors}. */
    const formState = (typeof WeakMap === 'function') ? new WeakMap() : null;

    /** Returns (creating if needed) the tracking state for one form. */
    function stateFor(form) {
        if (!formState) {
            return null;
        }
        let state = formState.get(form);
        if (!state) {
            state = { viewed: false, started: false, errors: 0 };
            formState.set(form, state);
        }
        return state;
    }

    /**
     * The provider-qualified identity of a form.
     *
     * Prefers the server-rendered attribute, which is authoritative because the
     * server used the same key when it recorded the submission. The DOM
     * fallbacks cover providers with no usable render filter; each mirrors the
     * native id that provider's server-side integration keys on.
     *
     * Elementor is deliberately absent. Its server side currently keys per-form
     * settings by the form's NAME while the DOM exposes the widget id, so a
     * derived key would look correct and never join to anything. Reporting at
     * page level with an honest gap beats a number that silently means nothing.
     */
    function formIdentity(form) {
        const declared = form.getAttribute(FORM_ATTR);
        if (declared) {
            return String(declared).slice(0, 191);
        }

        let match;

        // Gravity Forms — <form id="gform_7">
        match = /^gform_(\d+)$/.exec(form.id || '');
        if (match) {
            return 'gravityforms:' + match[1];
        }

        // WPForms — <form id="wpforms-form-123">
        match = /^wpforms-form-(\d+)$/.exec(form.id || '');
        if (match) {
            return 'wpforms:' + match[1];
        }

        // Contact Form 7 — hidden _wpcf7 input carries the post id.
        const cf7 = form.querySelector('input[name="_wpcf7"]');
        if (cf7 && cf7.value) {
            return 'contactform7:' + cf7.value;
        }

        // Fluent Forms — data-form_id on the form element.
        const fluent = form.getAttribute('data-form_id');
        if (fluent) {
            return 'fluentforms:' + fluent;
        }

        // Ninja Forms — <form id="nf-form-3-cont"> or a hidden formId input.
        match = /^nf-form-(\d+)-cont$/.exec(form.id || '');
        if (match) {
            return 'ninjaforms:' + match[1];
        }

        // Formidable — <form id="form_contactform"> plus a hidden form_id.
        const frm = form.querySelector('input[name="form_id"]');
        if (frm && frm.value && /formidable|frm_/.test(form.className || '')) {
            return 'formidableforms:' + frm.value;
        }

        return '';
    }

    /** A readable name for a form, for report rows. */
    function formLabel(form) {
        return cleanLabel(
            form.getAttribute('data-cvm-form-name') ||
            form.getAttribute('name') ||
            form.id ||
            form.getAttribute('aria-label')
        ) || 'form';
    }

    /** Common fields for every form lifecycle event. */
    function formEventData(form) {
        return {
            element_tag: 'form',
            element_label: formLabel(form),
            form_key: formIdentity(form)
        };
    }

    /** Whether a form is one this tracker should observe at all. */
    function trackableForm(form) {
        return form && form.tagName === 'FORM' && !inAdminBar(form) &&
            !form.hasAttribute('data-cvm-ignore');
    }

    /* form_view — an IntersectionObserver, so a form below the fold only
     * counts once it is actually on screen. Without that, "start rate" would be
     * measured against a denominator that includes every footer form nobody
     * ever scrolled to, and the number would look alarming for no reason.
     * Browsers without IntersectionObserver simply record no views (rather than
     * recording every render), so the rate stays honest where it exists. */
    const formObserver = (typeof IntersectionObserver === 'function')
        ? new IntersectionObserver(function (entries) {
            for (let i = 0; i < entries.length; i++) {
                const entry = entries[i];
                if (!entry.isIntersecting) {
                    continue;
                }
                const form = entry.target;
                const state = stateFor(form);
                if (state && !state.viewed) {
                    state.viewed = true;
                    track('form_view', formEventData(form));
                }
                formObserver.unobserve(form);
            }
        }, { threshold: 0.25 })
        : null;

    /** Begins observing every form currently in the DOM. Idempotent. */
    function observeForms() {
        const forms = document.querySelectorAll('form');
        for (let i = 0; i < forms.length; i++) {
            const form = forms[i];
            if (!trackableForm(form)) {
                continue;
            }
            const state = stateFor(form);
            if (formObserver && state && !state.viewed && !state.observed) {
                state.observed = true;
                formObserver.observe(form);
            }
        }
    }

    /* form_start — the first MEANINGFUL interaction, which is deliberately not
     * focus. Focus fires when a visitor tabs through a page or a browser
     * autofocuses a field, neither of which is "started filling this in". The
     * first input/change event is, and firing once per form per page view means
     * a long form produces one event rather than one per keystroke. */
    function markStarted(form) {
        const state = stateFor(form);
        if (!state || state.started) {
            return;
        }
        state.started = true;

        // A form the visitor starts filling has definitionally been seen, even
        // if the observer never fired (no IntersectionObserver, or the form was
        // inserted already in view). Recording the view here keeps start rate
        // from exceeding 100%.
        if (!state.viewed) {
            state.viewed = true;
            track('form_view', formEventData(form));
        }

        track('form_start', formEventData(form));
    }

    document.addEventListener('input', function (e) {
        const form = e.target && e.target.form;
        if (trackableForm(form)) {
            markStarted(form);
        }
    }, true);

    document.addEventListener('change', function (e) {
        const form = e.target && e.target.form;
        if (trackableForm(form)) {
            markStarted(form);
        }
    }, true);

    /* form_error — the native constraint-validation event. It reports WHICH
     * flag failed and nothing about the value, which is exactly the boundary
     * this feature has to respect. Providers that render errors purely in their
     * own JavaScript will not fire it; that gap is documented rather than
     * papered over with per-provider DOM scraping. */
    const VALIDITY_FLAGS = [
        ['valueMissing', 'required'],
        ['typeMismatch', 'type_mismatch'],
        ['patternMismatch', 'pattern'],
        ['tooShort', 'too_short'],
        ['tooLong', 'too_long'],
        ['rangeUnderflow', 'range'],
        ['rangeOverflow', 'range'],
        ['stepMismatch', 'step']
    ];

    /** The failing ValidityState flag, as a stable category name. */
    function errorCategory(field) {
        const validity = field.validity;
        if (!validity) {
            return 'invalid';
        }
        for (let i = 0; i < VALIDITY_FLAGS.length; i++) {
            if (validity[VALIDITY_FLAGS[i][0]]) {
                return VALIDITY_FLAGS[i][1];
            }
        }
        return 'invalid';
    }

    /** A field's developer-chosen identifier — never its value. */
    function fieldIdentifier(field) {
        return String(field.getAttribute('name') || field.id || '').slice(0, 64);
    }

    document.addEventListener('invalid', function (e) {
        const field = e.target;
        const form = field && field.form;
        if (!trackableForm(form)) {
            return;
        }

        const state = stateFor(form);
        if (state) {
            if (state.errors >= MAX_ERRORS_PER_FORM) {
                return;
            }
            state.errors++;
        }

        track('form_error', {
            element_tag: 'form',
            form_key: formIdentity(form),
            field_id: fieldIdentifier(field),
            field_type: String(field.type || field.tagName || '').toLowerCase().slice(0, 32),
            error_type: errorCategory(field)
        });
    }, true);

    /* Forms arrive late on plenty of sites — a modal, a tabbed layout, an
     * Elementor popup, an AJAX-rendered step. Re-observing on DOM mutations
     * catches those; the per-form state means nothing is double-counted. */
    observeForms();
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', observeForms);
    }
    window.addEventListener('load', observeForms);

    if (typeof MutationObserver === 'function') {
        const domObserver = new MutationObserver(function () {
            observeForms();
        });
        domObserver.observe(document.documentElement, { childList: true, subtree: true });
    }

    /* ------------------------------------------------------------------ *
     *  Custom events and goal selectors
     *
     *  Convermetry.track('name', { value: 250 }) lets site code report an
     *  action this tracker cannot observe. The NAME is all that can match a
     *  configured goal; an unmatched name is discarded server-side and never
     *  stored, so a typo here costs nothing. The optional numeric value is read
     *  only when the matching goal is configured to accept one.
     *
     *  Nothing else in the payload is sent. That is deliberate: an API that
     *  accepted arbitrary properties would become an unaudited channel for
     *  putting customer data into an analytics table.
     * ------------------------------------------------------------------ */

    /** Configured CSS-selector goals, as { goalId: selector }. */
    const selectorGoals = config.selectorGoals || {};

    /** Goal ids whose selector matches the clicked element. */
    function matchedSelectorGoals(el) {
        const matched = [];
        for (const goalId in selectorGoals) {
            if (!Object.prototype.hasOwnProperty.call(selectorGoals, goalId)) {
                continue;
            }
            try {
                if (el.closest && el.closest(selectorGoals[goalId])) {
                    matched.push(goalId);
                }
            } catch (e) {
                // An invalid selector must not break click tracking for
                // everything else on the page.
            }
        }
        return matched;
    }

    window.Convermetry = window.Convermetry || {};

    window.Convermetry.track = function (name, data) {
        const eventName = cleanLabel(name).slice(0, 120);
        if (!eventName) {
            return;
        }

        const payload = { name: eventName };

        // Only a finite number is carried, and only as a string. Anything else
        // in `data` is ignored rather than serialized.
        if (data && typeof data.value !== 'undefined') {
            const value = Number(data.value);
            if (isFinite(value)) {
                payload.value = String(value);
            }
        }

        track('custom_event', payload);
        flush('normal');
    };

    /* ------------------------------------------------------------------ *
     *  Hovers — pointer resting on an element for the dwell threshold
     * ------------------------------------------------------------------ */

    const hoverTracked = (typeof WeakSet === 'function') ? new WeakSet() : null;
    let hoverTimer = null;
    let hoverEl = null;

    document.addEventListener('mouseover', function (e) {
        const el = e.target && e.target.closest
            ? e.target.closest(INTERACTIVE + ', [data-cvm-hover]')
            : null;

        if (!el || el === hoverEl || inAdminBar(el)) {
            return;
        }
        if (hoverTracked && hoverTracked.has(el)) {
            return;
        }

        clearTimeout(hoverTimer);
        hoverEl = el;

        hoverTimer = setTimeout(function () {
            if (hoverTracked) {
                hoverTracked.add(el);
            }

            // An image src can carry query-string tokens (signed CDN URLs,
            // cache busters) — strip query and fragment before using it as
            // the fallback label, like every other stored URL.
            track('hover', {
                element_tag: el.tagName.toLowerCase(),
                element_label: labelFor(el) || cleanLabel(String(el.src || '').split('#')[0].split('?')[0]),
                target_url: cleanTarget(el.href),
                event_value: String(HOVER_DWELL)
            });
        }, HOVER_DWELL);
    }, true);

    document.addEventListener('mouseout', function (e) {
        if (!hoverEl) {
            return;
        }

        // Only cancel when the pointer truly left the tracked element (not
        // when it moved between the element's own children).
        const stillInside = e.relatedTarget && hoverEl.contains(e.relatedTarget);
        if (!stillInside && (e.target === hoverEl || hoverEl.contains(e.target))) {
            clearTimeout(hoverTimer);
            hoverEl = null;
        }
    }, true);

    /* ------------------------------------------------------------------ *
     *  Scroll depth — 50/100% milestones, once each per page view
     * ------------------------------------------------------------------ */

    const milestones = [50, 100];
    const reached = {};
    let scrollScheduled = false;

    function checkScrollDepth() {
        scrollScheduled = false;

        const doc = document.documentElement;
        const scrollable = doc.scrollHeight - window.innerHeight;

        if (scrollable <= 0) {
            // Nothing to scroll — 50% never genuinely happened here, so
            // record only the one milestone that's actually true instead of
            // firing every threshold at once.
            if (!reached[100]) {
                reached[50] = true;
                reached[100] = true;
                track('scroll_depth', { event_value: '100' });
            }
            return;
        }

        const percent = Math.round((window.scrollY || doc.scrollTop || 0) / scrollable * 100);

        for (let i = 0; i < milestones.length; i++) {
            const mark = milestones[i];
            if (percent >= mark && !reached[mark]) {
                reached[mark] = true;
                track('scroll_depth', { event_value: String(mark) });
            }
        }
    }

    window.addEventListener('scroll', function () {
        if (!scrollScheduled) {
            scrollScheduled = true;
            setTimeout(checkScrollDepth, 400);
        }
    }, { passive: true });

    // Check once after layout settles: short pages with nothing to scroll
    // record 100% immediately, and a browser-restored scroll position is
    // captured even if the visitor never scrolls again.
    setTimeout(checkScrollDepth, 800);

    /* ------------------------------------------------------------------ *
     *  Delivery — periodic flush plus a final beacon on page exit
     * ------------------------------------------------------------------ */

    setInterval(function () {
        flush('normal');
    }, FLUSH_INTERVAL);

    window.addEventListener('pagehide', function () {
        flush('lifecycle-exit');
    });

    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'hidden') {
            flush('lifecycle-exit');
        }
    });
})();
