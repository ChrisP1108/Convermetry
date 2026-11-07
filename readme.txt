=== Convermetry ===
Contributors: cpaschall1981
Tags: analytics, lead tracking, utm, webhooks, forms
Requires at least: 6.3
Tested up to: 7.1
Requires PHP: 8.3
Stable tag: 1.1.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Visitor analytics, campaign attribution and server-confirmed form lead tracking for WordPress, with signed, retried webhook delivery.

== Description ==

Convermetry connects the question "where did this visitor come from?" to the answer "which lead did they become?" — inside your own WordPress site.

It records how visitors use your pages, attributes each visit to a traffic channel and campaign, and captures form submissions **after the form plugin has confirmed them on the server**. Every lead is linked to the analytics session and campaign that produced it. Leads can then be forwarded to your own systems through webhooks, and you can be emailed when one arrives.

Everything is stored in your WordPress database. Convermetry works on its own: it needs no account, no API key and no external service.

= Analytics and attribution =

* Page views, clicks, form views, starts, validation errors and submit attempts, confirmed conversions, scroll depth, hover and custom events. Each type can be switched off.
* UTM campaign parameters, ad-click identifiers (only which parameter was present, never its value) and entrance referrers classified into channels such as Paid Search, Organic Social or Email.
* An analytics dashboard with top pages, landing pages, referrers, campaigns, channels, devices, conversions and lead outcomes, with summary charts beside the full tables and CSV and JSON export of the selected period.
* Goals for actions that are not form submissions (phone and email link clicks, downloads, reaching a page, custom events) and funnels that measure the ordered path to a conversion, each exportable as CSV or JSON.
* Form engagement and abandonment reporting that never records what a visitor typed into a form they did not submit.

= Leads =

* A Submissions screen with each lead's answers, the page it came from, its campaign attribution and its webhook delivery status.
* Lead status (new, qualified, won, lost…) and value, with history, and lead reports by channel, campaign, landing page and form.
* CSV and JSON export (everything, or just the current filters), per-submission delete, and a retention window after which data is deleted automatically.

= Supported form plugins =

Convermetry detects these automatically and records a submission when the form plugin's own server-side success hook fires. None of them is required.

* **Contact Form 7, WPForms, Gravity Forms, Fluent Forms, Ninja Forms and Formidable Forms** — captured automatically, with no per-form setup.
* **Elementor Pro (classic Form widget)** — captured automatically.
* **Elementor Pro Atomic forms** — opt in per form: in the Elementor editor, add **Convermetry** under *Actions after submit* on the form and update the page.
* **Bricks Builder (native Form element), Bricks 1.12.2 or newer** — opt in per form: in Bricks, tick **Convermetry** under *Actions after successful form submit* and save. Older Bricks versions are shown as unavailable.
* **Any other form** — send it through the documented `convermetry_form_submission` action or the `convermetry_submit_form()` function.

Submissions made before a form is connected are not recorded and cannot be recovered. Per-form options (exclude a form, set a custom form ID, add per-form webhook headers and query parameters) are on the Forms screen.

= Webhooks and email notifications =

* Send **form submissions** (one message per confirmed lead, delivered in the background) and/or scheduled **analytics reports** (hourly, twice daily, daily or weekly) to any number of HTTPS endpoints you configure.
* Optional HMAC-SHA256 signatures, stable delivery IDs for idempotency, automatic retries and an Activity Log of every attempt with its (redacted) payload and response.
* Optional internal **email notifications** to addresses you choose, sent through `wp_mail()`. They are off by default.

= Privacy and data handling =

This section describes what Convermetry does. It is not legal advice, and Convermetry does not by itself make a site compliant with any law. Review your privacy policy and your legal basis for processing.

**What is collected**

* For each tracked interaction: the event type, the page address without its query string, page title, clicked element text and link target, referrer (without query string), campaign parameters, a traffic channel, a device type (desktop, tablet or mobile), a random visit identifier and a timestamp.
* **IP addresses are stored by default** with analytics events and form submissions. You can turn this off under Convermetry → Settings ("Store visitor IP addresses"). User agents are never stored.
* For each confirmed form submission: the values the visitor submitted, the page, its campaign (UTM) parameters, and the analytics context of the visit (channel, campaign, landing page, pages viewed). Fields that look like passwords or other credentials are withheld from email notifications and redacted in the Activity Log, but they are still stored and sent to your webhooks, and card numbers are not recognized at all. If you send a form through the developer API, leave passwords and payment details out. If you turn on "Include page URL parameters", the page's other URL parameters are stored as well, including ad-click identifiers such as gclid, except those whose names look like secrets or contact details and any value containing an email address.
* Logged-in users are excluded from tracking by default. A member who arrived from a campaign and then logged in therefore submits with no campaign or channel; the submission is still recorded and delivered.
* **Do Not Track / Global Privacy Control are not honored by default.** When you enable that setting, visitors who send either signal are not tracked, and no IP address is stored with a form they submit.

