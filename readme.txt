=== Convermetry ===
Contributors: cpaschall1981
Tags: analytics, lead tracking, utm, webhooks, forms
Requires at least: 6.3
Tested up to: 7.1
Requires PHP: 8.3
Stable tag: 1.0.1
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
* An analytics dashboard with top pages, landing pages, referrers, campaigns, channels, devices, conversions and lead outcomes.
* Goals for actions that are not form submissions (phone and email link clicks, downloads, reaching a page, custom events) and funnels that measure the ordered path to a conversion.
* Form engagement and abandonment reporting that never records what a visitor typed into a form they did not submit.

= Leads =

* A Submissions screen with each lead's answers, the page it came from, its campaign attribution and its webhook delivery status.
* Lead status (new, qualified, won, lost…) and value, with history, and lead reports by channel, campaign, landing page and form.
* CSV export, per-submission delete, and a retention window after which data is deleted automatically.

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
* For each confirmed form submission: the values the visitor submitted, the page and its query parameters, and the analytics context of the visit (channel, campaign, landing page, pages viewed). Fields that look like passwords or other credentials are withheld from email notifications and redacted in the Activity Log.
* Logged-in users are excluded from tracking by default.
* **Do Not Track / Global Privacy Control are not honored by default.** When you enable that setting, visitors who send either signal are not tracked, and no IP address is stored with a form they submit.

**Browser storage — no cookies.** The tracker sets no cookies. It stores a random visit identifier (`cvmtry_session`) and the visit's attribution (`cvmtry_campaign`) in the browser's localStorage, and briefly holds unsent events (`cvmtry_pending`) in sessionStorage. The visit identifier is replaced after 30 minutes of inactivity. In the EU and UK, the rules that govern cookies also apply to this kind of storage.

**Consent.** Convermetry has no consent banner of its own and is not integrated with a consent-management plugin. Analytics starts collecting as soon as the plugin is activated. If your site needs consent before analytics runs, have your consent tool block the script handle `cvmtry-tracker` until consent is given, or return `false` from the `convermetry_should_enqueue_tracker` filter. Form submissions are still recorded server-side when the tracker does not run.

**Where it is stored and for how long.** In seven custom tables in your WordPress database. Analytics events, submissions, goal completions, lead history and Activity Log entries are deleted automatically after the retention period (default 90 days, adjustable from 7 to 365). Deleting the plugin from the Plugins screen removes every table, option and scheduled task it created.

**WordPress privacy tools.** Convermetry adds suggested text to Settings → Privacy → Policy Guide, generated from your current settings. It also adds an exporter and an eraser to Tools → Export Personal Data and Tools → Erase Personal Data. For an email address, they find the form submissions whose submitted values contain that exact address. The eraser deletes those submissions with their lead history and queued deliveries and notifications. It removes the lead from the Activity Log's stored payloads, and removes the visitor's IP address from the analytics of that visit. Analytics recorded during visits in which no form was submitted is not linked to an email address, so it can only age out through the retention period.

= External services =

Convermetry does **not** contact any server by default, and it never sends data to the plugin's author. All fonts, scripts and styles are bundled with the plugin. Information leaves your site only through features a site administrator configures:

* **Webhook endpoints.** When you add an endpoint under Convermetry → Webhooks, Convermetry sends it HTTPS POST requests to the URL you entered. Form submission messages contain the submitted form values, the submitter's IP address (when IP storage is on), the page and its query parameters, and the visit's analytics context. Analytics report messages contain aggregated statistics plus a list of individual conversions, each with its IP address (when stored) and visit identifier. You choose which endpoints receive which message type. The receiving service is chosen by you, so its terms of use and privacy policy are the ones you agreed to with that service.
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
6. The Activity Log: every delivery attempt with its payload and response, including retries.
7. Goals with their completions, conversion rates and value.
8. A funnel showing how many visits reached each step on the way to a quote request.
9. Tracking and privacy settings: interaction types, logged-in users, Do Not Track / Global Privacy Control, IP storage and data retention.

== Changelog ==

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

= 1.0.1 =
Renames the plugin's stored settings, tables and scheduled events to the `cvmtry` prefix. Settings and data saved by 1.0.0 are not carried over.

= 1.0.0 =
First WordPress.org release. Adds privacy-policy text and personal-data export/erasure for form submissions, and makes the interface translatable. No settings or data change on upgrade.
