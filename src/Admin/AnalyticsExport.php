<?php
declare(strict_types=1);

namespace Convermetry\Admin;

if (!defined('ABSPATH')) exit;

use Convermetry\Admin\Pages\AnalyticsPage;
use Convermetry\Analytics\GoalReports;
use Convermetry\Analytics\LeadReports;
use Convermetry\Analytics\ReportQueryException;
use Convermetry\Analytics\Reports;
use Convermetry\Goals\GoalRepository;

/**
 * The Analytics screen's reports as data, for its CSV and JSON exports.
 *
 * Every section runs the same query, with the same row limit and the same
 * window, as the panel that shows it on screen, so a file never holds a
 * number the dashboard would not. Values are raw — counts as integers, rates
 * as the percentage figures the tables print, dates in UTC — rather than the
 * screen's formatted strings, because a file is for a spreadsheet or a
 * script, not for reading.
 *
 * A section whose query fails is reported as failed (CSV: the dashboard's own
 * error sentence; JSON: null, and its key listed under "failed"), never as an
 * empty or zero result.
 *
 * Third-party analytics sections are not included: what they return from
 * summarize() is theirs to shape and has no column contract to write a file
 * against.
 */
final class AnalyticsExport
{
    /**
     * Every section, in dashboard order.
     *
     * @param string $start UTC window start (inclusive), 'Y-m-d H:i:s'.
     * @param string $end   UTC window end (exclusive), 'Y-m-d H:i:s'.
     * @return list<array{key: string, title: string, note: string, columns: array<string, string>, rows: array<int, array<string, mixed>>|null}>
     */
    public static function sections(string $start, string $end): array
    {
        $sections = [];

        $sections[] = self::section('summary', __('Overview', 'convermetry'), '', [
            'page_views'                   => __('Page Views', 'convermetry'),
            'clicks'                       => __('Clicks', 'convermetry'),
            'hovers'                       => __('Hovers', 'convermetry'),
            'scroll_milestones'            => __('Scroll Milestones', 'convermetry'),
            'form_submit_attempts'         => __('Form Submit Attempts', 'convermetry'),
            'confirmed_conversions'        => __('Confirmed Conversions', 'convermetry'),
            'server_confirmed_submissions' => __('Server-Confirmed Submissions', 'convermetry'),
            'other_events'                 => __('Other Events', 'convermetry'),
        ], static function () use ($start, $end): array {
            // The overview cards' own arithmetic: conversions deduplicated by
            // conversion id, everything not carded summed into Other Events.
            $totals = Reports::totalsByType($start, $end);
            $carded = ['pageview', 'click', 'hover', 'scroll_depth', 'form_submit', 'form_success'];

            return [[
                'page_views'                   => (int) ($totals['pageview'] ?? 0),
                'clicks'                       => (int) ($totals['click'] ?? 0),
                'hovers'                       => (int) ($totals['hover'] ?? 0),
                'scroll_milestones'            => (int) ($totals['scroll_depth'] ?? 0),
                'form_submit_attempts'         => (int) ($totals['form_submit'] ?? 0),
                'confirmed_conversions'        => Reports::conversionCount($start, $end),
                'server_confirmed_submissions' => Reports::serverSubmissionCount($start, $end),
                'other_events'                 => (int) array_sum(array_diff_key($totals, array_flip($carded))),
            ]];
        });

        $sections[] = self::section('daily_page_views', __('Daily Page Views', 'convermetry'), '', [
            'date'       => __('Date', 'convermetry'),
            'page_views' => __('Page Views', 'convermetry'),
        ], static fn(): array => array_map(static fn(array $point): array => [
            'date'       => (string) $point['date'],
            'page_views' => (int) $point['count'],
        ], Reports::dailyCounts($start, $end, 'pageview')));

        $sections[] = self::section('devices', __('Devices', 'convermetry'), '', [
            'device'     => __('Device', 'convermetry'),
            'page_views' => __('Page Views', 'convermetry'),
            'share'      => __('Share', 'convermetry'),
        ], static function () use ($start, $end): array {
            $devices = Reports::deviceBreakdown($start, $end);
            $total   = array_sum($devices);
            $rows    = [];

            foreach ($devices as $device => $views) {
                $rows[] = [
                    'device'     => (string) $device,
                    'page_views' => (int) $views,
                    'share'      => $total > 0 ? (int) round($views / $total * 100) : 0,
                ];
            }

            return $rows;
        });

        $sections[] = self::section('top_pages', __('Top Pages', 'convermetry'), '', [
            'page_url'   => __('Page', 'convermetry'),
            'page_title' => __('Page title', 'convermetry'),
            'views'      => __('Views', 'convermetry'),
            'sessions'   => __('Sessions', 'convermetry'),
        ], static fn(): array => self::pick(Reports::topPages($start, $end), ['page_url', 'page_title', 'views', 'sessions']));

        $sections[] = self::section('landing_pages', __('Top Landing Pages', 'convermetry'), '', [
            'page_url'   => __('Landing Page', 'convermetry'),
            'page_title' => __('Page title', 'convermetry'),
            'sessions'   => __('Sessions', 'convermetry'),
        ], static fn(): array => self::pick(Reports::topLandingPages($start, $end), ['page_url', 'page_title', 'sessions']));

        $sections[] = self::section('top_clicks', __('Top Clicked Elements', 'convermetry'), '', [
            'element'     => __('Element', 'convermetry'),
            'destination' => __('Destination', 'convermetry'),
            'clicks'      => __('Clicks', 'convermetry'),
        ], static fn(): array => array_map(static fn(array $row): array => [
            'element'     => (string) $row['element_label'] !== '' ? (string) $row['element_label'] : sprintf(
                /* translators: %s: HTML tag name of the clicked element, such as "a" or "button". */
                __('(unlabeled %s)', 'convermetry'),
                (string) $row['element_tag']
            ),
            'destination' => (string) $row['target_url'],
            'clicks'      => (int) $row['clicks'],
        ], Reports::topClicks($start, $end)));

        $sections[] = self::section('form_submit_attempts', __('Top Form Submit Attempts', 'convermetry'), '', [
            'form'     => __('Form', 'convermetry'),
            'page_url' => __('Page', 'convermetry'),
            'attempts' => __('Attempts', 'convermetry'),
        ], static fn(): array => array_map(static fn(array $row): array => [
            'form'     => (string) $row['element_label'] !== '' ? (string) $row['element_label'] : __('(unnamed form)', 'convermetry'),
            'page_url' => (string) $row['page_url'],
            'attempts' => (int) $row['submissions'],
        ], Reports::topForms($start, $end)));

        $sections[] = self::section('top_hovers', __('Most Hovered Elements', 'convermetry'), '', [
            'element' => __('Element', 'convermetry'),
            'type'    => __('Type', 'convermetry'),
            'hovers'  => __('Hovers', 'convermetry'),
        ], static fn(): array => array_map(static fn(array $row): array => [
            'element' => (string) $row['element_label'] !== '' ? (string) $row['element_label'] : __('(unlabeled element)', 'convermetry'),
            'type'    => (string) $row['element_tag'],
            'hovers'  => (int) $row['hovers'],
        ], Reports::topHovers($start, $end)));

        $sections[] = self::section('referrers', __('Top Referrers', 'convermetry'), '', [
            'referrer'  => __('Referring Page', 'convermetry'),
            'pageviews' => __('Pageviews', 'convermetry'),
        ], static fn(): array => array_map(static fn(array $row): array => [
            'referrer'  => (string) $row['referrer'],
            'pageviews' => (int) $row['visits'],
        ], Reports::topReferrers($start, $end)));

        $sections[] = self::section('channels', __('Channels', 'convermetry'), '', [
            'channel'         => __('Channel', 'convermetry'),
            'sessions'        => __('Sessions', 'convermetry'),
            'views'           => __('Views', 'convermetry'),
            'conversions'     => __('Conversions', 'convermetry'),
            'conversion_rate' => __('Conv. Rate', 'convermetry'),
        ], static fn(): array => self::pick(Reports::channelBreakdown($start, $end), ['channel', 'sessions', 'views', 'conversions', 'conversion_rate']));

        $campaignColumns = [
            'utm_source'   => __('Source', 'convermetry'),
            'utm_medium'   => __('Medium', 'convermetry'),
            'utm_campaign' => __('Campaign', 'convermetry'),
            'utm_id'       => __('ID', 'convermetry'),
        ];

        $sections[] = self::section('campaigns', __('Campaigns', 'convermetry'), '', $campaignColumns + [
            'sessions'        => __('Sessions', 'convermetry'),
            'views'           => __('Views', 'convermetry'),
            'conversions'     => __('Conversions', 'convermetry'),
            'conversion_rate' => __('Conv. Rate', 'convermetry'),
        ], static fn(): array => self::pick(Reports::topCampaigns($start, $end), ['utm_source', 'utm_medium', 'utm_campaign', 'utm_id', 'sessions', 'views', 'conversions', 'conversion_rate']));

        $sections[] = self::section('campaign_terms_content', __('Campaign Terms & Content', 'convermetry'), '', $campaignColumns + [
            'utm_term'    => __('Term', 'convermetry'),
            'utm_content' => __('Content', 'convermetry'),
            'sessions'    => __('Sessions', 'convermetry'),
            'views'       => __('Views', 'convermetry'),
            'conversions' => __('Conversions', 'convermetry'),
        ], static fn(): array => self::pick(Reports::topCampaignContent($start, $end), ['utm_source', 'utm_medium', 'utm_campaign', 'utm_id', 'utm_term', 'utm_content', 'sessions', 'views', 'conversions']));

        $sections[] = self::section('goals', __('Goal Completions', 'convermetry'), '', [
            'goal'        => __('Goal', 'convermetry'),
            'completions' => __('Completions', 'convermetry'),
            'sessions'    => __('Sessions', 'convermetry'),
            'rate'        => __('Rate', 'convermetry'),
            'value'       => __('Value', 'convermetry'),
            'currency'    => __('Currency', 'convermetry'),
        ], static fn(): array => array_map(static fn(array $goal): array => [
            'goal'        => (string) $goal['name'],
            'completions' => (int) $goal['completions'],
            'sessions'    => (int) $goal['sessions'],
            'rate'        => (int) $goal['sessions'] > 0 ? $goal['conversion_rate'] : '',
            'value'       => (string) $goal['value'] === '0.00' ? '' : (string) $goal['value'],
            'currency'    => (string) $goal['value'] === '0.00' ? '' : (string) $goal['currency'],
        ], GoalReports::summary($start, $end, GoalRepository::names(), 25)['goals']));

        foreach ([
            'channel'      => [__('Leads by Channel', 'convermetry'), __('Channel', 'convermetry')],
            'campaign'     => [__('Leads by Campaign', 'convermetry'), __('Campaign', 'convermetry')],
            'landing_page' => [__('Landing Page Performance', 'convermetry'), __('Landing Page', 'convermetry')],
            'form'         => [__('Leads by Form', 'convermetry'), __('Form', 'convermetry')],
        ] as $dimension => [$title, $label]) {
            $sections[] = self::section('leads_by_' . $dimension, $title, '', [
                'label'                 => $label,
                'leads'                 => __('Leads', 'convermetry'),
                'qualified'             => __('Qualified', 'convermetry'),
                'won'                   => __('Won', 'convermetry'),
                'qualified_rate'        => __('Qual. Rate', 'convermetry'),
                'attributed_lead_value' => __('Attributed Lead Value', 'convermetry'),
            ], static fn(): array => array_map(static fn(array $row): array => [
                'label'                 => (string) $row['label'],
                'leads'                 => (int) $row['leads'],
                'qualified'             => (int) $row['qualified'],
                'won'                   => (int) $row['won'],
                'qualified_rate'        => (int) $row['leads'] > 0 ? $row['qualified_rate'] : '',
                // Currencies are listed, never added together.
                'attributed_lead_value' => self::money((array) $row['value']),
            ], LeadReports::byDimension($start, $end, $dimension, 10)));
        }

        $sections[] = self::section('time_to_lead', __('Time to Lead', 'convermetry'), '', [
            'time_to_convert' => __('Time to convert', 'convermetry'),
            'leads'           => __('Leads', 'convermetry'),
            'share'           => __('Share', 'convermetry'),
        ], static function () use ($start, $end): array {
            $lag  = LeadReports::timeToLead($start, $end);
            $rows = [];

            foreach ($lag['buckets'] as $label => $count) {
                $rows[] = [
                    'time_to_convert' => LeadReports::bucketLabel((string) $label),
                    'leads'           => (int) $count,
                    'share'           => $lag['sampled'] > 0 ? round($count / $lag['sampled'] * 100, 1) : '',
                ];
            }

            // As on screen: each channel's median duration, in the Leads column.
            foreach ($lag['medians'] as $channel => $seconds) {
                $rows[] = [
                    /* translators: %s: marketing channel name. */
                    'time_to_convert' => sprintf(__('%s — median', 'convermetry'), (string) $channel),
                    'leads'           => AnalyticsPage::humanDuration((int) $seconds),
                    'share'           => '',
                ];
            }

            return $rows;
        });

        $sections[] = self::section('recent_conversions', __('Recent Conversions', 'convermetry'), '', [
            'occurred_at'   => __('When (UTC)', 'convermetry'),
            'form'          => __('Form', 'convermetry'),
            'source'        => __('Source', 'convermetry'),
            'channel'       => __('Channel', 'convermetry'),
            'campaign'      => __('Campaign', 'convermetry'),
            'page_url'      => __('Page', 'convermetry'),
            'ip_address'    => __('IP', 'convermetry'),
            'conversion_id' => __('Conversion ID', 'convermetry'),
        ], static fn(): array => array_map(static function (array $row): array {
            $attribution = (array) ($row['attribution'] ?? []);

            return [
                'occurred_at'   => (string) ($row['occurred_at'] ?? ''),
                'form'          => (string) ($row['form'] ?? ''),
                'source'        => !empty($row['server_confirmed']) ? (string) ($row['provider'] ?? '') : __('Frontend', 'convermetry'),
                'channel'       => (string) ($attribution['channel'] ?? ''),
                'campaign'      => trim(implode(' / ', array_filter([
                    (string) ($attribution['utm_source'] ?? ''),
                    (string) ($attribution['utm_medium'] ?? ''),
                    (string) ($attribution['utm_campaign'] ?? ''),
                ]))),
                'page_url'      => (string) ($row['page_url'] ?? ''),
                'ip_address'    => (string) ($row['ip_address'] ?? ''),
                'conversion_id' => (string) ($row['conversion_id'] ?? ''),
            ];
        }, Reports::recentConversions($start, $end, 15)));

        // Like the panel, this one ignores the period: the latest 15 events.
        $sections[] = self::section('latest_events', __('Latest Events', 'convermetry'), __('The latest 15 events, independent of the selected reporting period.', 'convermetry'), [
            'created_at' => __('When (UTC)', 'convermetry'),
            'event'      => __('Event', 'convermetry'),
            'page_url'   => __('Page', 'convermetry'),
            'page_title' => __('Page title', 'convermetry'),
            'detail'     => __('Detail', 'convermetry'),
            'device'     => __('Device', 'convermetry'),
        ], static fn(): array => array_map(static function (array $row): array {
            $detail = (string) $row['element_label'] !== '' ? (string) $row['element_label'] : (string) $row['target_url'];
            if ((string) ($row['event_value'] ?? '') !== '') {
                $detail = trim($detail . ' (' . $row['event_value'] . ')');
            }

            return [
                'created_at' => (string) $row['created_at'],
                'event'      => (string) $row['event_type'],
                'page_url'   => (string) $row['page_url'],
                'page_title' => (string) $row['page_title'],
                'detail'     => $detail,
                'device'     => (string) $row['device'],
            ];
        }, Reports::recentEvents(15)));

        return $sections;
    }

