# Convermetry

Visitor analytics, campaign attribution, and **server-confirmed form conversion tracking** with reliable webhook delivery — one WordPress plugin that answers the full funnel question:

```text
Where did this visitor come from?
        ↓
What pages did they visit?
        ↓
What did they interact with?
        ↓
Which form did they submit?
        ↓
Was the submission actually accepted by WordPress?
        ↓
What data did they submit?
        ↓
Which marketing campaign produced the lead?
        ↓
Was the lead successfully delivered to external systems?
```

Convermetry works standalone — full analytics dashboard, form integrations, and webhook delivery inside one WordPress install — and is architected so a future Convermetry SaaS can receive `analytics_report` and `form_submission` messages from many installations, keyed by a shared, versioned payload schema.

- **Version:** 0.4.0
- **Requires WordPress:** 6.3+
- **Requires PHP:** 8.3+
- **License:** GPL-2.0-or-later
- **Text domain / slug:** `convermetry` · **REST namespace:** `convermetry/v1` · **PHP namespace:** `Convermetry\`

---

## Contents

1. [Requirements & installation](#requirements--installation)
2. [Admin pages](#admin-pages)
3. [Submissions](#submissions)
4. [Analytics tracking](#analytics-tracking)
5. [Campaign & channel attribution](#campaign--channel-attribution)
6. [Session → submission → conversion correlation](#session--submission--conversion-correlation)
7. [Supported form providers](#supported-form-providers)
8. [Custom form integration API](#custom-form-integration-api)
9. [Notifications](#notifications)
10. [Webhooks](#webhooks)
11. [The three identifiers](#the-three-identifiers-submission_id--conversion_id--delivery_id)
12. [Payload schemas](#payload-schemas)
13. [HMAC signatures](#hmac-signatures)
14. [Retries & idempotency](#retries--idempotency)
15. [Activity Log](#activity-log)
16. [REST APIs](#rest-apis)
17. [Developer hooks](#developer-hooks)
18. [Privacy](#privacy)
19. [Database tables](#database-tables)
20. [Uninstall behavior](#uninstall-behavior)

---

## Requirements & installation

| Requirement | Minimum |
|---|---|
| PHP | 8.3 |
| WordPress | 6.3 |
| Form plugins | Optional — feature-detected (see [Supported form providers](#supported-form-providers)) |

1. Copy the `convermetry` folder into `wp-content/plugins/`.
2. Activate **Convermetry** on the Plugins screen. Activation creates the four custom tables and schedules the cleanup and webhook cron events.
3. Visit **Convermetry** in the admin menu for analytics; configure endpoints under **Convermetry → Webhooks**, form behavior under **Convermetry → Forms**, and tracking/identity under **Convermetry → Settings**.

Activation never fatals when no third-party form plugin is installed — every provider integration is feature-detected at runtime.

## Admin pages

```text
Convermetry
    Analytics      — the reporting dashboard (top-level default)
    Submissions    — every server-confirmed lead, with its attribution and answers
    Forms          — provider status, discovered forms, per-form configuration
    Notifications  — internal email alerts for new submissions, with analytics context
    Webhooks       — endpoints, delivery types, signing, schedule, request customization
    Activity Log   — every delivery attempt with its (redacted) payload and response
    Settings       — website/client identity, tracking toggles, privacy, retention
    About          — this documentation inside wp-admin
