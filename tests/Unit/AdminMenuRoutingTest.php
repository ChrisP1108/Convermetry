<?php

declare(strict_types=1);

namespace Convermetry\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Convermetry\Admin\AdminAssets;
use Convermetry\Admin\Capability;
use Convermetry\Admin\HomeStatus;
use Convermetry\Admin\Icons;
use Convermetry\Admin\Pages\AboutPage;
use Convermetry\Admin\Pages\ActivityLogPage;
use Convermetry\Admin\Pages\AnalyticsPage;
use Convermetry\Admin\Pages\FormsPage;
use Convermetry\Admin\Pages\FunnelsPage;
use Convermetry\Admin\Pages\GoalsPage;
use Convermetry\Admin\Pages\HomePage;
use Convermetry\Admin\Pages\NotificationsPage;
use Convermetry\Admin\Pages\SettingsPage;
use Convermetry\Admin\Pages\SubmissionsPage;
use Convermetry\Admin\Pages\WebhooksPage;
use PHPUnit\Framework\TestCase;

/**
 * Where the Convermetry menu goes, and the two ways moving its front door
 * could have broken the plugin.
 *
 * The first is a lost submenu. Every Convermetry screen registers itself under
 * the top-level slug, and nine of them named AnalyticsPage as that parent
 * purely because Analytics happened to own it. Handing the slug to the Home
 * page without repointing all nine would have orphaned them — WordPress
 * silently creates a second top-level menu for an unknown parent — so this
 * file asserts the parent by reading what each class actually registers.
 *
 * The second is a lost stylesheet. The shared admin CSS was enqueued by
 * AnalyticsPage, guarded by a substring test against its own slug, which
 * matched every Convermetry hook only because that slug was 'convermetry'.
 * Moving Analytics to 'convermetry-analytics' would have narrowed that guard
 * to one screen and quietly unstyled the other nine. Nothing errors when that
 * happens, which is exactly why it is pinned here.
 */
final class AdminMenuRoutingTest extends TestCase
{
    /** @var list<array{parent: string, slug: string, label: string, capability: string}> */
    private array $submenus = [];

    /** @var list<array{slug: string, capability: string, icon: string}> */
    private array $menus = [];

    /** @var list<string> */
    private array $enqueued = [];

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        Capability::reset();

        $this->submenus = [];
        $this->menus    = [];
        $this->enqueued = [];

        Functions\when('add_action')->justReturn(true);
        Functions\when('apply_filters')->alias(
            static fn(string $hook, mixed $value, mixed ...$rest): mixed => $value
        );

        Functions\when('add_menu_page')->alias(
            function (
                string $pageTitle,
                string $menuTitle,
                string $capability,
                string $slug,
                mixed $callback = null,
                string $icon = '',
                mixed $position = null,
            ): string {
                $this->menus[] = ['slug' => $slug, 'capability' => $capability, 'icon' => $icon];

                return $slug;
            }
        );

        Functions\when('add_submenu_page')->alias(
            function (
                string $parent,
                string $pageTitle,
                string $menuTitle,
                string $capability,
                string $slug,
                mixed $callback = null,
            ): string {
                $this->submenus[] = [
                    'parent'     => $parent,
                    'slug'       => $slug,
                    'label'      => $menuTitle,
                    'capability' => $capability,
                ];

                return $slug;
            }
        );

        Functions\when('wp_enqueue_style')->alias(function (string $handle): void {
            $this->enqueued[] = $handle;
        });
        Functions\when('wp_enqueue_script')->alias(function (string $handle): void {
            $this->enqueued[] = $handle;
        });

