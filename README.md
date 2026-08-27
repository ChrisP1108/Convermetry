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

- **Version:** 0.5.0
- **Requires WordPress:** 6.3+
- **Requires PHP:** 8.3+
- **License:** GPL-2.0-or-later
- **Text domain / slug:** `convermetry` · **REST namespace:** `convermetry/v1` · **PHP namespace:** `Convermetry\`

---

## Contents

1. [Requirements & installation](#requirements--installation)
2. [Admin pages](#admin-pages)
3. [Submissions](#submissions)
4. [Goals](#goals)
5. [Funnels](#funnels)
6. [Form engagement & abandonment](#form-engagement--abandonment)
7. [Lead status & value](#lead-status--value)
8. [Analytics tracking](#analytics-tracking)
9. [Campaign & channel attribution](#campaign--channel-attribution)
10. [Session → submission → conversion correlation](#session--submission--conversion-correlation)
11. [Supported form providers](#supported-form-providers)
12. [Custom form integration API](#custom-form-integration-api)
13. [Notifications](#notifications)
14. [Webhooks](#webhooks)
15. [The three identifiers](#the-three-identifiers-submission_id--conversion_id--delivery_id)
16. [Payload schemas](#payload-schemas)
17. [HMAC signatures](#hmac-signatures)
18. [Retries & idempotency](#retries--idempotency)
19. [Activity Log](#activity-log)
20. [REST APIs](#rest-apis)
21. [Developer hooks](#developer-hooks)
22. [Privacy](#privacy)
23. [Database tables](#database-tables)
24. [Testing](#testing)
25. [Upgrading to 0.5.0](#upgrading-to-050)
26. [Uninstall behavior](#uninstall-behavior)

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
    Submissions    — every server-confirmed lead, with its attribution, answers,
                     status and value
    Goals          — conversions that are not form submissions, and how they perform
    Funnels        — the ordered path to a conversion, and where visitors drop out
    Forms          — provider status, discovered forms, per-form configuration,
                     engagement and abandonment
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

## Goals

A **goal** is an important visitor action that is *not* a form submission — a phone number tapped, a PDF opened, a booking link followed, a pricing page reached. Before 0.5.0 these were invisible: a site whose best leads phone rather than fill in a form had nothing to measure.

Goals are managed under **Convermetry → Goals**, where each goal is listed together with its performance for the selected period.

### Form submissions are not goals

A confirmed form submission is a **server-confirmed** conversion: the form plugin's own success hook told Convermetry it happened. A goal completion is a **browser-observed** signal. They are stored in different tables and counted separately, on purpose — folding submissions into goals would quietly downgrade the plugin's most trustworthy number to the standard of its least.

### Matching happens on the server

Goals are matched **server-side**, at ingestion, against data the tracker already sends. The browser is not told what your goals are. Three consequences:

- Your list of valuable actions is competitive information and stays on the server.
- **Phone and email goals need no configuration at all.** The tracker already reports click destinations and already keeps `tel:` and `mailto:` URLs whole while stripping query strings from everything else — so you pick "on a phone number link" and you are done. No CSS selector required.
- A visitor cannot manufacture a conversion by claiming one. They can only report the same raw activity any visitor reports; the server decides what it means.

The single exception is a **CSS selector**, which genuinely cannot be evaluated without the DOM. Only those selectors are sent to the tracker, and the goal ids it reports back are re-validated against your enabled selector goals before anything is recorded — so that channel can at most claim a goal that really is an enabled selector goal, and cannot reach a URL or custom-event goal at all.

### Goal types

| Type | Rules | Notes |
|---|---|---|
| **Reaching a page** | is exactly / contains / starts with / ends with | Write the path as it appears in the address bar (`/thank-you/`) and it is matched against the URL's path; write a full URL and the whole URL is matched. Trailing slashes are forgiven, and matching is case-insensitive. |
| **A click** | on a phone number link · on an email link · that leaves this site · where the link contains / is exactly · matching a CSS selector | `tel:` and `mailto:` need no value. A phone tap is deliberately **not** counted as an external link. |
| **A custom event** | named | Fired from your own code — see below. |

### Counting

Each goal counts either **once per visit** or **every occurrence**:

```text
Once per session      phone CTA tapped five times   → 1 completion
Every occurrence      PDF downloaded three times    → 3 completions
```

Deduplication is enforced by a **UNIQUE database constraint**, not a PHP check, so an at-least-once replay of a tracker batch collides with the original instead of double-counting.

### Custom events

```js
Convermetry.track('appointment_booked');

// Optionally a numeric value, read only when the matching goal is
// configured to accept one:
Convermetry.track('appointment_booked', { value: 250 });
```

The **name** is the only thing that can match a goal. An event whose name matches no configured goal is **discarded and never stored**, so a typo in your theme's JavaScript costs nothing and cannot fill the events table. Nothing else in the payload is ever stored — only a numeric `value`, and only for a goal set to accept one. This is deliberate: an API that accepted arbitrary properties would become an unaudited route for putting customer data into an analytics table.

The pre-existing `convermetry:conversion` DOM event and the server-side `cvm_track_event()` helper are unchanged.

### Goals depend on the tracking they are built on

A click goal cannot fire if click tracking is switched off in **Settings → Tracking**. Goals deliberately do **not** override those toggles — silently re-enabling tracking you turned off would be the wrong fix — so the Goals screen names the specific setting and links to it.

### Editing a goal

A goal keeps its id forever. Editing its **matching rule** starts a new measurement series (and the reports say the definition changed) so that two different questions are never blended into one line. Renaming a goal, pausing it, or changing what it is worth does **not** reset anything. Removing a goal is a soft delete: its past completions are kept and still appear in reports for earlier periods, still correctly labelled.

Goals start counting **from when you create them**. Completions are not applied retroactively.

---

## Funnels

A **funnel** measures the path to a conversion *in order*: how many sessions reached each step, and how many were lost between them. Managed under **Convermetry → Funnels**.

```text
Retirement Consultation Funnel

Landing Page          1,242 sessions
  ↓ 62% continued · 471 lost
Services                771 sessions
  ↓ 38% continued · 480 lost
Form Started            291 sessions
  ↓ 44% continued · 163 lost
Submission Attempted    128 sessions
  ↓ 81% continued · 24 lost
Confirmed Submission    104 sessions

