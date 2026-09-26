<?php
declare(strict_types=1);

namespace Convermetry\Privacy;

if (!defined('ABSPATH')) exit;

use Convermetry\Analytics\SubmissionContext;
use Convermetry\Database\DatabaseManager;
use Convermetry\Database\FormSubmissions;
use Convermetry\Forms\SubmissionFields;
use Convermetry\Leads\LeadEvents;
use Convermetry\Leads\LeadStatus;
use Convermetry\Plugin;
use Convermetry\Support\SensitiveKeys;
use Convermetry\Webhook\DeliveryLog;
use Convermetry\Webhook\FormDeliveryQueue;

/**
 * WordPress personal-data exporter (Tools → Export Personal Data).
 *
 * For an email address, reports every Convermetry submission whose submitted
 * values contain that address (see {@see PersonalDataMatcher}), and the data
 * linked to each one:
 *
 *  - the submission itself: form, page, submitted values, IP address, and the
 *    analytics context captured with it (channel, campaign, landing page);
 *  - its lead status and value, and their change history;
 *  - where it was delivered: each webhook attempt's destination HOST, result
 *    and time, plus deliveries still queued. Hosts only — a full endpoint URL
 *    often embeds a secret token, and this report goes to the data subject;
 *  - the website activity of the analytics session it was submitted in.
 *
 * Read-only. Pages by OFFSET over a stable, id-ordered candidate set; nothing
 * here deletes, so the offsets never shift under it.
 */
final class PersonalDataExporter
{
    /** Candidate submissions examined per exporter page. */
    public const int SUBMISSIONS_PER_PAGE = 5;

    /** Most session events reported per submission. */
    public const int EVENTS_PER_SESSION = 200;

    /** Most delivery attempts reported per submission. */
    private const int DELIVERIES_PER_SUBMISSION = 100;

    /**
     * Exporter callback.
     *
     * @param string $email Address from the privacy request.
     * @param int    $page  1-based page, advanced by WordPress until done.
     * @return array{data: list<array<string, mixed>>, done: bool}
     */
    public static function export(string $email, int $page = 1): array
    {
        $email = PersonalDataMatcher::normalizeEmail($email);

        if ($email === '') {
            return ['data' => [], 'done' => true];
        }

        $page       = max(1, $page);
        $candidates = FormSubmissions::privacyCandidates(
            PersonalDataMatcher::likePatterns($email),
            0,
            ($page - 1) * self::SUBMISSIONS_PER_PAGE,
            self::SUBMISSIONS_PER_PAGE
        );

        $data = [];

        foreach ($candidates as $row) {
            if (!PersonalDataMatcher::storedDataMatches((string) ($row['submission_data'] ?? ''), $email)) {
                continue;
            }

            foreach (self::itemsFor($row) as $item) {
                $data[] = $item;
            }
        }

        return [
            'data' => $data,
            'done' => count($candidates) < self::SUBMISSIONS_PER_PAGE,
        ];
    }