        // Several pages' enqueueAssets() also localizes data onto their
        // script; irrelevant to what this file asserts (which handles load
        // where), so these just need to not fatal.
        Functions\when('wp_localize_script')->justReturn(true);
        Functions\when('wp_create_nonce')->justReturn('nonce');
        Functions\when('admin_url')->alias(static fn(string $path = ''): string => 'https://example.test/wp-admin/' . $path);
    }

    protected function tearDown(): void
    {
        Capability::reset();
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * Registers every Convermetry admin menu entry, in the order Plugin::init()
     * registers them.
     *
     * @return void
     */
    private function buildMenu(): void
    {
        HomePage::addMenu();
        AnalyticsPage::addMenu();
        SubmissionsPage::addMenu();
        GoalsPage::addMenu();
        FunnelsPage::addMenu();
        FormsPage::addMenu();
        NotificationsPage::addMenu();
        WebhooksPage::addMenu();
        ActivityLogPage::addMenu();
        SettingsPage::addMenu();
        AboutPage::addMenu();
    }

    // ------------------------------------------------------------- the front door

    public function testTheTopLevelMenuIsTheHomePageAndKeepsTheOriginalSlug(): void
    {
        $this->buildMenu();

        self::assertCount(1, $this->menus, 'Convermetry must register exactly one top-level menu.');
        self::assertSame('convermetry', $this->menus[0]['slug']);
        self::assertSame('convermetry', HomePage::MENU_SLUG);
    }

    /**
     * The first submenu row has to reuse the parent slug, or WordPress
     * generates its own duplicate row labelled after the menu.
     */
    public function testHomeIsTheFirstSubmenuRowAndSharesTheParentSlug(): void
    {
        $this->buildMenu();

        self::assertSame(HomePage::MENU_SLUG, $this->submenus[0]['slug']);
        self::assertSame(HomePage::MENU_SLUG, $this->submenus[0]['parent']);
        self::assertSame('Home', $this->submenus[0]['label']);
    }

    public function testAnalyticsKeepsItsPlaceDirectlyUnderHomeOnItsOwnSlug(): void
    {
        $this->buildMenu();

        self::assertSame('convermetry-analytics', AnalyticsPage::MENU_SLUG);
        self::assertSame('Analytics', $this->submenus[1]['label']);
        self::assertSame(AnalyticsPage::MENU_SLUG, $this->submenus[1]['slug']);
    }

    // ------------------------------------------------------------ nothing orphaned

    public function testEverySubmenuHangsOffTheTopLevelSlug(): void
    {
        $this->buildMenu();

        foreach ($this->submenus as $entry) {
            self::assertSame(
                HomePage::MENU_SLUG,
                $entry['parent'],
                $entry['slug'] . ' is registered under the wrong parent and would become its own menu.'
            );
        }
    }

    public function testNoTwoScreensClaimTheSameSlug(): void
    {
        $this->buildMenu();

        // Home's own row deliberately repeats the parent slug; every other slug
        // must be distinct, or one screen would render in place of another.
        $slugs = array_column($this->submenus, 'slug');

        self::assertSame(count($slugs), count(array_unique($slugs)));
    }

    /**
     * The slugs are URLs. Anything bookmarked, linked from a notification, or
     * hard-coded by a site's own snippet breaks if one changes, so every one of
     * them is written down here rather than derived.
     */
    public function testNoExistingScreenSlugChanged(): void
    {
        self::assertSame('convermetry', HomePage::MENU_SLUG);
        self::assertSame('convermetry-submissions', SubmissionsPage::MENU_SLUG);
        self::assertSame('convermetry-goals', GoalsPage::MENU_SLUG);
        self::assertSame('convermetry-funnels', FunnelsPage::MENU_SLUG);
        self::assertSame('convermetry-forms', FormsPage::MENU_SLUG);
        self::assertSame('convermetry-notifications', NotificationsPage::MENU_SLUG);
        self::assertSame('convermetry-webhooks', WebhooksPage::MENU_SLUG);
        self::assertSame('convermetry-activity', ActivityLogPage::MENU_SLUG);
        self::assertSame('convermetry-settings', SettingsPage::MENU_SLUG);
        self::assertSame('convermetry-about', AboutPage::MENU_SLUG);
    }

    public function testEveryScreenIsStillRegistered(): void
    {
        $this->buildMenu();

        $labels = array_column($this->submenus, 'label');

        foreach (
            [
                'Home', 'Analytics', 'Submissions', 'Goals', 'Funnels', 'Forms',
                'Notifications', 'Webhooks', 'Activity Log', 'Settings', 'About',
            ] as $expected
        ) {
            self::assertContains($expected, $labels, $expected . ' disappeared from the menu.');
        }
    }

    public function testHomeIsGatedByAResolvedScopeRatherThanALiteral(): void
    {
        $seen = [];

        Functions\when('apply_filters')->alias(
            static function (string $hook, mixed $value, mixed ...$rest) use (&$seen): mixed {
                if ($hook === 'convermetry_admin_capability') {
                    $seen[] = $rest[0];

                    return 'edit_posts';
                }

                return $value;
            }
        );

        HomePage::addMenu();

        self::assertContains(Capability::ANALYTICS_VIEW, $seen);
        self::assertSame('edit_posts', $this->menus[0]['capability']);
    }

    // ----------------------------------------------------------- shared stylesheets

    /**
     * @dataProvider convermetryHooks
     */
    public function testTheSharedStylesheetsLoadOnEveryConvermetryScreen(string $hook): void
    {
        AdminAssets::enqueue($hook);

        self::assertSame([AdminAssets::DESIGN_SYSTEM_HANDLE, AdminAssets::COMMON_HANDLE], $this->enqueued);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function convermetryHooks(): array
    {
        return [
            'home'         => ['toplevel_page_convermetry'],
            'analytics'    => ['convermetry_page_convermetry-analytics'],
            'submissions'  => ['convermetry_page_convermetry-submissions'],
            'activity log' => ['convermetry_page_convermetry-activity'],
            'settings'     => ['convermetry_page_convermetry-settings'],
            'about'        => ['convermetry_page_convermetry-about'],
        ];
    }

    /**
     * @dataProvider foreignHooks
     */
    public function testNothingIsEnqueuedOnScreensThatAreNotOurs(string $hook): void
    {
        AdminAssets::enqueue($hook);

        self::assertSame([], $this->enqueued, $hook . ' received Convermetry assets.');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function foreignHooks(): array
    {
        return [
            'dashboard' => ['index.php'],
            'plugins'   => ['plugins.php'],
            'posts'     => ['edit.php'],
            'options'   => ['settings_page_some_other_plugin'],
            'media'     => ['upload.php'],
        ];
    }

    /**
     * The Analytics chart assets are the heaviest thing the plugin ships to
     * wp-admin, and they are useful on exactly one screen.
     */
    public function testTheDashboardAssetsStayOnTheAnalyticsScreen(): void
    {
        AnalyticsPage::enqueueAssets('toplevel_page_convermetry');
        self::assertSame([], $this->enqueued, 'The Home screen must not load the chart assets.');

        AnalyticsPage::enqueueAssets('convermetry_page_convermetry-analytics');
        self::assertSame(['cvm-analytics', 'cvm-dashboard'], $this->enqueued);
    }

    /**
     * Every admin page's own stylesheet (assets/css/admin-<page>.css) loads on
     * that page's screen and nowhere else. Each page's slug is a substring of
     * no other Convermetry slug except Home's ('convermetry'), which every
     * other slug starts with — that asymmetry is exactly what made Home's own
     * guard easy to get wrong (see the next test), and is worth pinning for
     * every page, not only Home.
     *
     * @dataProvider pageStylesheets
     */
    public function testEachPageStylesheetLoadsOnlyOnItsOwnScreen(
        string $class,
        string $ownHook,
        string $styleHandle,
    ): void {
        $class::enqueueAssets($ownHook);
        self::assertContains($styleHandle, $this->enqueued, $ownHook . ' did not load ' . $styleHandle);

        foreach (self::convermetryHooks() as [$foreignHook]) {
            if ($foreignHook === $ownHook) {
                continue;
            }

            $this->enqueued = [];
            $class::enqueueAssets($foreignHook);
            self::assertNotContains(
                $styleHandle,
                $this->enqueued,
                $styleHandle . ' leaked onto ' . $foreignHook
            );
        }
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function pageStylesheets(): array
    {
        return [
            'home'          => [HomePage::class, 'toplevel_page_convermetry', 'cvm-home'],
            'about'         => [AboutPage::class, 'convermetry_page_convermetry-about', 'cvm-about'],
            'activity log'  => [ActivityLogPage::class, 'convermetry_page_convermetry-activity', 'cvm-activity-log'],
            'settings'      => [SettingsPage::class, 'convermetry_page_convermetry-settings', 'cvm-settings'],
        ];
    }

    /**
     * The regression this pins: Home's own slug, 'convermetry', is a
     * substring of every other Convermetry slug ('convermetry-settings',
     * 'convermetry-about', …), so the str_contains() guard every other page's
     * enqueueAssets() uses would make Home's stylesheet load on every
     * Convermetry screen instead of only its own. Home's guard must be an
     * exact match against its top-level hook suffix instead.
     */
    public function testHomeStylesheetDoesNotLeakOntoScreensWhoseSlugContainsItsOwn(): void
    {
        foreach (
            [
                'convermetry_page_convermetry-settings',
                'convermetry_page_convermetry-about',
                'convermetry_page_convermetry-analytics',
                'convermetry_page_convermetry-submissions',
            ] as $hook
        ) {
            $this->enqueued = [];
            HomePage::enqueueAssets($hook);
            self::assertSame([], $this->enqueued, $hook . ' incorrectly loaded the Home stylesheet.');
        }

        HomePage::enqueueAssets('toplevel_page_convermetry');
        self::assertSame(['cvm-home'], $this->enqueued);
    }

    /**
     * The Home page is semantic HTML and CSS. If a script ever becomes
     * necessary it should be a deliberate decision, not something that arrived
     * by copy-paste.
     */
    public function testTheHomePageShipsNoJavaScript(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../../src/Admin/Pages/HomePage.php');

        self::assertStringNotContainsString('wp_enqueue_script', $source);
        self::assertStringNotContainsString('<script', $source);
        self::assertStringNotContainsString('onclick', $source);
    }

    // ------------------------------------------------------------------- icons

    /**
     * An icon name that is not in the catalogue renders nothing at all — no
     * error, no warning, just a missing glyph nobody notices in review.
     */
    public function testEveryIconTheAdminScreensAskForExists(): void
    {
        $sources = [
            (string) file_get_contents(__DIR__ . '/../../src/Admin/Pages/HomePage.php'),
            (string) file_get_contents(__DIR__ . '/../../src/Admin/HomeStatusLevel.php'),
        ];

        $names = [];
        foreach ($sources as $source) {
            preg_match_all("/Icons::render\\(\\s*'([a-z-]+)'/", $source, $rendered);
            preg_match_all("/'icon'\\s*=>\\s*'([a-z-]+)'/", $source, $configured);

            $names = array_merge($names, $rendered[1], $configured[1]);
        }

        $names = array_values(array_unique($names));

        self::assertNotSame([], $names, 'The scan found no icon names, so it is not testing anything.');

        foreach ($names as $name) {
            self::assertTrue(Icons::has($name), 'Icon "' . $name . '" is not in the catalogue.');
        }

        // The status levels choose their glyph in code rather than in markup.
        foreach (\Convermetry\Admin\HomeStatusLevel::cases() as $level) {
            self::assertTrue(Icons::has($level->icon()), $level->value . ' has no glyph.');
        }
    }

    public function testIconsAreHiddenFromAssistiveTechnology(): void
    {
        foreach (['logo', 'chart', 'check', 'warning', 'circle', 'error', 'dot', 'shield'] as $name) {
            $svg = Icons::svg($name, 16);

            self::assertStringContainsString('aria-hidden="true"', $svg, $name);
            self::assertStringContainsString('focusable="false"', $svg, $name);
        }
    }

    public function testAnUnknownIconRendersNothingRatherThanBrokenMarkup(): void
    {
        self::assertSame('', Icons::svg('no-such-icon', 16));
        self::assertFalse(Icons::has('no-such-icon'));
    }

    /**
     * The top-level admin menu icon is a base64 data URI, not a class name
     * this plugin's own CSS ever reaches — WordPress paints it as a plain
     * background-image outside the Convermetry cascade entirely (see
     * Icons::adminMenuIcon()'s docblock). So unlike every catalogue icon, its
     * colours must be literal, valid hex values baked into the SVG itself:
     * `currentColor` or a CSS class here would silently render as black.
     */
    public function testTheAdminMenuIconIsASelfContainedSvgDataUriWithNoCssDependency(): void
    {
        $uri = Icons::adminMenuIcon();

        self::assertStringStartsWith('data:image/svg+xml;base64,', $uri);

        $svg = base64_decode(substr($uri, strlen('data:image/svg+xml;base64,')), true);

        self::assertIsString($svg);
        self::assertStringContainsString('<svg', $svg);
        self::assertStringNotContainsString('currentColor', $svg, 'is resolved outside this plugin\'s CSS and would render black');
        self::assertStringNotContainsString('class=', $svg, 'no class here reaches assets/css/admin-ui.css');

        // The same rising-line-and-two-dots geometry as the 'logo' catalogue
        // entry, so the menu icon reads as the same mark used in the Home
        // page hero.
        self::assertStringContainsString('M3 15.5 L8 10.5 L12 13 L17 5.5', $svg);
        self::assertSame(2, substr_count($svg, '<circle'));
    }

    public function testHomeRegistersTheAdminMenuIconRatherThanADashicon(): void
    {
        HomePage::addMenu();

        self::assertStringStartsWith('data:image/svg+xml;base64,', $this->menus[0]['icon']);
    }

    // ------------------------------------------------------------- capability gating

    public function testALinkToAScreenTheUserCannotOpenIsNotOffered(): void
    {
        Functions\when('current_user_can')->alias(
            static fn(string $capability): bool => false
        );

        self::assertSame('', HomeStatus::urlFor(SettingsPage::MENU_SLUG, Capability::SETTINGS_MANAGE));
    }

    public function testALinkIsBuiltThroughWordPressUrlHelpers(): void
    {
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('self_admin_url')->alias(
            static fn(string $path = ''): string => 'https://example.test/wp-admin/' . $path
        );
        Functions\when('add_query_arg')->alias(
            static fn(array $args, string $url): string => $url . '?' . http_build_query($args)
        );

        self::assertSame(
            'https://example.test/wp-admin/admin.php?page=convermetry-settings',
            HomeStatus::urlFor(SettingsPage::MENU_SLUG, Capability::SETTINGS_MANAGE)
        );
    }
}
