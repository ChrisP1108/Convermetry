# Plugin Check record — Convermetry 1.0.1

What the official Plugin Check tool reports against the 1.0.1 release ZIP, and
how each finding from the 1.0.0 pre-review was resolved. Kept so a reviewer's
question can be answered from one place, and so the next release can be
compared with this one. The 1.0.0 record is in git history.

## How it was run

| | |
|---|---|
| Tool | Plugin Check 2.1.0, `wp plugin check convermetry --format=csv --include-experimental` (also with `--include-low-severity-errors --include-low-severity-warnings`) |
| Target | The exact ZIP from `bin/build-zip.sh`, installed into an otherwise empty WordPress 7.1.1 site |
| PHP / MySQL | 8.4.18 / 8.0.35 |
| Result | **0 errors, 0 warnings** |
| Control | The 1.0.0 ZIP, checked in the same site with the same command, reports 340 warnings: the 336 from the pre-review plus four for 1.0.0's global constants, which Plugin Check flags once it infers prefixes from the code. |

To reproduce: build the ZIP, install it into a throwaway site that has Plugin
Check active, and run the command above. Do not run it against a development
checkout: `tests/`, `vendor/` and the other `.distignore` paths are not part of
the plugin and produce findings of their own.

## Review email

**Use wp_enqueue commands** (`src/Admin/Pages/AboutPage.php` `<noscript><style>`).
The two rules that reveal the hook reference without JavaScript now live in
`assets/css/admin-about.css`, scoped to the `no-js` class WordPress puts on the
admin `<body>` and removes from its own inline script in `admin-header.php`.
Behavior is unchanged: with scripting on the panels start collapsed and expand
on **Learn More**; with it off every panel is shown and the toggles are hidden.
The four inline `onclick`/`onsubmit` confirmation handlers (Goals and Funnels
**Remove**, Activity Log and Submissions **Clear All**) became a
`data-cvmtry-confirm` attribute read by `assets/js/admin-confirm.js`. No PHP file
prints a `<script>`, `<style>` or `<noscript>` block or an inline event handler;
`AdminMenuRoutingTest` enforces that. The tracker's configuration is attached
with `wp_add_inline_script()`.