    /**
     * Every export item for one matched submission row.
     *
     * @param array<string, mixed> $row A cvm_form_submissions row.
     * @return list<array<string, mixed>>
     */
    public static function itemsFor(array $row): array
    {
        $submissionId = (string) ($row['submission_id'] ?? '');
        $items        = [self::submissionItem($row)];

        foreach (LeadEvents::forSubmission($submissionId, 100) as $index => $event) {
            $items[] = [
                'group_id'          => 'convermetry-lead-history',
                'group_label'       => __('Lead status history', 'convermetry'),
                'group_description' => __('Changes the site made to the status and value of your form submissions.', 'convermetry'),
                'item_id'           => 'convermetry-lead-event-' . (string) ($event['lead_event_id'] ?? $submissionId . '-' . $index),
                'data'              => self::pairs([
                    [__('Submission ID', 'convermetry'), $submissionId],
                    [__('Date', 'convermetry'), self::isoDate((string) ($event['created_at'] ?? ''))],
                    [__('Previous status', 'convermetry'), LeadStatus::label((string) ($event['from_status'] ?? ''))],
                    [__('New status', 'convermetry'), LeadStatus::label((string) ($event['to_status'] ?? ''))],
                    [__('Value', 'convermetry'), self::money($event['value'] ?? null, (string) ($event['currency'] ?? ''))],
                ]),
            ];
        }

        foreach (DeliveryLog::forSubmission($submissionId, self::DELIVERIES_PER_SUBMISSION) as $attempt) {
            $items[] = [
                'group_id'          => 'convermetry-deliveries',
                'group_label'       => __('Form submission deliveries', 'convermetry'),
                'group_description' => __('Services this site sent your form submissions to.', 'convermetry'),
                'item_id'           => 'convermetry-delivery-' . (string) ($attempt['id'] ?? ''),
                'data'              => self::pairs([
                    [__('Submission ID', 'convermetry'), $submissionId],
                    [__('Date', 'convermetry'), self::isoDate((string) ($attempt['created_at'] ?? ''))],
                    [__('Destination', 'convermetry'), self::host((string) ($attempt['endpoint_url'] ?? ''))],
                    [__('Result', 'convermetry'), (int) ($attempt['success'] ?? 0) === 1
                        ? __('Delivered', 'convermetry')
                        : __('Not delivered', 'convermetry')],
                ]),
            ];
        }

        foreach (FormDeliveryQueue::rowsForSubmission($submissionId) as $index => $queued) {
            $items[] = [
                'group_id'          => 'convermetry-deliveries',
                'group_label'       => __('Form submission deliveries', 'convermetry'),
                'group_description' => __('Services this site sent your form submissions to.', 'convermetry'),
                'item_id'           => 'convermetry-queued-' . $submissionId . '-' . $index,
                'data'              => self::pairs([
                    [__('Submission ID', 'convermetry'), $submissionId],
                    [__('Date', 'convermetry'), self::isoDate((string) ($queued['created_at'] ?? ''))],
                    [__('Destination', 'convermetry'), self::host((string) ($queued['endpoint_url'] ?? ''))],
                    [__('Result', 'convermetry'), __('Waiting to be sent', 'convermetry')],
                ]),
            ];
        }

        $sessionId = (string) ($row['session_id'] ?? '');

        foreach (DatabaseManager::eventsForSession($sessionId, self::EVENTS_PER_SESSION) as $index => $event) {
            $items[] = [
                'group_id'          => 'convermetry-activity',
                'group_label'       => __('Website activity', 'convermetry'),
                'group_description' => __('Pages and interactions recorded during the visit in which you submitted a form.', 'convermetry'),
                'item_id'           => 'convermetry-activity-' . $submissionId . '-' . $index,
                'data'              => self::pairs([
                    [__('Date', 'convermetry'), self::isoDate((string) ($event['created_at'] ?? ''))],
                    [__('Activity', 'convermetry'), self::eventLabel((string) ($event['event_type'] ?? ''))],
                    [__('Page', 'convermetry'), (string) ($event['page_url'] ?? '')],
                    [__('Page title', 'convermetry'), (string) ($event['page_title'] ?? '')],
                    [__('Element', 'convermetry'), (string) ($event['element_label'] ?? '')],
                    [__('Link target', 'convermetry'), (string) ($event['target_url'] ?? '')],
                    [__('Referrer', 'convermetry'), (string) ($event['referrer'] ?? '')],
                    [__('Device', 'convermetry'), (string) ($event['device'] ?? '')],
                    [__('Traffic channel', 'convermetry'), (string) ($event['channel'] ?? '')],
                    [__('Campaign source', 'convermetry'), (string) ($event['utm_source'] ?? '')],
                    [__('Campaign medium', 'convermetry'), (string) ($event['utm_medium'] ?? '')],
                    [__('Campaign name', 'convermetry'), (string) ($event['utm_campaign'] ?? '')],
                    [__('IP address', 'convermetry'), (string) ($event['ip_address'] ?? '')],
                ]),
            ];
        }

        return $items;
    }

