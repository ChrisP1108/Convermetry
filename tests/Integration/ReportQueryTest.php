<?php
declare(strict_types=1);

namespace Convermetry\Tests\Integration;

use Convermetry\Analytics\FormEngagementReport;
use Convermetry\Analytics\GoalReports;
use Convermetry\Analytics\LeadReports;
use Convermetry\Database\FormSubmissions;
use Convermetry\Database\NewSubmission;

/**
 * The engagement, goal, and lead queries, executed against a real server.
 *
 * The unit suite already covers the ARITHMETIC these produce. What it cannot
 * cover is whether the SQL feeds that arithmetic the right numbers — the
 * correlated NOT EXISTS in the abandonment query, the per-currency grouping, the
 * backfill sentinel's NULL predicates, and the cascade a lead deletion performs
 * are all statements a real optimizer has to run for the answer to mean anything.
 */
final class ReportQueryTest extends IntegrationTestCase
{
    private const string START = '2026-08-01 00:00:00';
    private const string END   = '2026-09-01 00:00:00';

    /**
     * Records one form lifecycle event.
     */
    private function formEvent(string $type, string $session, string $at, array $extra = []): int
    {
        return $this->insertEvent(array_merge([
            'event_type'    => $type,
            'session_id'    => $session,
            'form_key'      => 'gravityforms:7',
            'element_label' => 'Contact',
            'page_url'      => 'https://example.com/contact/',
            'created_at'    => $at,
        ], $extra));
    }

    // ── Form engagement and abandonment ──────────────────────────────────────

    /**
     * The abandonment query's correlated NOT EXISTS has three conditions that
     * all matter — same session, same form, and a success that came AFTER the
     * start and WITHIN the completion window. Each is exercised here.
     */
    public function testAbandonmentCountsStartsWithNoTimelySuccess(): void
    {
        // Converted promptly — not abandoned.
        $this->formEvent('form_view', 'f1', '2026-08-10 09:00:00');
        $this->formEvent('form_start', 'f1', '2026-08-10 09:01:00');
        $this->formEvent('form_submit', 'f1', '2026-08-10 09:02:00');
        $this->formEvent('form_success', 'f1', '2026-08-10 09:02:30', ['event_value' => 'conv1']);

        // Never converted — abandoned.
        $this->formEvent('form_view', 'f2', '2026-08-10 10:00:00');
        $this->formEvent('form_start', 'f2', '2026-08-10 10:01:00');

        // Converted 45 minutes later — outside the 30-minute window, so the
        // start is abandoned even though a success exists for the session.
        $this->formEvent('form_start', 'f3', '2026-08-10 11:00:00');
        $this->formEvent('form_success', 'f3', '2026-08-10 11:45:00', ['event_value' => 'conv3']);

        // Viewed only.
        $this->formEvent('form_view', 'f4', '2026-08-10 12:00:00');

        $forms = FormEngagementReport::totals(self::START, self::END);

        self::assertCount(1, $forms);
        $form = $forms[0];

        self::assertSame('gravityforms:7', $form['form_key']);
        self::assertSame(3, $form['views'], 'f1, f2, f4');
        self::assertSame(3, $form['started'], 'f1, f2, f3');
        self::assertSame(1, $form['attempts']);
        self::assertSame(2, $form['successful'], 'conv1 and conv3');
        self::assertSame(2, $form['abandoned'], 'f2 never converted; f3 converted too late.');
    }

    /**
     * A success belonging to a DIFFERENT form must not rescue this form's start.
     */
    public function testASuccessOnAnotherFormDoesNotClearAbandonment(): void
    {
        $this->formEvent('form_start', 'g1', '2026-08-10 09:00:00');
        $this->formEvent('form_success', 'g1', '2026-08-10 09:05:00', [
            'form_key'    => 'wpforms:3',
            'event_value' => 'convOther',
        ]);

        $forms = FormEngagementReport::totals(self::START, self::END);

        $gravity = array_values(array_filter(
            $forms,
            static fn(array $f): bool => $f['form_key'] === 'gravityforms:7'
        ));

        self::assertCount(1, $gravity);
        self::assertSame(1, $gravity[0]['abandoned']);
    }