**Browser storage — no cookies.** The tracker sets no cookies. It stores a random visit identifier (`cvmtry_session`) and the visit's attribution (`cvmtry_campaign`) in the browser's localStorage, and briefly holds unsent events (`cvmtry_pending`) in sessionStorage. The visit identifier is replaced after 30 minutes of inactivity. In the EU and UK, the rules that govern cookies also apply to this kind of storage.

**Consent.** Convermetry has no consent banner of its own and is not integrated with a consent-management plugin. Analytics starts collecting as soon as the plugin is activated. If your site needs consent before analytics runs, have your consent tool block the script handle `cvmtry-tracker` until consent is given, or return `false` from the `convermetry_should_enqueue_tracker` filter. Form submissions are still recorded server-side when the tracker does not run.

**Where it is stored and for how long.** In seven custom tables in your WordPress database. Analytics events, submissions, goal completions, lead history and Activity Log entries are deleted automatically after the retention period (default 90 days, adjustable from 7 to 365). Deleting the plugin from the Plugins screen removes every table, option and scheduled task it created.

**WordPress privacy tools.** Convermetry adds suggested text to Settings → Privacy → Policy Guide, generated from your current settings. It also adds an exporter and an eraser to Tools → Export Personal Data and Tools → Erase Personal Data. For an email address, they find the form submissions whose submitted values contain that exact address. The eraser deletes those submissions with their lead history and queued deliveries and notifications. It removes the lead from the Activity Log's stored payloads, and removes the visitor's IP address from the analytics of that visit. Analytics recorded during visits in which no form was submitted is not linked to an email address, so it can only age out through the retention period.

= External services =

Convermetry does **not** contact any server by default, and it never sends data to the plugin's author. All fonts, scripts and styles are bundled with the plugin. Information leaves your site only through features a site administrator configures:

* **Webhook endpoints.** When you add an endpoint under Convermetry → Webhooks, Convermetry sends it HTTPS POST requests to the URL you entered. Form submission messages contain the submitted form values, the submitter's IP address (when IP storage is on), the page, its campaign (UTM) parameters (and its other URL parameters when you turn on "Include page URL parameters"), and the visit's analytics context. Analytics report messages contain aggregated statistics plus a list of individual conversions, each with its IP address (when stored) and visit identifier. You choose which endpoints receive which message type. The receiving service is chosen by you, so its terms of use and privacy policy are the ones you agreed to with that service.
* **Email notifications.** When enabled, a notification for each new submission is sent through your site's own mail system (`wp_mail()`, and any SMTP plugin you use) to the recipients you enter. It can include the submitted values, analytics context and, only if you enable it, the visitor journey and IP address.

Copies that have already been delivered to a webhook endpoint or sent by email are outside Convermetry's control. Deleting a submission, retention and the eraser cannot recall them; they are kept according to the receiving system's own policies.

= For developers =

Convermetry has a documented hook API (85 actions and filters), a custom form submission API, a read-only REST endpoint for the delivery log, and versioned webhook payload schemas. The full reference is in the plugin under Convermetry → About and in the README.md file included with the plugin.

== Installation ==

1. Install Convermetry from the Plugins → Add New screen, or upload the `convermetry` folder to `/wp-content/plugins/`.
2. Activate it on the Plugins screen. Your server must run PHP 8.3 or newer.
3. Open **Convermetry → Home**. It shows what the plugin is recording and a setup checklist.
4. Review **Convermetry → Settings**: which interactions to track, whether to store IP addresses, whether to honor Do Not Track / Global Privacy Control, and the retention period.
5. Check **Convermetry → Forms**. Supported form plugins are detected automatically. For Elementor Pro Atomic forms and Bricks forms, add the Convermetry action to each form in the builder (see Description).
6. Optionally add webhook endpoints under **Convermetry → Webhooks** and email notifications under **Convermetry → Notifications**.
7. Review the suggested text under **Settings → Privacy → Policy Guide** and update your privacy policy.

== Frequently Asked Questions ==

= Do I need an account or an external service? =

No. Convermetry stores everything in your WordPress database and works with no account, API key or remote service. Webhooks and email notifications are optional and send data only to destinations you configure.