    /**
     * Streams the sections as one CSV file: a title line, then each section
     * as its own titled table separated by a blank row.
     *
     * @param string                              $filename Download file name.
     * @param string                              $heading  First line: what the file is and what it covers.
     * @param list<array<string, mixed>>          $sections From {@see sections()}.
     * @return never
     */
    public static function csv(string $filename, string $heading, array $sections): never
    {
        $output = Export::startCsv($filename);
        if ($output === null) {
            exit;
        }

        Export::csvRow($output, [$heading]);

        foreach ($sections as $section) {
            Export::csvRow($output, []);
            Export::csvRow($output, [(string) $section['title']]);

            if ($section['note'] !== '') {
                Export::csvRow($output, [(string) $section['note']]);
            }

            if ($section['rows'] === null) {
                Export::csvRow($output, [__('This section could not be loaded due to a database error. Your data is safe — try refreshing shortly, or check your site\'s PHP error log if this continues.', 'convermetry')]);
                continue;
            }

            Export::csvRow($output, array_values($section['columns']));

            foreach ($section['rows'] as $row) {
                $cells = [];
                foreach (array_keys($section['columns']) as $key) {
                    $cells[] = $row[$key] ?? '';
                }
                Export::csvRow($output, $cells);
            }
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closes the php://output stream; no file is involved.
        fclose($output);
        exit;
    }

    /**
     * The sections as one JSON document: section key => list of rows (the
     * overview as a single object), with failed sections null and listed.
     *
     * @param list<array<string, mixed>> $sections From {@see sections()}.
     * @param array<string, mixed>       $meta     Report metadata.
     * @return array<string, mixed>
     */
    public static function document(array $sections, array $meta): array
    {
        $reports = [];
        $failed  = [];

        foreach ($sections as $section) {
            $key = (string) $section['key'];

            if ($section['rows'] === null) {
                $reports[$key] = null;
                $failed[]      = $key;
                continue;
            }

            $reports[$key] = $key === 'summary' ? ($section['rows'][0] ?? (object) []) : $section['rows'];
        }

        return $meta + ['reports' => $reports, 'failed' => $failed];
    }

    /**
     * Runs one section's query, catching its failure.
     *
     * @param string                $key     Machine key (the JSON property).
     * @param string                $title   The report's own title.
     * @param string                $note    A note printed under the title ('' for none).
     * @param array<string, string> $columns Row key => column label, in order.
     * @param callable(): array<int, array<string, mixed>> $rows Runs the query.
     * @return array{key: string, title: string, note: string, columns: array<string, string>, rows: array<int, array<string, mixed>>|null}
     */
    private static function section(string $key, string $title, string $note, array $columns, callable $rows): array
    {
        try {
            $data = $rows();
        } catch (ReportQueryException) {
            $data = null;
        }

        return [
            'key'     => $key,
            'title'   => $title,
            'note'    => $note,
            'columns' => $columns,
            'rows'    => $data,
        ];
    }

    /**
     * The named keys of each report row, in order.
     *
     * @param array<int, array<string, mixed>> $rows Report rows.
     * @param list<string>                     $keys Keys to keep.
     * @return list<array<string, string|int|float>>
     */
    private static function pick(array $rows, array $keys): array
    {
        $out = [];

        foreach ($rows as $row) {
            $picked = [];
            foreach ($keys as $key) {
                $value        = $row[$key] ?? '';
                $picked[$key] = is_int($value) || is_float($value) ? $value : (string) $value;
            }
            $out[] = $picked;
        }

        return $out;
    }

    /**
     * A per-currency value map as "amount CODE" pairs, never summed.
     *
     * @param array<string, mixed> $values Currency code => decimal string.
     * @return string
     */
    private static function money(array $values): string
    {
        $parts = [];

        foreach ($values as $currency => $amount) {
            if ((string) $amount !== '' && (float) $amount !== 0.0) {
                $parts[] = $amount . ' ' . $currency;
            }
        }

        return implode('; ', $parts);
    }
}