**Prefixes.** Every plugin-owned name uses the six-character `cvmtry` prefix
(`cvmtry_`, `CVMTRY_`, `cvmtry-`, `data-cvmtry-*`, `X-CVMTRY-Page`,
`cvmtry:log-deleted`). Public hooks keep `convermetry_`, PHP code keeps the
`Convermetry\` namespace, and the text domain is `convermetry`. 1.0.0 was never
publicly released, so there is no migration and no alias: nothing reads,
copies or deletes the previous names.

**Nonces and permissions.** Every request handler that changes data or reveals
stored data verifies a nonce and a capability before doing anything:

| Handler | Nonce action | Capability scope |
|---|---|---|
| Settings save (`options.php`) | `register_setting()` group | `manage_options` (Settings API) |
| Webhooks save / Notifications save / cancel queue / Forms save (`admin-post.php`) | `cvmtry_save_webhooks`, `cvmtry_save_notifications`, `cvmtry_cancel_notifications`, `cvmtry_save_forms` | webhooks / notifications / forms manage |
| Webhook retry discard (GET) | `cvmtry_discard_retry` | webhooks manage |
| Goals and Funnels save / delete | `cvmtry_save_goal`, `cvmtry_delete_goal`, `cvmtry_save_funnel`, `cvmtry_delete_funnel` | goals / funnels manage |
| Clear All logs / submissions | `cvmtry_clear_activity_logs`, `cvmtry_clear_submissions` | activity / submissions manage, delete |
| CSV and JSON exports (GET links) | `cvmtry_export_{csv,json}`, `cvmtry_submissions_export_{csv,csv_filtered}` | activity view, submissions export |
| AJAX: list, detail, delete, lead update, API toggle, key regeneration, webhook test, notification test | one action each, named after the `wp_ajax_cvmtry_*` hook | the matching scope |

The 32 `NonceVerification.Recommended` reads were all presentation-only and
are now unslashed and sanitized where read, each with an annotation naming
the handler that produced the value:

- `period` on Analytics, Goals and Funnels — matched against a fixed list and
  only chooses the date range displayed, so report links stay bookmarkable;
- `cvmtry_search` on Submissions — only pre-fills the search box for the deep
  link in notification emails; rows come from the nonce-checked AJAX action;
- `cvmtry_saved`, `cvmtry_cleared`, `cvmtry_cancelled`, `cvmtry_retry_discarded`,
  `cvmtry_goal_saved`/`_error`, `cvmtry_funnel_saved`/`_error` — flags set by the
  redirect after a nonce-checked handler; each selects one fixed notice;
- `page` — identifies the screen a notice belongs to.

**Guideline 11.** Notices are scoped to the plugin's own screens, except the
PHP-version error, which now shows only to users who can activate plugins and
only on the Dashboard and Plugins screens. There are no activation redirects,
dashboard widgets, admin-bar items or plugin-row promotions. The Home screen's
"Convermetry Cloud" card is a static, linkless "Coming Soon" note at the bottom
of the plugin's own page: no pricing, no call to action, no external request.
It was reviewed and left as is.

## SQL findings from the pre-review

| Count | Code | Resolution |
|---:|---|---|
| 37 | `PreparedSQL.InterpolatedNotPrepared` | Fixed. Report queries bind table and column names with `%i` and lead-status lists as values (`IN (` + generated `%s` list); the goal and form filters became one literal statement each (`(%s = '' OR goal_id = %s)`). The one interpolation left is the `TAGGED_SQL` class constant in `Reports::topCampaigns()`, a fixed predicate used four times, annotated. |
| 2 | `PreparedSQLPlaceholders.UnfinishedPrepare` | Fixed in `GoalReports` by the above. In `FunnelReport` the scanner read the `'sql'` array key as query text; the builder output is destructured first. |
| 4 | `PreparedSQLPlaceholders.ReplacementsWrongNumber` | Scanner limitation, verified and annotated: one array of arguments matches the placeholders of a helper-built clause (`buildWhereClause()` in `DeliveryLog` and `FormSubmissions`, the six-column tuple list in `Reports::topCampaignContent()`). Integration tests run these against MySQL. |
| 11 | `PluginCheck.Security.DirectDB.UnescapedDBParameter` | Scanner limitation, verified and annotated: the variable holds fixed SQL fragments with bound values (`$where`, the multi-row insert verb and tuples), or `ReportQuery`'s argument, which is `prepare()` output at every call site. Two call sites that broke that contract — `Reports::hasEvents()` and `GoalReports::lastSeen()` — now prepare their SQL. |
| 130 / 117 | `DirectDatabaseQuery.DirectQuery` / `NoCaching` | Custom tables and options-table lease rows have no WordPress API. Each call carries an annotation stating why it is direct and uncached: a write, a queue claim or lock that must read the live row, a retention delete, a schema check, uninstall, or an on-demand admin read. No caching was added: queue, lock and delivery-state reads must be live, admin screens show current data, the funnel report already caches its result, and form discovery is cached by `FormProviderRegistry`. |
| 1 | `DirectDatabaseQuery.SchemaChange` | `uninstall.php` drops the plugin's own tables; annotated. |
| 1 | `PrefixAllGlobals.NonPrefixedFunctionFound` | Fixed: `cvmtry_track_event()`. |
| 1 | `PrefixAllGlobals.DynamicHooknameFound` | `Extensions::attach()` now refuses any hook name that does not start with `convermetry_`, and is annotated; all four callers pass literals. |

Every `phpcs:ignore` / `phpcs:disable` names the specific sniff codes and gives
the reason after `--`. None disables a rule file-wide.

## Verification for this release

- Unit suite (1,166 tests), JavaScript suites (37 tests: tracker and admin
  confirm), PHPStan level 8 with no baseline, builder regression suite (24).
- Integration suite against MySQL 8.0 (75 tests), including the rewritten
  report queries.
- End-to-end suite against WordPress 7.1.1 (21 tests): activation, cron,
  REST, queued delivery to a local receiver, privacy tools, uninstall.
- A clean WordPress 7.1.1 site installed from the release ZIP and driven in
  Chrome: every admin screen loads without JavaScript errors; saves, AJAX
  actions, exports, nonce rejection, confirmation prompts, the deliveries REST
  API, the About page with JavaScript on and off, a Contact Form 7 submission
  correlated through the tracker, scheduled tasks, deactivation,
  reactivation and uninstall. Webhooks went only to a local receiver and mail
  was captured, never sent.