Overall conversion: 8.37%
```

Step types: **visited a page**, **completed a goal**, **saw a form**, **started filling a form**, **attempted to submit**, and **submission confirmed by the form plugin**. A form step with no specific form counts any form on the site.

### Ordering is real

Steps must happen **in sequence**. A session that reached step three without step two is not counted at step three. Each step's position is constrained to occur strictly after the previous step's — the naive approach of comparing each step's earliest occurrence gets this wrong in a way that looks right on small data:

```text
Session did:  B at 09:00,  A at 10:00,  B at 11:00
A → B funnel: SHOULD succeed (they did A, then B)
Earliest-occurrence comparison: MIN(B)=09:00 < A, so it reports failure.
```

Ordering uses the event id rather than the timestamp, because the events table's `created_at` is the moment the row was *inserted* (the tracker sends no client timestamp) — so the two are the same order by construction, and the id is the finer, tie-free version of it. **A consequence worth knowing:** funnel order is *ingestion* order. Within one batch the browser's order is preserved; a batch that failed and was resent from a later page sorts by when it arrived.

Sessions with no session id are excluded — an empty session id is not one visitor, it is every visitor whose session could not be established, and grouping on it would produce one enormous pseudo-session that appears to complete every funnel.

### Cohorts and the completion window

A funnel is a **cohort**: the sessions that reached step 1 during the selected period. Later steps are counted for up to **24 hours past the end of the period**, so a session that entered at 23:55 on the last day is not unfairly cut off — without that, conversion rates would sag at every window edge for reasons unrelated to your site.

Each funnel's result is cached for five minutes, keyed by its definition, so editing a step invalidates the cache automatically.

Funnels are limited to 8 steps and 20 funnels per site.

---

## Form engagement & abandonment

**Convermetry → Forms** now reports the path between a visitor seeing a form and submitting it.

```text
Contact Form

Views                 1,428      Start Rate         47.8%
Started                 682      Completion Rate    40.5%
Attempts                311
Successful              276
Abandoned               406
```

### What each number counts

Mixing units here would make every rate meaningless and the mix would be invisible, so:

| Column | Unit | Evidence |
|---|---|---|
| Views | **sessions** in which the form scrolled into view | browser-observed |
| Started | **sessions** in which someone began filling it in | browser-observed |
| Attempts | **raw submit presses** — one visitor fighting a validation error produces several, which is the point | browser-observed |
| Successful | **distinct conversion ids** | **server-confirmed** by the form plugin |
| Abandoned | **sessions** that started and did not succeed | browser-observed |

A **completion rate above 100% is not an error.** It means confirmed submissions outnumbered observed starts, which happens when visitors submit with JavaScript blocked — the browser-observed columns are undercounting, and clamping the number to 100 would hide the one figure that tells you so.

### Abandonment has a grace period

A form started ninety seconds ago is being filled in, not abandoned. A start counts as abandoned only after **30 minutes** pass with no confirmed submission; anything more recent is shown as *still in progress*. Without that, abandonment would spike towards 100% for the most recent hour of any window and then decay — an artifact that looks exactly like a real problem. The 30 minutes matches the tracker's session idle window: past it the visitor's session has rotated, so a success could no longer be attributed to the same session as the start.

### Friction points — and what is never recorded

Where a form provider uses native browser validation, Convermetry records **which field failed and why**:

```text
Most common friction points

Field            Type     Problem                                  Errors
phone            tel      Left empty                                  218
desired-service  select   Left empty                                  164
email            email    Wrong format (e.g. not an email address)    131
```

**No value a visitor typed is ever recorded.** A validation event is rebuilt on the server from exactly three whitelisted pieces — the field's developer-chosen id, its type, and which `ValidityState` flag failed — and everything else in the request is discarded *by construction*. Field identifiers are additionally character-restricted and truncated to 64 characters, so an implementation that mistakenly sent a value would be stripped to something unrecognizable rather than quietly stored.

### Elementor is excluded from form-level engagement

Elementor identifies a form by its **display name** on the server while exposing a **widget id** in the browser, so the two cannot be matched reliably — and an engagement figure attributed to the wrong form is worse than none. Elementor submissions are recorded, attributed, delivered, and reported normally everywhere else in Convermetry. The other six providers are fully supported.

---

## Lead status & value

Stored submissions can now carry an **outcome** and a **monetary value**, so campaign reporting can be measured against what leads were actually worth rather than treating every conversion as equal. Set both on the **Submissions** detail panel; both are filterable in the list.

```text
Status:  [ Qualified ▼ ]      Lead Value:  [ 12,500.00 ]
```

| Status | Meaning |
|---|---|
| `new` | Not yet assessed — the default for every submission |
| `qualified` | A real, well-matched lead |
| `unqualified` | A genuine person who was not a fit |
| `won` | Converted into business |
| `lost` | A real lead that did not convert |
| `spam` | Never a lead at all |

### This is not a CRM

Six statuses, deliberately. There are no assignees, pipeline stages, follow-up dates, or activity notes — every one of those would be a worse version of a tool you already have, and none changes the answer to *"which marketing produced valuable leads?"*.

Two grouping rules carry real weight:

- **`won` counts as qualified.** A lead that converted was self-evidently qualified, and requiring it to pass through `qualified` first would under-report every site that records the final outcome in one step.
- **Only `spam` leaves the denominator.** An unqualified or lost lead was still a lead your marketing produced. Excluding those would make a channel look better the more poor-quality leads it sent. `spam` is separate from `unqualified` for exactly this reason: merging them would inflate every denominator with bot traffic.

### Value and currency

Values are stored as exact `DECIMAL(13,2)` and handled as decimal **strings** end to end — never as floating point. A lead worth 0.10 recorded ten thousand times totals exactly 1000.00.

Input is forgiving about presentation and strict about value: `$12,500.00`, `12 500`, `€1.234,56` and `1234.56 USD` all parse; `12abc` is **rejected** rather than silently read as 12.

The site currency is set under **Settings → Data** and is **stamped onto each lead** when you first record a value — changing the setting later never rewrites what is already recorded. Reports therefore **group by currency and never sum across codes**: a column adding 100 EUR to 100 USD and showing 200 would be a fabricated number.

### History

Every status or value change is recorded with who made it and when, shown in the submission's detail panel. The change and its history row are written in a single transaction, so a lead can never end up in a state its history disagrees with.

### Lead outcomes do not reach webhooks in 0.5.0

This is a deliberate decision, not an omission. Form submission payloads are **frozen on their first delivery attempt**, and scheduled analytics windows **advance without ever revisiting**. A `lead` block on either could therefore only ever report a lead as `new`/`null` — wrong for every lead anybody ever qualifies, and a field that lies is worse than an absent one.

The `cvm_lead_events` history table exists so that a `lead_status_changed` message can be added once there is a delivery path whose semantics can carry a correction. That is the top item for the next release.

### Reporting

**Analytics → Lead outcomes** breaks leads down by channel, campaign, landing page, and form:

```text
Channel            Leads  Qualified  Won  Qual. Rate  Attributed Lead Value

Paid Search          211        129   31       61.1%           247,500.00 USD
Organic Search       180        102   24       56.7%           194,000.00 USD
Referral              62         39   11       62.9%            83,500.00 USD
Paid Social          141         37    7       26.2%            42,000.00 USD
```

- **Lead Qualification Rate** = (qualified + won) ÷ total leads
- **Lead-to-Win Rate** = won ÷ total leads
- **Attributed Lead Value** = the total recorded against the cohort
- **Attributed Revenue** = the same, restricted to leads marked `won`

**Nothing is called ROI or ROAS.** Both are ratios against ad *spend*, Convermetry has no cost data, and a "return" computed without the investment half is not a weaker version of the metric — it is a different number wearing its name.

Leads are counted by **the date the lead arrived**, with status read as it stands now — so these figures move as you qualify leads, which is precisely why they are not shipped in scheduled webhook payloads.

### Time to lead

```text
Under 5 minutes            41   38.0%
5–30 minutes               22   20.4%
30 minutes–24 hours        18   16.7%
1–7 days                   19   17.6%
7+ days                     8    7.4%

