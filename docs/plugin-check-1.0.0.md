# Plugin Check record — Convermetry 1.0.0

What the official Plugin Check tool still reports against the 1.0.0 release ZIP,
and why each remaining finding was left as it is. Kept so a reviewer's question
can be answered from one place, and so the next release can be compared with
this one.

## How it was run

| | |
|---|---|
| Tool | Plugin Check 2.1.0, `wp plugin check convermetry --format=json --include-experimental` |
| Target | The exact ZIP from `bin/build-zip.sh`, unzipped into an otherwise empty WordPress 7.1.2 site |
| PHP | 8.3.32 |
| Result | **0 errors, 336 warnings** (the pre-release baseline was 663 findings, about 170 of them errors) |

To reproduce: build the ZIP, install it into a throwaway site that has Plugin
Check active, and run the command above. Do not run it against a development
checkout: `tests/`, `vendor/` and the other `.distignore` paths are not part of
the plugin and produce findings of their own.

## Fixed during release preparation

- Output escaping throughout the admin screens, with HTML-bearing
  translations passed through `wp_kses_post()`.
- Every table name in SQL is bound with the `%i` identifier placeholder, and
  `LIKE` patterns go through `$wpdb->esc_like()`.
- Unslashed and sanitized request input; integer request values read with
  `intval( wp_unslash( … ) )`.
- Internationalization: the literal `convermetry` text domain everywhere,
  translator comments on every placeholder, no HTML wrapped inside
  translatable strings, and script translations wired with
  `wp_set_script_translations()`.
- Uninstall helpers and globals prefixed.
- `array_is_list()`: Plugin Check reported it as a WordPress 6.5 function (it
  is WordPress's polyfill of a native PHP 8.1 function; the plugin requires PHP
  8.3). The two call sites now use the equivalent `array_values( $x ) === $x`
  so that the automated check has nothing to flag.

## Remaining warnings

| Count | Code | Why it stays |
|---:|---|---|
| 130 | `WordPress.DB.DirectDatabaseQuery.DirectQuery` | The plugin keeps its data in seven custom tables (events, submissions, delivery log, delivery queue, notification queue, goal completions, lead history). WordPress has no API for custom tables, so `$wpdb` is the only way to use them. Every query is prepared. |
| 117 | `WordPress.DB.DirectDatabaseQuery.NoCaching` | Most of these are writes and queue operations, which must see the live row (the delivery and notification workers claim rows atomically). Funnel results, the most expensive report, are cached in a transient. The other reads run on demand on capability-checked admin screens and in the Activity Log API, where caching would show stale numbers. |
| 37 | `WordPress.DB.PreparedSQL.InterpolatedNotPrepared` | Report queries in `Reports`, `LeadReports`, `GoalReports` and `FormEngagementReport` interpolate only class constants (fixed SQL fragments such as `TAGGED_SQL`), columns picked from a fixed whitelist (`LeadReports::DIMENSIONS`), or table names built from `$wpdb->prefix`. All values are bound through `prepare()`. |
| 32 | `WordPress.Security.NonceVerification.Recommended` | Read-only display parameters on admin screens (`period`, `tab`, list filters, the `cvm_saved` notice flag), read on `GET` behind a capability check and cast or sanitized. Nothing changes state on `GET`. Every form and AJAX action that does change state verifies a nonce. |
| 11 | `PluginCheck.Security.DirectDB.UnescapedDBParameter` | `get_results()` / `get_var()` receive a variable that holds the output of `$wpdb->prepare()` (paged list queries in `FormSubmissions`, `DeliveryLog`, `ReportQuery`, `GoalCompletions`, `DatabaseManager`). The check cannot follow the variable back to `prepare()`. |
| 4 | `WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber` | The `WHERE` clause is built by a helper (`buildWhereClause()`) that returns SQL with placeholders plus the matching value list, so the placeholder count is not visible where `prepare()` is called. |
| 2 | `WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare` | Funnel and goal report statements are assembled from fixed fragments by `StepCompiler` / `GoalReports` and prepared with their collected parameters in one call. |
| 1 | `WordPress.DB.DirectDatabaseQuery.SchemaChange` | `uninstall.php` drops the plugin's own tables, which is what deleting the plugin is documented to do. |
| 1 | `WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound` | `cvm_track_event()`, a public function documented since before 1.0 and used by site code. Renaming it would break those callers (see the prefix note below). |
| 1 | `WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound` | `Extensions::attach()` calls `apply_filters( $filter, … )`. Its four call sites pass the literals `convermetry_webhook_payload_extensions`, `convermetry_submission_context_extensions`, `convermetry_tracker_config_extensions` and `convermetry_delivery_log_api_item`. |

Every `phpcs:ignore` / `phpcs:disable` in the source carries a reason after
`--`, and each was checked against the code it covers.

## Open question for review: the `cvm` prefix

Public API names use `convermetry_` (the `convermetry_*` hooks,
`convermetry_submit_form()`) and PHP code lives in the `Convermetry\`
namespace. Internal storage and a few older public names use the three-letter
`cvm` prefix:

- options, transients and cron hooks (`cvm_*`, about 110 distinct keys);
- the seven tables (`{$wpdb->prefix}cvm_*`);
- constants (`CVM_VERSION`, `CVM_PLUGIN_DIR`, `CVM_PLUGIN_FILE`, `CVM_PLUGIN_URL`);
- script handles (`cvm-tracker`, `cvm-admin`, …) and browser storage keys
  (`cvm_session`, `cvm_campaign`, `cvm_pending`);
- the public function `cvm_track_event()`.

WordPress.org reviewers sometimes ask for prefixes of four or more
characters. These were left unchanged for 1.0.0 because renaming the stored
names needs a data migration and renaming the public ones breaks existing
integrations. If the review team asks for it, the change is a migration
(copy the options and rename the tables on upgrade) plus deprecated aliases for
`cvm_track_event()` and the script handles, not a search-and-replace.
