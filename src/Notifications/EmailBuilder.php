<?php
declare(strict_types=1);

namespace Convermetry\Notifications;

if (!defined('ABSPATH')) exit;

use Convermetry\Admin\SubmissionsPage;
use Convermetry\Analytics\SubmissionContext;
use Convermetry\Forms\SubmissionFields;
use Convermetry\Support\SensitiveKeys;

/**
 * Renders one internal notification email: subject line and HTML body.
 *
 * A dedicated presenter rather than markup inside the queue worker or the
 * admin page, because the same rendering has to be reachable from two places
 * (a real send and the synthetic test send) and because almost every rule
 * worth testing lives here: escaping, sensitive-field omission, the content
 * toggles, and the header-injection guard on the subject.
 *
 * NO OUTPUT BUFFERING. The admin pages build markup with ob_start(), which is
 * safe there, but this class concatenates instead so that it is structurally
 * incapable of emitting anything — phpunit.xml sets
 * beStrictAboutOutputDuringTests, and a builder that echoed would fail the
 * whole suite from a distance.
 *
 * Everything except {@see self::siteInfo()} is pure.
 */
final class EmailBuilder
{
    /** Longest rendered field value, in characters, before truncation. */
    private const int MAX_VALUE_LEN = 2000;

    /** Longest rendered body, in bytes. */
    private const int MAX_BODY_BYTES = 262144;

    /** RFC 5737 documentation address — never a real visitor. */
    private const string TEST_IP = '203.0.113.42';

    /**
     * The site-level values every message needs.
     *
     * The one impure method here: it is called once by the caller and passed
     * in, which is what keeps the rest of the class testable without WordPress.
     *
     * @return array{site_name: string, home_url: string, admin_url: string}
     */
    public static function siteInfo(): array
    {
        return [
            'site_name' => (string) get_bloginfo('name'),
            'home_url'  => (string) home_url('/'),
            'admin_url' => (string) admin_url('admin.php'),
        ];
    }

    /**
     * Renders the subject line from its template.
     *
     * Substitution is a strtr() over a fixed token map — never a regex built
     * from user input, and never anything that evaluates PHP.
     *
     * The order of the three cleanup steps is load-bearing and is the reason
     * this is not done at save time alone. Tokens are substituted FIRST, then
     * CR/LF/NUL are stripped, because the dangerous newline arrives inside a
     * substituted value: form names come from third-party form plugins and are
     * typed by whoever built the form, so a form named
     * "Contact\r\nBcc: attacker@example.test" is a reachable input, not a
     * hypothetical one.
     *
     * @param string               $template   Subject template with {tokens}.
     * @param array<string, mixed> $submission Submission row.
     * @param array<string, mixed> $context    Decoded, defaulted analytics context.
     * @param array<string, string> $siteInfo  {@see self::siteInfo()}.
     * @return string
     */
    public static function subject(string $template, array $submission, array $context, array $siteInfo): string
    {
        $attribution = is_array($context['attribution'] ?? null) ? $context['attribution'] : [];

        $subject = strtr($template, [
            '{site_name}'     => (string) ($siteInfo['site_name'] ?? ''),
            '{form_name}'     => (string) ($submission['form_name'] ?? ''),
            '{provider}'      => (string) ($submission['provider'] ?? ''),
            '{form_id}'       => (string) ($submission['form_id'] ?? ''),
            '{submission_id}' => (string) ($submission['submission_id'] ?? ''),
            '{channel}'       => (string) ($context['channel'] ?? ''),
            '{campaign}'      => (string) ($attribution['utm_campaign'] ?? ''),
            '{date}'          => self::localDate($submission, 'Y-m-d'),
        ]);

        $subject = (string) preg_replace('/[\r\n\x00]+/', ' ', $subject);
        $subject = trim((string) preg_replace('/\s+/', ' ', $subject));
        $subject = mb_substr($subject, 0, NotificationSettings::SUBJECT_MAX_LEN);

        return $subject !== '' ? $subject : 'New form submission';
    }

