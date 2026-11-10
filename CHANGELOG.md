# Changelog

All notable changes to Convermetry are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## 1.1.5

No schema change and no migration. Stored rows are not rewritten: a delivery
attempt that 1.1.4 failed to log stays missing, and its submission keeps the
delivery state it settled on.

### Fixed

- **A delivery answered with a body that was not valid UTF-8 had no Activity
  Log row, so its lead showed "not_sent".** `$wpdb->insert()` refuses a whole
  row when a value is not valid UTF-8 for its utf8mb4 column, and two ordinary
  responses produced one. WordPress's transport stops reading a response at
  64 KB, and that cut, made in bytes, split a character of a large page in
  Japanese or another multibyte script. And a body in another encoding, such
  as an ISO-8859-1 error page with "é", was stored as received, because only
  JSON bodies are re-encoded. `DeliveryLog::log()` returned
  `LogOutcome::Failed`, and the submission's delivery state, computed from the
  log, settled on `not_sent`: for a lead that was delivered with a 200, and for
  one whose six attempts all failed, which should have been `failed`. Nothing
  told the site owner the lead never arrived. Each stored body is now valid
  UTF-8, with every invalid byte sequence replaced by U+FFFD, and the 64 KB cap
  cuts on a character boundary. Neither needs mbstring.
- **Top Pages and Top Landing Pages showed the alphabetically last title ever
  seen for a page.** `MAX(page_title)` picks the title that sorts last, so one
  pageview under an unusual title relabelled the page for every range that
  included it; a single test pageview titled with 254 "t"s replaced the label
  of a page with 868 views. Chrome's built-in translation rewrites
  `document.title`, and so can a crafted tracking request. Each page is now
  labelled with the title of its most recent pageview (for landing pages, its
  most recent landing) in the range that had a title, found by `MAX(id)` and
  read by primary key. The Analytics screen, its exports and the analytics
  webhook's `top_pages` and `top_landing_pages` all change. No window
  functions and no new index.
- **`Convermetry.track()` turned non-numbers into values.** The value went
  through `Number()`, which makes `null`, `""`, `" "` and `false` 0, `true` 1
  and `[7]` 7, so a dynamic-value goal recorded 0.00 for a page that passed an
  empty price field. Only a finite number, or a string that is a decimal number
  once trimmed (`"  42.5  "`, `"1e3"`), is sent now; anything else is ignored
  and the goal's own value applies, as 1.1.4 already documented. Negative
  numbers are still sent, and still ignored by the server.
- **A click on an SVG link recorded no destination.** An SVG `<a>`'s `href`
  is an `SVGAnimatedString`, not a string, so the click was stored without a
  `target_url`: no link-click goal could match it, and a middle-click on it
  was not recorded at all. Its `baseVal` is now resolved against the
  document's base URL. Hovers use the same destination.
- **Image-map links recorded no click.** `<area href>` was not among the
  elements the tracker watches. It is now, labelled by its `alt` text.

### Documentation

- The Home screen's session count includes every session with any event in
  the range; the goal conversion-rate denominator (the Goals screen and
  `goals.sessions` in the analytics payload) counts only sessions with a
  pageview in the range. The difference, and the visits that cause it, is
  explained in both methods' docblocks, README.md and a readme FAQ entry.
  Neither figure changes.
- README.md describes the newest-title rule, which `Convermetry.track()`
  values are sent, SVG and image-map clicks, and UTF-8 Activity Log bodies.
- readme.txt screenshot captions 4 and 6 describe the current screenshots.

### Tests

- A unit test stores oversized Japanese bodies (cut by the cap and cut by the
  transport), an ISO-8859-1 body, an oversized ASCII body and a valid
  multibyte body through `DeliveryLog::log()`, against a `$wpdb` stand-in that
  refuses invalid UTF-8 as the real one does.
- The WordPress suite's receiver can answer with 90 KB of Japanese HTML or an
  ISO-8859-1 error page. A 200 with the Japanese page gives one log row and
  `delivered`; a 500, retried until the attempts are spent, gives a row per
  attempt and `failed`; the ISO-8859-1 page gives its row with U+FFFD in place
  of each "é". Each first checks that the real `$wpdb` refuses the raw body.
- Integration tests label a page and a landing page with their newest title,
  not "Zzz old", and keep a blank title from replacing a real one.
- A new shimmed-browser suite (`tests/js/custom-event.test.mjs`) covers which
  `Convermetry.track()` values are sent, and the real-browser suite covers
  SVG links (click and middle-click) and image-map areas.
- Every new test except the guards against over-correcting fails against
  1.1.4.

## 1.1.4

No schema change and no migration. Stored rows are not rewritten: a negative
lead or goal value saved before this version keeps its value, and validation
errors recorded under a blank field id stay in that row.

### Fixed

- **A middle-click recorded no click and completed no click goal.** A link
  opened in a new tab with the middle button is reported by the browser as
  `auxclick`, not `click`, and the tracker only listened for `click`. Both now
  share one handler. A middle-click counts on a link, where it opens the
  destination; on a button it activates nothing and is not recorded, and the
  right button never is. Each activation is handled by exactly one listener,
  chosen by button rather than by timing, so a browser that reports one
  middle-click as both events counts it once.
- **A dynamic-value goal stored negative values from the browser.**
  `Convermetry.track('booking', { value: -5 })` recorded a completion worth
  -5.00 and lowered the goal's attributed value. A negative supplied value is
  now ignored like a non-numeric one, and the goal's configured value applies.
  Zero is accepted.
- **`Money::parse()` cut extra decimals from a string but rounded a float.**
  `"12.345"` became 12.34 while the float `12.345` became 12.35, and `"0.005"`
  became 0.00 — silently, in a class whose own rule is that it never silently
  misstates an amount. Both paths now round half-up to the cent in string
  arithmetic, never through a float: `"12.345"` and `12.345` both give 12.35,
  and `1.7099999999999997` from page JavaScript gives 1.71 rather than 1.70.
  Rounding is checked against the column's capacity after any carry.
- **An old library's `Array.prototype.toJSON` stopped all tracking on a
  page.** Prototype.js 1.6 makes an array's JSON a string, so a batch went out
  with `events` as a string, the endpoint refused it with a 400, and the
  tracker dropped it as unfixable. Every `JSON.stringify` in the tracker — the
  request body, the stored retry queue, the campaign record, the size estimate
  and the correlation context — now sets `Array.prototype.toJSON` and
  `Object.prototype.toJSON` aside for that one call and restores them.
- **A malformed stored session id was used as-is.** A `cvmtry_session` value
  such as `not-a-hex-id.<timestamp>` was sent unchanged; the server kept only
  its hex characters, so it became the session `aed`, shared by everyone
  holding such a value, while form submissions rejected the same id and the
  lead lost its session. Only an id the tracker could have minted (32 lowercase
  hex characters) is reused now; anything else starts a new session.
- **Fields with a non-ASCII name, or no name or id, merged in "Most common
  friction points".** The server kept ASCII characters only, so `邮箱` became
  blank, and a field with neither attribute was blank to begin with. Field ids
  now keep letters, combining marks and digits in any script, truncated at 64
  characters rather than bytes, and a field with no name or id is identified
  by its type and position in its form (`email#3`). Labels are never used:
  they are translated, and would split one field into a row per language.
- **A replayed submission wrote a second `form_success` row.**
  `SubmissionService::record()` stored the conversion event before the insert
  that detects a duplicate conversion id, so a replayed request added an event
  row before it was turned away. Reports count distinct conversion ids and
  never showed it, but the raw events table and `Reports::totalsByType()` did.
  The event is now recorded once the insert has decided the submission is new.
  An insert that fails for any other reason still records it, because the
  visitor's submission happened.

### Changed

