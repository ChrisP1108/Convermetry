# Plugin Check record — Convermetry 1.0.2

What the official Plugin Check tool reports against the 1.0.2 release ZIP, and
how each finding from the manual review of 1.0.1 was resolved. Kept so a
reviewer's question can be answered from one place. Earlier records (1.0.0,
1.0.1) are in git history.

## How it was run

| | |
|---|---|
| Tool | Plugin Check 2.1.0, `wp plugin check convermetry --format=csv --include-experimental` (also with `--include-low-severity-errors --include-low-severity-warnings`) |
| Target | The exact ZIP from `bin/build-zip.sh`, installed into a clean WordPress 7.1.2 site with `WP_DEBUG`, `WP_DEBUG_LOG` and `SCRIPT_DEBUG` on |
| PHP / MySQL | 8.4.18 / 8.0.35 |
| Result | **0 errors, 0 warnings** in both runs |

The first 1.0.2 build reported one error, `EscapeOutput.OutputNotEscaped` for
the status in `AdminRequest::deny()`'s `wp_die()` arguments; it is now cast to
`int`. Do not run the tool against a development checkout: `tests/`, `vendor/`
and the other `.distignore` paths are not part of the plugin.

## Review email (manual review of 1.0.1)

### Nonces and user permissions

The review named `GoalsPage::processDelete()`, `isRequest()` and
`currentPeriod()`, `SubmissionsPage::handleDeleteAjax()`, and compound
conditions in the Activity Log, Notifications and Webhooks handlers, "out of a
total of 14". Every admin request handler was audited, not only those.

**Structure.** Each handler now runs the same separated guards in its own body,
each a single `if` that ends the request on failure, before it reads any other
input or touches data:

1. the HTTP method (`AdminRequest::isPost()` / `isGet()`; 405 otherwise);
2. the capability, `current_user_can(Capability::required(<scope>))` — the
   per-action scope, never a hard-coded `manage_options`;
3. the nonce present, as one string (`isset()` and `is_string()`);
4. `wp_verify_nonce()` against the handler's own action;
5. input, type-checked before it is cast or sanitized.

`isRequest()` and `authorize()` are gone; no handler relies on a helper for its
nonce or capability check. `AdminRequest` only reports the method and sends the
refusal (`wp_die()` 403/405/400, or the same `{success:false,data:{message}}`
JSON the scripts read, now with a 403/400/405 status).

**No request handling on `admin_init`.** Everything that was on it moved to its
own `admin_post_{action}` hook, so ordinary admin page loads never reach it:

| Handler | Hook / nonce action | Method | Scope |
|---|---|---|---|
| Goals save / remove | `cvmtry_save_goal`, `cvmtry_delete_goal` | POST | goals.manage |
| Funnels save / remove | `cvmtry_save_funnel`, `cvmtry_delete_funnel` | POST | funnels.manage |
| Activity Log Clear All | `cvmtry_clear_activity_logs` | POST | activity.manage |
| Activity Log exports | `cvmtry_activity_export_csv`, `cvmtry_activity_export_json` | GET | activity.view |
| Submissions Clear All | `cvmtry_clear_submissions` | POST | submissions.delete |
| Submissions exports | `cvmtry_submissions_export_csv`, `…_csv_filtered` | GET | submissions.export |
| Discard pending retry | `cvmtry_discard_retry` | GET (link) | webhooks.manage |
| Webhooks / Notifications / Forms save, discard queue | `cvmtry_save_webhooks`, `cvmtry_save_notifications`, `cvmtry_save_forms`, `cvmtry_cancel_notifications` | POST | webhooks / notifications / forms .manage |
| AJAX: logs list, log delete, API toggle, API key, submissions list, detail, delete, lead update, webhook test, notification test | one action each, named after its `wp_ajax_cvmtry_*` hook | POST | activity.view, activity.manage, api.manage ×2, submissions.view ×2, submissions.delete, leads.edit, webhooks.manage, notifications.manage |

Settings are saved through `options.php` (Settings API nonce); the group now
uses the `settings.manage` scope via `option_page_capability_*`.