Paid Search — median      18 min
Organic Search — median   1.4 days
```

Measured from the **first page view of the session that converted**. Convermetry keeps **no persistent visitor identity across sessions** and this release does not invent one, so a visitor who researched for a week and returned in a fresh session is measured from that final visit — the narrower truth beats a cross-visit figure derived from an identity that does not exist.

Medians rather than averages: the distribution is heavily right-skewed, and one lead that took three weeks would drag an average past every real experience of your site.

---

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
    "plugin_version": "0.5.0",
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
    "schema_version": "1.1",
    "source": "convermetry",
    "plugin_version": "0.5.0",
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
        "devices": { "desktop": 820, "mobile": 420 },
        "goals": {
            "sessions": 1180,
            "total": 3,
            "truncated": false,
            "goals": [
                {
                    "goal_id": "g4f2a9c1b8e0d21f0",
                    "name": "Phone number tapped",
                    "completions": 84,
                    "sessions": 84,
                    "conversion_rate": 7.12,
                    "value": "0.00",
                    "currency": ""
                },
                {
                    "goal_id": "g7c1d6a4b98e73d2",
                    "name": "Brochure downloaded",
                    "completions": 51,
                    "sessions": 39,
                    "conversion_rate": 3.31,
                    "value": "1275.00",
                    "currency": "USD"
                }
            ]
        }
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
- **`goals`** (new in schema 1.1) reports [goal](#goals) completions for the window. `goals.sessions` is the denominator every `conversion_rate` uses — sessions in the window, not completions — so a once-per-session goal can never exceed 100%. `completions` counts every recorded completion; `sessions` counts distinct sessions that completed it. `value` is an exact decimal **string**, never a number, and is `"0.00"` when the goal carries no configured value; parse it with a decimal type, not a float. The list is capped at 100 goals ordered by completions, and `truncated` says so explicitly rather than leaving you to infer it from a row count.

**Test analytics payloads** (**Send analytics test** on the Webhooks page) cover the **last 7 days**, carry `"test": true`, get a fresh random `delivery_id`, are **never retried**, and **do not advance the endpoint's last-sent marker** — so testing an endpoint never creates a gap in its scheduled reporting.

### Form submission payload

`message_type: form_submission` — the canonical full example. Where an Analytics Report carries reporting data for a window, a Form Submission carries **one lead's own data plus the analytics context correlated to it**. Endpoints opt into it independently of analytics reports.

```json
{
    "schema_version": "2.0",
    "source": "convermetry",
    "plugin_version": "0.5.0",
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

Convermetry exposes a public hook API for plugins and code snippets. Two rules hold across all of it:

- **Nothing registered means nothing changes.** With no callbacks, payload bytes, request URLs and headers, delivery ids, signatures, retry schedules, analytics results, admin HTML, REST output, CSV files, and tracker configuration are all exactly what they were. No `extensions` property appears anywhere until something fills it.
- **Filters that customize data may see that data; observers may not.** A filter whose job is to change an email body necessarily sees the email body. The observational actions deliberately carry ids, counts, and outcomes — never submitted fields, rendered emails, request bodies, response bodies, signing secrets, credential-bearing URLs, or raw IP addresses. Where an argument does carry personal data it is called out below.

### Webhook delivery

Composition filters run **once per logical delivery, before the request is frozen**. A retry re-sends the frozen bytes and never re-runs them, so nothing here can change a delivery already in flight.

| Hook | Type | Purpose |
|---|---|---|
| `convermetry_webhook_payload` | filter | Modify any outbound payload before it is frozen/encoded. `(array $payload, string $messageType, array $meta)` — `$meta` is `['start','end']` for reports, `['submission_id']` for submissions. Runs last, after the extensions filter |
| `convermetry_webhook_payload_extensions` | filter | Add namespaced data as the payload's `extensions` property. `(array $extensions, string $messageType, array $meta)` — keys must be `vendor/thing`; bounded to 32 KB / 50 keys / JSON primitives; an empty result adds no property at all |
| `convermetry_webhook_query_args` | filter | Query parameters appended to the endpoint URL, after the global → page → per-form → runtime merge. `(array $params, array $context)` — result re-normalized to bounded scalar keys/values, order preserved; the URL still passes `wp_safe_remote_post()`'s SSRF checks |
| `convermetry_webhook_headers` | filter | Non-protocol request headers, after the global → per-form → runtime merge. `(array $headers, array $context)` — a callback may **not** add, alter, or remove `Content-Type`, `Host`, `Content-Length`, `Transfer-Encoding`, `Connection`, `User-Agent`, `Idempotency-Key`, or `X-Convermetry-Signature`; those are restored to their pre-filter state. Values are sent as-is, and the Activity Log redacts by NAME |
| `convermetry_webhook_timeout` | filter | HTTP timeout in seconds for one attempt (default 15). `(int $timeout, array $context)` — runs per network attempt; values outside 1–30 are **ignored, not clamped**. Raising it costs queue throughput: the worker's whole pass budget is 45s. Redirects, TLS verification, blocking mode, and the response-size cap are deliberately not filterable |

The lifecycle actions all receive the same credential-free `$context`: `message_type`, `kind` (`scheduled`/`immediate`/`retry`/`test`), `attempt`, `delivery_id`, `is_test`, `endpoint_key`, `endpoint_label`, `endpoint_origin` (scheme + host only), `submission_id`, `conversion_id`, `form_key`, `window_start`, `window_end`, `transport_attempted`, `disposition`. Every key is always present.

| Hook | Type | Purpose |
|---|---|---|
| `convermetry_form_delivery_queued` | action | A submission was genuinely queued for one endpoint. `(array $context)` — only when the `INSERT IGNORE` created a row; a suppressed duplicate fires nothing. Nothing is sent or frozen yet |
| `convermetry_webhook_delivery_frozen` | action | A delivery's body, URL, and headers are now fixed. `(array $context, string $storage, int $bodyBytes)` — `$storage` is `'memory'` (analytics, persisted only if a retry follows) or `'queue_row'` (form queue, verified written). Never fires on a frozen retry, nor on the synchronous or test paths, which freeze nothing |
| `convermetry_webhook_before_send` | action | Immediately before a real network request, after signing. `(array $context, array $meta)` — `$meta` is `['body_bytes','body_sha256','header_names','signed']`, metadata only: no URL, no header values, no body. Fires per attempt; does **not** fire when encoding or a report query failed before the wire. Do not throw from here — the request it announces would not happen |
| `convermetry_webhook_delivery_attempted` | action | One attempt's **transport** result. `(array $context, bool $ok, int $code, string $message)` — `transport_attempted` is false when nothing reached the wire. Deliberately does not report the retry/queue disposition; nothing has decided one yet |
| `convermetry_delivery_attempt_logged` | action | What became of the Activity Log row. `(array $context, string $disposition)` — `'stored'`, `'suppressed'` (a `convermetry_delivery_log_row` callback returned false), or `'failed'` |
| `convermetry_webhook_delivery_succeeded` | action | The endpoint accepted it **and** the bookkeeping committed. `(array $context)` — analytics: last-sent advanced and retry chain cleared; form queue: row deleted and delivery state recomputed. Once per successful attempt per endpoint |
| `convermetry_webhook_retry_scheduled` | action | The next attempt is persisted. `(array $context, int $nextAttempt, int $nextAttemptAt)` — never speculative; never on a test or on the synchronous path |
| `convermetry_webhook_retry_chain_exhausted` | action | An **analytics** chain gave up, and its terminal state is persisted. `(array $context)` — *not* abandonment: the frozen body stays in the retry state and the next scheduled dispatch resumes it. Read as "this endpoint is failing", not "this data is gone" |
| `convermetry_webhook_delivery_abandoned` | action | A queued **form** delivery is gone for good, its row deleted. `(array $context, string $reason)` — genuinely terminal, unlike the analytics chain |
| `convermetry_webhook_delivery_canceled` | action | A queued delivery was removed unsent. `(array $context, string $reason)` — currently `'submission_deleted'`: the submission was deleted before the worker reached the row |
| `convermetry_retry_schedule` | filter | The webhook retry backoff delays in seconds (both message types) |
| `convermetry_webhook_report_limit` | filter | Max rows per `top_*` list in analytics payloads (default 200) |
| `convermetry_delivery_log_row` | filter | Redact/modify an Activity Log row before storage; return `false` to skip logging |
| `convermetry_allow_insecure_webhooks` | filter | Return `true` to allow `http://` endpoints (development only) |
| `convermetry_allowed_hosts` | filter | Hostnames accepted in tracked URLs / Origin checks, treated as internal in referrer reports |

### Tracking

| Hook | Type | Purpose |
|---|---|---|
| `convermetry_should_enqueue_tracker` | filter | Whether to load the frontend tracker on this request. `(bool $should, array $enabled)` — runs after the configured exclusions, so it can only suppress, never resurrect. Runs on `wp_enqueue_scripts`, so conditional tags are available |
| `convermetry_tracker_config_extensions` | filter | Add namespaced data to `window.ConvermetryConfig.extensions`. `(array $extensions, array $enabled)` — smallest budget in the plugin (8 KB / 20 keys), because this is inlined into every page view. **This data is public**: never put a key, token, or anything visitor-specific here. The REST endpoint and batching limits cannot be replaced |
| `convermetry_should_track_event` | filter | Whether to record one tracked event. `(bool $should, string $type, array $data)` — runs **last** in sanitization, so `$data` is whitelisted and bounded; raw anonymous input from the public endpoint is never exposed to a hook. Returning `false` drops this event only |
| `convermetry_tracked_event` | filter | Inspect/modify an event row before storage; return `false` to drop. `(array $row, string $type)` |
| `convermetry_client_ip` | filter | Map the client IP used for tracking rate limits **and** as the basis of the stored address (reverse proxies / CDNs) |
| `convermetry_stored_ip` | filter | The address about to be **persisted**, after the privacy gates. `(string $ip)` — the pseudonymization hook (truncate, hash, or return `''`). Must return a valid IPv4/IPv6 address or `''`. Deliberately does **not** affect the rate-limit identity, which would collapse every visitor into one bucket |
| `convermetry_tracking_batch_recorded` | action | One batch was written. `(int $stored, int $accepted, int $offered, ?string $batchId)` — one action per batch, never per event, on the plugin's hottest path. The three counts differ: offered → accepted (survived sanitization) → stored (survived deduplication) |
| `convermetry_tracking_rate_limited` | action | A batch was rejected by the rate limiter. `(int $events, int $window)` — carries no address and no hash of one; the endpoint is public and unauthenticated |
| `convermetry_rate_limits` | filter | `['per_ip' => 300, 'site_wide' => 3000]` events/minute |
| `convermetry_source_aliases` | filter | Extend/override the utm_source alias map |
| `convermetry_channel` | filter | Override the marketing channel assigned at ingestion. `(string $channel, array $row, string $type)` |

### Analytics

| Hook | Type | Purpose |
|---|---|---|
| `convermetry_analytics_sections` | filter | Register `AnalyticsSectionInterface` adapters that add a dashboard panel **and** contribute to `analytics.extensions` on the wire. `(array $sections)` — a typed registry, never SQL: there is deliberately no way to pass a query fragment or table name to a path that runs unattended on cron. Keys must be namespaced; a section that throws is dropped, not propagated |
| `convermetry_analytics_extensions` | filter | Extension data attached to an analytics summary. `(array $extensions, string $start, string $end, int $limit)` — pre-populated from registered sections. Computed inside `Reports::buildSummary()`, i.e. once per delivery at freeze time; a retry never rebuilds it. Bounded to 32 KB / 50 keys |
| `convermetry_analytics_periods` | filter | Reporting periods (in days) offered on the dashboard. `(int[] $periods)` — default `[7, 30, 90]`; validated, deduplicated, sorted, and **clamped to the retention window** so a period longer than the data cannot draw a chart that looks like a traffic collapse |
| `convermetry_analytics_report_failed` | action | A report could not be generated. `(string $component, string $reportKey, string $start, string $end, string $error)` — `$error` is an exception **class name**, never a message: a database error quotes the failing statement |
| `convermetry_analytics_admin_panels` | action | Render extra panels at the end of the dashboard. `(string $start, string $end)` — runs after this screen's capability check; **your callback must escape its own output** |

### Forms & submissions

| Hook | Type | Purpose |
|---|---|---|
| `convermetry_should_record_submission` | filter | Whether to record a submission at all. `(bool $should, string $formKey, string $provider, array $fields)` — runs after normalization (so spam rules can read the fields) and before **any** write. `false` skips the conversion event, the row, the queue, and the notifications. The visitor sees success: returning a failure would make Elementor's synchronous mode reject a valid form. **`$fields` contains PII** |
| `convermetry_submission_fields` | filter | The normalized field descriptors. `(array $fields, string $formKey, string $provider)` — a **changed** result is re-normalized, so `cvm_*` stays stripped and the descriptor shape holds. **Contains PII** |
| `convermetry_submission_context_extensions` | filter | Namespaced data added to the stored analytics context. `(array $extensions, string $formKey, string $provider)` — attached once before persistence, so every endpoint and every retry sees the same context. Cannot replace conversion id, session id, attribution, timestamps, or form identity |
| `convermetry_submission_recorded` | action | Fires after a submission is recorded, before webhook delivery is considered — so listeners run even with no endpoints configured (this is where notifications are queued). `($submissionId, $conversionId, $context)` |
| `convermetry_submission_recorded_details` | action | Fires immediately after the above with what its fixed signature cannot carry. `(int $rowId, string $submissionId, array $form, array $fields)` — `$form` is `{provider, form_key, form_name, native_id}`. **`$fields` contains PII**; use `convermetry_submission_recorded` if you only need to know a submission happened |
| `convermetry_submission_duplicate` | action | A duplicate of an already-recorded submission (double-fired callback, replayed AJAX). `(string $submissionId, string $conversionId, string $formKey)` — nothing is written or re-queued; **do not re-send anything** |
| `convermetry_submission_delivery_state_changed` | action | The recorded delivery state genuinely changed. `(string $submissionId, string $state, string $previous)` — only on a transition; the state is recomputed several times per delivery and most recomputations are silent |
| `convermetry_submission_deleted` | action | A submission and everything attached to it are gone. `(int $id, string $submissionId)` — fires last, after the queue rows, queued notifications, and lead history are removed. Carries ids only: the data is what is being erased |
| `convermetry_submissions_cleared` | action | Every submission, queued delivery, queued notification, and lead history row was removed. `()` — once for the whole operation; the rows are dropped with `TRUNCATE` and bulk deletes that never load one |
| `convermetry_form_settings_saved` | action | Per-form settings were written. `(string[] $formKeys)` — fires from the storage layer on a real write, so CLI callers raise it too |
| `convermetry_discovered_forms` | filter | The forms discovered for one provider. `(array $forms, string $providerKey)` — runs **before** the 5-minute cache is written, so the result is normalized back to `{native_id, name}`, empty ids dropped, duplicates collapsed |
| `convermetry_form_providers` | filter | Register custom `FormProviderInterface` adapters |
| `convermetry_submission_csv_columns` | filter | The export's columns as an ordered `key => header label` map. `(array $columns)` — paired with the values filter **by key, never by position**, so the two cannot drift out of alignment |
| `convermetry_submission_csv_values` | filter | One exported row's `key => value` map. `(array $values, array $row)` — runs per row while streaming, so keep it cheap. Values must be scalar or null and go through the same formula-injection escaping as core ones. **Contains PII** |
| `convermetry_submissions_columns` | filter | Extra cells appended to each row of the submissions list. `(array $columns, array $row)` — `key => already-escaped HTML`, **printed verbatim, so escape it yourself**. **`$row` contains PII** |
| `convermetry_submission_detail_sections` | action | Render extra blocks at the end of a submission's detail panel. `(array $row)` — after the nonce and capability checks; **escape your own output**. **`$row` contains PII** |
| `convermetry_submission_row_actions` | action | Render extra buttons in a submission's action bar. `(array $row)` — nonce-protect anything that acts. **`$row` contains PII** |
| `convermetry_forms_admin_sections` | action | Render extra content at the end of the Forms screen. `()` — outside the settings form, so post your own form to `admin-post.php`. **Escape your own output** |
| `convermetry_form_submission` | action | Submit a custom form (fire-and-forget, background delivery) |

### Goals, funnels & leads

| Hook | Type | Purpose |
|---|---|---|
| `convermetry_should_record_goal_completion` | filter | Whether to record one matched completion. `(bool $should, array $row, array $goal)` — a decision only: the row is passed for inspection, and nothing returned changes it. The completion id, definition hash, event uid, dedupe key, and timestamp are identity, and a hook that could rewrite them could silently defeat once-per-session goals |
| `convermetry_goal_completion` | filter | Inspect/modify a [goal](#goals) completion row before it is written. `(array $row, array $goal)` |
| `convermetry_goal_matched` | action | Fires after a batch of goal completions is stored. `(int $stored, array $rows)` |
| `convermetry_goal_completions_recorded` | action | Fires immediately after the above with the offered/stored split. `(int $stored, int $offered, array $completionIds)` — the two counts differ because completions are written with `INSERT IGNORE` against a dedupe key; `$completionIds` are the ids **offered**, not the ids stored |
| `convermetry_goal_saved` | action | A goal definition was persisted. `(string $goalId, array $goal, ?array $previous)` — `$previous` is null for a new goal. Fires from the repository, so CLI callers raise it too, and only on a successful write |
| `convermetry_goal_deleted` | action | A goal was deleted. `(string $goalId, string $now)` — only when it existed and the write succeeded. The deletion is soft: completions and the name survive so historical reports keep working |
| `convermetry_funnel_saved` | action | A funnel definition was persisted. `(string $funnelId, array $funnel, ?array $previous)` — editing a funnel changes what every past report says, retroactively |
| `convermetry_funnel_deleted` | action | A funnel was deleted. `(string $funnelId, string $now)` |
| `convermetry_lead_status_updated` | action | Fires after a [lead's](#lead-status--value) status or value changes. `($submissionId, $toStatus, $fromStatus, ?string $value, string $currency)` |
| `convermetry_lead_updated` | action | Fires immediately after the above with the full before/after. `(string $submissionId, array $to, array $from, int $userId, string $leadEventId)` — `$to`/`$from` are `{status, value, currency}`. Both fire **after** the transaction commits. Values are exact decimal **strings**, never floats; currency is stamped, not converted, and a null value is not `'0.00'` |

### Notifications

| Hook | Type | Purpose |
|---|---|---|
| `convermetry_should_queue_notification` | filter | Whether to queue notifications for a submission. `(bool $should, string $formKey, array $identity)` — runs after the configured rules said yes, so it can only narrow. `$identity` is identity columns, never field values |
| `convermetry_notification_recipients` | filter | The addresses to queue. `(array $recipients, string $formKey, array $identity)` — runs once at **queue** time; each address becomes its own row with its own retry chain. Re-validated through `sanitize_email()`/`is_email()`, deduplicated, capped at 20 |
| `convermetry_notification_message` | filter | Subject, HTML body, and additional headers, per attempt. `(array $message, string $submissionId, int $attempt)` — the **recipient is not changeable**: one row is one address, and a per-attempt rewrite could collapse two rows onto one mailbox. Subject gets the header-injection strip and 200-char cap, the body the 256 KB cap, and the four required headers are reinstated. **`$message['html']` contains PII** |
| `convermetry_notification_queued` | action | A notification was genuinely queued. `(string $submissionId, string $recipient, int $attempt)` — only for a real insert |
| `convermetry_notification_before_send` | action | Immediately before `wp_mail()`. `(string $submissionId, string $recipient, int $attempt)` — no subject, no body, no fields |
| `convermetry_notification_accepted` | action | `wp_mail()` returned true **and** the queue row was removed. `(string $submissionId, string $recipient, int $attempt)` — "accepted", never "delivered": the local transport took the message, which is not receipt |
| `convermetry_notification_retry_scheduled` | action | The next attempt is persisted. `(string $submissionId, string $recipient, int $nextAttempt, int $nextAttemptAt)` |
| `convermetry_notification_abandoned` | action | Retries spent, row deleted, message will never be sent. `(string $submissionId, string $recipient, int $attempt, string $error)` |
| `convermetry_notification_canceled` | action | Queued notifications were cancelled unsent. `(string $submissionId, string $recipient, string $reason, int $count)` — `$reason` is `'expired'`, `'submission_deleted'`, or `'admin_clear'`. Per-row from the worker; **one aggregate action** for bulk clears, with `$recipient` empty — addresses are never read back purely to emit a hook |
| `convermetry_notification_retry_schedule` | filter | The email-notification retry backoff in seconds (default `[300, 900, 3600]`). Deliberately separate from the webhook schedule — a stale lead notification is worse than none, and email has no receiver-side idempotency |
| `convermetry_sensitive_keys` | filter | Extend the credential-looking field/header names redacted from the Activity Log **and** omitted from notification emails (e.g. add `ssn`). Matched as substrings of a canonical form: lowercase with non-alphanumeric runs collapsed to `_`, so `API Key`, `x-api-key` and `API_KEY` all match `api_key`. Extend it; returning a shorter list weakens both surfaces |

### Operations, settings & API

| Hook | Type | Purpose |
|---|---|---|
| `convermetry_retention_cleanup_started` | action | One store begins deleting past the retention cutoff. `(string $store, string $cutoff)` — observational: a listener **cannot** cancel a pass, change the cutoff, or extend retention |
| `convermetry_retention_cleanup_completed` | action | One store's pass finished. `(string $store, string $cutoff, int $deleted, bool $moreRemain, string $outcome)` — `$outcome` is `completed`/`truncated`/`query_failed`/`lock_lost`. Convermetry schedules any follow-up pass itself |
| `convermetry_migration_started` | action | A migration pass began, with the lease held. `(string $context)` — `'cli'`, `'cron'`, or `'admin'`. Do not throw: the lease would be held until it expires. No SQL is passed to any migration hook |
| `convermetry_migration_completed` | action | A pass finished. `(string $context, bool $pending)` — fires after the lease is released and after the pending check **and** reschedule decision, so `$pending` is settled. A pending migration is normal mid-migration, not an error |
| `convermetry_migration_failed` | action | A pass threw. `(string $context, string $error)` — `$error` is the exception **class name**. Fires after the lease is released and before the failure continues to the caller. A migration that merely did not land is not a failure |
| `convermetry_storage_error` | action | A database operation Convermetry needed verifiably failed. `(string $subsystem, string $operation, string $code, array $context)` — reserved for verified failures: a duplicate `INSERT IGNORE`, an abandoned notification, or a still-pending migration do **not** fire it. Never carries SQL, `$wpdb->last_error`, submitted fields, IPs, or secrets |
| `convermetry_settings_saved` | action | A settings section was written. `(string $section, string[] $changedKeys)` — listens on WordPress's own option-write hooks, so it fires on a real write only (never for a form submitted without edits) and catches CLI and migration writers too. **Key names only, never values**: two sections hold signing secrets and token-bearing endpoint URLs |
| `convermetry_admin_capability` | filter | The capability required for one admin surface. `(string $capability, string $scope)` — scopes: `analytics.view`, `submissions.view`, `submissions.export`, `submissions.delete`, `leads.edit`, `goals.manage`, `funnels.manage`, `forms.manage`, `notifications.manage`, `webhooks.manage`, `activity.view`, `activity.manage`, `api.manage`, `settings.manage`. All default to `manage_options` and are applied to menu visibility **and** every handler behind it. Must return a non-empty lowercase `[a-z0-9_]` name; anything else falls back, because `current_user_can('')` would lock the owner out. Grant deliberately — `submissions.export` is every lead's name and email in one file |
| `convermetry_delivery_log_api_item` | filter | Add a namespaced `extensions` property to one delivery-log REST item. `(array $extensions, array $item)` — runs after endpoint-URL redaction and body decoding. The core keys are **immutable**: a filter that could rewrite `success` would let a plugin lie to a monitoring dashboard. Bounded to 4 KB / 10 keys |

### Examples

**Add data to every outbound webhook payload.** Runs before the payload is frozen, so retries re-send it unchanged:

```php
add_filter('convermetry_webhook_payload_extensions', function (array $extensions, string $messageType, array $meta): array {
    if ($messageType === 'form_submission') {
        $extensions['acme/crm'] = [
            'tenant' => get_option('acme_tenant_id'),
            'source' => 'wordpress',
        ];
    }

    return $extensions;
}, 10, 3);
```

**Add a header to one endpoint only.** The context identifies the endpoint without exposing its URL:

```php
add_filter('convermetry_webhook_headers', function (array $headers, array $context): array {
    if ($context['endpoint_origin'] === 'https://hooks.acme.test') {
        $headers['X-Acme-Tenant'] = get_option('acme_tenant_id');
    }

    return $headers;
}, 10, 2);
```

**Observe successful and abandoned deliveries.** Note which action means what: an exhausted *analytics* chain is resumable, an abandoned *form* delivery is not:

```php
add_action('convermetry_webhook_delivery_succeeded', function (array $context): void {
    if ($context['is_test']) {
        return;
    }

    acme_metrics_increment('convermetry.delivered', ['endpoint' => $context['endpoint_label']]);
});

add_action('convermetry_webhook_delivery_abandoned', function (array $context, string $reason): void {
    // Terminal: this submission will never reach this endpoint.
    acme_alert("Convermetry gave up delivering {$context['submission_id']} to {$context['endpoint_label']} ({$reason})");
}, 10, 2);
```

**Skip recording a submission.** Runs before any write, and the visitor still sees success:

```php
add_filter('convermetry_should_record_submission', function (bool $should, string $formKey, string $provider, array $fields): bool {
    foreach ($fields as $field) {
        if ($field['id'] === 'email' && str_ends_with((string) $field['value'], '@internal.example')) {
            return false; // Staff testing the form — not a lead.
        }
    }

    return $should;
}, 10, 4);
```

**Add a section to the dashboard and the analytics payload.** Implement `AnalyticsSectionInterface` rather than passing SQL:

```php
add_filter('convermetry_analytics_sections', function (array $sections): array {
    $sections[] = new Acme_Subscriptions_Section(); // getKey() returns 'acme/subscriptions'
    return $sections;
});
```

For something small with no queries and no dashboard panel, the filter is enough:

```php
add_filter('convermetry_analytics_extensions', function (array $extensions, string $start, string $end): array {
    $extensions['acme/plan'] = ['tier' => get_option('acme_plan_tier')];
    return $extensions;
}, 10, 3);
```

**React to a lead update.** Fires after the update and its history row have both committed; values are exact decimal strings:

```php
add_action('convermetry_lead_updated', function (string $submissionId, array $to, array $from, int $userId, string $leadEventId): void {
    if ($to['status'] === 'won' && $from['status'] !== 'won') {
        acme_crm_close_deal($submissionId, $to['value'], $to['currency'], $leadEventId);
    }
}, 10, 5);
```

Helper functions: `convermetry_submit_form()` (result-aware submission) and `cvm_track_event()` (custom server-side analytics event). In the browser, `Convermetry.track(name, { value })` reports a [custom event](#custom-events) and the pre-existing `convermetry:conversion` DOM event is unchanged.

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
- **Form abandonment records no field values, ever.** A validation event is rebuilt server-side from three whitelisted pieces — the field's developer-chosen id, its type, and which `ValidityState` flag failed — and every other key in the request is discarded by construction rather than by a blocklist. Field ids are character-restricted and truncated to 64 characters, so an implementation that mistakenly sent a typed value would be stripped to something unrecognizable rather than quietly stored. See [Form engagement & abandonment](#form-engagement--abandonment).
- **Custom event payloads are not storage.** `Convermetry.track('name', {...})` sends only the event name and, when the matching goal is configured for it, a single numeric value. Nothing else in that object is transmitted or stored — an API accepting arbitrary properties would be an unaudited route for putting customer data into an analytics table. An event matching no configured goal is discarded and never stored at all.
- **Goal and funnel records carry no PII.** They hold only normalized URLs (already stripped of query strings), the attribution snapshot, and a device bucket. No submitted field value can reach them.
- **Lead status and value stay on the submission record** and its history table. They are never duplicated into event storage, and in 0.5.0 they never leave the site — see [Lead status & value](#lead-status--value).
- **Goal definitions are not published to visitors.** Matching happens on the server. The one exception is a CSS-selector goal, whose selector must reach the browser to be evaluated; the ids reported back are re-validated server-side before anything is recorded.
- Goal completions and lead status history age out on the **same retention window** as everything else, and both tables are dropped on uninstall.
- Everything is deleted after the configurable retention window (7–365 days, default 90) by bounded, chunked cleanup jobs.

## Database tables

| Table | Purpose |
|---|---|
| `{$prefix}cvm_events` | One row per visitor interaction (analytics engine). Unique `(batch_id, batch_seq)` makes tracker replays idempotent; indexed by type/date, type/session/date, date, page URL, `form_key`/type/date, and session/type/id (the funnel step chain). `form_success` rows carry the `conversion_id` in `event_value`. `form_key` is the form lifecycle's shared dimension across `form_view` → `form_start` → `form_error` → `form_submit` → `form_success`, and is empty on every other type. Stores the visitor `ip_address` unless disabled in Settings. |
| `{$prefix}cvm_form_submissions` | One row per server-confirmed submission: `submission_id` (unique), `conversion_id` (unique — the dedup point), session id, provider/form identity, page URL + query, submitter `ip_address` (empty when disabled in Settings), sanitized `submission_data`, frozen `analytics_context`, runtime overrides, plus the indexed `channel`, `utm_campaign`, `utm_source`, `utm_medium`, `utm_id` and `landing_page` columns the Submissions page filters on and the lead reports group by, the `lead_status` / `lead_value` / `lead_currency` / `lead_status_at` outcome columns, and the recorded `delivery_state` / `delivery_json` webhook outcome. |
| `{$prefix}cvm_delivery_queue` | The background form-delivery queue: one row per submission × endpoint with status, attempt, next-attempt time, claim token, and the frozen URL/headers/body. Rows are deleted on acknowledgment or abandonment. |
| `{$prefix}cvm_notification_queue` | The background email-notification queue: one row per submission × recipient with the frozen settings snapshot, status, attempt, next-attempt time, claim token, and last failure reason. Carries **no lead data** — the submission is read at send time. Rows are deleted on send, on abandonment, when the submission is deleted, or when their two-hour TTL expires. |
| `{$prefix}cvm_webhook_deliveries` | The Activity Log: one row per delivery attempt with normalized `message_type`/`kind`/`attempt` columns, identifiers, redacted headers, redacted request/response bodies (64 KB cap each). |
| `{$prefix}cvm_goal_completions` | One row per [goal](#goals) completion. `dedupe_key` carries a UNIQUE index and is the entire deduplication mechanism for both counting behaviours. `source_event_id` is the id of the event that triggered the completion, which is what gives a goal step its position in [funnel](#funnels) ordering. `completion_id` is a stable public identifier. Marketing dimensions (channel, source/medium/campaign/id, landing page, device) are denormalized onto the row so every breakdown needs no join. `value` is `DECIMAL(13,2)`, nullable — `NULL` means "no value configured", which is not the same fact as `0.00`. |
| `{$prefix}cvm_lead_events` | [Lead](#lead-status--value) status-change history: one row per transition with the previous and new status, the value as at that change, the user who made it, and a stable `lead_event_id`. Rows are cascaded away when the submission is deleted, when all submissions are cleared, and by retention. |

All tables are created via `dbDelta()` with versioned schema options; migrations are **verified** (columns and critical indexes checked) before their version is recorded, so a failed/partial migration retries on the next load. `channel` and `utm_campaign` are denormalized copies of two values that also live inside the frozen `analytics_context` — promoted to indexed columns so the Submissions page can filter and build dropdowns without decoding every row's JSON. `delivery_state` / `delivery_json` are likewise recorded rather than derived — see [Submissions](#submissions). Rows predating schema 1.2.0/1.3.0/1.4.0 are backfilled in chunks under a wall-clock budget by the daily cleanup cron, by a catch-up event scheduled right after the upgrade, and by the Submissions page itself (so sites whose WP-Cron never fires still finish). An un-backfilled row is exactly one whose `channel`, `delivery_state`, or `landing_page` `IS NULL`, so the backfill needs no progress option and terminates on its own. New submissions write every derived column at insert, so only history ever reaches the backfill worker.

**Schema migrations never run inside a visitor's request.** Adding an index is a table rebuild on every engine, and `ADD COLUMN` is only an instant metadata change on MySQL 8.0.12+. `MigrationRunner` therefore runs migrations only in WP-Cron, WP-CLI, or a genuine admin page view, under an option-row lease so only one runs at a time; a frontend request that notices a pending migration schedules it and touches no DDL. While a migration is outstanding the Goals and Funnels screens say so plainly rather than querying a column that does not exist yet. Large retry state never lives in autoloaded options — the analytics retry-state and last-sent options are stored with `autoload = no`, and form payloads live in the queue table.

## Testing

```bash
composer lint              # php -l over every PHP file
composer test              # unit suite — pure logic, no WordPress, no database
composer test:integration  # the real queries against a real MySQL server
```

The **unit** suite is deliberately database-free. There is no hand-rolled `$wpdb` mock anywhere in it: a mock only ever proves the test author's model of MySQL, and a green "delete cascade" built on one would make an unverified erasure guarantee look verified.

The **integration** suite exists because that boundary has a cost, and 0.5.0 paid it. The funnel report's statement is assembled outside-in, so the last step's placeholders appear first in the finished SQL; parameters were bound in step order and every one landed on the wrong placeholder. Nothing errored — they are all `%s` — so the query compared a page URL against a timestamp, matched nothing, and reported every funnel as zero, while a unit test asserting the SQL's *structure* stayed green. It now runs the generated SQL against a real server.

It skips itself cleanly when no database is reachable, so `composer test` needs no setup. To run it:

```bash
CVM_TEST_DB_HOST=127.0.0.1 CVM_TEST_DB_NAME=cvm_test \
CVM_TEST_DB_USER=root CVM_TEST_DB_PASS=root \
composer test:integration
```

`CVM_TEST_DB_SOCKET` is also accepted. **The database is truncated between tests — point it at a throwaway, never at a real site's.**

What it covers: the DDL producing exactly the columns and indexes each migration verifies; the UNIQUE constraints genuinely deduplicating under `INSERT IGNORE`; the generated funnel SQL including ordering, cross-table goal steps, and the eight-step cap; the abandonment query's correlated `NOT EXISTS`; per-currency lead grouping; the corrected backfill sentinel; and the lead-history cascade.

What it does not cover: there is no WordPress in it, so `dbDelta`, cron, REST, and the provider hooks stay on the manual checklist.

---

## Upgrading to 0.5.0

**Nothing you have configured changes, and no existing behaviour is altered.** Upgrade in place; there is no need to deactivate and reactivate.

### What happens on upgrade

Four schema migrations run: two new tables (`cvm_goal_completions`, `cvm_lead_events`), a `form_key` column plus two indexes on the events table, and the lead and attribution columns on the submissions table.

**They do not run inside a visitor's page load.** Adding an index rebuilds the table on every database engine, and the events table is usually the largest one on the site. Migrations therefore run only in WP-Cron, WP-CLI, or a genuine admin page view, one at a time under a lease. Until they finish, the Goals and Funnels screens say *"Preparing"* rather than querying columns that do not exist yet; everything else works normally throughout.

On a large events table expect a **one-time migration cost**. If your site has millions of events, run the upgrade at a quiet hour.

Existing submissions are backfilled with their landing page and full campaign identity in bounded chunks by the daily cleanup cron, a catch-up event scheduled right after the upgrade, and the Submissions page itself. Every existing submission reads as `new` immediately — that comes from the column default, not a backfill.

### Compatibility

| Area | Status |
|---|---|
| Existing settings, webhooks, notifications, forms | Unchanged |
| Historical submissions | Render normally; all show lead status `new` |
| **Form submission payloads** | **Unchanged — still schema `2.0`** (and `1.0` for pre-2.0 rows). No new fields. |
| **Analytics report payloads** | **`1.0` → `1.1`.** Purely additive: one new `analytics.goals` section. Every `1.0` field is present, in place, with the same shape — a receiver written against `1.0` keeps working untouched. |
| Frozen retries in flight | Replay their original bytes under their original `delivery_id`, as always |
| `cvm_track_event()`, `convermetry_submit_form()`, `convermetry:conversion` | Unchanged |

If your receiver rejects unknown JSON keys, allow additive fields within a major version before upgrading — that is the contract `schema_version` expresses.

### New tracking is on by default

`form_view`, `form_start`, `form_error` and `custom_event` are enabled after upgrade, alongside the existing types, under **Settings → Tracking**.

Note the volume: `form_view` fires once per visible form per page view, so a form in your site footer adds roughly one event per page view. That is also exactly the denominator a start rate needs, so it is a real cost for a real number — but if you do not want abandonment analytics, switch those three off and the cost disappears.

Goal matching adds a small per-event cost on the server and has its own switch under **Settings → Tracking**. With no goals configured it does nothing.

---

## Uninstall behavior

**Deactivation preserves everything:** tables and data are kept, analytics retry chains are suspended (frozen deliveries resume under their original `delivery_id`s after reactivation), and queued form deliveries wait in the database for the re-armed worker.

**Deleting the plugin** (Plugins screen) runs `uninstall.php`: drops all **seven** tables — including `cvm_goal_completions` and `cvm_lead_events` — and deletes every option (goal and funnel definitions included), transient, rate-limit counter row, and scheduled cron event. On **multisite**, the cleanup runs per site across the whole network. No trace remains.

## Folder structure

```text
convermetry/
├── convermetry.php              # Plugin header, PHP 8.3 guard, bootstrap, activation, helpers
├── uninstall.php                # Complete cleanup on plugin deletion (multisite-aware)
├── README.md
├── tests/
│   ├── Unit/                    # Pure logic; no WordPress, no database
│   └── Integration/             # The real queries against a real MySQL server
├── assets/
│   ├── css/admin.css            # Shared admin styles (cards, toggles, logs, forms, builders)
│   ├── css/dashboard.css        # Analytics dashboard + print styles
│   ├── js/admin.js              # Webhooks/Forms pages (repeater, builders, tests, filtering)
│   ├── js/dashboard.js          # Chart navigation/tooltips, panel state, print prep
│   ├── js/activity-log.js       # Activity Log accordions, filters, pagination, API card
│   ├── js/submissions.js        # Submissions list, filters, pagination, lazy detail panels
│   ├── js/goals.js              # Goals editor (rule controls, edit-in-place)
│   ├── js/funnels.js            # Funnels step editor
│   └── js/tracker.js            # Frontend tracker, form lifecycle, custom events
└── src/
    ├── Autoloader.php           # Minimal PSR-4 autoloader (no Composer)
    ├── Plugin.php               # Composition root
    ├── Admin/                   # AnalyticsPage, SubmissionsPage, GoalsPage, FunnelsPage,
    │                             # FormsPage, NotificationsPage, WebhooksPage,
    │                             # ActivityLogPage, SettingsPage, AboutPage
    ├── Analytics/               # ReportQuery (shared read path), Reports, GoalReports,
    │                             # FunnelReport, FormEngagementReport, LeadReports,
    │                             # ReportQueryException, SubmissionContext
    ├── Api/                     # TrackingController, DeliveryLogController
    ├── Database/                # DatabaseManager (events), FormSubmissions, PreparedEvent,
    │                             # MigrationRunner (keeps DDL out of visitor requests)
    ├── Forms/                   # FormProviderInterface, FormProviderRegistry, FormSettings,
    │   │                        # SubmissionService, SubmissionResult, SubmissionFields
    │   └── Providers/           # Elementor, GravityForms, WPForms, ContactForm7,
    │                             # FluentForms, NinjaForms, FormidableForms
    ├── Funnels/                 # FunnelSettings, FunnelRepository, StepCompiler
    ├── Goals/                   # GoalSettings, GoalRepository, GoalMatcher (pure),
    │                             # GoalRecorder, GoalCompletions
    ├── Leads/                   # LeadStatus, Money (exact decimals), LeadService, LeadEvents
    ├── Notifications/           # NotificationSettings, NotificationDispatcher,
    │                             # NotificationQueue, EmailBuilder, NotificationMailer
    ├── Settings/                # Options (typed settings access)
    ├── Support/                 # Http (the single safe outbound transport),
    │                             # SensitiveKeys (shared credential-name policy),
    │                             # Url (the one URL-normalization policy)
    ├── Tracking/                # Channels (the one attribution engine), Correlation, ScriptLoader
    └── Webhook/                 # WebsiteInfoBuilder, PayloadBuilder, RequestFactory,
                                 # AnalyticsDispatcher, FormDeliveryQueue, DeliveryLog
```