= Does Convermetry use cookies? =

No. It uses the browser's localStorage and sessionStorage instead (see "Browser storage" in the Description). Privacy rules on cookies generally also apply to this storage, so treat it like a cookie in your privacy notice and consent decisions.

= Does analytics start as soon as I activate the plugin? =

Yes. Page views and the other interaction types are enabled by default, logged-in users are excluded, and IP addresses are stored. Review Convermetry → Settings after activating. If you need visitor consent first, see the next question.

= How do I wait for consent before tracking? =

Configure your consent tool to block the `cvmtry-tracker` script until consent is given, or add a filter to `convermetry_should_enqueue_tracker` that returns `false` until your consent check passes. Server-confirmed form submissions are still recorded, without analytics context, when the tracker does not run.

= Which form plugins are supported? =

Contact Form 7, WPForms, Gravity Forms, Fluent Forms, Ninja Forms, Formidable Forms, Elementor Pro (classic forms and Atomic forms) and Bricks Builder 1.12.2+. Other forms can use the developer API.

= My Elementor Atomic or Bricks form is listed on the Forms screen but nothing is recorded. =

Those builders only run the actions you select for each form. Add **Convermetry** to the form's actions in the builder and save the page. Listing a form on the Forms screen does not add the action for you.

= Are single-page navigation and pages inside iframes counted? =

A page change made by JavaScript with `history.pushState()` is not counted as a page view, and later events keep the address the page was loaded on, so page-address goals and funnel steps cannot fire on those sections. Report such a step with `Convermetry.track()` and a custom-event goal instead. A page of your site shown inside an iframe is counted as its own page view, so a page that embeds another of your pages counts both; use the `convermetry_should_enqueue_tracker` filter to keep the tracker off a page that is only ever embedded.

= What data leaves my site? =

Nothing, unless you configure webhook endpoints or email notifications. See "External services" in the Description for exactly what each one sends.

= How long is data kept? =

90 days by default. Change it between 7 and 365 days under Convermetry → Settings. Older data is deleted by a daily cleanup task.

= How do I stop storing IP addresses? =

Untick **Store visitor IP addresses** under Convermetry → Settings. New records then have no IP address; existing records keep theirs until retention deletes them. You can also honor Do Not Track / Global Privacy Control on the same screen.

= How do I export or erase someone's data? =

Use WordPress's Tools → Export Personal Data and Tools → Erase Personal Data with the person's email address. Convermetry finds form submissions whose submitted values contain that exact address. Webhook deliveries and emails that were already sent cannot be recalled; the eraser lists the webhook destinations the data had reached. Entries stored by your form plugin itself are handled by that plugin's own privacy tools, if it provides them.

= What happens when I deactivate or delete the plugin? =

Deactivating stops tracking and scheduled tasks but keeps your data. Deleting the plugin from the Plugins screen removes all of its tables, options and scheduled tasks, on every site of a multisite network.

== Screenshots ==

1. The Home screen: what Convermetry is recording on this site, the setup checklist and a status overview.
2. The Analytics dashboard: overview totals and daily page views, followed by content, engagement, acquisition, device, goal and lead reports.
3. A submission expanded: lead status and value, form details, channel and campaign attribution, and the visitor's path to the form.
4. The Forms screen: form engagement and abandonment, detected form plugins and per-form settings.
5. Webhook endpoints: the message types each one receives, signing secrets and test buttons.
6. The Activity Log, at the foot of the Webhooks screen: every delivery attempt with its payload and response, including retries.
7. Goals with their completions, conversion rates and value.
8. A funnel showing how many visits reached each step on the way to a quote request.
9. Tracking and privacy settings: interaction types, logged-in users, Do Not Track / Global Privacy Control, IP storage and data retention.

== Changelog ==

= 1.1.4 =
* Fix: a middle-click that opens a link in a new tab now records the click and completes click goals. A right-click, or a middle-click on something that is not a link, records nothing.
* Fix: a negative value passed to `Convermetry.track()` no longer lowers a goal's value. It is ignored, like a value that is not a number, and the goal's own value is used.
* Fix: amounts with more than two decimal places are rounded half-up to the cent (12.345 becomes 12.35), the same way whether they are typed or sent from page code. Previously typed amounts were cut short (0.005 became 0.00).
* Fix: an old script library that changes how arrays are converted to JSON (Prototype.js 1.6) no longer stops all tracking on the pages that load it.
* Fix: a damaged visit identifier in the browser's storage is replaced instead of being sent, so visitors holding one are no longer merged into a single visit.
* Fix: form fields named in a non-Latin script, and fields with no name or id, are listed separately in "Most common friction points" instead of merging into one blank row.
* Fix: a form submission sent twice no longer stores a second conversion event. Reports were not affected.
* Changed: lead values and goal values can no longer be negative. The Submissions screen and the goal editor explain why and how to record a refund or lost deal instead.
* Documentation: what the tracker does not count (single-page navigation, pages inside iframes, logged-in members), visitors who share one network and the per-IP limit, and keeping passwords and payment details out of forms sent through the developer API.