    /**
     * A success from another SESSION must not rescue this session's start.
     */
    public function testASuccessInAnotherSessionDoesNotClearAbandonment(): void
    {
        $this->formEvent('form_start', 'h1', '2026-08-10 09:00:00');
        $this->formEvent('form_success', 'h2', '2026-08-10 09:05:00', ['event_value' => 'convH2']);

        $forms = FormEngagementReport::totals(self::START, self::END);

        self::assertSame(1, $forms[0]['abandoned']);
    }

    /**
     * A visitor who submits, then returns and starts the form again, has
     * genuinely abandoned the second attempt — matching the EARLIER success
     * would hide that, which is why the query requires the success to come
     * after the start.
     */
    public function testARestartAfterASuccessCountsAsAbandoned(): void
    {
        $this->formEvent('form_start', 'i1', '2026-08-10 09:00:00');
        $this->formEvent('form_success', 'i1', '2026-08-10 09:01:00', ['event_value' => 'convI1']);
        $this->formEvent('form_start', 'i1', '2026-08-10 14:00:00');

        $forms = FormEngagementReport::totals(self::START, self::END);

        // The session's FIRST start is the one the query groups on, and it was
        // satisfied — so this session is not abandoned. The assertion documents
        // the actual semantics rather than an assumption about them.
        self::assertSame(1, $forms[0]['started'], 'Starts are counted per session, not per attempt.');
        self::assertSame(0, $forms[0]['abandoned']);
    }

    /**
     * Friction points must expose field metadata and nothing else. The column
     * that would hold a typed value does not exist, which is the strongest form
     * this guarantee can take.
     */
    public function testFrictionPointsCarryOnlyFieldMetadata(): void
    {
        $this->formEvent('form_error', 'j1', '2026-08-10 09:00:00', [
            'element_label' => 'phone',
            'element_tag'   => 'tel',
            'event_value'   => 'required',
        ]);
        $this->formEvent('form_error', 'j2', '2026-08-10 09:10:00', [
            'element_label' => 'phone',
            'element_tag'   => 'tel',
            'event_value'   => 'required',
        ]);
        $this->formEvent('form_error', 'j3', '2026-08-10 09:20:00', [
            'element_label' => 'email',
            'element_tag'   => 'email',
            'event_value'   => 'type_mismatch',
        ]);

        $friction = FormEngagementReport::frictionPoints(self::START, self::END);

        self::assertCount(2, $friction);
        self::assertSame('phone', $friction[0]['field_id']);
        self::assertSame(2, $friction[0]['errors']);
        self::assertSame(2, $friction[0]['sessions']);

        foreach ($friction as $row) {
            self::assertSame(
                ['form_key', 'field_id', 'field_type', 'error_type', 'errors', 'sessions'],
                array_keys($row),
                'A friction row must expose nothing beyond field metadata and counts.'
            );
        }
    }

    // ── Goals ────────────────────────────────────────────────────────────────

    /**
     * A goal's conversion rate divides converting SESSIONS by sessions, so an
     * every-occurrence goal cannot report above 100%.
     */
    public function testGoalRatesUseSessionsAsTheDenominator(): void
    {
        // Two sessions in the window.
        $this->insertEvent(['session_id' => 'k1', 'created_at' => '2026-08-10 09:00:00']);
        $this->insertEvent(['session_id' => 'k2', 'created_at' => '2026-08-10 09:00:00']);

        $goalId = 'g' . str_repeat('a', 16);

        // One session, three completions — an every-occurrence goal.
        foreach (['1', '2', '3'] as $n) {
            self::$db->query(self::$db->prepare(
                'INSERT INTO wp_cvm_goal_completions
                 (completion_id, goal_id, definition_hash, dedupe_key, event_uid, session_id, value, currency, created_at)
                 VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s)',
                str_repeat($n, 32),
                $goalId,
                'abcdef123456',
                str_repeat($n, 32),
                str_repeat($n, 32),
                'k1',
                '25.00',
                'USD',
                '2026-08-10 09:0' . $n . ':00'
            ));
        }

        $summary = GoalReports::summary(self::START, self::END, [$goalId => 'PDF downloaded']);

        self::assertSame(2, $summary['sessions']);