- **Lead values and goal values cannot be negative.** A negative amount was
  subtracted from Attributed Lead Value and Revenue for the whole cohort.
  The Submissions screen now refuses it with a message, and so does the goal
  editor's value field. A refund or a lost deal is a lower or cleared value, or
  a status.

### Documentation

- What the tracker does not count: single-page navigation through
  `history.pushState()`, pages shown inside an iframe (which count as their own
  page views), and logged-in members, whose submissions carry no attribution
  while logged-in users are excluded.
- The per-IP rate limit counts an address, not a visitor, so many visitors
  behind one office, school or carrier network can lose events silently; raise
  `per_ip` with `convermetry_rate_limits` where that is your audience.
- The custom-form API stores and delivers every field it is given. Integrators
  are told to leave out passwords and payment details, with a
  `convermetry_submission_fields` example for removing them.
- README.md, readme.txt and the About screen describe the new rounding and
  negative-value rules, middle-clicks, session ids and field identifiers.

### Tests

- The real-browser tracker suite covers a middle-click by script and by the
  DevTools input API, the right button, a button, one activation reported
  twice, Prototype.js's `Array.prototype.toJSON` on both the request and the
  stored retry queue, malformed and valid stored session ids, and unnamed and
  non-ASCII fields. The six regression tests among them fail against the 1.1.3
  tracker; the other three guard against over-correcting.
- Unit tests cover half-up rounding and string/float agreement in `Money`,
  negative dynamic, goal and lead values, Unicode field ids, and the order of
  the conversion event in `SubmissionService::record()`. The WordPress suite
  replays a submission and counts its rows.
- Two source-contract tests read a fixed number of characters of the method
  they check, and stopped short as the methods grew; they now read the whole
  method.

## 1.1.3

No schema change and no migration. Stored rows are not rewritten: a submission
recorded before this version can still hold every URL parameter until retention
removes it, but its values are filtered wherever they are read, so they are no
longer shown or sent. Activity Log rows from before this version are unchanged
and age out the same way.

### Security

- **URL parameters on the submitting page were stored and sent to webhooks.**
  Every parameter on the `Referer` reached `page_query`, the Submissions screen
  and `website_info.page.query`, whatever the page-parameters setting said, so
  ad-click ids, email addresses and reset tokens all left with the lead. One
  policy (`PageQuery`) now decides at every one of those places. With forwarding
  off, only `utm_*` parameters survive. With it on, ad-click ids are kept
  because forwarding them is the setting's purpose, but names that look like a
  secret or contact details are dropped, and so is any value containing `@`. A
  `Referer` on another site is no longer read at all.
- **The Activity Log kept visitor data in the response when told not to.** With
  *Store form submission data* off, a receiver that echoed its input put the
  values back into `response_data`. The response body is now not stored in that
  mode, because no scrubbing can recognize every way a receiver reformats what
  it echoes; the status code and any transport error stay. The page's URL
  parameters are removed from the stored payload and request URL as well.

### Fixed

- **Percent-encoded characters were stripped from the page URL.**
  `sanitize_text_field()` ran over the raw `Referer`, so
  `leak.check%40example.com` became `leak.checkexample.com` and `/caf%C3%A9/`
  became `/caf/`. Values are now decoded before they are sanitized.
- **Prerendered pages counted as visits.** A page the browser prerendered and
  the visitor never opened recorded a pageview and could complete a goal. The
  tracker now starts when the page is activated. `Convermetry.track()` calls
  made while it is prerendered wait and are replayed once.
- **Back and Forward were not counted when the browser restored a page from its
  back/forward cache.** A restore now records a pageview and starts the page's
  scroll, hover and form state over, so returning to a form and starting it
  again is a second `form_start`.
- **Abandonment missed a second attempt.** Submit, come back, start again, leave
  counted as nothing unfinished, because the query judged a session's first
  start. It now judges the latest, as the report's documentation always said.
- **Long values could lose a whole batch of events.** `page_title` was not
  shortened in the browser, so a very long title pushed a full batch past the
  64 KB limit, and the batch was refused. Page fields are cut to the width the
  server stores, and a flush splits into several requests when it still would
  not fit.
- **An emoji at a length limit could lose a whole batch of events.** Cutting text
  through a surrogate pair serialized half a character, PHP's JSON parser
  rejected the entire body, and the tracker does not retry a 400. Labels, titles,
  campaign values and destinations are now cut on a character boundary.
- **A visitor without JavaScript got a conversion with no page and an invented
  channel.** Their `form_success` event had a blank page URL and was classified
  Direct while their lead had no channel. The event now takes its page from a
  same-site `Referer` and has no channel, like the lead. The channel report lists
  those conversions under a blank channel, where before they added to Direct.

## 1.1.2

No schema change and no migration. Stored events are never rewritten.

### Fixed

- **A form field named `method` stopped the tracker starting.** A form exposes
  its named controls as its own properties, so `form.method` was an element
  and seeding — which runs synchronously while the deferred tracker
  initializes — threw: no events, no `window.Convermetry`, and no correlation
  fields for any later form. Every read the tracker makes of a form now goes
  through the native accessor, and each form is seeded and observed on its
  own, so one form's markup cannot stop the rest.
- **A field named `action` let an external POST form receive correlation
  data.** The destination is now resolved as the browser resolves it —
  including a `<base href>` and the pressed button's `formaction` /
  `formmethod` — and a submission that is not a same-origin `POST` has any
  seeded `cvmtry_*` fields taken back out before the browser reads the form.
- **A field named `id` cost a form its `form_view`, `form_start` and
  `form_submit` events.** Labels are read from attributes, and only strings
  become labels.
- **The Forms screen showed misleading names.** `MAX(element_label)` chose by
  collation, so `form` beat `Footer Feedback` and `demo-quote-request` beat
  `Demo Quote Request`. The name is now chosen by a documented preference: a
  real name over a placeholder, the form plugin's own title (latest
  submission, then confirmed successes) over markup, a name over a slug, then
  frequency and recency. `form_submit` now uses the same label as the form's
  view and start, honoring `data-cvmtry-form-name`.
- **Funnels and abandonment could miss a real completion when the server's
  confirmation was stored before the browser's batch.** `form_submit` rows now
  carry their attempt's conversion token in `event_value`, and a confirmed
  success is ordered no earlier than the attempt with the same token in the
  same session. No timestamp is used and nothing else moves. Events recorded
  before this change carry no token and keep their previous (ingestion)
  ordering.
- **Ninja Forms and Elementor Atomic now participate in that ordering fix.**
  Their outgoing request records one attempt with the same token, session and
  form key as the server submission, even without a native submit event.
  Native submits reserve tokens without double-counting; retries of the same
  payload preserve them, and new submissions get fresh tokens. For these two
  AJAX providers, client validation that sends no request is not counted as a
  submission attempt. GET and cross-origin Atomic requests remain uncorrelated.
- **Visitors on CUBOT phones were discarded as bots.** The filter matched the
  bare substring `bot`; it now matches how crawlers, headless browsers and
  scripted clients name themselves (`Googlebot/2.1`, a `+https://…` contact
  URL, `HeadlessChrome`, `curl/`).

### Tests

- `tests/js/tracker-browser.test.mjs` runs the tracker in real headless Chrome,
  driven over the DevTools protocol with no dependency, against forms whose
  controls shadow the form's own properties. CI requires it to run.
- AJAX transport regressions cover native and AJAX-only attempts, retries,
  fresh submissions, provider form keys, privacy gates and disabled tracking.
  Browser tests inspect the serialized requests; MySQL tests verify repeated
  submissions whose confirmations were stored before their attempts.

## 1.1.1

Resolves the two warnings WordPress Plugin Check reported against 1.1.0. No
behavior, permission, setting or data change.