= 1.1.3 =
* Security: URL parameters on the page a form was submitted from are no longer stored or sent to webhooks unless you ask for them. By default only campaign (UTM) parameters are kept, so ad-click IDs, email addresses and reset tokens in a page URL are discarded. With "Include page URL parameters" on, other parameters are kept too, including ad-click IDs, except names that look like secrets or contact details and any value containing an email address.
* Security: with "Store form submission data in the Activity Log" off, the Activity Log no longer stores the endpoint's response, because a receiver can echo the visitor's values back. The status code and connection errors are kept. The page's URL parameters are removed from the stored copy too.
* Fix: percent-encoded characters in a page address are no longer stripped.
* Fix: a page the browser prerenders no longer counts as a visit or completes a goal unless the visitor opens it.
* Fix: Back and Forward now count as page views when the browser restores a page from its cache.
* Fix: a visitor who submits a form, comes back, starts it again and leaves now counts as abandoned. Abandonment is calculated when a report is viewed, so figures for past periods can rise.
* Fix: a very long page title, or an emoji at a length limit, no longer loses a whole batch of tracked events.
* Fix: a form submitted without JavaScript now records its page and no longer counts as Direct traffic. The channel report lists these conversions under a blank channel.

= 1.1.2 =
* Fix: a form field named `method` no longer stops the tracker from starting. Previously no events were recorded on that page and later forms lost their session link. One form's markup can no longer affect the others.
* Fix: a form field named `action` can no longer make an external form look like one on your site. Session and campaign data is only added to forms that post to your own site, as the browser actually sends them — including a `<base>` URL and the submit button's own destination.
* Fix: a form field named `id` no longer loses that form's view, start and submit-attempt events.
* Fix: the Forms screen lists each form under a meaningful name. It prefers your form plugin's own title over labels read from the page, and never shows the generic "form" when a real name is known. Submit attempts now use the same name as the form's views and starts, including `data-cvmtry-form-name`.
* Fix: funnels and abandonment no longer miss a completed submission when the form plugin confirmed it before the browser's tracking request arrived. Each submit attempt now carries its conversion token, so a confirmed submission is always ordered after its own attempt. This includes Ninja Forms and Elementor Atomic forms. Events recorded before 1.1.2 keep their previous order.
* Fix: visitors on phones whose model name contains "bot", such as CUBOT handsets, are no longer discarded as bots. Search crawlers, headless browsers and scripted clients are still filtered.

= 1.1.1 =
* Hardening: the JSON export handlers on the Submissions screen verify their nonce in the same step that reads the request, and the shared export nonce check reads and unslashes the nonce directly, resolving two WordPress Plugin Check warnings. Export behavior and permissions are unchanged.

= 1.1.0 =
* New: CSV and JSON export on the Analytics, Goals and Funnels screens, covering the period you are viewing with the same figures the screen shows. Submissions adds JSON export alongside its CSV export, for everything or for the current filters. Each export needs the same permission as its screen (Submissions: `submissions.export`).
* New: Analytics shows a device-share doughnut beside the daily page-view chart and summary charts of top pages, clicked elements, channels, goal completions and lead outcomes. Each chart links to its full table, and every value is printed beside its mark.
* Changed: every admin screen has a consistent layout. Page headers carry each screen's period filter and actions, related settings are grouped into panels, and long settings forms keep their Save button in reach. All existing text, settings and behavior are unchanged.
* Changed: Home lists this site's status and quick links before the setup checklist. Goals and Funnels have a link to their editor in the header, and each funnel card leads with its overall conversion. A submission's details show the lead outcome and submitted answers beside its attribution and delivery details, with Delete at the foot of the panel. Webhooks has links to each of its sections. About has a contents sidebar on wide screens.
* Fix: the Cancel button on the Goals and Funnels editors appears only while you are editing an existing item.
* Fix: printing Analytics for 30 or 90 days no longer lets the daily chart overflow the page.