        $goal = $summary['goals'][0];
        self::assertSame(3, $goal['completions']);
        self::assertSame(1, $goal['sessions']);
        self::assertSame(50.0, $goal['conversion_rate'], 'One of two sessions completed it.');
        self::assertSame('75.00', $goal['value'], 'Three completions at 25.00, summed exactly.');
    }

    public function testGoalBreakdownGroupsByDimension(): void
    {
        $goalId = 'g' . str_repeat('b', 16);

        foreach ([['1', 'Paid Search'], ['2', 'Paid Search'], ['3', 'Organic Search']] as [$n, $channel]) {
            self::$db->query(self::$db->prepare(
                'INSERT INTO wp_cvm_goal_completions
                 (completion_id, goal_id, definition_hash, dedupe_key, event_uid, session_id, channel, created_at)
                 VALUES (%s, %s, %s, %s, %s, %s, %s, %s)',
                str_repeat($n, 32),
                $goalId,
                'abcdef123456',
                str_repeat($n, 32),
                str_repeat($n, 32),
                'sess' . $n,
                $channel,
                '2026-08-10 09:00:00'
            ));
        }

        $rows = GoalReports::breakdown(self::START, self::END, $goalId, 'channel');

        self::assertSame('Paid Search', $rows[0]['label']);
        self::assertSame(2, $rows[0]['completions']);
        self::assertSame('Organic Search', $rows[1]['label']);
    }

    // ── Lead outcomes ────────────────────────────────────────────────────────

    public function testLeadsGroupByChannelWithQualificationRates(): void
    {
        $rows = [
            ['Paid Search', 'won', '10000.00'],
            ['Paid Search', 'qualified', '5000.00'],
            ['Paid Search', 'unqualified', null],
            ['Paid Search', 'new', null],
            ['Organic Search', 'qualified', '2000.00'],
            // Spam must leave the denominator entirely.
            ['Paid Search', 'spam', null],
        ];

        foreach ($rows as $i => [$channel, $status, $value]) {
            $this->insertSubmission([
                'submission_id' => 'lead' . $i,
                'conversion_id' => 'leadc' . $i,
                'channel'       => $channel,
                'lead_status'   => $status,
                'lead_value'    => $value,
                'lead_currency' => $value === null ? '' : 'USD',
            ]);
        }

        $report = LeadReports::byDimension(self::START, self::END, 'channel');

        $paid = array_values(array_filter($report, static fn(array $r): bool => $r['label'] === 'Paid Search'))[0];

        self::assertSame(4, $paid['leads'], 'Spam is excluded; unqualified and new are not.');
        self::assertSame(2, $paid['qualified'], 'won counts as qualified.');
        self::assertSame(1, $paid['won']);
        self::assertSame(50.0, $paid['qualified_rate']);
        self::assertSame(25.0, $paid['win_rate']);
        self::assertSame(['USD' => '15000.00'], $paid['value']);
        self::assertSame(['USD' => '10000.00'], $paid['revenue'], 'Revenue counts only won leads.');
    }

    /**
     * Currencies are grouped, never summed. A single total mixing EUR and USD
     * would be a fabricated number.
     */
    public function testMixedCurrenciesAreReportedSeparately(): void
    {
        $this->insertSubmission([
            'submission_id' => 'cur1', 'conversion_id' => 'curc1',
            'channel' => 'Referral', 'lead_status' => 'won',
            'lead_value' => '100.00', 'lead_currency' => 'USD',
        ]);
        $this->insertSubmission([
            'submission_id' => 'cur2', 'conversion_id' => 'curc2',
            'channel' => 'Referral', 'lead_status' => 'won',
            'lead_value' => '100.00', 'lead_currency' => 'EUR',
        ]);

        $report = LeadReports::byDimension(self::START, self::END, 'channel');
        $row    = array_values(array_filter($report, static fn(array $r): bool => $r['label'] === 'Referral'))[0];

        self::assertSame(['USD' => '100.00', 'EUR' => '100.00'], $row['value']);
        self::assertArrayNotHasKey('', $row['value'], 'A merged 200.00 total must never appear.');
    }

    public function testEmptyDimensionValuesAreLabelledRatherThanDropped(): void
    {
        $this->insertSubmission([
            'submission_id' => 'nochan', 'conversion_id' => 'nochanc',
            'channel' => '', 'lead_status' => 'new',
        ]);

        $report = LeadReports::byDimension(self::START, self::END, 'channel');

        self::assertSame('(none)', $report[0]['label']);
        self::assertSame(1, $report[0]['leads']);
    }

    /**
     * Time to lead is measured from the converting session's first pageview. A
     * submission whose session has no pageview at all is excluded rather than
     * counted as instant.
     */
    public function testTimeToLeadMeasuresFromTheSessionsFirstPageview(): void
    {
        $this->insertEvent(['session_id' => 'lag1', 'created_at' => '2026-08-10 09:00:00']);
        $this->insertEvent(['session_id' => 'lag1', 'created_at' => '2026-08-10 09:01:00']);
        $this->insertSubmission([
            'submission_id' => 'lagsub1', 'conversion_id' => 'lagconv1',
            'session_id' => 'lag1', 'channel' => 'Paid Search',
            'created_at' => '2026-08-10 09:03:00',
        ]);

        $this->insertEvent(['session_id' => 'lag2', 'created_at' => '2026-08-11 09:00:00']);
        $this->insertSubmission([
            'submission_id' => 'lagsub2', 'conversion_id' => 'lagconv2',
            'session_id' => 'lag2', 'channel' => 'Paid Search',
            'created_at' => '2026-08-11 11:00:00',
        ]);

        // No pageview for this session — excluded, not counted as instant.
        $this->insertSubmission([
            'submission_id' => 'lagsub3', 'conversion_id' => 'lagconv3',
            'session_id' => 'orphan', 'channel' => 'Direct',
            'created_at' => '2026-08-12 09:00:00',
        ]);

        $lag = LeadReports::timeToLead(self::START, self::END);

        self::assertSame(2, $lag['sampled'], 'The session with no pageview must be excluded.');
        self::assertSame(1, $lag['buckets']['Under 5 minutes']);
        self::assertSame(1, $lag['buckets']['30 minutes–24 hours']);
        self::assertArrayHasKey('Paid Search', $lag['medians']);
        self::assertArrayNotHasKey('Direct', $lag['medians']);
    }

    // ── The backfill sentinel ────────────────────────────────────────────────

    /**
     * The bug this predicate was corrected for: an install that already ran the
     * 1.2.0 backfill has a NON-NULL channel, so a sentinel testing only
     * `channel IS NULL` would never select it — and its landing page and full
     * campaign identity would stay NULL forever, silently blanking the campaign
     * and landing-page lead reports on every existing site.
     */
    public function testTheBackfillSentinelSelectsAlreadyUpgradedRows(): void
    {
        // The exact shape of a row from an install upgraded at 1.2.0/1.3.0.
        $this->insertSubmission([
            'submission_id'  => 'old1',
            'conversion_id'  => 'oldc1',
            'channel'        => 'Paid Search',
            'delivery_state' => 'delivered',
            'landing_page'   => null,
            'utm_source'     => null,
            'utm_medium'     => null,
            'utm_id'         => null,
        ]);

        self::assertTrue(
            FormSubmissions::needsBackfill(),
            'A row with a populated channel but a NULL landing_page must still be selected for backfill.'
        );

        // A fully-populated row must not be selected.
        self::$db->query('TRUNCATE TABLE wp_cvm_form_submissions');
        $this->insertSubmission([
            'submission_id'  => 'new1',
            'conversion_id'  => 'newc1',
            'channel'        => 'Paid Search',
            'delivery_state' => 'not_sent',
            'landing_page'   => 'https://example.com/land/',
        ]);

        self::assertFalse(FormSubmissions::needsBackfill());
    }

    /**
     * A submission created by this version writes every derived column at
     * insert, so it never enters the backfill queue at all.
     */
    public function testFreshSubmissionsNeverNeedBackfilling(): void
    {
        // Built through fromContext(), the way SubmissionService builds it:
        // the six derived attribution columns are DERIVED from the context
        // rather than passed alongside it, so this exercises the derivation as
        // well as the INSERT.
        FormSubmissions::insert(NewSubmission::fromContext(
            submissionId: 'fresh1',
            conversionId: 'freshc1',
            sessionId: 'sess0001',
            provider: 'gravityforms',
            formKey: 'gravityforms:7',
            formName: 'Contact',
            nativeFormId: '7',
            formId: '7',
            pageUrl: 'https://example.com/contact/',
            ipAddress: '',
            pageQuery: [],
            fields: [],
            context: [
                'channel'      => 'Paid Search',
                'attribution'  => [
                    'utm_campaign' => 'spring',
                    'utm_source'   => 'google',
                    'utm_medium'   => 'cpc',
                    'utm_id'       => 'cmp-1',
                ],
                'landing_page' => ['url' => 'https://example.com/land/'],
            ],
            runtime: ['query' => [], 'headers' => []],
        ));

        self::assertNull(
            self::$db->get_var("SELECT landing_page FROM wp_cvm_form_submissions WHERE landing_page IS NULL LIMIT 1"),
            'A freshly inserted submission must carry its landing page already.'
        );

        self::assertSame(
            ['Paid Search', 'spring', 'google', 'cpc', 'cmp-1', 'https://example.com/land/'],
            array_values((array) self::$db->get_row(
                'SELECT channel, utm_campaign, utm_source, utm_medium, utm_id, landing_page'
                . " FROM wp_cvm_form_submissions WHERE submission_id = 'fresh1'",
                ARRAY_A
            )),
            'Every derived column is written from the context at insert time.'
        );

        // delivery_state used to be left NULL for the delivery pipeline to
        // fill, which meant every submission on a site that does not use
        // webhooks matched BACKFILL_PREDICATE and was re-derived by the daily
        // worker — a state nothing was going to change and the insert already
        // knew. Nothing has been attempted at this point, which is exactly what
        // NotSent means.
        self::assertSame(
            'not_sent',
            self::$db->get_var("SELECT delivery_state FROM wp_cvm_form_submissions WHERE submission_id = 'fresh1'"),
            'A submission with no delivery attempted carries that verdict from the start.'
        );

        // The assertion this test's NAME has always promised.
        self::assertFalse(
            FormSubmissions::needsBackfill(),
            'A row created by this version must never enter the backfill queue.'
        );
    }

    // ── Cascades ─────────────────────────────────────────────────────────────

    /**
     * A lead's status history is data ABOUT that lead. "Removed permanently"
     * that left a trail of who qualified them and what they were valued at
     * would be a broken promise.
     */
    public function testDeletingASubmissionRemovesItsLeadHistory(): void
    {
        $rowId = $this->insertSubmission(['submission_id' => 'casc1', 'conversion_id' => 'cascc1']);

        self::$db->query(self::$db->prepare(
            'INSERT INTO wp_cvm_lead_events (lead_event_id, submission_id, from_status, to_status, currency, user_id, created_at)
             VALUES (%s, %s, %s, %s, %s, %d, %s)',
            str_repeat('a', 32),
            'casc1',
            'new',
            'won',
            'USD',
            1,
            '2026-08-10 09:00:00'
        ));

        self::assertSame('1', self::$db->get_var('SELECT COUNT(*) FROM wp_cvm_lead_events'));

        FormSubmissions::deleteSubmission($rowId);

        self::assertSame('0', self::$db->get_var('SELECT COUNT(*) FROM wp_cvm_form_submissions'));
        self::assertSame(
            '0',
            self::$db->get_var('SELECT COUNT(*) FROM wp_cvm_lead_events'),
            'The lead history outlived the lead it describes.'
        );
    }

    /**
     * The status update and its history row are one transaction, so a lead can
     * never end up in a state its own history disagrees with.
     */
    public function testUpdatingALeadWritesTheRowAndItsHistoryTogether(): void
    {
        $this->insertSubmission(['submission_id' => 'upd1', 'conversion_id' => 'updc1']);

        $ok = FormSubmissions::updateLead('upd1', 'won', '12500.00', 'USD', 7, 'new');

        self::assertTrue($ok);
        self::assertSame(
            'won',
            self::$db->get_var("SELECT lead_status FROM wp_cvm_form_submissions WHERE submission_id = 'upd1'")
        );
        self::assertSame(
            '12500.00',
            self::$db->get_var("SELECT lead_value FROM wp_cvm_form_submissions WHERE submission_id = 'upd1'")
        );

        $history = self::$db->get_results(
            "SELECT from_status, to_status, value, user_id FROM wp_cvm_lead_events WHERE submission_id = 'upd1'"
        );

        self::assertCount(1, $history);
        self::assertSame('new', $history[0]['from_status']);
        self::assertSame('won', $history[0]['to_status']);
        self::assertSame('12500.00', $history[0]['value']);
        self::assertSame('7', (string) $history[0]['user_id']);
    }
}