```

**Submissions** and **Activity Log** answer different questions and are easy to
confuse:

| | Submissions | Activity Log |
|---|---|---|
| One row is | one form submission | one delivery *attempt* |
| Exists without webhooks | **yes** | no |
| Shows | the lead, its attribution, its answers | the payload sent and the response returned |
| Cleared by | Clear All Submissions | Clear All Logs |

Clearing one never touches the other.

**Notifications** is independent of both: it has its own master switch, its own
queue, and works on a site with no webhook endpoints configured.

### Analytics

For a selectable 7/30/90-day period (UTC calendar days, clamped to the retention window with an explanatory notice when clamped): summary cards (Page Views, Clicks, Form Submit Attempts, Confirmed Conversions, **Server-Confirmed Submissions**, Hovers, Scroll Milestones, Other Events), an accessible daily page-view chart (single-Tab-stop keyboard navigation, touch/mouse tooltips, visible axes, data-table fallback, horizontal scroll for dense periods), and collapsible sections:

| Section | Reports |
|---|---|
| Overview | Summary cards + daily page-view chart |
| Content | Top Pages, Top Landing Pages |
| Engagement | Top Clicked Elements, Top Form Submit Attempts, Most Hovered Elements |
| Acquisition | Top Referrers, Channels, Campaigns, Campaign Terms & Content |
| Devices | Mobile vs desktop share |
| Conversions | Recent Conversions — each with attribution, provider/form identity, and conversion id |
| Recent Activity | The latest 15 raw events in the site timezone |

A **Print / Save as PDF** button produces a print-optimized report (sections and chart values expanded automatically, landscape campaign tables, link URLs printed). Empty states and per-section database-error notices are explicit — a failed query is never rendered as a silent zero.

The three form metrics are deliberately distinct:

- **Form Submit Attempts** — frontend `submit` events; success unconfirmed.
- **Confirmed Conversions** — unique conversions deduplicated by `conversion_id` across both detection paths.
- **Server-Confirmed Submissions** — submissions a form plugin's own server-side success hook confirmed. When a provider integration is available, this server-side signal is authoritative.

### Submissions

See [Submissions](#submissions) below.

### Forms

Shows each supported provider as **Active** or **Unavailable**, automatically discovers every active provider's forms (cached for five minutes), and offers filtering by provider, name/id text, and included/excluded state. Per form:

| Setting | Meaning |
|---|---|
| Native Form ID | The provider's own identity (read-only) |
| Custom/External Form ID | Sent as `form_id` in payloads; native id is the fallback |
| Enabled / Excluded | Detected forms are **included by default** — new forms need no setup. Exclusion stops processing; configuration is **preserved** while excluded |
| Include page URL query parameters | Per-form override of the global setting |
| URL Query Parameters | Per-form parameters (highest precedence) |
| Request Headers | Per-form headers |

### Webhooks

Endpoints, delivery types, signing, schedule, backfill, failure mode, global headers/query parameters, per-endpoint tests, pending retries, and the pending form-delivery queue — see [Webhooks](#webhooks) below.

Each endpoint independently chooses its **Delivery Types**, so it can receive **Analytics Reports only**, **Form Submissions only**, or **both**:

- **Analytics Reports** (`analytics_report`) — scheduled, aggregated reports covering a time window, not one webhook per tracked event. See [Analytics report payload](#analytics-report-payload).
- **Form Submissions** (`form_submission`) — one individual, server-confirmed lead, delivered immediately. See [Form submission payload](#form-submission-payload).

For an analytics-only endpoint, check **Analytics Reports** and leave **Form Submissions** unchecked. Note what that does and does not mean: no **submitted form field values** are sent, but analytics reports still describe individual conversions in `conversions.recent[]` — conversion id, form name and ids, provider, and the visitor's IP when IP storage is on. Each endpoint block has separate **Send analytics test** and **Send form test** buttons, and both message types appear in the Activity Log, distinguishable by `message_type`.

### Activity Log / Settings / About

See [Activity Log](#activity-log); Settings holds the website/client identity (`website_info`), tracking toggles, privacy signals, the **IP addresses** switch (on by default, covering analytics events and form submissions — see [Privacy](#privacy)), hover dwell, retention, and the Activity Log lead-data privacy switch.

## Submissions

**Convermetry → Submissions** lists every form submission the plugin confirmed server-side, joined to the analytics session that produced it. It reads the `cvm_form_submissions` table directly, so it is a complete record of the site's leads **whether or not any webhook endpoint is configured** — webhook delivery is something that happens *to* a submission, not the reason one exists.

### The list

Rows are newest-first and paginated (5/10/25/50/100 per page), loaded over the `cvm_get_submissions` AJAX action:

| Column | Source |
|---|---|
| Date | `created_at` (UTC) |
| Visitor / Lead | Derived from the visitor's own answers — an assembled name and/or email address, falling back to a phone number, then `(no contact details)` |
| Form | `form_name`, with the provider beneath it |
| Page | Path of the page the form was submitted from |
| Source | The classified marketing `channel` |
| Campaign | `utm_campaign` |
| Status | Webhook delivery state (below) |

### Delivery status

Delivery state is **recorded on the submission** as each outcome happens, so it survives anything that later happens to the Activity Log:

| Chip | Meaning |
|---|---|
| **Delivered** | Every endpoint this submission was sent to acknowledged it |
| **Partial (1/2)** | Some endpoints acknowledged, others did not |
| **Failed** | Every attempt against every endpoint failed and the retry chain is spent |
| **Queued** | Still in the delivery queue, possibly mid-retry |
| **Not sent** | Nothing was ever queued or sent |

**"Not sent" is a neutral state, not an error.** With no form webhook endpoint configured, every submission reads *Not sent — no form webhook*; when delivery is merely paused it reads *Not sent — webhooks paused*. Either way the page says so explicitly. Recording, attribution, and analytics correlation all work independently of delivery.

A submission is judged against the endpoints it was **actually attempted against**, never the endpoints configured right now — adding a third endpoint today does not retroactively downgrade last month's successful two-endpoint delivery to *Partial*. The **last** attempt against an endpoint is that endpoint's verdict, so a failure followed by a successful retry reads as delivered, with the retry's status code.

Because the state is stored rather than re-derived from the Activity Log, **clearing the Activity Log does not change any submission's delivery status** — the log is diagnostic, the submission row is the record.

### Filters & search

All filters combine, and each dropdown hides itself until it has more than one value to offer:

- **Year** and **Month**
- **Provider** and **Form**
- **Channel** and **Campaign**
- **Delivery state** — any of the five above

Search is debounced (300 ms) and matches the submitted field values, the form name, the page URL, and — exactly — a pasted `submission_id` or `conversion_id`. Non-ASCII names (`José`, `Ñuñez`) match whether the row was written before or after the storage encoding switched to `JSON_UNESCAPED_UNICODE`.

### The detail panel

Expanding a row loads its detail over `cvm_get_submission_detail` (once per row, then cached client-side):

- **Form** — provider, form name, form id, native form id, conversion page, timestamp, `submission_id`, `conversion_id`.
- **Analytics & attribution** — channel, the full UTM set, ad-click type, entrance referrer, landing page, device, session id, session start, pageviews, and the visitor's IP when IP storage is on. When the tracker's correlation fields never reached the server (JavaScript blocked, tracking off, a privacy signal honored, a server-to-server submission) the panel says so rather than showing blanks.
- **Visitor journey** — the session's recent pages in order, ending at the submission.
- **Submitted fields** — every field name and the value the visitor entered.
- **Page query parameters** — when the submitting URL carried any.
- **Webhook delivery** — per-endpoint ✓/✕/⏳ with the response code, and a link into the Activity Log for the full payload and response.

The session summary behind *Visitor journey* is normally computed when a webhook delivery freezes its payload. On a site with no endpoints that never happens, so the detail handler computes it lazily on first expand and persists the result — at most one extra query per submission, ever.

### CSV export

Two buttons, both streaming in keyset-paginated chunks so memory stays bounded on any table size:

- **Export Current Filters** — exactly the rows on screen; the link is rewritten as filters change, and the server re-sanitizes the query string through the same code path the list uses.
- **Export All To CSV** — every stored submission.

Field sets differ from form to form, so there is no honest column-per-field header. The fixed columns carry identity, attribution, and delivery state — Date, Submission ID, Conversion ID, Session ID, Provider, Form Name, Form ID, Native Form ID, Conversion Page, Channel, UTM Source/Medium/Campaign/Term/Content, Ad Click Type, Entrance Referrer, Landing Page, Device, IP Address, Delivery Status — and the visitor's own answers travel in a final **Submission Data (JSON)** column. Cells beginning `=`, `+`, `-`, or `@` are tab-prefixed so spreadsheets treat them as text rather than formulas.

### Deleting

Submissions hold the information visitors typed into your forms. Two ways to remove it:

- **Delete Submission** — inside an expanded row, for a single record (e.g. an erasure request).
- **Clear All Submissions** — the toolbar button, nonce-protected and confirmed.

Both are permanent, and **both cancel any pending or retrying webhook delivery for the submissions they remove**. That matters: once a delivery has made its first attempt, the queue holds a frozen copy of the payload — the visitor's field values included — and would otherwise keep replaying it on the retry schedule for hours after the lead was "permanently" deleted.

Neither touches Activity Log entries: a delivery attempt is a separate record of something the site did, and erasing a lead must not silently destroy the outbound audit trail. Submissions also age out automatically with the shared retention window under **Settings**; the queue worker likewise stops delivering any submission whose row no longer exists.

## Analytics tracking

A single dependency-free script (`assets/js/tracker.js`) is enqueued deferred on frontend pages (never for logged-in users when exclusion is on — the default). It batches events and delivers them to `POST /wp-json/convermetry/v1/track`:

```json
{"batch_id": "b3f2a9c1b8e0d", "events": [{"type": "pageview", "page_url": "...", ...}]}
```

**Delivery reliability** — batches flush every 5 seconds, at 20 events, and on page exit via `navigator.sendBeacon`. Every batch is persisted to a bounded `sessionStorage` store *before* it is sent (in-memory fallback when unavailable) and removed only on server acknowledgment (2xx, or a 4xx other than 429). Failed sends back off exponentially with jitter; a 429 pauses the whole tab and honors `Retry-After`. Fetch-delivered batches are **at-least-once** and replays are **idempotent**: rows are stored under a unique `(batch_id, event ordinal)` key, so a replayed batch never inflates counts. Fresh page-exit beacon hand-offs are best-effort; a batch that already survived a failed send stays persisted through a beacon hand-off.

**Endpoint defenses** — whitelisted, currently-enabled event types only; tracked page URLs must be `http(s)` on this site's host and are canonicalized to scheme+host+path; foreign `Origin`/`Referer` rejected; bots and empty user agents ignored; DNT/GPC enforced server-side when enabled; request bodies and batch sizes capped; scalar-only field values, sanitized and truncated; rate limits charged **per event** — 300/IP/minute plus 3,000/minute site-wide (`convermetry_rate_limits` filter) — via atomic object-cache counters, falling back (and failing **closed**) to an atomic single-statement database counter. The per-IP check runs first so a flooding IP never consumes the site-wide budget. A dashboard warning appears for 24 hours after the site-wide cap is hit. The rate-limit key itself is a hashed, short-lived derivative of the IP; the address is separately stored on each event row when IP storage is enabled (see [Privacy](#privacy)).

**Tracked events** (each individually toggleable): `pageview`, `click`, `form_submit` (attempts), `form_success` (confirmed conversions), `hover` (configurable dwell, opt-in via `data-cvm-hover`), `scroll_depth` (50/100%). Custom server-side events via `cvm_track_event()`.

**Sessions** are cookie-free: the id lives in `localStorage` and rotates after 30 minutes of inactivity.

## Campaign & channel attribution

All six UTM parameters (`utm_source`, `utm_medium`, `utm_campaign`, `utm_id`, `utm_term`, `utm_content`) are captured from tagged landing URLs. Ad-click identifiers (`gclid`, `gbraid`, `wbraid`, `fbclid`, `msclkid`, `ttclid`, `twclid`, `li_fat_id`) are recognized — only the parameter **name** is stored (`click_id_type`), never the value — and imply source/medium when no UTM tags are present (`gclid` → `google`/`cpc`).

Attribution is **last-touch within the session**: the most recent tagged landing attributes the visit from that point on, and the snapshot rides on **every** event in the session. Untagged acquisition persists too — the session's entrance referrer travels as `session_referrer` (with an explicit `session_direct` marker for verified direct entrances), so organic/social/referral visits keep their channel across internal navigation. The session's landing page is persisted alongside and carried into each form submission's `analytics_context.landing_page`.

**Channels** — every attributed event is classified at ingestion into: Paid Search, Paid Social, Organic Search, Organic Social, Email, Display, Affiliate, SMS, Referral, Direct, Other. Source aliases are normalized (`fb`/`facebook.com` → `facebook`; `convermetry_source_aliases` filter), referrer hosts match search/social networks only in the registrable-domain position, and the rules are overridable per event (`convermetry_channel` filter). There is exactly **one** attribution engine (`Convermetry\Tracking\Channels`): the dashboard, the analytics payloads, and every form submission's `analytics_context.channel` classify through the same code.

## Session → submission → conversion correlation

The critical link between analytics and leads is **token-based — never timestamps**:

1. On page load (and refreshed at submit time, in the capture phase, *before* any AJAX handler serializes the form) the tracker injects three hidden internal fields into every form:
   - `cvm_conversion_id` — a fresh conversion token per submission attempt,
   - `cvm_session_id` — the current analytics session id,
   - `cvm_context` — a compact JSON snapshot of the session's attribution, entrance referrer/direct marker, landing page, and page URL.
2. The form plugin processes the submission normally. When its **server-side success hook** fires, Convermetry's provider adapter extracts and strictly validates those fields from the request (all transport shapes are handled, including Fluent Forms' serialized `data` blob), and **strips every `cvm_*` field** from the submission data.
3. The confirmed conversion is recorded as a `form_success` analytics event under **that same conversion token**, together with a durable row in the form-submissions table (session id, attribution context, sanitized lead data).
4. The tracker's own frontend success listeners (Elementor `submit_success`, CF7 `wpcf7mailsent`, WPForms `wpformsAjaxSubmitSuccess`, Gravity Forms `gform_confirmation_loaded`) reuse the **same token** for their `form_success` event — so whichever paths fire, every report deduplicates them into **one** conversion by `conversion_id`.

AJAX forms are fully supported (the capture-phase refresh runs before provider serialization). When the fields are absent (tracker disabled, privacy signals honored, JavaScript blocked, server-to-server submissions), the conversion id is generated server-side and the submission still records and delivers — just with an empty `analytics_context`. No cookies are used at any point.

Duplicate protection at every layer: a double-fired provider callback hits the `UNIQUE conversion_id` index and records nothing twice; queue rows are unique per `(submission_id, endpoint)`; reports count `DISTINCT conversion_id`; receivers deduplicate by `delivery_id`.

## Supported form providers

Providers are feature-detected — nothing loads or breaks when a plugin is absent — and discovered automatically:

| Provider | Availability check | Server-confirmed hook | Native identity |
|---|---|---|---|
| Elementor Pro | `ELEMENTOR_PRO_VERSION` / `\ElementorPro\Plugin` | `elementor_pro/forms/new_record` | Form **name** (settings key; widget id travels as `native_form_id`) |
| Gravity Forms | `GFAPI` | `gform_after_submission` | Form id |
| WPForms | `wpforms()` | `wpforms_process_complete` | Form id |
| Contact Form 7 | `WPCF7_ContactForm` | `wpcf7_mail_sent` | Form post id |
| Fluent Forms | `FLUENTFORM` / `wpFluentForm()` | `fluentform/submission_inserted` (+ legacy alias, double-fire-guarded) | Form id |
| Ninja Forms | `Ninja_Forms()` | `ninja_forms_after_submission` | Form id |
| Formidable Forms | `FrmForm` / `FrmEntry` / `FrmField` | `frm_after_create_entry` (priority 30) | Form id |

Elementor discovery walks `_elementor_data` post meta directly (public queries miss private post types such as `elementor_library`). Gravity Forms uses `GFAPI::get_forms()`; WPForms lists the `wpforms` post type; CF7 uses `WPCF7_ContactForm::find()`; Ninja Forms uses `Ninja_Forms()->form()->get_forms()`; Formidable uses `FrmForm::get_published_forms()`.

Two providers filter events the hook alone would over-report:

- **Ninja Forms** skips admin form previews, which run the full submission pipeline (`settings.is_preview`), and normalizes the `<id>_<instance>` form id that multi-instance renders submit.
- **Formidable Forms** skips repeater/embedded child entries, which fire the same hook as the parent (`is_child`), and saved drafts, which create an entry before the real submission (`is_draft`). It registers at priority 30 so Formidable's own form actions (which run at 20) have settled first.

**Delivery behavior for supported forms** (the default, `background` failure mode):

1. The form plugin processes/stores/sends the submission normally.
2. Convermetry captures the server-side success, records the submission + conversion, and queues one delivery per form endpoint — a handful of INSERTs, no payload building, no HTTP.
3. Control returns immediately; the visitor is never delayed.
4. A background worker (kicked within seconds via a spawned cron) builds, enriches, freezes, and sends the payloads, retrying on failure.

An external webhook outage can never make a valid form submission appear to fail. The optional **Show error to visitor** mode (Webhooks page) runs delivery synchronously for Elementor Pro forms and surfaces failures on the form via Elementor's AJAX handler.

## Custom form integration API

Fire-and-forget with background delivery and automatic retries:

```php
do_action('convermetry_form_submission',
    ['form_name' => 'Booking Widget', 'form_id' => 'booking-1'],
    ['name' => $name, 'email' => $email],
    ['url_query' => ['channel' => 'widget'], 'headers' => ['X-Source' => 'booking']] // optional
);
```

**Two field shapes are accepted.** The `'name' => $value` map above is the
long-standing form and stays fully supported — each key becomes both the field's
`id` and its `label`. If you can supply a distinct human label, pass the richer
descriptor list instead and it travels through to payloads, the Submissions
page, CSV exports, and notification emails unchanged:

```php
convermetry_submit_form(
    ['form_name' => 'Booking Widget', 'form_id' => 'booking-1'],
    [
        ['id' => 'email',     'label' => 'Email address',        'value' => $email],
        ['id' => 'interests', 'label' => 'Services of interest', 'value' => ['Tax planning', 'Retirement']],
    ]
);
```

`value` may be a string or a list of strings — nothing nested. Entries with an
empty `id` are dropped, `label` falls back to `id` when blank, duplicate labels
are preserved as separate fields, and `cvm_*` keys are stripped from either
shape. See [`submission_data`: schema 2.0](#submission_data-schema-20).

Result-aware and synchronous — the caller receives the real outcome and handles failures itself (no automatic retries):

```php
$result = convermetry_submit_form(
    ['form_name' => 'Booking Widget', 'form_id' => 'booking-1'],
    $fields,
    $url_query,       // optional, this call only
    $request_headers  // optional, this call only
);