**Reporting period.** `currentPeriod()` reads a read-only date-range filter.
Period links on Analytics, Goals and Funnels now carry a nonce for that
screen's filter action (`ReportPeriod`); a supplied period is honored only with
that nonce and only when it is an offered value, otherwise the last 30 days are
shown with a notice. The filter nonce authorizes nothing else, and no save or
delete nonce is accepted for it. The Goals and Funnels forms and redirects
carry the period with a fresh filter nonce.

**Request reads still without a nonce, deliberately:**

- the post-redirect notice flags (`cvmtry_saved`, `cvmtry_cleared`,
  `cvmtry_cancelled`, `cvmtry_error`, `cvmtry_retry_discarded`,
  `cvmtry_goal_saved`/`_error`, `cvmtry_funnel_saved`/`_error`) and `page` —
  each is compared with fixed values and only selects one fixed notice;
- `cvmtry_search` on Submissions — the deep link in notification emails. The
  link is written by WP-Cron and opened by whichever recipient follows it, so
  no per-session nonce could verify. It is accepted only in the shape of a
  submission id, only pre-fills the search box, and rows are read by the
  nonce- and capability-checked list action.

### Sanitize, validate, escape

**Webhooks.** `WebhookSettingsInput` checks every field's type, then validates
and sanitizes each for its meaning: URLs through `esc_url_raw()` and
`wp_http_validate_url()` (HTTPS unless `convermetry_allow_insecure_webhooks`),
labels with `sanitize_text_field()`, signing secrets verbatim but free of
control characters. A rejected endpoint is stored for its notice as position,
reason code and a `sanitize_text_field()`-cleaned excerpt of at most 80
characters; the URL as typed is no longer stored. Each field is unslashed once,
in the handler. A malformed request stores nothing.

**Arrays.** `cvmtry_global_headers`, `cvmtry_global_query` and the per-form
lists go through `KeyValuePairs::fromHeaderInput()` / `fromQueryInput()`:
container and rows type-checked, header names must be RFC 9110 tokens, no
control characters anywhere, values kept verbatim (`sanitize_text_field()`
stripped `%XX` and tag-like text from credentials). `cvmtry_rendered_forms`
and the per-form rule keys are validated by
`FormProviderRegistry::validFormKey()` and kept verbatim — a key's identity may
contain spaces and capitals, so `sanitize_key()` would corrupt it.

Remaining `phpcs:ignore`/`disable` comments in the admin layer are the notice
flags and deep link above (`NonceVerification.Recommended`), and
`InputNotSanitized` where a nested array is unslashed once and handed straight
to its field-by-field validator. Each names its sniff and its reason.

## Verification for this release

- PHP lint; PHPStan level 8, no baseline; unit suite (1,242 tests) on PHP 8.5.3
  and 8.4.18; JavaScript suites (37); builder regression suite (24).
- Integration suite against MySQL 8.0.35 (75 tests).
- WordPress suite against WordPress 7.1.2 (275 tests), including
  `AdminRequestHandlersTest`: every handler refused for a missing, invalid,
  expired, other-action, other-user and array-valued nonce, a role without the
  scope and the wrong method, with every side effect asserted absent.
- The release ZIP on a clean WordPress 7.1.2 site with `WP_DEBUG` on, driven in
  Chrome: every Convermetry screen; period links, tampered and expired links;
  goal and funnel save and remove with the period kept; webhook endpoints,
  secrets and headers round-tripped, middle endpoint removed with ids kept,
  test sends, rejected URLs with markup, retry discard; Contact Form 7 form
  settings and notification rules, including with the provider deactivated;
  test email; queue discard; submissions list, deep link, detail, lead update,
  delete, exports; Activity Log list, delete, API toggle and key, exports,
  Clear All; settings save; refused requests (403/400/405) from an
  administrator with bad input and from an editor; an anonymous Contact Form 7
  submission through the tracker. No JavaScript errors, no PHP notices,
  warnings or deprecations in the debug log. Webhooks went only to a local
  receiver and mail was captured, never sent.