= 1.0.3 =
* Changed: the Activity Log is now the last section of the Webhooks screen, with everything it had before: the Deliveries API card, Clear All Logs, CSV and JSON export, and the successful and failed delivery lists with their filters, search and paging. It no longer has its own menu item, and its old address redirects to the new section.
* Changed: a user given only Activity Log access through the `convermetry_admin_capability` filter can open the Webhooks screen and sees only the log. Webhook settings, signing secrets, pending retries and the delivery queue still need `webhooks.manage`. Delete, Clear All Logs and the Deliveries API card are shown only to users allowed to use them.
* Changed: the Home screen's Activity Log card and a submission's "Open the Activity Log" link open the new section, and are shown only to users who may view the log.

= 1.0.2 =
* Security: every admin form, link and AJAX action checks the request method, the user's capability and its own nonce as separate steps before reading any input. Saves, deletions, exports and retry discards run only on their own admin-post actions, never on ordinary admin page loads.
* Security: webhook URLs, labels, signing secrets, custom headers and query parameters are validated field by field. A rejected URL is reported without storing what was typed, and a malformed submission no longer resets saved settings.
* Security: reporting-period links on Analytics, Goals and Funnels carry their own nonce; an expired link shows the last 30 days with a notice.
* Fix: Discard on a pending analytics retry now removes it.
* Fix: removing an endpoint on the Webhooks screen no longer gives the next endpoint a new identity when saved.
* Fix: a site that delegates the Settings screen with the `convermetry_admin_capability` filter can now save it.

= 1.0.1 =
* Changed: every plugin-owned name now uses the `cvmtry` prefix — stored options, database tables, scheduled events, script and style handles, CSS classes, `data-cvmtry-*` attributes, browser storage keys and the `cvmtry_track_event()` helper. Public `convermetry_*` hooks and functions are unchanged.
* Changed: the About screen's hook reference and the confirmation prompts for Remove and Clear All now run entirely from enqueued scripts and stylesheets; no inline script or style blocks are printed.
* Changed: the PHP version notice is shown only to administrators, on the Dashboard and Plugins screens.
* Hardening: report queries bind every table and column name as an identifier and every value through prepared statements, and display-only request parameters are sanitized where they are read.

= 1.0.0 =
* New: WordPress privacy tools integration — suggested privacy policy text generated from your settings, and a personal-data exporter and eraser for form submissions and their linked data.
* New: the plugin interface, notification emails and scripts are translatable (text domain `convermetry`), with a translation template in `languages/`.
* Fix: the Remove buttons on the Goals and Funnels screens now ask for confirmation as intended.
* Fix: deleting the plugin now removes every option it created (one version option was previously left behind).
* Fix: Ninja Forms submissions are now linked to the visit and campaign they came from (they were previously recorded without attribution).
* Fix: the Forms screen now counts submit attempts (the Attempts column previously always showed 0), and lists Formidable Forms and Ninja Forms forms in its engagement report.
* Hardening: stricter escaping of admin output and prepared SQL identifiers throughout.
* Changed: first public release on WordPress.org. Earlier 0.x versions were distributed privately; their full history is in CHANGELOG.md, included with the plugin.

== Upgrade Notice ==

= 1.1.4 =
Middle-clicks on links now count as clicks and complete click goals, so click figures can rise. Lead and goal values can no longer be negative, and amounts round to the nearest cent. No migration.

= 1.1.3 =
Privacy fix: page URL parameters such as ad-click IDs and tokens are no longer stored or sent by default. Also fixes prerender, Back/Forward and abandonment counts, so reported figures can change. No migration.

= 1.1.2 =
Fixes form tracking for fields named method, action or id, form names on the Forms screen, funnel completions, and CUBOT phones being treated as bots. No settings or data change on upgrade.

= 1.1.1 =
Resolves two Plugin Check warnings in the export handlers. No settings, data or behavior change on upgrade.

= 1.1.0 =
Adds CSV and JSON exports and charts, and reorganizes the admin screens. No settings or data change on upgrade.

= 1.0.3 =
The Activity Log moves to the foot of the Webhooks screen; its old address redirects there. No settings or data change on upgrade.

= 1.0.2 =
Security hardening of admin requests and settings validation. No settings or data change on upgrade; custom header names that are not valid HTTP are reported the next time the page is saved.

= 1.0.1 =
Renames the plugin's stored settings, tables and scheduled events to the `cvmtry` prefix. Settings and data saved by 1.0.0 are not carried over.

= 1.0.0 =
First WordPress.org release. Adds privacy-policy text and personal-data export/erasure for form submissions, and makes the interface translatable. No settings or data change on upgrade.