    /**
     * Renders the complete HTML body.
     *
     * @param array<string, mixed>  $submission Submission row.
     * @param array<string, mixed>  $context    Decoded, defaulted analytics context.
     * @param array<string, mixed>  $snapshot   Normalized settings snapshot.
     * @param array<string, string> $siteInfo   {@see self::siteInfo()}.
     * @return string
     */
    public static function body(array $submission, array $context, array $snapshot, array $siteInfo): string
    {
        $include = is_array($snapshot['include'] ?? null) ? $snapshot['include'] : [];

        $html = '<div style="font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;'
              . 'font-size:15px;line-height:1.5;color:#1d2327;max-width:640px;margin:0 auto;padding:24px;">';

        $html .= '<h1 style="font-size:20px;margin:0 0 4px;">'
               . esc_html(self::text($submission['form_name'] ?? '', 'Form submission'))
               . '</h1>';

        $html .= '<p style="margin:0 0 24px;color:#646970;font-size:13px;">'
               . esc_html(sprintf(
                   'New submission on %s · %s',
                   self::text($siteInfo['site_name'] ?? '', 'your site'),
                   self::localDate($submission, 'F j, Y \a\t g:i a')
               ))
               . '</p>';

        $html .= self::section('Submission', self::table([
            ['label' => 'Form',            'value' => self::text($submission['form_name'] ?? '')],
            ['label' => 'Provider',        'value' => self::text($submission['provider'] ?? '')],
            ['label' => 'Conversion page', 'value' => self::text($submission['page_url'] ?? '')],
            ['label' => 'Submission ID',   'value' => self::text($submission['submission_id'] ?? '')],
        ]));

        if (!empty($include['fields'])) {
            $fields = self::fields($submission);
            $html  .= self::section(
                'Submitted fields',
                $fields === []
                    ? self::note('This submission recorded no field values.')
                    : self::table($fields)
            );
        }

        if (!empty($include['analytics'])) {
            $rows = self::analyticsRows($context);

            // hasAnalytics() is computed BEFORE the IP row is appended. The IP
            // is resolved server-side, independently of the tracker, so a
            // server-to-server submission has an address and no attribution at
            // all — which is exactly when the explanation is needed.
            $explain = !self::hasAnalytics($rows);

            if (!empty($include['ip'])) {
                $ip = self::text($submission['ip_address'] ?? '');
                if ($ip !== '') {
                    $rows[] = ['label' => 'IP address', 'value' => $ip];
                }
            }

            $html .= self::section(
                'Analytics & attribution',
                $explain
                    ? self::note(
                        'Analytics context was unavailable for this submission — the visitor\'s '
                        . 'session could not be correlated. The lead itself was recorded normally.'
                    ) . ($rows !== [] ? self::table($rows) : '')
                    : self::table($rows)
            );
        }

        if (!empty($include['journey'])) {
            $pages = self::journeyItems($context);
            $html .= self::section(
                'Recent pages',
                $pages === []
                    ? self::note('No page history was recorded for this visit.')
                    : self::list($pages)
            );
        }

        $html .= '<p style="margin:24px 0 0;"><a href="' . esc_url(self::detailUrl($submission, $siteInfo)) . '"'
               . ' style="color:#2271b1;">View this submission in WordPress</a></p>';

        $html .= '<p style="margin:16px 0 0;color:#646970;font-size:12px;">'
               . esc_html(
                   'Sent by Convermetry. This email is a copy of lead data and is not covered by '
                   . 'Convermetry\'s retention or deletion controls.'
               )
               . '</p>';

        $html .= '</div>';

        return self::capBody($html);
    }

    /**
     * The submitted fields to show, as label/value rows.
     *
     * Fields whose id OR human label looks credential-bearing are OMITTED
     * ENTIRELY rather than shown as [REDACTED]. The Activity Log is an audit
     * trail and benefits from a placeholder; an email is a lead notification
     * sent outside the site's control, where a placeholder would only announce
     * to every recipient that a secret exists.
     *
     * @param array<string, mixed> $submission Submission row.
     * @return list<array{label: string, value: string}>
     */
    public static function fields(array $submission): array
    {
        $out = [];

        foreach (SubmissionFields::fromStoredJson((string) ($submission['submission_data'] ?? '')) as $field) {
            $id    = (string) ($field['id'] ?? '');
            $label = (string) ($field['label'] ?? '');

            if (SensitiveKeys::matches($id) || SensitiveKeys::matches($label)) {
                continue;
            }

            $out[] = [
                'label' => $label !== '' ? $label : $id,
                'value' => self::truncate(SubmissionFields::flatten($field['value'] ?? '')),
            ];
        }

        return $out;
    }