### Security

- **Submissions JSON exports** (`cvmtry_submissions_export_json` and
  `cvmtry_submissions_export_json_filtered`) check the nonce inline — present
  as one string, then `wp_verify_nonce()` — in the same function that then
  reads the filters from `$_GET`, exactly as the CSV export handlers do,
  instead of through `Export::nonceIsValid()`
  (`WordPress.Security.NonceVerification.Recommended`).
- **`Export::nonceIsValid()`**, still used by the Analytics, Goals and Funnels
  exports, type-checks `$_GET['_wpnonce']` and passes it straight through
  `wp_unslash()` and `sanitize_text_field()` rather than through a local copy
  (`WordPress.Security.ValidatedSanitizedInput.MissingUnslash`).

## 1.1.0

CSV and JSON exports for every reporting screen, charts drawn from the reports
already on screen, and a layout pass across every admin page. No setting,
stored data, schema, tracking, calculation, delivery or payload changes.

### Added

- **Exports.** CSV and JSON for the selected period on Analytics
  (`cvmtry_analytics_export_csv` / `_json`, `analytics.view`), Goals
  (`cvmtry_goals_export_csv` / `_json`, `goals.manage`) and Funnels
  (`cvmtry_funnels_export_csv` / `_json`, `funnels.manage`), and JSON for
  Submissions (`cvmtry_submissions_export_json` and
  `cvmtry_submissions_export_json_filtered`, `submissions.export`). Each format
  is its own admin-post action and nonce action; each handler checks method,
  scope and nonce in that order before reading input. Period links carry the
  period signed with the screen's filter nonce, and an expired or altered
  period is refused rather than replaced by the default.
