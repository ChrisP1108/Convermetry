<?php

declare(strict_types=1);

namespace Convermetry\Tests\Unit;

use Convermetry\Leads\LeadStatus;
use PHPUnit\Framework\TestCase;

/**
 * The lead qualification vocabulary.
 *
 * Small surface, but three of its decisions change every number in the lead
 * reports, so they are asserted rather than assumed:
 *
 *  - 'new' is the default, and a submission that nobody has assessed must read
 *    as unassessed rather than as a negative outcome.
 *  - 'won' counts as qualified. Requiring a lead to pass through 'qualified'
 *    first would under-report every site whose users mark the final outcome in
 *    one step, which is most of them.
 *  - Only 'spam' leaves the denominator. An unqualified or lost lead was still
 *    a lead the marketing produced; excluding those would make a channel look
 *    better the more bad leads it sent.
 */
final class LeadStatusTest extends TestCase
{
    public function testTheDefaultIsNew(): void
    {
        self::assertSame('new', LeadStatus::DEFAULT);
        self::assertContains('new', LeadStatus::ALL);
    }

    /**
     * The schema default and the code default have to agree. If the column said
     * 'new' and the code said something else, every pre-existing row would read
     * as one status and every new one as another.
     */
    public function testTheDefaultMatchesTheColumnDefault(): void
    {
        $ddl = (string) file_get_contents(
            __DIR__ . '/../../src/Database/FormSubmissions.php'
        );

        self::assertStringContainsString(
            "lead_status VARCHAR(16) NOT NULL DEFAULT '" . LeadStatus::DEFAULT . "'",
            $ddl
        );
    }

    /**
     * @dataProvider validStatuses
     */
    public function testValidStatusesAreAccepted(string $status): void
    {
        self::assertTrue(LeadStatus::isValid($status));
        self::assertSame($status, LeadStatus::normalize($status));
        self::assertNotSame('', LeadStatus::label($status));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function validStatuses(): array
    {
        return [
            'new'         => ['new'],
            'qualified'   => ['qualified'],
            'unqualified' => ['unqualified'],
            'won'         => ['won'],
            'lost'        => ['lost'],
            'spam'        => ['spam'],
        ];
    }

    /**
     * @dataProvider invalidStatuses
     */
    public function testInvalidStatusesAreRejected(mixed $status): void
    {
        self::assertFalse(LeadStatus::isValid($status));
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function invalidStatuses(): array
    {
        return [
            'unknown word'  => ['nurturing'],
            'wrong case'    => ['Won'],
            'empty'         => [''],
            'null'          => [null],
            'integer'       => [1],
            'array'         => [['won']],
            'sql fragment'  => ["won' OR '1'='1"],
        ];
    }

    /**
     * Normalizing is for READS of stored data — a value that somehow got into
     * the column falls back to the default rather than creating a silent bucket
     * that appears in no total and no filter. Writes go through LeadService,
     * which REJECTS an invalid status instead of coercing it, because silently
     * storing 'new' when somebody meant 'won' is worse than refusing.
     */
    public function testUnknownStoredValuesNormalizeToTheDefault(): void
    {
        self::assertSame('new', LeadStatus::normalize('nurturing'));
        self::assertSame('new', LeadStatus::normalize(null));
        self::assertSame('new', LeadStatus::normalize(''));
        self::assertSame('new', LeadStatus::normalize(['won']));
    }

    public function testWonCountsAsQualified(): void
    {
        self::assertTrue(
            LeadStatus::isQualified('won'),
            'A lead that converted was self-evidently qualified; requiring it to pass through '
            . '"qualified" first would under-report every site that records the final outcome in one step.'
        );
        self::assertTrue(LeadStatus::isQualified('qualified'));
    }

    /**
     * @dataProvider unqualifiedStatuses
     */
    public function testOtherStatusesAreNotQualified(string $status): void
    {
        self::assertFalse(LeadStatus::isQualified($status));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function unqualifiedStatuses(): array
    {
        return [
            'new'         => ['new'],
            'unqualified' => ['unqualified'],
            'lost'        => ['lost'],
            'spam'        => ['spam'],
        ];
    }

    public function testOnlyWonCountsAsWon(): void
    {
        self::assertTrue(LeadStatus::isWon('won'));

        foreach (['new', 'qualified', 'unqualified', 'lost', 'spam'] as $status) {
            self::assertFalse(LeadStatus::isWon($status), "'{$status}' must not count as won.");
        }
    }

    /**
     * The denominator rule. Only spam is excluded — everything else was a lead
     * the marketing produced, whatever became of it.
     */
    public function testOnlySpamLeavesTheDenominator(): void
    {
        self::assertFalse(LeadStatus::countsAsLead('spam'));

        foreach (['new', 'qualified', 'unqualified', 'won', 'lost'] as $status) {
            self::assertTrue(
                LeadStatus::countsAsLead($status),
                "'{$status}' must stay in lead totals — excluding it would make a channel look better "
                . 'the more poor-quality leads it sent.'
            );
        }
    }

    /**
     * Spam is distinct from unqualified on purpose: unqualified is a real person
     * who was not a fit, spam was never a lead. Merging them would inflate every
     * denominator with bot traffic.
     */
    public function testSpamAndUnqualifiedAreDistinct(): void
    {
        self::assertNotSame(
            LeadStatus::countsAsLead('spam'),
            LeadStatus::countsAsLead('unqualified')
        );
    }

    public function testEveryStatusHasALabelAndAChipClass(): void
    {
        $labels = LeadStatus::labels();

        foreach (LeadStatus::ALL as $status) {
            self::assertArrayHasKey($status, $labels, "'{$status}' has no label.");
            self::assertNotSame('', $labels[$status]);
            self::assertStringStartsWith('cvm-status-', LeadStatus::chipClass($status));
        }
    }

    public function testTheLabelMapOffersNothingUnrecognized(): void
    {
        foreach (array_keys(LeadStatus::labels()) as $status) {
            self::assertContains($status, LeadStatus::ALL);
        }
    }

    /**
     * The product boundary, asserted so it cannot drift by accident. Convermetry
     * records the OUTCOME of a lead so marketing can be measured against it; it
     * does not manage the work of pursuing one. Anything resembling a pipeline
     * stage belongs in the CRM the site already has.
     */
    public function testTheVocabularyStaysSmall(): void
    {
        self::assertCount(
            6,
            LeadStatus::ALL,
            'Adding statuses is how an analytics plugin becomes a worse CRM. If a new one is genuinely '
            . 'needed, change this assertion deliberately rather than letting the list grow.'
        );

        foreach (['contacted', 'nurturing', 'proposal', 'negotiating', 'follow_up'] as $pipeline) {
            self::assertNotContains(
                $pipeline,
                LeadStatus::ALL,
                "'{$pipeline}' is a sales pipeline stage, not a marketing outcome."
            );
        }
    }
}