    /**
     * The attribution summary rows, in the order the admin detail panel uses.
     *
     * Blank values are dropped so the table shows what was actually captured
     * rather than a wall of empty cells.
     *
     * @param array<string, mixed> $context Decoded, defaulted analytics context.
     * @return list<array{label: string, value: string}>
     */
    public static function analyticsRows(array $context): array
    {
        $attribution = is_array($context['attribution'] ?? null) ? $context['attribution'] : [];
        $landing     = is_array($context['landing_page'] ?? null) ? $context['landing_page'] : [];
        $pageviews   = (int) ($context['pageview_count'] ?? 0);

        $candidates = [
            ['label' => 'Channel',       'value' => self::text($context['channel'] ?? '')],
            ['label' => 'UTM source',    'value' => self::text($attribution['utm_source'] ?? '')],
            ['label' => 'UTM medium',    'value' => self::text($attribution['utm_medium'] ?? '')],
            ['label' => 'UTM campaign',  'value' => self::text($attribution['utm_campaign'] ?? '')],
            ['label' => 'Landing page',  'value' => self::text($landing['url'] ?? '')],
            ['label' => 'Device',        'value' => self::text($context['device'] ?? '')],
            ['label' => 'Pages viewed',  'value' => $pageviews > 0 ? (string) $pageviews : ''],
            ['label' => 'Session start', 'value' => self::text($context['session_started_at'] ?? '')],
        ];

        return array_values(array_filter(
            $candidates,
            static fn(array $row): bool => $row['value'] !== ''
        ));
    }

    /**
     * Whether any real attribution was captured.
     *
     * @param list<array{label: string, value: string}> $rows Analytics rows, before the IP is appended.
     * @return bool
     */
    public static function hasAnalytics(array $rows): bool
    {
        return $rows !== [];
    }

    /**
     * The visitor's recent pages, oldest first.
     *
     * Reports::sessionSummary() stores them newest-first (ORDER BY id DESC),
     * but a journey reads forward.
     *
     * @param array<string, mixed> $context Decoded analytics context.
     * @return list<string>
     */
    public static function journeyItems(array $context): array
    {
        $pages = is_array($context['recent_pages'] ?? null) ? $context['recent_pages'] : [];

        $out = [];
        foreach (array_reverse(array_values($pages)) as $page) {
            if (is_scalar($page) && (string) $page !== '') {
                $out[] = (string) $page;
            }
        }

        return $out;
    }

    /**
     * A deep link to this submission in the Submissions admin page.
     *
     * The list is JavaScript-rendered and seeds its search box from
     * 'cvm_search' (see SubmissionsPage::enqueueAssets()), and
     * FormSubmissions::buildWhereClause() matches submission_id exactly, so
     * this lands on a one-row list rather than the full table.
     *
     * @param array<string, mixed>  $submission Submission row.
     * @param array<string, string> $siteInfo   {@see self::siteInfo()}.
     * @return string
     */
    public static function detailUrl(array $submission, array $siteInfo): string
    {
        return (string) add_query_arg(
            [
                'page'       => SubmissionsPage::MENU_SLUG,
                'cvm_search' => rawurlencode((string) ($submission['submission_id'] ?? '')),
            ],
            (string) ($siteInfo['admin_url'] ?? '')
        );
    }

    /**
     * A clearly-marked sample message built entirely from synthetic data.
     *
     * It never loads a submission, so a test send cannot expose a real lead —
     * not the most recent one, not any one. The synthetic context carries real
     * -looking analytics values so the toggles the admin just changed are
     * actually visible in what arrives.
     *
     * @param array<string, mixed>  $snapshot Normalized settings snapshot.
     * @param array<string, string> $siteInfo {@see self::siteInfo()}.
     * @return array{subject: string, html: string}
     */
    public static function testMessage(array $snapshot, array $siteInfo): array
    {
        $submission = [
            'submission_id'   => 'test-submission',
            'conversion_id'   => 'test-conversion',
            'provider'        => 'test',
            'form_name'       => 'Convermetry Test Form',
            'form_id'         => 'convermetry-test',
            'page_url'        => (string) ($siteInfo['home_url'] ?? '') . 'contact/',
            'ip_address'      => self::TEST_IP,
            'created_at'      => gmdate('Y-m-d H:i:s'),
            'submission_data' => (string) wp_json_encode([
                ['id' => 'name',    'label' => 'Full name',     'value' => 'Test Person'],
                ['id' => 'email',   'label' => 'Email address', 'value' => 'test@example.com'],
                ['id' => 'message', 'label' => 'Message',       'value' => 'This is a Convermetry test — not a real submission.'],
            ]),
        ];

        $context = SubmissionContext::withDefaults([
            'channel'            => 'Paid Search',
            'attribution'        => [
                'utm_source'   => 'google',
                'utm_medium'   => 'cpc',
                'utm_campaign' => 'convermetry-test',
            ],
            'landing_page'       => ['url' => (string) ($siteInfo['home_url'] ?? '')],
            'device'             => 'desktop',
            'pageview_count'     => 3,
            'session_started_at' => gmdate('c', time() - 600),
            'recent_pages'       => ['/contact/', '/services/', '/'],
        ]);

        $subject = self::subject((string) ($snapshot['subject'] ?? ''), $submission, $context, $siteInfo);

        return [
            'subject' => '[Test] ' . $subject,
            'html'    => self::body($submission, $context, $snapshot, $siteInfo),
        ];
    }

