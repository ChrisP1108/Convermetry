<?php

declare(strict_types=1);

namespace Convermetry\Tests\Unit;

use Convermetry\Analytics\FormEngagementReport;
use PHPUnit\Framework\TestCase;

/**
 * Which of a form's labels the Forms screen shows.
 *
 * Regression origin: the report took MAX(element_label), which picks by
 * collation order. "form" — the tracker's fallback when markup names nothing —
 * sorts after "Footer Feedback" and "Cloud Signup", and "demo-quote-request"
 * sorts after "Demo Quote Request", so the least informative label won even
 * when the proper name was the form plugin's own title on every confirmed
 * success. The choice is now a ranking with a reason at each step; the SQL that
 * gathers the candidates is covered against a real database in
 * tests/Integration/ReportQueryTest.
 */
final class FormNamePreferenceTest extends TestCase
{
    /**
     * @return array{label: string, source: int, confirmed: int, seen: int, latest: int}
     */
    private static function browser(string $label, int $seen = 1, int $latest = 1): array
    {
        return ['label' => $label, 'source' => FormEngagementReport::SOURCE_BROWSER, 'confirmed' => 0, 'seen' => $seen, 'latest' => $latest];
    }

    /**
     * @return array{label: string, source: int, confirmed: int, seen: int, latest: int}
     */
    private static function confirmed(string $label, int $confirmed = 1, int $latest = 1): array
    {
        return ['label' => $label, 'source' => FormEngagementReport::SOURCE_CONFIRMED, 'confirmed' => $confirmed, 'seen' => $confirmed, 'latest' => $latest];
    }

    /**
     * @return array{label: string, source: int, confirmed: int, seen: int, latest: int}
     */
    private static function submission(string $label): array
    {
        return ['label' => $label, 'source' => FormEngagementReport::SOURCE_SUBMISSION, 'confirmed' => 0, 'seen' => 0, 'latest' => 0];
    }

    public function testTheTrackersGenericFallbackNeverBeatsARealName(): void
    {
        // "form" outnumbers the real name here, and sorts after it.
        self::assertSame('Footer Feedback', FormEngagementReport::preferredName('custom:footer', [
            self::browser('form', 40),
            self::browser('Footer Feedback', 3),
        ]));
        self::assertSame('Cloud Signup', FormEngagementReport::preferredName('custom:signup', [
            self::browser('Cloud Signup'),
            self::browser('form', 9, 99),
        ]));
    }

    public function testTheFormPluginsOwnTitleBeatsWhatTheMarkupSaid(): void
    {
        self::assertSame('Demo Quote Request', FormEngagementReport::preferredName('gravityforms:4', [
            self::browser('demo-quote-request', 120, 900),
            self::confirmed('Demo Quote Request', 12, 800),
        ]));
    }

    /**
     * Authority outranks how a label reads: if the form plugin's title really
     * is a slug, that is still the form's name.
     */
    public function testAuthorityComesBeforeStyle(): void
    {
        self::assertSame('contact', FormEngagementReport::preferredName('contactform7:9', [
            self::browser('Get In Touch', 50),
            self::confirmed('contact', 2),
        ]));
    }

    /**
     * The latest submission row carries the form's CURRENT title, so a renamed
     * form shows its new name even while older labels dominate the range.
     */
    public function testTheLatestSubmissionsTitleIsTheCurrentName(): void
    {
        self::assertSame('Quote Request (2026)', FormEngagementReport::preferredName('wpforms:12', [
            self::confirmed('Quote Request', 300, 500),
            self::submission('Quote Request (2026)'),
        ]));
    }

    /**
     * When the form plugin had no title, the server fell back to an id. That is
     * a placeholder, and a name the site gave the form in its markup wins.
     *
     * @dataProvider serverPlaceholders
     */
    public function testAServerFallbackLosesToAnyRealName(string $formKey, string $placeholder): void
    {
        self::assertSame('Newsletter', FormEngagementReport::preferredName($formKey, [
            self::submission($placeholder),
            self::confirmed($placeholder, 30),
            self::browser('Newsletter'),
        ]));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function serverPlaceholders(): array
    {
        return [
            'bare native id (Bricks)'  => ['bricks:ab12cd', 'ab12cd'],
            '"Form <id>" (Fluent)'     => ['fluentforms:12', 'Form 12'],
            'the provider key'         => ['gravityforms:7', 'gravityforms'],
            'Atomic default "Form"'    => ['elementor-atomic:e9f8', 'Form'],
        ];
    }

    public function testAPlaceholderIsStillShownWhenItIsAllThereIs(): void
    {
        self::assertSame('form', FormEngagementReport::preferredName('custom:x', [self::browser('form')]));
        self::assertSame('', FormEngagementReport::preferredName('custom:x', []));
        self::assertSame('', FormEngagementReport::preferredName('custom:x', [self::browser('   ')]));
    }

    public function testAmongBrowserLabelsANameBeatsAnIdentifier(): void
    {
        self::assertSame('Demo Quote Request', FormEngagementReport::preferredName('custom:quote', [
            self::browser('demo-quote-request', 50, 50),
            self::browser('Demo Quote Request', 2, 3),
        ]));
    }

    public function testFrequencyThenRecencyBreakTheRemainingTies(): void
    {
        self::assertSame('Contact Us', FormEngagementReport::preferredName('custom:c', [
            self::browser('Contact', 3, 90),
            self::browser('Contact Us', 7, 10),
        ]));
        self::assertSame('Contact Us', FormEngagementReport::preferredName('custom:c', [
            self::browser('Contact', 5, 10),
            self::browser('Contact Us', 5, 90),
        ]));
    }

    /**
     * The same candidates in any order give the same answer — including two
     * spellings that differ only by case, which a case-insensitive collation
     * would have treated as one and returned either of.
     */
    public function testTheChoiceDoesNotDependOnInputOrder(): void
    {
        $candidates = [
            self::browser('Contact Form', 4, 20),
            self::browser('contact form', 4, 20),
            self::browser('CONTACT FORM', 4, 20),
        ];

        $answers = [];
        foreach ([[0, 1, 2], [2, 1, 0], [1, 2, 0], [1, 0, 2]] as $order) {
            $answers[] = FormEngagementReport::preferredName(
                'custom:c',
                array_map(static fn(int $i): array => $candidates[$i], $order)
            );
        }

        self::assertCount(1, array_unique($answers));
        self::assertSame('CONTACT FORM', $answers[0], 'Byte order settles a complete tie.');
    }
}