if (!$result->ok) {
    // $result->msg              — user-facing description
    // $result->status           — last HTTP status (0 for early exits/transport errors)
    // $result->failedDeliveries — exact requests that failed, for custom retry logic
}
// $result->submissionId / $result->conversionId — the recorded identifiers
```

Both paths run the full pipeline — per-form exclusion and overrides (keyed `custom:<form_name>`), correlation-field extraction from the current request, conversion recording, and dedup. Third-party **provider adapters** register via:

```php
add_filter('convermetry_form_providers', function (array $providers) {
    $providers[] = new My_Form_Provider(); // implements Convermetry\Forms\FormProviderInterface
    return $providers;
});
```

Custom **frontend** conversions (goals no server hook can see):

```js
document.dispatchEvent(new CustomEvent('convermetry:conversion', {
    detail: { name: 'appointment_booked' } // optional: conversion_id to correlate with a server record
}));
```

Custom **server-side** analytics events: `cvm_track_event('purchase', ['page_url' => ..., 'event_value' => '99.00']);`

## Notifications

**Convermetry → Notifications** sends an internal email when a form submission
is recorded, enriched with the attribution Convermetry already captured for that
visitor. It is **off by default** and has its own master switch — it works with
no webhook endpoints configured, and disabling webhooks does not disable it.

These are **internal** notifications. Convermetry never emails the person who
submitted the form; visitor autoresponders are out of scope.

### Before you enable it

- **Email creates a copy of lead data outside Convermetry's controls.** Deleting
  a submission — or letting retention expire it — cancels any notification still
  queued, and guarantees no queued message can be rendered afterwards, because
  the queue stores no lead data of its own. It **cannot recall a message already
  sent**. Those copies live in recipients' mailboxes under whatever retention
  policy applies there, not yours. If you are relying on Convermetry's retention
  window for a compliance story, enabling this changes that story.
- **Your form plugin probably already emails you.** Most do. These are in
  addition, not a replacement.
- **"Sent" means handed to your mail system.** Convermetry uses `wp_mail()`, and
  a `true` return means the local transport *accepted* the message. It is not
  proof of delivery and says nothing about spam foldering. Nothing in the plugin
  claims a notification was "delivered" — that word is reserved for webhooks,
  where a receiver actually returned 2xx.
- Convermetry stores **no mail credentials** and implements no SMTP transport of
  its own. Any SMTP plugin you already run keeps working unchanged.

### Settings

| Setting | Default | Notes |
|---|---|---|
| Enable notifications | **off** | Master switch. |
| Recipients | none | One address per line (commas/semicolons also work). Validated, deduplicated case-insensitively, capped at 20. Each recipient gets a **separate message**, so nobody sees the rest of the list. Never derived from submitted data. |
| Subject | `New {form_name} submission on {site_name}` | See tokens below. |
| Scope | Every form | *Every form* notifies unless a form is switched off; *Only selected forms* notifies only forms switched on. Per-form rules are **inherit / always / never**. |
| Submitted fields | on | The visitor's answers, as label/value rows. |
| Analytics summary | on | Channel, UTM source/medium/campaign, landing page, conversion page, device, pages viewed, session start. |
| Visitor journey | **off** | The pages this visitor viewed. Browsing history for an identifiable person. |
| IP address | **off** | Personal data in the EU/UK; only available when IP storage is on in Settings. |

Per-form rules use the same keys as the Forms page (`provider:identity`).
Elementor forms are keyed by **name**, so renaming an Elementor form resets its
rule to the scope default.

Subject tokens — a fixed allowlist, substituted literally. There is no
expression language and no PHP evaluation:

`{site_name}` · `{form_name}` · `{provider}` · `{channel}` · `{submission_id}` ·
`{form_id}` · `{campaign}` · `{date}`

Anything else is left as literal text. CR/LF and NUL are stripped **after**
substitution, so a form named `Contact\r\nBcc: …` cannot inject a mail header.

### What is never emailed

Fields whose ID **or** label looks credential-bearing — passwords, tokens, API
keys, secrets, authorization values — are **omitted entirely**, even with
*Submitted fields* on. They are not shown as `[REDACTED]`: a placeholder would
tell every recipient that a secret exists. This uses the same policy as Activity
Log redaction, so `convermetry_sensitive_keys` extends both at once.
Convermetry's `cvm_*` correlation fields never appear either.

### Delivery

Notifications are queued, never sent during the visitor's request:

- The submission action enqueues; a WP-Cron worker renders and sends. No
  `wp_mail()`, payload build, or analytics query happens while the visitor waits.
- One queue row per **(submission, recipient)**, unique — a double-fired
  submission cannot produce two emails to one address, and one failing address
  does not re-mail the others.
- The queue stores a recipient, a settings snapshot, and scheduling state —
  **never the rendered email or the lead's answers**. The submission is fetched
  fresh at send time, which is what makes deletion effective.
- Settings are **snapshotted when the lead arrives**. Changing them applies to
  new submissions; anything already queued sends with the settings that were
  active at the time. Turning the master switch off stops new notifications but
  does not pause queued ones — the page has an explicit **Discard queued
  notifications** button for that.
- Retries are bounded and short: 5 min, 15 min, 1 h, then the row is abandoned
  and a warning appears in wp-admin. Email has no receiver-side idempotency, so
  a long retry chain would risk duplicates and deliver stale leads.
  (`convermetry_notification_retry_schedule` adjusts it.)
- Every row carries a hard **two-hour time-to-live**. A notification that could
  not be sent within that window — WP-Cron disabled, the site deactivated — is
  dropped rather than delivered days late as though it just arrived.
- Only a short failure reason is retained for diagnostics. The rendered body and
  the submitted values are never logged. Notification sends do **not** appear in
  the Activity Log, which covers webhook deliveries only.

**Send test email** builds its message entirely from fabricated data (a
`Convermetry Test Form`, `test@example.com`, and the RFC 5737 documentation
address `203.0.113.42`). It never loads a real submission, so testing cannot
expose a lead.


## Webhooks

Configure any number of endpoints under **Convermetry → Webhooks**. Each endpoint has:

| Field | Purpose |
|---|---|
| Webhook URL | HTTPS required (`convermetry_allow_insecure_webhooks` filter allows HTTP for development) |
| Label | Optional; badges Activity Log entries and identifies endpoints in the REST API |
| Signing Secret | Optional per-endpoint HMAC key; overrides the shared secret for this endpoint only |
| **Delivery Types** | **Analytics Reports** and/or **Form Submissions** checkboxes |

The two delivery types are independent: an endpoint may receive **Analytics Reports only**, **Form Submissions only**, or **both**. Checking **Analytics Reports** while leaving **Form Submissions** unchecked produces an analytics-only endpoint that never receives **submitted form field values** — though its reports still describe individual conversions in `conversions.recent[]` (conversion id, form name and ids, provider, and the visitor's IP when IP storage is on). The reverse produces a leads-only endpoint that never receives reports. So one install can feed, for example:

```text
Convermetry SaaS            Analytics ✓   Form Submissions ✓
HubSpot Middleware          Analytics ✗   Form Submissions ✓   (leads only)
Reporting Data Warehouse    Analytics ✓   Form Submissions ✗   (analytics only)
```

| Delivery type | `message_type` | What one message is | Cadence | Payload |
|---|---|---|---|---|
| Analytics Reports | `analytics_report` | An **aggregated** report for one time window | Scheduled — hourly, twice daily, daily, or weekly | [Analytics report payload](#analytics-report-payload) |
| Form Submissions | `form_submission` | **One** server-confirmed lead | Immediate, in the background | [Form submission payload](#form-submission-payload) |

**Analytics reports** aggregate a whole reporting window into one message — they are never one webhook per page view, click, or conversion — and are sent hourly, twice daily, daily, or weekly (a single site-wide schedule). Window boundaries are **UTC**, with `period.start` inclusive and `period.end` exclusive. Delivery windows are tracked **per endpoint** (each payload covers the time since that endpoint's last successful delivery); gaps longer than one interval — downtime, a paused status toggle, or an enabled **history backfill** for new endpoints — are delivered as consecutive, non-overlapping, interval-sized windows (up to 10 per run). Conversion delivery is **lossless**: a window holding more than 100 individual conversions is split into consecutive deliveries rather than truncated. Each site's schedule is anchored at a stable random offset within the interval so fleets sharing one endpoint never stampede it, and dispatch runs under a site-wide mutex (MySQL named lock, with a token/lease option-row fallback) so overlapping cron executions can never build overlapping windows. Each `top_*` list holds up to 200 rows (`convermetry_webhook_report_limit`).

**Form submissions** are delivered immediately in the background from a database-backed queue — one row per submission × endpoint (see [Retries & idempotency](#retries--idempotency)).

**Request customization** (applies to the URL and headers of outbound requests):

- **Global headers** (e.g. `Authorization`) on every request — sent intact, redacted in the Activity Log.
- **Global URL query parameters** on every request.
- **Page URL parameters** — optionally pass the submitting page's query string through to form-submission webhook URLs (global default plus per-form override).
- **Per-form query parameters and headers** (Forms page), **runtime** parameters/headers (custom API calls).

Merge precedence for query parameters (later overrides earlier for shared keys):

```text
Global URL parameters → Page URL parameters → Per-form URL parameters → Runtime parameters
```

Headers: `Content-Type` → global → per-form → runtime, with `User-Agent`, `Idempotency-Key`, and `X-Convermetry-Signature` added at send time.

**Per-endpoint tests** — every endpoint block has **Send analytics test** and **Send form test** buttons. Test payloads carry `"test": true` and clearly marked sample data, never touch delivery markers or real submissions, are never retried, and appear in the Activity Log with kind `test`. An analytics test reports on the **last 7 days** and does **not** advance the endpoint's last-sent marker, so testing never creates a gap in scheduled reporting; a form test builds a sample submission and never reads real lead data.

All requests go through one transport: `wp_safe_remote_post()` (URL re-validated at request time — SSRF protection even if DNS changed after saving), redirects disabled, response downloads capped at 64 KB at the transport layer, 15-second timeout.

## The three identifiers: `submission_id` / `conversion_id` / `delivery_id`

| Identifier | Identifies | Scope | Deduplicate by it when… |
|---|---|---|---|
| `submission_id` | The form submission itself | **Global** — identical in every delivery of that submission, to every endpoint | You aggregate the same lead arriving via multiple endpoints |
| `conversion_id` | The analytics conversion joined to the submission (and its session) | Shared between the frontend `form_success` event and the server-confirmed record | You count conversions (all Convermetry reports do exactly this) |
| `delivery_id` | One outbound webhook delivery | **Endpoint-specific**; stable across every retry of that delivery; echoed as `Idempotency-Key` | You receive webhooks — dedup by `delivery_id` is sufficient to never double-process |

Supporting identifiers: `batch_id` + event ordinal make tracker ingestion idempotent; `session_id` groups one visit.

## Payload schemas

These are the **outbound** messages Convermetry POSTs to your configured webhook endpoints. They are distinct from the inbound browser tracking API (`POST /wp-json/convermetry/v1/track`), which only accepts events from this site's own tracker into the local database — it never forwards anything to a webhook receiver. Everything a receiver gets is produced later, by the two outbound paths: scheduled [Analytics Reports](#analytics-report-payload) and immediate [Form Submissions](#form-submission-payload).

Every outbound message shares one versioned envelope:

```json
{
    "schema_version": "1.0 | 2.0",
    "source": "convermetry",
    "plugin_version": "0.4.0",
    "message_type": "analytics_report | form_submission",
    "website_info": { },
    "generated_at": "2026-08-22T14:00:00+00:00",
    "delivery_id": "…"
}
```

`website_info` is produced by one builder for both message types. Every key is always present (empty string when unconfigured) so consumers get a predictable schema; `domain` derives automatically from the home URL with `www.` stripped:

```json
"website_info": {
    "name": "Example Financial",
    "url": "https://www.example.com",
    "domain": "example.com",
    "id": "site-123",
    "client": { "first_name": "Jane", "last_name": "Smith", "id": "client-456" }
}
```

### Analytics report payload

`message_type: analytics_report` — the canonical full example. An Analytics Report is an **aggregated summary of a time window**, produced on a schedule; it is never one webhook per tracked event. Endpoints opt into it independently of form submissions, so this may be the only message type an endpoint ever receives.

```json
{
    "schema_version": "1.0",
    "source": "convermetry",
    "plugin_version": "0.4.0",
    "message_type": "analytics_report",
    "website_info": {
        "name": "Example Financial", "url": "https://example.com", "domain": "example.com",
        "id": "site-123",
        "client": { "first_name": "Jane", "last_name": "Smith", "id": "client-456" }
    },
    "generated_at": "2026-08-22T14:00:00+00:00",
    "delivery_id": "0f52c1d6a4b98e73d21f06c58a9b3e47",
    "period": {
        "start": "2026-08-21T14:00:00+00:00",
        "end": "2026-08-22T14:00:00+00:00"
    },
    "analytics": {
        "totals": { "pageview": 1240, "click": 512, "form_submit": 38, "form_success": 24, "hover": 940, "scroll_depth": 2210 },
        "daily_pageviews": [ { "date": "2026-08-21", "count": 610 }, { "date": "2026-08-22", "count": 630 } ],
        "top_pages": [ { "page_url": "https://example.com/", "page_title": "Home", "views": 400, "sessions": 310 } ],
        "top_landing_pages": [ { "page_url": "https://example.com/spring-sale/", "page_title": "Spring Sale", "sessions": 180 } ],
        "top_clicks": [ { "element_label": "Get a Quote", "element_tag": "a", "target_url": "https://example.com/quote", "clicks": 88 } ],
        "top_forms": [ { "element_label": "contact-form", "page_url": "https://example.com/contact", "submissions": 21 } ],
        "top_hovers": [ { "element_label": "Pricing", "element_tag": "a", "hovers": 130 } ],
        "top_referrers": [ { "referrer": "https://www.google.com/", "visits": 210 } ],
        "top_campaigns": [
            {
                "utm_source": "newsletter", "utm_medium": "email", "utm_campaign": "spring-sale",
                "utm_id": "cmp-2210", "channel": "Email",
                "views": 96, "sessions": 74,
                "conversions": 7, "converting_sessions": 6, "conversion_rate": 8.11
            }
        ],
        "top_campaign_content": [
            {
                "utm_source": "google", "utm_medium": "cpc", "utm_campaign": "summer-sale",
                "utm_id": "cmp-3301", "utm_term": "emergency plumber", "utm_content": "ad-variant-b",
                "views": 42, "sessions": 31, "conversions": 3
            }
        ],
        "channels": [
            { "channel": "Paid Search", "views": 320, "sessions": 240, "conversions": 12, "converting_sessions": 11, "conversion_rate": 4.58 }
        ],
        "conversions": {
            "total": 24,
            "server_confirmed": 19,
            "recent": [
                {
                    "conversion_id": "c1f52c1d6a4b98e73",
                    "form": "Contact Form",
                    "page_url": "https://example.com/contact",
                    "referrer": "https://example.com/services",
                    "device": "desktop",
                    "ip_address": "203.0.113.42",
                    "session_id": "9f2c…",
                    "occurred_at": "2026-08-22 09:14:02",
                    "server_confirmed": true,
                    "submission_id": "s5f2a9c1b8e0d21f06c5",
                    "provider": "elementor",
                    "form_id": "contact-form-01",
                    "native_form_id": "7ac3d1f",
                    "attribution": {
                        "channel": "Paid Search",
                        "utm_source": "google", "utm_medium": "cpc", "utm_campaign": "summer-sale",
                        "utm_id": "", "utm_term": "", "utm_content": "",
                        "click_id_type": "gclid"
                    }
                }
            ]
        },
        "devices": { "desktop": 820, "mobile": 420 }
    }
}
```

**Reading this payload:**

- **`period` is the UTC window the report covers** — `start` inclusive, `end` exclusive. Both boundaries, and `generated_at`, derive from the window end rather than the wall-clock time an attempt runs, so a retry re-sends a byte-identical body.
- **Delivery windows are tracked per endpoint.** Each payload covers the time since *that* endpoint's last successful delivery, so adding, pausing, or backfilling one endpoint never shifts another's windows. The *frequency* (hourly / twice daily / daily / weekly) is a single site-wide setting.
- **The dashboard and this payload share the same reporting query layer** (`Reports::buildSummary()`), so the admin screens and a webhook receiver cannot disagree about the same window.
- **`conversions.total`** is the count of distinct conversions in the window, **deduplicated by conversion id** — the frontend `form_success` event and the server-confirmed record share one id and can never double-count.
- **`conversions.server_confirmed`** is the number of stored server-confirmed submission records in the window. It is normally lower than `total`: a conversion the tracker saw but no provider hook confirmed counts in `total` only.
- **`conversions.recent[].ip_address`** is the visitor's IP — taken from the server-confirmed submission record when there is one (captured in the form POST the provider confirmed), otherwise from the analytics `form_success` event. Empty when IP storage is disabled in Settings.
- **`conversions.recent`** holds the **individual conversion records** for the window — each with the attribution snapshot taken when it occurred, plus provider/form/submission identity when `server_confirmed` is `true`. Every other section is aggregate reporting data.
- **Conversion delivery is lossless on the scheduled path:** a window holding more than 100 individual conversions is split into consecutive deliveries rather than truncated. (`recent` itself is capped at 500 entries; scheduled windows are bounded well below that, but a *test* send — which uses a fixed 7-day window with no such bounding — can reach the cap on a busy site.)
- Each `top_*` list holds up to 200 rows (`convermetry_webhook_report_limit`), deliberately deeper than the dashboard's top 10.

**Test analytics payloads** (**Send analytics test** on the Webhooks page) cover the **last 7 days**, carry `"test": true`, get a fresh random `delivery_id`, are **never retried**, and **do not advance the endpoint's last-sent marker** — so testing an endpoint never creates a gap in its scheduled reporting.

### Form submission payload

`message_type: form_submission` — the canonical full example. Where an Analytics Report carries reporting data for a window, a Form Submission carries **one lead's own data plus the analytics context correlated to it**. Endpoints opt into it independently of analytics reports.

```json
{
    "schema_version": "2.0",
    "source": "convermetry",
    "plugin_version": "0.4.0",
    "message_type": "form_submission",
    "website_info": {
        "name": "Example Financial", "url": "https://example.com", "domain": "example.com",
        "id": "site-123",
        "client": { "first_name": "Jane", "last_name": "Smith", "id": "client-456" },
        "page": {
            "url": "https://example.com/contact",
            "query": { "utm_source": "google", "utm_medium": "cpc" }
        }
    },
    "generated_at": "2026-08-22T14:32:00+00:00",
    "delivery_id": "endpoint-specific-idempotent-delivery-id",
    "form_submission": {
        "submission_id": "s5f2a9c1b8e0d21f06c5",
        "conversion_id": "c1f52c1d6a4b98e73",
        "provider": "elementor",
        "form_name": "Contact Form",
        "form_id": "contact-form-01",
        "native_form_id": "7ac3d1f",
        "ip_address": "203.0.113.42",
        "submission_data": [
            { "id": "1",  "label": "Full name",     "value": "John Doe" },
            { "id": "3",  "label": "Email address", "value": "john@example.com" },
            { "id": "4",  "label": "Phone",         "value": "555-555-5555" },
            { "id": "7",  "label": "Message",       "value": "I would like more information." },
            { "id": "9",  "label": "Services of interest", "value": ["Tax planning", "Retirement"] }
        ]
    },
    "analytics_context": {
        "session_id": "9f2c4be1a6d8c3f0…",
        "channel": "Paid Search",
        "attribution": {
            "utm_source": "google", "utm_medium": "cpc", "utm_campaign": "retirement-planning",
            "utm_id": "", "utm_term": "financial advisor", "utm_content": "ad-b",
            "click_id_type": "gclid"
        },
        "entrance_referrer": "https://www.google.com/",
        "landing_page": { "url": "https://example.com/retirement-planning/" },
        "device": "desktop",
        "pageview_count": 4,
        "session_started_at": "2026-08-22T14:20:11+00:00",
        "recent_pages": ["https://example.com/contact", "https://example.com/retirement-planning/"]
    }
}
```

#### `submission_data`: schema 2.0

`submission_data` is an **ordered list of field descriptors**, not an object:

| Key | Type | Notes |
|---|---|---|
| `id` | string | The provider-native field ID or key. Stable across renames. Never empty. |
| `label` | string | The human-readable label captured at submission time. Falls back to `id` when the provider exposes no reliable label. |
| `value` | string \| string[] | A sanitized string, or a list of sanitized strings for multi-value fields. Never a nested object. |

Why a list. The pre-2.0 format was a `label => value` object, which forced every
provider to discard either the stable ID (Gravity Forms, WPForms, Ninja Forms
and Formidable keyed by label) or the human label (Elementor keyed by ID). It
also **silently merged two fields that shared a label** — two fields both called
"Name" became one. A list preserves provider order, preserves duplicates, and
keeps the ID for automation alongside the label for humans. Match on `id`; show
`label`.

Label availability differs by provider, and Convermetry does not guess:

| Provider | `id` | `label` |
|---|---|---|
| Elementor | field ID | the field's title |
| Gravity Forms | field ID | the field label |
| WPForms | field ID | the field name |
| Ninja Forms | field ID (or key) | the field label, else its key |
| Formidable Forms | field ID | the field name, else its key |
| Contact Form 7 | posted field name | **same as `id`** — CF7 exposes no reliable label without parsing form markup |
| Fluent Forms | submitted key | **same as `id`** — labels live in an internal JSON blob, not a public API |

Convermetry's own correlation fields (`cvm_conversion_id`, `cvm_session_id`,
`cvm_context`) are stripped before storage and never appear here.

#### Migrating from schema 1.0 — branch on `schema_version`

Moving `submission_data` from an object to an array is a breaking wire change,
so it is versioned. **Both versions travel during the transition**, and the two
message types version independently:

| Message | `schema_version` |
|---|---|
| `analytics_report` | `1.0` — unchanged, reports were not affected |
| `form_submission`, submission recorded by 0.4.0+ | `2.0` |
| `form_submission`, submission recorded before 0.4.0 | `1.0`, carrying its original object |

Historical rows are **never** rewritten, in the database or on the wire. A
submission delivered as `1.0` before the upgrade would otherwise reach a second
endpoint — or a retry — as `2.0`, so one `submission_id` would arrive in two
different shapes. A frozen retry can therefore deliver a `1.0` body long after
the site is running 0.4.0.

That is why receivers must branch on **`schema_version`**, never on
`plugin_version`:

```php
$data = $payload['form_submission']['submission_data'];