    /**
     * The submission item: what was submitted, where, and with what context.
     *
     * @param array<string, mixed> $row A cvm_form_submissions row.
     * @return array<string, mixed>
     */
    private static function submissionItem(array $row): array
    {
        $submissionId = (string) ($row['submission_id'] ?? '');
        $context      = SubmissionContext::of($row);
        $attribution  = is_array($context['attribution'] ?? null) ? $context['attribution'] : [];
        $landing      = is_array($context['landing_page'] ?? null) ? $context['landing_page'] : [];

        $data = self::pairs([
            [__('Submission ID', 'convermetry'), $submissionId],
            [__('Submitted', 'convermetry'), self::isoDate((string) ($row['created_at'] ?? ''))],
            [__('Form', 'convermetry'), (string) ($row['form_name'] ?? '')],
            [__('Form plugin', 'convermetry'), self::providerLabel((string) ($row['provider'] ?? ''))],
            [__('Page', 'convermetry'), (string) ($row['page_url'] ?? '')],
            [__('IP address', 'convermetry'), (string) ($row['ip_address'] ?? '')],
        ]);

        foreach (SubmissionFields::fromStoredJson((string) ($row['submission_data'] ?? ''))->all() as $field) {
            $sensitive = SensitiveKeys::matches($field->id) || SensitiveKeys::matches($field->label);

            $data[] = [
                'name'  => $field->label,
                'value' => $sensitive
                    ? __('[Withheld: this field looks like a password or other credential]', 'convermetry')
                    : $field->displayValue(),
            ];
        }

        $data = array_merge($data, self::pairs([
            [__('Traffic channel', 'convermetry'), (string) ($context['channel'] ?? '')],
            [__('Campaign source', 'convermetry'), (string) ($attribution['utm_source'] ?? '')],
            [__('Campaign medium', 'convermetry'), (string) ($attribution['utm_medium'] ?? '')],
            [__('Campaign name', 'convermetry'), (string) ($attribution['utm_campaign'] ?? '')],
            [__('Campaign term', 'convermetry'), (string) ($attribution['utm_term'] ?? '')],
            [__('Campaign content', 'convermetry'), (string) ($attribution['utm_content'] ?? '')],
            [__('Entrance referrer', 'convermetry'), (string) ($context['entrance_referrer'] ?? '')],
            [__('Landing page', 'convermetry'), (string) ($landing['url'] ?? '')],
            [__('Device', 'convermetry'), (string) ($context['device'] ?? '')],
            [__('Lead status', 'convermetry'), LeadStatus::label((string) ($row['lead_status'] ?? ''))],
            [__('Lead value', 'convermetry'), self::money($row['lead_value'] ?? null, (string) ($row['lead_currency'] ?? ''))],
        ]));

        return [
            'group_id'          => 'convermetry-submissions',
            'group_label'       => __('Form submissions', 'convermetry'),
            'group_description' => __('Form submissions recorded by Convermetry, with the visit details captured when you submitted them.', 'convermetry'),
            'item_id'           => 'convermetry-submission-' . $submissionId,
            'data'              => $data,
        ];
    }

    /**
     * Name/value pairs for an export item, dropping empty values so the report
     * lists what is stored rather than every column that could be.
     *
     * A list of pairs rather than a label-keyed map: two labels can translate
     * to the same word, and a map would silently drop one of them.
     *
     * @param list<array{0: string, 1: string}> $pairs [label, value] pairs.
     * @return list<array{name: string, value: string}>
     */
    private static function pairs(array $pairs): array
    {
        $out = [];

        foreach ($pairs as [$name, $value]) {
            if ($value !== '') {
                $out[] = ['name' => $name, 'value' => $value];
            }
        }

        return $out;
    }

    /**
     * A stored UTC datetime as ISO 8601, or '' when empty or unparseable.
     *
     * @param string $datetime 'Y-m-d H:i:s' in UTC.
     * @return string
     */
    private static function isoDate(string $datetime): string
    {
        $time = $datetime !== '' ? strtotime($datetime . ' UTC') : false;

        return $time === false ? '' : gmdate('c', $time);
    }

    /**
     * The host of an endpoint URL. Never the path or query: those regularly
     * carry a secret token, and this value is handed to the data subject.
     *
     * @param string $url Endpoint URL.
     * @return string
     */
    private static function host(string $url): string
    {
        $host = wp_parse_url($url, PHP_URL_HOST);

        return is_string($host) ? $host : '';
    }

    /**
     * A decimal value with its currency, or '' when no value was recorded.
     *
     * @param mixed  $value    DECIMAL column value or null.
     * @param string $currency ISO currency code.
     * @return string
     */
    private static function money(mixed $value, string $currency): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return trim((string) $value . ' ' . $currency);
    }

    /**
     * The registered provider's label, falling back to the stored key.
     *
     * @param string $key Provider key.
     * @return string
     */
    private static function providerLabel(string $key): string
    {
        $provider = $key !== '' ? Plugin::getInstance()->getFormRegistry()->get($key) : null;

        return $provider !== null ? $provider->getLabel() : $key;
    }

    /**
     * A readable name for an analytics event type.
     *
     * @param string $type Stored event_type.
     * @return string
     */
    private static function eventLabel(string $type): string
    {
        return match ($type) {
            'pageview'     => __('Page view', 'convermetry'),
            'click'        => __('Click', 'convermetry'),
            'hover'        => __('Hover', 'convermetry'),
            'scroll_depth' => __('Scrolled the page', 'convermetry'),
            'form_view'    => __('Form viewed', 'convermetry'),
            'form_start'   => __('Form started', 'convermetry'),
            'form_error'   => __('Form validation error', 'convermetry'),
            'form_submit'  => __('Form submit attempt', 'convermetry'),
            'form_success' => __('Form submitted', 'convermetry'),
            'custom_event' => __('Custom event', 'convermetry'),
            default        => $type,
        };
    }
}