    // ── Rendering primitives ─────────────────────────────────────────────────

    /**
     * A two-column label/value table.
     *
     * @param list<array{label: string, value: string}> $rows Rows to render.
     * @return string
     */
    private static function table(array $rows): string
    {
        if ($rows === []) {
            return '';
        }

        $html = '<table role="presentation" cellpadding="0" cellspacing="0"'
              . ' style="width:100%;border-collapse:collapse;">';

        foreach ($rows as $row) {
            $html .= '<tr>'
                   . '<th scope="row" style="text-align:left;vertical-align:top;padding:6px 16px 6px 0;'
                   . 'width:38%;color:#646970;font-weight:600;">'
                   . esc_html($row['label'])
                   . '</th>'
                   . '<td style="vertical-align:top;padding:6px 0;word-break:break-word;">'
                   . esc_html($row['value'])
                   . '</td>'
                   . '</tr>';
        }

        return $html . '</table>';
    }

    /**
     * An ordered list of page URLs.
     *
     * @param list<string> $items Page URLs, oldest first.
     * @return string
     */
    private static function list(array $items): string
    {
        $html = '<ol style="margin:0;padding-left:20px;">';
        foreach ($items as $item) {
            $html .= '<li style="padding:2px 0;word-break:break-word;">' . esc_html($item) . '</li>';
        }

        return $html . '</ol>';
    }

    /**
     * A titled block.
     *
     * @param string $heading Section heading.
     * @param string $inner   Pre-escaped inner markup.
     * @return string
     */
    private static function section(string $heading, string $inner): string
    {
        return '<div style="margin:0 0 24px;">'
             . '<h2 style="font-size:13px;text-transform:uppercase;letter-spacing:.04em;'
             . 'color:#646970;margin:0 0 8px;border-bottom:1px solid #dcdcde;padding-bottom:6px;">'
             . esc_html($heading)
             . '</h2>'
             . $inner
             . '</div>';
    }

    /**
     * An explanatory sentence.
     *
     * @param string $text Message.
     * @return string
     */
    private static function note(string $text): string
    {
        return '<p style="margin:0 0 8px;color:#646970;">' . esc_html($text) . '</p>';
    }

    // ── Value helpers ────────────────────────────────────────────────────────

    /**
     * Coerces a row value to a trimmed string, with an optional fallback.
     *
     * @param mixed  $value    Raw value.
     * @param string $fallback Used when the value is blank.
     * @return string
     */
    private static function text(mixed $value, string $fallback = ''): string
    {
        $text = is_scalar($value) ? trim((string) $value) : '';

        return $text !== '' ? $text : $fallback;
    }

    /**
     * Caps one rendered value.
     *
     * Submitted values are not length-limited upstream, and one long message
     * body would otherwise be rendered once per recipient.
     *
     * @param string $value Rendered value.
     * @return string
     */
    private static function truncate(string $value): string
    {
        return mb_strlen($value) > self::MAX_VALUE_LEN
            ? mb_substr($value, 0, self::MAX_VALUE_LEN) . '…'
            : $value;
    }

    /**
     * Caps the whole message. Truncation is display-only — the stored
     * submission and the webhook payload are untouched.
     *
     * Public so {@see NotificationMailer::reconcile()} can reapply it to a body
     * returned by convermetry_notification_message, which has not been through
     * {@see body()} and is therefore unbounded until it has.
     *
     * @param string $html Rendered body.
     * @return string
     */
    public static function capBody(string $html): string
    {
        if (strlen($html) <= self::MAX_BODY_BYTES) {
            return $html;
        }

        return mb_strcut($html, 0, self::MAX_BODY_BYTES)
             . '</table></div><p>[This notification was truncated because it exceeded the size limit.]</p></div>';
    }

    /**
     * The submission's creation time rendered in the SITE's timezone.
     *
     * created_at is stored in UTC; an internal notification is read by people
     * who think in local time, so wp_date() converts rather than reporting a
     * timestamp that looks wrong to everyone in the office.
     *
     * @param array<string, mixed> $submission Submission row.
     * @param string               $format     PHP date format.
     * @return string
     */
    private static function localDate(array $submission, string $format): string
    {
        $createdAt = (string) ($submission['created_at'] ?? '');
        $timestamp = $createdAt !== '' ? (int) strtotime($createdAt . ' UTC') : time();

        return (string) wp_date($format, $timestamp);
    }
}