$fields = $payload['schema_version'] === '1.0'
    // Legacy object: the key is the label, and the ID is unavailable.
    ? array_map(
        static fn($label, $value) => ['id' => $label, 'label' => $label, 'value' => $value],
        array_keys($data),
        $data
    )
    : $data;
```

The **Send form test** button on the Webhooks page sends schema `2.0`, so you
can verify a receiver against the current format before real leads arrive.

`ip_address` is the submitter's IP, captured during the visitor's own request and frozen with the row — delivery and retries run later in a background worker where `REMOTE_ADDR` belongs to cron, so it is never re-resolved at send time. The key is **always present**; it is an empty string when the Settings toggle is off, when no valid address could be determined (CLI/cron, an unusual proxy setup), or for a submission stored before the field existed. Values are validated as real IPv4/IPv6 addresses, so a malformed `REMOTE_ADDR` or a comma-joined forwarding chain stores nothing rather than junk.

`generated_at` is the submission's creation time — identical across endpoints and retries. The session-summary fields (`pageview_count`, `session_started_at`, `recent_pages`) are enriched in the background worker via two small indexed queries, never during the visitor's request, and frozen with the payload. Submissions without correlation data carry the same `analytics_context` keys with empty values. There is **no** `client_location_data` and no external geolocation call — a `geo` block can be added later as an ingestion-side enrichment without schema changes.

## HMAC signatures

When a signing secret is configured (shared, or per-endpoint — the endpoint's own secret wins, so one receiver never learns the key that signs payloads for others), every request carries:

```text
X-Convermetry-Signature: sha256=<hex>
```

— the HMAC-SHA256 of the **raw JSON body bytes**, keyed with the secret. Verify by recomputing over the exact received bytes and comparing with a constant-time function:

```php
$expected = 'sha256=' . hash_hmac('sha256', $rawBody, $secret);
if (!hash_equals($expected, $_SERVER['HTTP_X_CONVERMETRY_SIGNATURE'] ?? '')) { http_response_code(401); exit; }
```

Signatures are computed at send time over the frozen bytes, so retries re-sign the identical body; rotating a secret mid-retry simply signs the same bytes with the new key.

## Retries & idempotency

Both message types share the retry schedule (filterable via `convermetry_retry_schedule`):

```text
Initial delivery → 5 minutes → 30 minutes → 2 hours → 6 hours → 16 hours   (~24.6 h total)
```

**Frozen requests.** On the first delivery attempt the final URL (all query-parameter layers merged), the **configured** headers (global + per-form + runtime), and the serialized JSON body are frozen. Every retry replays that exact body and URL under the same `delivery_id` — retention cleanup, settings changes, or plugin updates between attempts can never mutate them.

Three headers are *not* frozen; they are regenerated on each attempt from the frozen body: `Idempotency-Key` (always the same `delivery_id`), `User-Agent` (carries the plugin version, so it changes if the site updates mid-chain), and `X-Convermetry-Signature` (computed with the secret **current at send time**, so rotating a secret changes a retry's signature — intentionally, so a rotated key still verifies). A payload that fails to JSON-encode (e.g. a filter introduced an unencodable value) is treated as a *failed attempt* entering the normal chain; an empty body is never sent, and the payload is never rebuilt without the filter.

**Analytics reports** — per-endpoint retry chains via single-event crons. An exhausted chain (or one whose cron could not be scheduled — detected as *orphaned*) keeps its frozen delivery; the next scheduled dispatch re-sends it under the original `delivery_id` first, and only after acknowledgment does the endpoint's marker advance — exactly to the frozen window's end — so consecutive deliveries never overlap. Every retry-state mutation happens under the dispatch mutex. Deactivating the plugin *suspends* chains (frozen deliveries resume after reactivation under their original ids); frozen deliveries older than the retention window expire, and each pending retry has a **Discard** action on the Webhooks page.

**Form submissions** — one queue row per submission × endpoint in `cvm_delivery_queue`. Endpoints that acknowledged are deleted from the queue and **never re-sent** when a sibling endpoint fails. Rows are claimed atomically (a token-stamped conditional `UPDATE`), so overlapping workers can't double-send; rows stranded in `sending` by a dead worker are reclaimed after 10 minutes. The worker cron is re-armed by activation, the daily cleanup, and every analytics dispatch run, so queued leads survive lost cron events and deactivate/reactivate cycles. After the final failed attempt the delivery is abandoned — every attempt remains in the Activity Log.

**Delivery is at-least-once.** Any duplicate a receiver can ever see carries a `delivery_id` it has already processed — deduplicating by `delivery_id` is sufficient to never double-count.

## Activity Log

**Convermetry → Activity Log** records every delivery attempt — analytics report or form submission; scheduled, immediate, retry, or test — as normalized columns (`message_type`, `kind`, `attempt`; never parsed from display labels), with: timestamp, endpoint URL + label, delivery/submission/conversion ids, form provider and name, HTTP status, final request URL, request headers (credential values redacted), the **JSON payload sent**, the response body, and transport errors.

Stored bodies are a **redacted representation of the request, not a byte-exact copy**: values under sensitive-looking keys (`password`, `token`, `secret`, `api_key`, …) are replaced with `[REDACTED]` in both request and response before storage, form `submission_data` is replaced entirely when *Store form submission data in the Activity Log* is off, and both bodies are capped at 64 KB. A stored body therefore will **not** reproduce the original `X-Convermetry-Signature` — verify signatures against what your endpoint received, never against a log copy.

Two paginated accordions (Successful / Failed) offer year/month, message-type, endpoint, provider, and form filters, debounced payload search, a per-page selector (5–100), and per-entry delete. The toolbar provides **Clear All Logs** and **CSV/JSON export** — both stream in keyset-paginated chunks, so even huge logs export in bounded memory. Entries share the analytics retention window.

**Log security:** response bodies are size-capped at download (64 KB via `limit_response_size`) and at storage; values of sensitive-looking keys (`password`, `passwd`, `secret`, `token`, `authorization`, `api_key`, `apikey`, `access_token`, `refresh_token`, `client_secret`, …) in JSON bodies are replaced with `[REDACTED]` (BOM/whitespace-prefixed JSON included); configured credential headers are stored redacted (sent intact); signing secrets never appear in logs. The `convermetry_delivery_log_row` filter allows site-specific redaction of e.g. PII fields, or skipping a row entirely; a Settings toggle can exclude form `submission_data` from stored payloads while keeping delivery metadata.

## REST APIs

### `POST /wp-json/convermetry/v1/track` (public)

The tracker's ingestion endpoint — see [Analytics tracking](#analytics-tracking) for its validation, dedup, rate-limiting, and privacy behavior. Answers `202` with `{"stored": n}`, `400`/`403`/`413`/`429` (`Retry-After`) on rejection, and `503` when storage failed (the tracker keeps the batch and retries).

### `GET /wp-json/convermetry/v1/deliveries` (API-key)

Read-only Activity Log API, off by default; enable it (and manage its key) on the Activity Log page.

```text
GET /wp-json/convermetry/v1/deliveries?page=1&per_page=25&status=error&message_type=form_submission
Authorization: <api-key>
```

| Parameter | Values |
|---|---|
| `page` / `per_page` | Pagination (`per_page` max 100) |
| `status` | `success` \| `error` |
| `message_type` | `analytics_report` \| `form_submission` |
| `endpoint` | An endpoint **label**, or the `endpoint_key` (md5 of the URL) echoed in responses |
| `provider` | Form provider key |
| `form_id` | Exact form name |
| `after` | `YYYY-MM` or `YYYY-MM-DD` (calendar-month filter) |

Pagination metadata returns in `X-WP-Total`, `X-WP-TotalPages`, and `X-CVM-Page` headers. Only a SHA-256 hash of the key is stored — the raw key is shown **once** at generation; regenerating invalidates the old key immediately. Wrong keys get `401` (throttled per IP after repeated failures → `429`); a disabled API answers `403`. In responses, `endpoint_url` is **redacted to scheme + host** — webhook URLs frequently embed bearer tokens, and this read-only key must never hand out downstream write credentials; identify endpoints by `endpoint_label`/`endpoint_key` (full URLs stay visible to admins in wp-admin). Intended for **server-to-server** use — CORS permits browser calls for flexibility, but never embed the key in public frontend JavaScript.

## Developer hooks

| Hook | Type | Purpose |
|---|---|---|
| `convermetry_tracked_event` | filter | Inspect/modify an event row before storage; return `false` to drop. `(array $row, string $type)` |
| `convermetry_webhook_payload` | filter | Modify any outbound payload before it is frozen/encoded. `(array $payload, string $messageType, array $meta)` — `$meta` is `['start','end']` for reports, `['submission_id']` for submissions |
| `convermetry_webhook_report_limit` | filter | Max rows per `top_*` list in analytics payloads (default 200) |
| `convermetry_allowed_hosts` | filter | Hostnames accepted in tracked URLs / Origin checks, treated as internal in referrer reports |
| `convermetry_client_ip` | filter | Map the client IP used for tracking rate limits **and** the IP stored on analytics events and form submissions (reverse proxies / CDNs) |
| `convermetry_rate_limits` | filter | `['per_ip' => 300, 'site_wide' => 3000]` events/minute |
| `convermetry_source_aliases` | filter | Extend/override the utm_source alias map |
| `convermetry_channel` | filter | Override the marketing channel assigned at ingestion. `(string $channel, array $row, string $type)` |
| `convermetry_delivery_log_row` | filter | Redact/modify an Activity Log row before storage; return `false` to skip logging |
| `convermetry_allow_insecure_webhooks` | filter | Return `true` to allow `http://` endpoints (development only) |
| `convermetry_form_providers` | filter | Register custom `FormProviderInterface` adapters |
| `convermetry_retry_schedule` | filter | The webhook retry backoff delays in seconds (both message types) |
| `convermetry_notification_retry_schedule` | filter | The email-notification retry backoff in seconds (default `[300, 900, 3600]`). Deliberately separate from the webhook schedule — a stale lead notification is worse than none, and email has no receiver-side idempotency |
| `convermetry_sensitive_keys` | filter | Extend the credential-looking field/header names redacted from the Activity Log **and** omitted from notification emails (e.g. add `ssn`). Matched as substrings of a canonical form: lowercase with non-alphanumeric runs collapsed to `_`, so `API Key`, `x-api-key` and `API_KEY` all match `api_key`. Extend it; returning a shorter list weakens both surfaces |
| `convermetry_form_submission` | action | Submit a custom form (fire-and-forget, background delivery) |
| `convermetry_submission_recorded` | action | Fires after a submission is recorded, before webhook delivery is considered — so listeners run even with no endpoints configured (this is where notifications are queued). `($submissionId, $conversionId, $context)` |

