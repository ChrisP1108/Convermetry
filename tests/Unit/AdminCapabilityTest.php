<?php

declare(strict_types=1);

namespace Convermetry\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Convermetry\Admin\Capability;
use PHPUnit\Framework\TestCase;

/**
 * Who is allowed to do what, and the two ways that could go wrong.
 *
 * The first is a lockout. This filter decides a capability NAME, and
 * current_user_can('') is false for every user including the site owner — so a
 * callback that returns an empty string, or null, or an integer, would lock an
 * administrator out of their own plugin with no way back except editing PHP.
 * The shape check refuses exactly that, and nothing else: whether a returned
 * capability is more or less privileged than manage_options is the site owner's
 * business, not this class's.
 *
 * The second is a filter that is only half true. A capability that hides a menu
 * entry but does not guard the AJAX handler behind it is worse than no filter,
 * because it looks like access control and is not. The sweep test at the bottom
 * is what keeps that honest: no literal 'manage_options' may remain anywhere in
 * the admin layer.
 */
final class AdminCapabilityTest extends TestCase
{
    private const PLUGIN_DIR = __DIR__ . '/../../';

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        Capability::reset();
    }

    protected function tearDown(): void
    {
        Capability::reset();
        Monkey\tearDown();
        parent::tearDown();
    }

    public function testEveryScopeDefaultsToManageOptions(): void
    {
        foreach (self::scopeNames() as $scope) {
            self::assertSame(Capability::DEFAULT, Capability::required($scope), "{$scope} changed its default");
        }
    }

    public function testTheScopeIsPassedToTheFilterSoOneCallbackCanAnswerForAll(): void
    {
        $seen = [];

        Functions\when('apply_filters')->alias(
            static function (string $hook, mixed $value, mixed ...$rest) use (&$seen) {
                if ($hook === 'convermetry_admin_capability') {
                    $seen[] = $rest[0];

                    return $rest[0] === 'submissions.export' ? 'export_leads' : $value;
                }

                return $value;
            }
        );

        self::assertSame('export_leads', Capability::required(Capability::SUBMISSIONS_EXPORT));
        self::assertSame(Capability::DEFAULT, Capability::required(Capability::ANALYTICS_VIEW));
        self::assertSame(['submissions.export', 'analytics.view'], $seen);
    }

    /**
     * A returned value that is not a usable capability name would make
     * current_user_can() false for everyone — the site owner included.
     *
     * @dataProvider unusableCapabilities
     */
    public function testAnUnusableReturnFallsBackRatherThanLockingEveryoneOut(mixed $returned): void
    {
        Functions\when('apply_filters')->alias(
            static fn(string $hook, mixed $value, mixed ...$rest) => $hook === 'convermetry_admin_capability'
                ? $returned
                : $value
        );

        self::assertSame(Capability::DEFAULT, Capability::required(Capability::SETTINGS_MANAGE));
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function unusableCapabilities(): array
    {
        return [
            'empty string' => [''],
            'whitespace'   => ['   '],
            'null'         => [null],
            'false'        => [false],
            'integer'      => [42],
            'array'        => [['edit_posts']],
            'with spaces'  => ['edit posts'],
            'with dash'    => ['edit-posts'],
            'uppercase'    => ['EDIT_POSTS'],
        ];
    }

    public function testAValidCustomCapabilityIsAcceptedWhateverItsPrivilegeLevel(): void
    {
        Functions\when('apply_filters')->alias(
            static fn(string $hook, mixed $value, mixed ...$rest) => $hook === 'convermetry_admin_capability'
                ? 'edit_posts'
                : $value
        );

        // Deliberately weaker than manage_options: widening access is the site
        // owner's call, and this class validates shape only.
        self::assertSame('edit_posts', Capability::required(Capability::ANALYTICS_VIEW));
    }

    public function testEachScopeIsResolvedOnlyOncePerRequest(): void
    {
        $calls = 0;

        Functions\when('apply_filters')->alias(
            static function (string $hook, mixed $value, mixed ...$rest) use (&$calls) {
                if ($hook === 'convermetry_admin_capability') {
                    $calls++;
                }

                return $value;
            }
        );

        Capability::required(Capability::ANALYTICS_VIEW);
        Capability::required(Capability::ANALYTICS_VIEW);
        Capability::required(Capability::GOALS_MANAGE);

        self::assertSame(2, $calls, 'One resolution per distinct scope.');
    }

    public function testCurrentUserCanAsksWordPressWithTheResolvedCapability(): void
    {
        Functions\when('apply_filters')->alias(
            static fn(string $hook, mixed $value, mixed ...$rest) => $hook === 'convermetry_admin_capability'
                ? 'export_leads'
                : $value
        );

        $asked = null;
        Functions\when('current_user_can')->alias(function (string $cap) use (&$asked): bool {
            $asked = $cap;

            return true;
        });

        self::assertTrue(Capability::currentUserCan(Capability::SUBMISSIONS_EXPORT));
        self::assertSame('export_leads', $asked);
    }

    /**
     * The scopes exist because these are genuinely different levels of trust.
     * Collapsing them back into one would make the split decorative.
     */
    public function testReadingExportingAndDeletingAreDistinctScopes(): void
    {
        self::assertNotSame(Capability::SUBMISSIONS_VIEW, Capability::SUBMISSIONS_EXPORT);
        self::assertNotSame(Capability::SUBMISSIONS_VIEW, Capability::SUBMISSIONS_DELETE);
        self::assertNotSame(Capability::SUBMISSIONS_EXPORT, Capability::SUBMISSIONS_DELETE);
        self::assertNotSame(Capability::WEBHOOKS_MANAGE, Capability::ANALYTICS_VIEW);

        self::assertSame(count(self::scopeNames()), count(array_unique(self::scopeNames())));
    }

    // ------------------------------------------------------- the sweep guard

    /**
     * The test that stops the filter from becoming a lie. A menu entry hidden
     * by a capability whose AJAX handler still hard-codes 'manage_options' is
     * not access control; it is decoration.
     */
    public function testNoAdminSurfaceStillHardCodesTheCapability(): void
    {
        foreach (glob(self::PLUGIN_DIR . 'src/Admin/*.php') ?: [] as $file) {
            if (basename($file) === 'Capability.php') {
                continue; // Where the default is legitimately written down.
            }

            self::assertStringNotContainsString(
                "'manage_options'",
                (string) file_get_contents($file),
                basename($file) . ' hard-codes a capability instead of resolving a scope.'
            );
        }
    }

    /**
     * Lead editing lives outside src/Admin but is an admin surface all the same.
     * The old constant survives for compatibility; the code must not use it.
     */
    public function testLeadEditingResolvesAScopeRatherThanReadingTheLegacyConstant(): void
    {
        $source = (string) file_get_contents(self::PLUGIN_DIR . 'src/Leads/LeadService.php');

        self::assertStringContainsString(
            'Capability::currentUserCan(Capability::LEADS_EDIT)',
            $source
        );
        self::assertStringNotContainsString('current_user_can(self::CAPABILITY)', $source);
        self::assertStringContainsString(
            "public const string CAPABILITY = 'manage_options';",
            $source,
            'The published constant must survive for anything that read it.'
        );
    }

    /**
     * @return list<string>
     */
    private static function scopeNames(): array
    {
        return [
            Capability::ANALYTICS_VIEW,
            Capability::SUBMISSIONS_VIEW,
            Capability::SUBMISSIONS_EXPORT,
            Capability::SUBMISSIONS_DELETE,
            Capability::LEADS_EDIT,
            Capability::GOALS_MANAGE,
            Capability::FUNNELS_MANAGE,
            Capability::FORMS_MANAGE,
            Capability::NOTIFICATIONS_MANAGE,
            Capability::WEBHOOKS_MANAGE,
            Capability::ACTIVITY_VIEW,
            Capability::ACTIVITY_MANAGE,
            Capability::API_MANAGE,
            Capability::SETTINGS_MANAGE,
        ];
    }
}