- **Export content.** Analytics runs the panels' own queries, row limits and
  retention-clamped window (`AnalyticsPage::reportWindow()`), writing raw
  values; a failed section is `null` and listed under `failed` (CSV: the
  dashboard's error sentence), never empty. Goals and Funnels export exactly
  what their table and cards show. The Submissions JSON uses the CSV's column
  keys and values — `convermetry_submission_csv_columns` and
  `convermetry_submission_csv_values` now shape both — with `submission_data`
  as the descriptor list and no formula escaping.
- **Charts.** `Convermetry\Admin\Charts` renders ranked and grouped bars and a
  doughnut as plain HTML and SVG — no script, no library — with every value
  printed beside its mark and the marks hidden from assistive technology.
  Analytics adds a device-share doughnut and summary charts (top pages, clicked
  elements, channels, goal completions, leads by channel as grouped
  leads/qualified/won bars on one scale); Forms adds successful-submission and
  friction-point bars; Goals adds completions by goal. Each is drawn from the
  result its table prints: Analytics memoizes report results per render
  (`AnalyticsPage::result()`), so no chart costs a query. Chart colours are the
  new `--cvmtry-ui-chart-*` tokens in `assets/css/admin-ui.css`.
- **Shared admin pieces** in `assets/css/admin-common.css`: page header and
  actions, section cards, a sticky save bar (with `scroll-padding-bottom` so
  focused fields stay clear of it), section links, the danger zone, and table
  scroll containers. `Convermetry\Admin\Export` holds the download plumbing
  for the new exports.

### Changed

- **Layout, every screen.** Existing text, headings and type styles are
  unchanged; hierarchy comes from grouping, spacing, borders and surfaces, and
  card, accordion and panel surfaces now use the design-system tokens.
  Analytics: header with period, exports and Print; activity and form metrics
  in separate tile groups; chart row; summary grid; report cards inside
  panels. Home: status and quick links before Getting Started. Forms:
  engagement, providers and per-form configuration as separate regions, with
  compact provider cards. Goals and Funnels: period filter, exports and an
  editor jump link in the header; funnel cards two-up, each opening with its
  overall conversion. Submissions: search-first filter bar, two-column
  expanded record, Delete moved to a footer action bar after
  `convermetry_submission_detail_sections`, Clear All moved below the list.
  Webhooks: section links, status, retries and queue grouped at the top,
  gridded endpoint blocks, and an Activity Log heading row with its exports,
  Clear All Logs after the log and the Deliveries API card in its own column.
  Notifications and Settings: bounded panels and grouped rows. About: a
  contents sidebar on wide screens.
- **Analytics' Print / Save as PDF button** moved into the page header; it is
  still revealed by `assets/js/dashboard.js`, which now also opens a panel when
  its "View data table" link is followed.

### Fixed

- The Goals and Funnels editors' Cancel button showed in "add" mode, because
  `.button`'s display overrode the `hidden` attribute.
- The print stylesheet targeted retired `--30`/`--90` chart classes, so a
  dense daily chart kept its on-screen minimum width on paper; it now targets
  `--wide`.

## 1.0.3

The Activity Log becomes the last section of the Webhooks screen.

### Changed

- **Activity Log moved into Webhooks.** The Deliveries API card, the retention
  notice, Clear All Logs, CSV/JSON export, and the Successful/Failed Deliveries
  accordions with their filters, debounced search, per-page selection,
  pagination and per-entry delete now sit at the foot of
  **Convermetry → Webhooks**, at the `#cvmtry-activity-log` anchor, below the
  settings form, pending retries and pending queue. The Activity Log submenu is
  gone. Stored log rows, settings, the API key, REST routes, delivery and
  retention are unchanged.
- **Old address redirects.** `admin.php?page=convermetry-activity` is
  redirected on `admin_menu` (before WordPress refuses an unregistered page) to
  `admin.php?page=convermetry-webhooks#cvmtry-activity-log`, for users with
  `activity.view`. Anyone else gets WordPress's usual refusal, as before.
- **Clear All Logs** now returns to the Webhooks screen's Activity Log section,
  with its success notice inside the section.
- **Links.** Home's Activity Log card and a submission's "Open the Activity
  Log" link open the section, built with `self_admin_url()`/`add_query_arg()`,
  and are offered only to users with `activity.view`.
  `HomeStatus::urlFor()` takes an optional fragment.
- **Code.** `ActivityLogPage` is replaced by `Convermetry\Admin\ActivityLogSection`,
  which owns the section, its script configuration and all seven handlers
  (`cvmtry_get_activity_logs`, `cvmtry_delete_activity_log`,
  `cvmtry_toggle_delivery_api`, `cvmtry_regen_delivery_api_key`,
  `cvmtry_clear_activity_logs`, `cvmtry_activity_export_csv`,
  `cvmtry_activity_export_json`), registered once per admin request by
  `WebhooksPage::init()`. Action names, nonce actions, and the export file
  names are unchanged.
- **Assets.** `assets/js/activity-log.js` is renamed `assets/js/admin-webhooks.js`
  (handle `cvmtry-webhooks`, still `wp-i18n` + `cvmtry-admin-confirm`, still
  configured by `CVMTRY_LOG`) and now queries only inside the section.
  `assets/css/admin-activity-log.css` is merged, rule for rule, into
  `assets/css/admin-webhooks.css`; `.cvmtry-delivery-wrap` is on the section
  only. `assets/js/admin.js` (`CVMTRY_ADMIN`) is unchanged.

### Security

- **Separate scopes on one screen.** Webhooks opens for `webhooks.manage` *or*
  `activity.view` — the submenu is registered with the capability of the scope
  the user holds — and each section is printed only for its own scope. A user
  delegated only the log never receives the settings form, signing secrets,
  endpoint URLs from the retry and queue lists, or `admin.js` and its test
  nonce. Delete and Clear All Logs are shown, and their nonces issued, only
  with `activity.manage`; the Deliveries API card and its nonces only with
  `api.manage`. Every handler keeps its own server-side check.

## 1.0.2

Addresses the WordPress.org manual review of 1.0.1.

### Security

- **Admin request handlers.** Every admin-post and AJAX handler now checks, as
  separate guards in its own body and in this order: the HTTP method, the
  capability for its own scope (`current_user_can(Capability::required(…))`),
  that its nonce is present as a single string, and `wp_verify_nonce()` against
  its own action — before it reads any other input or touches any data. The
  shared `GoalsPage::isRequest()`, `FunnelsPage::isRequest()` and
  `SubmissionsPage::authorize()` helpers, and the compound conditions that
  mixed these checks with request detection, are gone.
- **No request handling on `admin_init`.** Goals and Funnels save/remove,
  Activity Log and Submissions Clear All, every CSV/JSON export, and Discard on
  a pending webhook retry are registered on their own `admin_post_{action}`
  hooks, so ordinary admin page loads never reach them. Each export format is
  its own action with its own nonce (`cvmtry_activity_export_csv`,
  `cvmtry_activity_export_json`, `cvmtry_submissions_export_csv`,
  `cvmtry_submissions_export_csv_filtered`).
- **Typed input.** Request values are type-checked before they are cast or
  sanitized; an array where a scalar belongs is refused, never cast. Row ids
  are parsed digits-only: `intval()` turned an array-valued `submission_row` or
  `log_id` into row 1. The deliveries API toggle accepts only `'1'` or `'0'`,
  so a malformed request can neither disable the API nor mint a key.
- **AJAX refusals** keep the `{success: false, data: {message}}` shape the admin
  scripts read and now carry an HTTP status: 403 for a capability or nonce
  failure, 400 for invalid input, 405 for the wrong method.
- **Reporting period.** Period links on Analytics, Goals and Funnels carry a
  nonce for that screen's read-only filter action (`ReportPeriod`). A supplied
  period is used only with that nonce and only when it is one of the offered
  values; otherwise the last 30 days are shown with a notice. The filter nonce
  authorizes nothing else, and no save or delete nonce is accepted for it. The
  Goals and Funnels forms and their redirects carry the period with a fresh
  filter nonce.
- **Webhook settings** are validated by `WebhookSettingsInput`: every field's
  type, then each field for its meaning — URLs through `esc_url_raw()` and
  `wp_http_validate_url()` (HTTPS unless the development filter allows HTTP),
  labels as display text, signing secrets verbatim but free of control
  characters. A rejected endpoint is reported as its position, a reason code
  and a sanitized, length-bounded excerpt; the URL as typed is no longer stored
  in the transient behind the notice. A request that is not the shape the form
  posts (an array where text belongs, a missing endpoint list, an unknown
  interval) stores nothing instead of resetting endpoints and headers.
  Endpoint ids are still accepted only for configured endpoints, once each.
- **Custom headers and query parameters** (Webhooks and Forms) are validated
  rather than run through `sanitize_text_field()`, which stripped `%XX`
  sequences and tag-like text from credentials. A header name must be an
  RFC 9110 token; no name or value may contain a control character. Invalid
  rows are counted in a notice and not saved.
- **Form keys** in the Notifications and Forms saves are validated and kept
  verbatim (`FormProviderRegistry::validFormKey()`), so an identity with spaces
  or capitals is never rewritten. A malformed Notifications submission is
  refused whole; a malformed Forms block is skipped, which leaves that form's
  stored settings untouched. Rules for forms not shown on the page are kept.
- **Settings.** A field posted in a shape the form never sends keeps its stored
  value (`(int)` of an array clamped retention to 7 days). `options.php` now
  requires the `settings.manage` scope for this group instead of always
  `manage_options`.

### Fixed

- **Discard** on a pending analytics retry removes it again. The link names a
  chain by md5 of its URL while the state map is keyed by the endpoint's
  durable id, so the chain was never found and the screen reported success.
- Removing an endpoint block on the Webhooks screen renamed every field of the
  blocks after it except the hidden id, so the next save gave the following
  endpoint a new identity and stranded its retry state.

### Tests

- `tests/WordPress/AdminRequestHandlersTest` (real WordPress): every handler
  refused for a missing, invalid, expired, other-action (including the period
  filter's), other-user's and array-valued nonce, for a role without the scope,
  and for the wrong method — each asserting that no option, table row, lead,
  API key, retry chain, email or HTTP request changed. Scope distinctions,
  successful requests, array-valued ids, rejected URLs with markup and control
  characters, malformed payloads, credential round-trips, endpoint identity,
  per-form settings, and the period links and redirects. `wp_die()`, the JSON
  responses and redirects are modelled as termination.
- `tests/Unit/AdminInputValidationTest`: the header, query, form-key,
  notification and webhook validators and the period filter.

## 1.0.1

Addresses the WordPress.org pre-review of 1.0.0, which was never publicly
released.

### Changed

- **Prefix.** Every plugin-owned identifier uses the six-character `cvmtry`
  prefix: options, transients and their cache keys, the seven tables
  (`{$wpdb->prefix}cvmtry_*`), cron hooks, constants (`CVMTRY_VERSION`,
  `CVMTRY_PLUGIN_FILE`, `CVMTRY_PLUGIN_DIR`, `CVMTRY_PLUGIN_URL`), script and
  style handles, localized script globals, AJAX actions, nonce actions and
  fields, request parameters, CSS classes and custom properties, HTML ids,
  `data-cvmtry-*` attributes, the `cvmtry:log-deleted` DOM event, browser storage
  keys, the hidden correlation fields the tracker adds to forms, the
  `X-CVMTRY-Page` REST response header, and the global helper
  `cvmtry_track_event()`. The `convermetry_*` hooks, `convermetry_submit_form()`,
  the `Convermetry\` namespace and the `convermetry` text domain are unchanged.
  There is no migration: settings and data stored by 1.0.0 are not read.
- **No inline script or style blocks.** The About screen's no-JavaScript rules
  for the hook reference moved from a `<noscript><style>` block into
  `admin-about.css`, keyed off the `no-js` class WordPress puts on the admin
  `<body>`. The confirmation prompts on Remove (Goals, Funnels) and Clear All
  (Activity Log, Submissions) moved from inline `onclick`/`onsubmit` handlers to
  a `data-cvmtry-confirm` attribute read by the new `admin-confirm.js`, which is
  registered on Convermetry screens and loaded only as a dependency of the
  scripts that need it.
- **PHP version notice.** Shown only to users who can activate plugins, and
  only on the Dashboard and Plugins screens.
- `Extensions::attach()` dispatches only `convermetry_`-prefixed hooks; any
  other name is refused with `_doing_it_wrong()`.

### Security and SQL

- Report queries in `Reports`, `LeadReports`, `GoalReports` and
  `FormEngagementReport` bind table and column names with `%i` and the lead
  status lists as values, instead of interpolating them. The goal and
  friction-point filters are a single prepared statement each
  (`(%s = '' OR goal_id = %s)`) rather than a WHERE clause assembled in PHP.
- `Reports::hasEvents()` and `GoalReports::lastSeen()` passed unprepared SQL to
  `ReportQuery`, whose contract is prepared SQL only; both are now prepared.
- Display-only request parameters (reporting periods, the submission search
  deep link, saved/cleared notice flags, screen detection) are unslashed and
  sanitized where read, with an annotation naming the nonce-verified handler
  that produced each flag.
- Direct database calls on the plugin's own tables carry a per-call annotation
  stating why the call is direct and uncached (custom table, queue claim,
  options-table lease, retention delete, and so on). No caching was added:
  queue, lock and delivery-state reads must be live, and the admin screens show
  current data. See `docs/plugin-check-1.0.1.md`.

### Tests

- Integration coverage for the rewritten report queries: goal breakdown for all
  goals and for one, the zero-filled daily series, last-seen per goal,
  friction points for one form, lead summary, top campaigns (with orphan
  conversions), keyword drilldown, landing pages and `hasEvents()`.
- Unit coverage that the confirm script is registered but never enqueued
  directly, that every screen with a destructive action depends on it, that no
  admin source prints an inline script, style or event handler, and that
  `Extensions::attach()` refuses unprefixed hooks.

## 1.0.0

First release prepared for the WordPress.org plugin directory.

### Added

- **WordPress privacy tools.** Suggested privacy-policy text for Settings →
  Privacy → Policy Guide, generated from the current settings (IP storage,
  Do Not Track / Global Privacy Control, retention, webhooks, notifications).
  A personal-data **exporter** and **eraser** for Tools → Export / Erase
  Personal Data: for an email address they find the form submissions whose
  submitted values contain that exact address. Export covers the submission,
  its lead history, delivery destinations (host only) and the analytics of the
  visit. Erasure deletes the submission through the existing delete cascade,
  replaces the bodies of its Activity Log rows with an erasure marker, blanks
  that conversion's IP and session id in logged analytics reports, and blanks
  the IP on the visit's analytics events. The erasure summary names the
  webhook destinations the data had reached and states what cannot be recalled.
- **Translations.** Every user-facing string in the admin screens, notices,
  notification emails and admin scripts is translatable under the
  `convermetry` text domain; admin scripts use `wp-i18n` with
  `wp_set_script_translations()`. A translation template ships in
  `languages/convermetry.pot`.
- **readme.txt** for the WordPress.org directory, with the data-handling,
  browser-storage and external-service disclosures.

### Changed

- Bumped the plugin version to 1.0.0 for WordPress.org submission preparation.
- Every SQL statement binds table and column names with `%i` identifier
  placeholders (WordPress 6.2+) instead of concatenating them; `LIKE` patterns
  and `IN` lists are bound through `prepare()`.
- Admin output is escaped where it is printed: rendered detail panels print
  directly instead of echoing pre-built HTML, and report cells and
  integration-supplied submission columns pass through `wp_kses_post()`.
- Request input is unslashed and sanitized at the point it is read.
- The release builder refuses to build when a runtime file would be silently
  omitted (untracked, or hidden by `.gitignore`), when `readme.txt`, `LICENSE`
  or `uninstall.php` is missing, or when `readme.txt`'s `Stable tag` disagrees
  with the plugin header.

### Fixed

- **Remove on the Goals and Funnels screens deleted without asking.** The
  confirmation message was printed into the `onsubmit` handler unquoted, which
  made the handler a JavaScript syntax error — and a throwing `onsubmit` lets
  the form submit. The message is now JSON-encoded, so the confirmation shows.
- The About page and README pointed to a "Settings → Privacy" section that does
  not exist; the IP address toggle is under Settings → Tracking.
- **Uninstall left one option behind.** `cvmtry_webhook_state_version`, written by
  the analytics dispatcher's state migration, was missing from `uninstall.php`'s
  list, so deleting the plugin did not remove every trace as documented. The
  end-to-end uninstall test now asserts that no `cvmtry_` option or transient
  survives, rather than checking a sample.
- **Ninja Forms leads arrived without their visit and campaign.** Ninja Forms 3
  posts one `formData` JSON document built from its own models, not the
  `<form>`, so the tracker's hidden correlation fields never reached the server
  (and its `<form>` has no `method="post"`, so they were not injected either).
  Submissions were recorded and delivered, but with an empty
  `analytics_context`. The tracker now adds the three values to Ninja Forms'
  `nf_ajax_submit` request as top-level fields through a `jQuery.ajaxPrefilter`,
  where the existing server-side reader already looks. Found by submitting a
  real Ninja Forms form in a browser; covered by `tests/js/ninja-tracker.test.mjs`.
- **The Forms screen's Attempts column always showed 0.** The engagement report
  counts `form_submit` events by `form_key`, but the tracker sent `form_submit`
  without one (`form_view` and `form_start` carried it), so every attempt was
  left out. `form_submit` now carries the form key. Attempts recorded before
  this release have no form key and stay uncounted. Covered by
  `tests/js/form-events.test.mjs`.
- **Formidable Forms and Ninja Forms were missing from the Forms screen's
  engagement report.** The tracker could not identify either form in the
  browser: it looked for an `frm_` class that Formidable 6 no longer renders
  (`frm-show-form`) and prefixed the key `formidableforms:` where the server
  records `formidable:`, and it read the Ninja Forms id from the `<form>` when
  Ninja Forms 3 puts it on the wrapping `<div id="nf-form-N-cont">`. Views,
  starts and attempts now carry the same key the server records. Ninja Forms
  submits without a native `submit` event, so its Attempts column stays empty.
- The Analytics screen's "Other Events" card said it counted custom events
  recorded via `cvmtry_track_event()`; it counts every type not shown in its own
  card (form views, form starts, validation errors and custom events). The
  description now says so.
- The top label of the daily page-view chart's Y-axis was cut in half by the
  chart's scroll container.

## 0.11.0

### Added

- **Bricks Builder native form support.** A new `bricks` provider adds a
  **Convermetry** choice to the Bricks Form element's *Actions after successful
  form submit* control (`bricks/elements/form/controls`) and handles the
  submission Bricks dispatches to `bricks/form/action/convermetry`. Like
  Elementor Atomic, capture is **opt in per form**: nothing is recorded until a
  site owner ticks that action and saves. Bricks submissions run through the
  same `SubmissionService::record()` pipeline as every other provider — the same
  exclusions, per-form overrides, field redaction, storage, conversion
  recording, notifications, delivery and deduplication — with no payload, queue,
  retry or settings code duplicated. Scope is the **native** Bricks Form element
  only; third-party Bricks form add-ons are neither supported nor discovered.

- **Provider registration now runs in two passes.** Bricks is a theme, and
  WordPress loads a theme's `functions.php` after `plugins_loaded` — where
  Convermetry initialises — so a Bricks site truthfully answered "Bricks is not
  here" at the only moment the registry asked. Registration now repeats on
  `after_setup_theme`, guarded per provider key: a provider available at boot
  registers there and is never touched again, one that only becomes available
  with the theme registers exactly once in the second pass, and nothing is
  wired twice. Availability is a `BRICKS_VERSION` check, so a **Bricks child
  theme** is detected exactly like the parent. Bricks **1.12.2+** is required —
  the release that added named custom form actions; an older Bricks is reported
  Unavailable, with a notice explaining why, and registers nothing.

- **Bricks form identity is the form element id**, on its own — the same thing
  Bricks' own Form Submissions groups entries by. Bricks reports `postId` as the
  post a submission came *from*, which for a header, footer, popup or reused
  template is not the document defining the form, so a document-scoped key would
  split one form's settings across every page it appears on. Discovery walks
  Bricks' three content-area meta keys (`_bricks_page_content_2`,
  `_bricks_page_header_2`, `_bricks_page_footer_2`) through the WordPress
  metadata API, covering `bricks_template` posts as well as pages, skipping
  revisions, trash and auto-drafts, and deduplicating by element id.

- **Session attribution reaches Bricks submissions** through Bricks' own
  documented `bricks/form/submit` event: the three correlation values
  (`cvmtry_conversion_id`, `cvmtry_session_id`, `cvmtry_context`) are set on the prepared
  `event.detail.formData` as top-level entries, never as `form-field-<id>`
  values. `bricks/form/success` records the confirmed conversion under **the
  same token the server received**, and `bricks/form/error` records a
  `form_error` without ever reading Bricks' response body. Bricks form tags also
  carry a server-rendered `data-cvmtry-form-key` matching the server-side identity.

### Changed

- The Forms page, the About page's provider tables, the Webhooks failure-mode
  description and the README no longer describe Elementor Atomic as the only
  opt-in provider; both builder integrations are now covered, including what
  *Included* does and does not mean for them.

### Notes

- **This integration has not been run against a Bricks install.** It was built
  against the Bricks Academy documentation for the Form element, custom form
  actions, the Content Area and Form schemas, `bricks/element/render_attributes`
  and the frontend form events, and Convermetry's own half of that contract is
  covered by automated tests — including the tracker's handlers executed against
  dispatched events. The runtime half (that ticking the action dispatches
  `bricks/form/action/convermetry`, that mutating `event.detail.formData` reaches
  the request Bricks sends, how template-defined forms are stored and reported,
  and what Bricks shows a visitor for a failed action) is listed as unverified
  in the README's Bricks known limitations.

### Security

- **Bricks password fields are never recorded.** Field mapping is driven by
  Bricks' field *definitions* rather than the submitted array, because Bricks
  field ids are opaque six-character strings that name-based redaction cannot
  classify. A `password` field is dropped outright, and a submitted
  `form-field-*` key with no definition is not recorded at all — an unknown type
  could be a credential. Uploads carry only the URLs Bricks exposes; the
  physical file path is never read.

## 0.10.0

### Added

- **Elementor Pro Atomic Forms support**, alongside — never replacing —
  classic Elementor forms. A new `elementor_atomic` provider registers a
  `Convermetry` action with Elementor Pro's Atomic Action_Runner and adds it to
  the Atomic form root's "Actions after submit" control; classic forms keep
  capturing automatically, but an Atomic form requires that one editor step
  before Convermetry sees its submissions, and the Forms page now says so
  prominently next to the provider and every discovered Atomic form. Atomic
  submissions run through the same `SubmissionService::record()` pipeline as
  every other provider — the same exclusions, per-form overrides, field
  redaction, storage, conversion recording, notifications, delivery, and
  deduplication — with no payload, queue, retry, or settings code duplicated.

- **Atomic form identity is document-scoped** (`<post id>:<element id>`)
  rather than name-based: two forms sharing a display name, or the same
  template reused across pages, stay distinct and keep independent settings.
  Discovery walks `_elementor_data` directly (as classic Elementor discovery
  already does), so template-only forms in `elementor_library` are found too.

- **Session attribution now reaches Atomic submissions.** Atomic's frontend
  does not serialize the `<form>` — Elementor hand-builds its own request — so
  a hidden input would be silently dropped. The tracker now attaches
  `cvmtry_conversion_id`, `cvmtry_session_id`, and `cvmtry_context` directly to that
  request as top-level fields (never as submitted form data), gated by the
  same same-origin and `data-cvmtry-ignore` rules every other form already
  follows.

- Two new "Go Further with Goals and Funnels" cards on the Home page's Getting
  Started panel, and separate Goals and Funnels cards in the Convermetry
  Status grid and Quick Access — all built from the existing design system
  with no new CSS. The status cards report configured/enabled counts (and,
  for Goals, the global goal-matching switch) without ever implying that
  configuration alone proves a conversion was recorded.

### Changed

- **Every admin page class moved from `src/Admin/` to `src/Admin/Pages/`**
  (namespace `Convermetry\Admin\Pages`), separating the pages themselves from
  the helper classes (`Capability`, `Icons`, `HomeStatus`, and friends) that
  support them. No admin URL, slug, or behavior changed — this is a source
  reorganization only.

## 0.9.0

### Added

- **A Convermetry Home screen.** The top-level menu item now opens an
  introduction to the plugin instead of an analytics dashboard of an empty
  database: what Convermetry does, a Getting Started checklist, a status grid,
  quick links to every screen, and the visit-to-lead journey. Everything it
  reports is read from the installation — there is no sample data on it, a
  failed read renders as an em dash rather than as a zero, and a step is ticked
  off only when the plugin has observed the thing it describes (settings saved,
  a provider active, an event recorded, a submission captured).

- **A Convermetry admin design system** (`assets/css/admin-ui.css`): design
  tokens plus the components built on them — cards, buttons, status pills,
  grids, icon tiles, typography, flow diagrams. Every selector is scoped under
  `.cvmtry-ui` and the classes are prefixed `cvmtry-ui-`, so it cannot affect a screen
  that has not opted in and does not collide with the existing `.cvmtry-card` /
  `.cvmtry-badge` styles the other screens still use. Fonts are system stacks; no
  external font or framework is loaded.

- `Convermetry\Admin\Icons`, a small catalogue of inline SVGs. Every icon is
  decorative (`aria-hidden`, `focusable="false"`) and takes its colours from CSS
  tokens rather than from hard-coded hex values.

- `Reports::sessionCount()`, `Reports::hasEvents()` and
  `FormSubmissions::latestCreatedAt()` — the bounded reads the Home page needs,
  none of which load a data set to summarize it.

### Changed

- **Analytics moved from `admin.php?page=convermetry` to
  `admin.php?page=convermetry-analytics`.** It keeps every report, filter,
  chart, and export, and stays the first entry under the menu; only the slug
  changed, because the top-level slug now opens Home. No other screen's URL
  changed.

- Shared admin stylesheets are enqueued by a new `Convermetry\Admin\AdminAssets`
  rather than by the Analytics page. They used to be guarded by a substring test
  against the Analytics slug, which matched every Convermetry screen only
  because that slug happened to be `convermetry`; moving Analytics would have
  silently unstyled nine screens.

- **The admin heading typeface is now Play**, self-hosted from `assets/fonts/`
  (latin + latin-ext, OFL-licensed — see that directory's README), matching the
  Home page design across every Convermetry screen. `.button-primary` and
  `.button-secondary` on every screen now match the Home page's button
  shape and palette as well; a bare `.button` (an inline "Remove", "+ Add", or
  similar control) keeps its native WordPress size and only picks up the
  shared corner radius, so dense rows and tables are unaffected. No page's
  markup, copy, or behavior changed.

- **`assets/css/admin.css` was split into one stylesheet per admin page**
  (`assets/css/admin-<page>.css`), plus `assets/css/admin-common.css` for the
  `cvmtry-*` component classes two or more pages still share (cards, the toggle
  switch, the accordion/pagination list pattern, and so on) — see that file's
  header for the full per-page map. Every property preserved its exact value;
  this is a reorganization, not a restyle, verified by diffing every parsed
  CSS rule between the old file and its replacements. Each admin page now
  enqueues its own stylesheet from its own `enqueueAssets()`, depending on
  `AdminAssets::COMMON_HANDLE`; `admin-common.css`'s "Design-system alignment"
  section is what points every screen's headings and buttons at
  `admin-ui.css`'s tokens. Two pages with no page-specific rule today
  (Settings, Notifications) and the Home page (fully covered by the design
  system already) each still get their own reserved, already-wired
  stylesheet, so a future page-specific rule has an obvious home.

- **`assets/css/dashboard.css` renamed to `assets/css/admin-analytics.css`**,
  matching every other screen's `assets/css/admin-<page>.css`. Its style
  handle changed with it, from `cvmtry-dashboard` to `cvmtry-analytics`; the script
  handle and `assets/js/dashboard.js` (the chart behavior) are unrelated and
  keep their name.

- **The Analytics period filter ("Last 7/30/90 days") now uses the same
  `.button` / `.button-primary` / `.button-secondary` classes as the Goals and
  Funnels period filters**, instead of its own `.cvmtry-period-btn` skin — one
  button look for "select a reporting period" everywhere it appears, styled
  from `admin-common.css` like every other button on these three screens. The
  retired skin's now-unused CSS (the joined-segment border, the mobile
  equal-width override) was removed from `admin-analytics.css` along with it;
  the wrapper wraps and spaces the buttons but no longer draws them.

## 0.8.0

Production-hardening release. Focused on build safety, durable webhook endpoint
identity, and correct delivery-log date filtering.

### Security

- **`bin/build-zip.sh` could delete the repository.** Staging was computed as
  `"$OUT_DIR/convermetry"`, and because a checkout is itself named
  `convermetry`, `bin/build-zip.sh ..` resolved staging to the repository root —
  which the script then passed to `rm -rf`. Staging now happens in a private
  `mktemp` directory removed by an `EXIT` trap, never derived from the output
  directory. Every path is canonicalised (resolving symlinks) and validated
  before use: an empty argument, `/`, the repository root, any ancestor of it,
  and any path that resolves onto the source tree are all refused, as is an
  unquoted path that arrives as several arguments.

- **A deleted or renamed webhook endpoint could be signed with the shared
  secret.** The signing secret was resolved by endpoint URL, so an edited URL
  matched no endpoint and silently fell through to the shared secret — a
  signature a receiver could not distinguish from a forgery — and an in-flight
  retry for a deleted endpoint got one too. Secrets now resolve by durable
  endpoint id, and a reference that resolves to no endpoint sends **no**
  signature at all.

- Authenticated delivery-log responses now carry `Cache-Control: no-store,
  no-cache, must-revalidate, private`, `Pragma: no-cache` and `Expires: 0`, so
  delivery rows do not linger in shared proxy or browser back/forward caches.

### Added

- **Durable webhook endpoint identity.** Each endpoint carries an immutable,
  collision-resistant id (`WebhookEndpoint::$id`), assigned once by
  `Options::ensureEndpointIds()` and never regenerated by a routine save. The
  last-sent delivery window, retry state, retry cron arguments and signing-secret
  lookup are all keyed by it.

- `AnalyticsDispatcher::migrateEndpointState()` re-keys existing installations
  from `md5(url)` onto durable ids. It assigns ids, re-keys the last-sent and
  retry-state maps, re-points pending retry cron events, drops state for
  endpoints that are no longer configured, and verifies each write by reading it
  back before the legacy keys are considered replaced. It is idempotent, safe to
  interrupt, and safe to run repeatedly; the version marker is stamped only after
  a fully verified pass, so a failed run simply retries.

- `Options::secretForId()`, `Options::secretForEndpointRef()`,
  `Options::endpointById()` and `Options::endpointIdForUrl()`. The `Ref` variant
  accepts a legacy URL as well as an id, because payloads frozen before the
  migration recorded the URL and can still be in flight across the upgrade.

- `DeliveryLogController::parseDateParam()` — strict `YYYY-MM-DD` UTC parsing.

- `FormSettings::resolveKey()` — resolves a form's stored configuration key,
  falling back to a legacy identity when the current one has no entry yet.

- `LICENSE` (GPL-2.0-or-later) and this changelog.

- `tests/Shell/build-zip-safety.sh` — 18 regression tests for build-script path
  safety, each against a disposable fixture repository.

- `tests/Unit/VersionConsistencyTest.php` — fails CI when version surfaces drift.

### Changed

- **Elementor forms are keyed by widget id, not form name.** Two widgets both
  left at the default "New Form" previously collapsed into one shared
  configuration, and renaming a form orphaned its settings. Sites upgrading keep
  working through `FormSettings::resolveKey()`, which falls back to the legacy
  name key until the next save migrates the entry across. The legacy entry is
  deliberately **not** deleted, because queued rows store the form key they were
  written with and read it when their delivery is first frozen.

- **Delivery-log date filtering is correct and index-friendly.** `after` used to
  collapse to a year+month filter — `after=2026-08-15` returned the whole of
  August — and `before` was registered but never read. Both are now parsed
  strictly and applied as a half-open `[from, before)` window on `created_at`,
  with `before` inclusive at the API level. Impossible dates (`2026-02-30`,
  `2026-99-99`), unpadded dates, and reversed ranges are rejected with `400`.

- Calendar filters in `DeliveryLog` use range predicates instead of
  `YEAR()`/`MONTH()`, so the `created_at` index can actually serve them. The one
  remaining function-wrapped case is a month with no year ("this month across all
  years"), which has no contiguous range and is unreachable from the REST API.

- The Webhooks screen round-trips each endpoint's durable id through a hidden
  field, and only an id that is already configured may be carried through a save,
  so a hand-crafted POST cannot graft one endpoint's identity onto another.

- The unit suite now fails on skipped and incomplete tests (`failOnSkipped`,
  `failOnIncomplete`).

### Fixed

- **A submission could be reported as queued when nothing was queued.**
  `FormDeliveryQueue::enqueue()` counted only rows whose `INSERT IGNORE`
  reported one affected row. That statement reports zero both for a duplicate
  the unique index suppressed and for a row it declined to write, and
  `$wpdb->query()` returns `false` outright on error — so a refused write was
  indistinguishable from an idempotent no-op. `SubmissionService` then discarded
  the count entirely and reported `queued: true` regardless. A submission could
  be recorded, its delivery row could fail to persist, and the caller was told
  the webhook was on its way. A partial failure was worse: two of three
  destinations received the lead while the third disappeared without a trace.

  Both queues now return a verified `QueueOutcome` distinguishing inserted,
  duplicate and failed rows, resolving every ambiguous result by reading the row
  back. `SubmissionResult::$queued` reflects what is durably present, failures
  emit sanitized `convermetry_storage_error` telemetry, and a repair pass is
  scheduled for exactly the endpoints whose rows are known to be absent —
  never a broader re-queue, which could re-send a delivery a worker had already
  completed and deleted.

- **The rate-limit window could move backwards.** The window was computed in
  PHP and interpolated into the counter statement, and the "different window"
  branch replaced the row with that PHP-side window unconditionally. A request
  that computed window N but reached the server after one that had already
  written N+1 reset the row backwards to N, discarding the newer window's
  accumulated count; two clients straddling a minute boundary could flip the row
  back and forth, under-counting a flood at exactly the moment the limit matters.
  MySQL now computes the window from its own clock inside the statement, and the
  comparison is `>=` so a window at or ahead of the current one is charged into
  rather than replaced — which also survives the database clock being stepped
  backwards by NTP. A `REGEXP` guard keeps the numeric comparison away from a
  malformed value, which under `STRICT_TRANS_TABLES` would otherwise raise
  ERROR 1292, fail the statement, and — combined with failing closed — reject
  every tracking request until the row was repaired by hand. Covered by a real
  MySQL integration suite, because the defect lived entirely inside the SQL.

- **Queue repair was a single attempt, and its scheduling was unchecked.** A
  failed enqueue scheduled one repair pass 30 seconds later; if the database
  problem persisted that long, the destination stayed undelivered. Repair now
  follows a bounded backoff (30s, 5m, 30m) and then reports
  `queue_repair_abandoned` rather than retrying forever, and the result of
  `wp_schedule_single_event()` is checked — a cron write that fails is exactly
  as lost as the queue row was.

- **Repair is addressed by durable endpoint id.** It previously recorded
  `md5(url)`, so an operator editing an endpoint's URL between the failed
  enqueue and the repair pass left the recorded reference matching nothing and
  that destination was never queued. Queue rows keep their existing
  `endpoint_key`; only repair addressing changed.

- **The duplicate-submission path asserted a delivery it never checked.** It
  returned `queued: true` on the reasoning that the original request had already
  queued everything. If that request's enqueue failed and its repair passes
  failed too, the duplicate confidently reported a delivery that did not exist.
  It now reports whether delivery rows actually exist.

- **Queue repair no longer infers intent from `not_sent`.** The first version of
  the duplicate-path repair was gated on there being no queue row and a recorded
  delivery state of exactly `not_sent`, described as proving the original enqueue
  never landed. It does not prove that. `not_sent` is a NEUTRAL state, and two
  ordinary situations produce it:

  - A submission recorded on a site that does not use webhooks at all. Enable
    webhooks and add an endpoint months later, and a replayed provider callback
    for that old submission would have delivered a lead that was never meant to
    be sent anywhere.
  - A delivery that SUCCEEDED and left no evidence. The worker deletes the queue
    row on a 2xx, and the delivery-log row that would remember it can be
    suppressed by a `convermetry_delivery_log_row` filter or lost to a failed
    `INSERT`. With neither row left, `refreshDeliveryState()` settles the
    submission back on `not_sent` — and the receiver would have been sent a lead
    it had already processed. The stable `delivery_id` / `Idempotency-Key` helps
    receivers that honour it, but Convermetry must not depend on a third-party
    receiver deduplicating correctly.

  A verified failed `INSERT` now writes a durable repair record — the submission
  and the exact endpoint ids still owed a row — to a non-autoloaded option,
  before the repair cron is scheduled, because scheduling is itself a write that
  can fail. Every repair path is authorised by that record and by nothing else.
  The record is coerced on read and expires after seven days.

- **The repair record is one row per submission, not one shared map.** Its first
  implementation kept every outstanding repair in a single serialized option and
  rewrote the whole map on each change — a read-modify-write with no
  compare-and-swap. Two submissions failing to queue at the same moment each
  built a map from a read taken before the other existed, and the second write
  dropped the first's obligation entirely. That is the ordinary shape of this
  path rather than a load-test curiosity: what puts submissions on it is the
  queue table refusing writes for everyone at once.

  Records are now `cvmtry_queue_repair_<submission id>`, written with a single
  `INSERT ... ON DUPLICATE KEY UPDATE` against the unique `option_name` index
  and read straight back through `$wpdb` — the same pattern, and the same
  reasoning about option caches, as the rate limiter's per-key counters. Two
  writers no longer share a value to race over.

  Separate rows also removed the 100-submission cap the shared map needed. A
  site that loses hundreds of queue writes during an outage owes every one of
  them afterwards; it is the daily PASS that is bounded (chunk, chunk cap and
  wall-clock budget, paging by cursor rather than offset so removals cannot make
  it skip rows), not the set of obligations.

- **Repair-record writes and deletions are verified.** `update_option()` returns
  false both for a failed write and for a value that did not change, so its
  result cannot answer "did this land?" — and this record is the only thing
  standing between a refused queue `INSERT` and a silently lost lead. Records
  are read back after writing, retried once, and reported as
  `queue_repair_not_recorded` if still absent; a record that survives its own
  deletion is reported as `queue_repair_not_cleared`.

- **A second, independent guard against re-sending a delivered lead.** Before
  re-creating a queue row, repair now checks the Activity Log for an attempt
  against that endpoint. A logged attempt means the delivery WAS made and the
  missing queue row is the worker having finished with it — so the obligation
  settles instead of re-queuing. A stale record can no longer cause a duplicate
  delivery on its own; both guards would have to fail.

- **Giving up is now announced.** A repair record that reaches its seven-day
  window emits `queue_repair_expired` before it is removed, rather than
  disappearing as a pruning side effect. "Delivery of this lead was abandoned"
  is worth saying out loud.

- **An end-to-end suite that runs inside a real WordPress.** The unit suite has
  no WordPress and the integration suite executes the plugin's SQL directly
  against MySQL, so everything between "the SQL is right" and "the plugin works
  when WordPress runs it" was untested. `tests/WordPress` installs a real
  WordPress into a throwaway database, activates the plugin, and asserts on what
  actually happens: dbDelta creating every table, the cron events being
  scheduled, the REST route answering a real request, a submission reaching the
  queue, the WP-Cron worker delivering over real HTTP to a real receiver, and
  the recorded state settling on `delivered`. It checks the signature against
  the body that arrived rather than the one the plugin believed it sent, covers
  a refused delivery staying queued, and runs `uninstall.php` the way WordPress
  does — reinstalling afterwards so it is safe in any order. New CI job; the
  suite skips itself when no WordPress is configured, and CI fails if it skips.

  Writing it found three things. The tracking endpoint accepts-and-discards a
  request with no User-Agent, which is deliberate bot handling and now has a
  test of its own rather than only being worked around. `wp_safe_remote_post()`
  refuses a local receiver twice over — loopback address and non-safe port — and
  both refusals are real SSRF safeguards, so the suite opts out of them
  explicitly, per test, rather than quietly. And the receiver helper's original
  readiness check waited for the port to accept connections, which a server
  orphaned by an interrupted earlier run satisfies just as well: deliveries
  succeeded, the Activity Log recorded a 200, and the assertion on what arrived
  read an empty file belonging to a different process. It now asks the server
  who it is and accepts only its own token.

- `phpunit.wordpress.xml` is excluded from the release ZIP. `bin/build-zip.sh`
  copies tracked files and refuses a list of development artifacts by name, so a
  new PHPUnit config would have shipped the moment it was committed; the
  archive check in CI now matches any `phpunit*.xml` rather than the two
  filenames it knew about.

- The `actions/checkout` and `actions/upload-artifact` pins move to v5, clearing
  the Node 20 runtime deprecation warnings on every run.

- **A repair the cron could not carry out is no longer lost.** Bounded backoff
  ends the cron chain after roughly half an hour, and
  `wp_schedule_single_event()` can fail outright, leaving nothing to retry.
  `FormDeliveryQueue::repairPending()` now runs on the daily cleanup and works
  from the durable record — never from a scan for "submissions with no queue
  row", which equally describes every submission that was delivered and cleaned
  up. A repair reached out of band reports and re-records rather than opening a
  second backoff chain, and a record settles when its row lands, when its
  endpoint is deleted from the configuration, or when the submission is erased.
  `queue_repair_abandoned` now means the cron chain is spent, not that the
  obligation is; the new `queue_repair_on_duplicate` code reports a repair
  triggered by a replayed provider callback.

- **New submissions no longer enter the backfill queue.** `FormSubmissions`
  documents that every derived column is written at insert so that a row created
  by this version never needs backfilling, but `delivery_state` was left `NULL`.
  On a site that does not use webhooks, that made every submission match
  `BACKFILL_PREDICATE` and be re-derived by the daily worker — to `not_sent`, the
  value the insert already knew. It is written at insert now, and the
  integration test whose name promised this invariant asserts it.

- **The database-backed tracking rate limiter failed open.** The counter's
  write result was never checked, and when the read-back did not produce the
  expected `window|count` value the code fell back to treating the current
  request's own event count as the counter — a number far below any cap. A
  counter that could not be written or read therefore admitted every request,
  silently. Each step is now checked and every failure rejects the request and
  reports sanitized telemetry, matching the documented intent. A window
  rollover between the charge and the read is correctly treated as legitimate
  rather than as a failure.

- All 32 known-defect PHPUnit skips are implemented and passing: `DateRangeTest`
  (17), `ElementorIdentityTest` (6), `EndpointStateMigrationTest` (6) and
  `FrozenRetryTest` (3). The suite runs with zero skips, enforced by
  `failOnSkipped` so a known defect cannot be parked in it again.

- Editing an endpoint's URL no longer resets its delivery window, orphans its
  frozen retry payload, or leaves a retry cron event that `wp_unschedule_event()`
  can no longer match — uncancellable, yet still firing. `pruneStaleState()`
  compared `md5(url)` against configured endpoints, so an edited URL looked
  deleted and its undelivered payload was discarded.

- A deleted endpoint's retry state is now cleared by state key rather than by
  URL. Clearing by URL missed the row once state was keyed by durable id, leaking
  the row and the frozen personal data in it.

- README stated that activation creates four custom tables; it creates seven.