Helper functions: `convermetry_submit_form()` (result-aware submission) and `cvm_track_event()` (custom server-side analytics event).

## Privacy

- **Email notifications are opt-in and leave your retention window.** Convermetry → Notifications is off by default. When enabled, each notification is a copy of lead data in a mailbox Convermetry does not control: deleting a submission cancels anything still queued and guarantees no queued message can be rendered afterwards, but it **cannot recall a message already sent**. Retention, deletion, and export controls in this plugin do not reach those copies. The visitor-journey and IP-address toggles are off by default for the same reason, and credential-looking fields are never emailed at all. See [Notifications](#notifications).
- **No cookies.** Session ids live in `localStorage` and rotate after 30 minutes of inactivity.
- Tracked URLs are canonicalized to scheme + host + path — **no query strings are ever stored**. Referrers and click/form destinations are likewise stripped (whole `mailto:`/`tel:` destinations are kept — the address *is* the destination; strip via `convermetry_tracked_event` if unacceptable).
- Campaign values are stored after sanitization, except values containing `@` (dropped as likely emails) — never put personal data in UTM parameters. Ad-click identifiers store only the parameter **name**; the value never leaves the browser.
- **Visitor IP addresses are stored by default**, on both write paths: every analytics event (page views, clicks, hovers, scroll milestones, conversions) and every server-confirmed form submission. Turn it off with **Settings → Privacy → IP addresses**; new rows then record an empty value while existing rows are untouched and age out with retention. User agents are never stored on either path.
  - **In the EU/UK an IP address is personal data.** Retaining it for general visitor activity — not only for leads someone actively submitted — normally has to be disclosed in your privacy policy and rest on a lawful basis. Consider whether your consent tooling should gate the tracker.
  - Addresses come from `REMOTE_ADDR` and are validated as real IPv4/IPv6 — remap behind a proxy or CDN with `convermetry_client_ip`.
  - To **anonymize rather than disable**, use `convermetry_tracked_event` to rewrite `ip_address` (e.g. zero the last octet) before the row is written; it runs on every analytics row.
  - When the site honors **Do Not Track / Global Privacy Control** and a visitor sends one, **no IP is stored on either path**. For analytics that visitor produces no rows at all; a form they submit is still recorded and delivered — they actively submitted it — but its `ip_address` is empty. Both paths go through one gate (`ClientIp::forStorage()`), so the setting, the signal, and this documentation cannot drift apart.
  - The Activity Log stores a redacted copy of each delivery's request payload, so IPs appear there too: the form payload's copy is replaced when *Store form submission data in the Activity Log* is off, but an **analytics report's** `conversions.recent[].ip_address` is logged regardless. Log rows age out with the same retention window, and `convermetry_delivery_log_row` can redact anything further.
- **No external geolocation service is ever contacted** — the legacy synchronous ipapi.co lookup was deliberately removed; a form submission never waits on any third party, and a stored IP is never sent anywhere except your own webhook endpoints.
- Optional **Do Not Track / Global Privacy Control** handling (off by default), enforced in the tracker, at the REST endpoint, and in the server-side conversion recorder. DNT/GPC is an opt-out signal, not a consent mechanism — gate the tracker with your consent tool if your jurisdiction requires consent.
- Logged-in users are excluded from tracking by default.
- Form `submission_data` is first-party lead data the visitor actively submitted; it is stored for delivery/retry and ages out with retention. The Activity Log's copy can be disabled in Settings, and `convermetry_delivery_log_row` supports field-level redaction.
- Everything is deleted after the configurable retention window (7–365 days, default 90) by bounded, chunked cleanup jobs.

## Database tables

| Table | Purpose |
|---|---|
| `{$prefix}cvm_events` | One row per visitor interaction (analytics engine). Unique `(batch_id, batch_seq)` makes tracker replays idempotent; indexed by type/date, type/session/date, date, and page URL. `form_success` rows carry the `conversion_id` in `event_value`. Stores the visitor `ip_address` unless disabled in Settings. |
| `{$prefix}cvm_form_submissions` | One row per server-confirmed submission: `submission_id` (unique), `conversion_id` (unique — the dedup point), session id, provider/form identity, page URL + query, submitter `ip_address` (empty when disabled in Settings), sanitized `submission_data`, frozen `analytics_context`, runtime overrides, plus the indexed `channel` and `utm_campaign` columns the Submissions page filters on, and the recorded `delivery_state` / `delivery_json` webhook outcome. |
| `{$prefix}cvm_delivery_queue` | The background form-delivery queue: one row per submission × endpoint with status, attempt, next-attempt time, claim token, and the frozen URL/headers/body. Rows are deleted on acknowledgment or abandonment. |
| `{$prefix}cvm_notification_queue` | The background email-notification queue: one row per submission × recipient with the frozen settings snapshot, status, attempt, next-attempt time, claim token, and last failure reason. Carries **no lead data** — the submission is read at send time. Rows are deleted on send, on abandonment, when the submission is deleted, or when their two-hour TTL expires. |
| `{$prefix}cvm_webhook_deliveries` | The Activity Log: one row per delivery attempt with normalized `message_type`/`kind`/`attempt` columns, identifiers, redacted headers, redacted request/response bodies (64 KB cap each). |

All tables are created via `dbDelta()` with versioned schema options; migrations are **verified** (columns and critical indexes checked) before their version is recorded, so a failed/partial migration retries on the next load. `channel` and `utm_campaign` are denormalized copies of two values that also live inside the frozen `analytics_context` — promoted to indexed columns so the Submissions page can filter and build dropdowns without decoding every row's JSON. `delivery_state` / `delivery_json` are likewise recorded rather than derived — see [Submissions](#submissions). Rows predating schema 1.2.0/1.3.0 are backfilled in chunks under a wall-clock budget by the daily cleanup cron, by a catch-up event scheduled right after the upgrade, and by the Submissions page itself (so sites whose WP-Cron never fires still finish). An un-backfilled row is exactly one whose `channel` or `delivery_state` `IS NULL`, so the backfill needs no progress option and terminates on its own. Large retry state never lives in autoloaded options — the analytics retry-state and last-sent options are stored with `autoload = no`, and form payloads live in the queue table.

## Uninstall behavior

**Deactivation preserves everything:** tables and data are kept, analytics retry chains are suspended (frozen deliveries resume under their original `delivery_id`s after reactivation), and queued form deliveries wait in the database for the re-armed worker.

**Deleting the plugin** (Plugins screen) runs `uninstall.php`: drops all four tables, deletes every option, transient, rate-limit counter row, and scheduled cron event. On **multisite**, the cleanup runs per site across the whole network. No trace remains.

## Folder structure

```text
convermetry/
├── convermetry.php              # Plugin header, PHP 8.3 guard, bootstrap, activation, helpers
├── uninstall.php                # Complete cleanup on plugin deletion (multisite-aware)
├── README.md
├── assets/
│   ├── css/admin.css            # Shared admin styles (cards, toggles, logs, forms, builders)
│   ├── css/dashboard.css        # Analytics dashboard + print styles
│   ├── js/admin.js              # Webhooks/Forms pages (repeater, builders, tests, filtering)
│   ├── js/dashboard.js          # Chart navigation/tooltips, panel state, print prep
│   ├── js/activity-log.js       # Activity Log accordions, filters, pagination, API card
│   ├── js/submissions.js        # Submissions list, filters, pagination, lazy detail panels
│   └── js/tracker.js            # Frontend tracker + form correlation
└── src/
    ├── Autoloader.php           # Minimal PSR-4 autoloader (no Composer)
    ├── Plugin.php               # Composition root
    ├── Admin/                   # AnalyticsPage, SubmissionsPage, FormsPage, NotificationsPage,
    │                             # WebhooksPage, ActivityLogPage, SettingsPage, AboutPage
    ├── Analytics/               # Reports (shared query layer), ReportQueryException,
    │                             # SubmissionContext (shared analytics-context enrichment)
    ├── Api/                     # TrackingController, DeliveryLogController
    ├── Database/                # DatabaseManager (events), FormSubmissions
    ├── Forms/                   # FormProviderInterface, FormProviderRegistry, FormSettings,
    │   │                        # SubmissionService, SubmissionResult, SubmissionFields
    │   └── Providers/           # Elementor, GravityForms, WPForms, ContactForm7,
    │                             # FluentForms, NinjaForms, FormidableForms
    ├── Notifications/           # NotificationSettings, NotificationDispatcher,
    │                             # NotificationQueue, EmailBuilder, NotificationMailer
    ├── Settings/                # Options (typed settings access)
    ├── Support/                 # Http (the single safe outbound transport),
    │                             # SensitiveKeys (shared credential-name policy)
    ├── Tracking/                # Channels (the one attribution engine), Correlation, ScriptLoader
    └── Webhook/                 # WebsiteInfoBuilder, PayloadBuilder, RequestFactory,
                                 # AnalyticsDispatcher, FormDeliveryQueue, DeliveryLog
```
