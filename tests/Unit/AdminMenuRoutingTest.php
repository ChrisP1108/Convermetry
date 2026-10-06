<?php

declare(strict_types=1);

namespace Convermetry\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Convermetry\Admin\ActivityLogSection;
use Convermetry\Admin\AdminAssets;
use Convermetry\Admin\Capability;
use Convermetry\Admin\HomeStatus;
use Convermetry\Admin\Icons;
use Convermetry\Admin\Pages\AboutPage;
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

    /** @var array<string, list<string>> Handle => dependencies, per wp_enqueue_script(). */
    private array $scriptDeps = [];

    /** @var list<string> */
    private array $registered = [];

    /** @var array<string, array{src: string, deps: list<string>}> Handle => source and dependencies, per wp_enqueue_style(). */
    private array $styles = [];

    /** @var array<string, array{src: string, deps: list<string>}> Handle => source and dependencies, per wp_enqueue_script(). */
    private array $scripts = [];

    /** @var array<string, array<string, mixed>> JavaScript object name => data, per wp_localize_script(). */
    private array $localized = [];

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        Capability::reset();

        $this->submenus = [];
        $this->menus    = [];
        $this->enqueued   = [];
        $this->scriptDeps = [];
        $this->registered = [];
        $this->styles     = [];
        $this->scripts    = [];
        $this->localized  = [];

        Functions\when('add_action')->justReturn(true);

        // An administrator unless a test says otherwise: the Webhooks screen
        // decides its menu capability and its scripts from what the user holds.
        Functions\when('current_user_can')->justReturn(true);
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

        Functions\when('wp_enqueue_style')->alias(function (string $handle, string $src = '', array $deps = []): void {
            $this->enqueued[]        = $handle;
            $this->styles[$handle]   = ['src' => $src, 'deps' => $deps];
        });
        Functions\when('wp_enqueue_script')->alias(function (string $handle, string $src = '', array $deps = []): void {
            $this->enqueued[]          = $handle;
            $this->scriptDeps[$handle] = $deps;
            $this->scripts[$handle]    = ['src' => $src, 'deps' => $deps];
        });
        Functions\when('wp_register_script')->alias(function (string $handle): bool {
            $this->registered[] = $handle;

            return true;
        });

        // Several pages' enqueueAssets() also localizes data onto their
        // script. Recorded, because the Webhooks screen's two configuration
        // objects are part of what it promises its scripts.
        Functions\when('wp_localize_script')->alias(function (string $handle, string $name, array $data): bool {
            $this->localized[$name] = $data;

            return true;
        });
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
        self::assertSame('convermetry-settings', SettingsPage::MENU_SLUG);
        self::assertSame('convermetry-about', AboutPage::MENU_SLUG);

        // The Activity Log's own screen is retired, but its URL is still in
        // bookmarks and links: the slug is still recognised — and redirected —
        // and the section it now opens has a fixed anchor.
        self::assertSame('convermetry-activity', ActivityLogSection::LEGACY_SLUG);
        self::assertSame('cvmtry-activity-log', ActivityLogSection::ANCHOR);
    }

    public function testEveryScreenIsStillRegistered(): void
    {
        $this->buildMenu();

        $labels = array_column($this->submenus, 'label');

        foreach (
            [
                'Home', 'Analytics', 'Submissions', 'Goals', 'Funnels', 'Forms',
                'Notifications', 'Webhooks', 'Settings', 'About',
            ] as $expected
        ) {
            self::assertContains($expected, $labels, $expected . ' disappeared from the menu.');
        }
    }

    /**
     * The Activity Log is a section of Webhooks now: no second screen, and no
     * menu row that would render a duplicate of it.
     */
    public function testTheActivityLogIsNoLongerAScreenOfItsOwn(): void
    {
        $this->buildMenu();

        self::assertNotContains(ActivityLogSection::LEGACY_SLUG, array_column($this->submenus, 'slug'));
        self::assertNotContains('Activity Log', array_column($this->submenus, 'label'));
        self::assertFileDoesNotExist(__DIR__ . '/../../src/Admin/Pages/ActivityLogPage.php');
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
            'webhooks'     => ['convermetry_page_convermetry-webhooks'],
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

    // ---------------------------------------------------------- confirm prompts

    /**
     * The confirm-prompt script is registered on Convermetry screens and never
     * enqueued by AdminAssets itself: it reaches a screen only as a dependency
     * of a page script that needs it, so Home stays free of JavaScript.
     */
    public function testTheConfirmScriptIsRegisteredButNotEnqueued(): void
    {
        foreach (self::convermetryHooks() as [$hook]) {
            $this->enqueued   = [];
            $this->registered = [];

            AdminAssets::enqueue($hook);

            self::assertSame([AdminAssets::CONFIRM_HANDLE], $this->registered, $hook . ' did not register the confirm script.');
            self::assertNotContains(AdminAssets::CONFIRM_HANDLE, $this->enqueued, $hook . ' enqueued the confirm script directly.');
        }

        foreach (self::foreignHooks() as [$hook]) {
            $this->registered = [];
            AdminAssets::enqueue($hook);
            self::assertSame([], $this->registered, $hook . ' received the confirm script.');
        }
    }

    /**
     * Each screen with a Remove or Clear All action loads the confirm script
     * through its own page script, so the prompt cannot silently disappear.
     *
     * @dataProvider destructiveScreens
     */
    public function testScreensWithDestructiveActionsDependOnTheConfirmScript(
        string $class,
        string $hook,
        string $scriptHandle,
    ): void {
        Functions\when('self_admin_url')->alias(static fn(string $path = ''): string => 'https://example.test/wp-admin/' . $path);
        Functions\when('add_query_arg')->alias(static fn(array $args, string $url): string => $url . '?' . http_build_query($args));
        Functions\when('wp_nonce_url')->alias(static fn(string $url): string => $url);
        // The Funnels screen hands its script the configured goals.
        Functions\when('get_option')->alias(static fn(string $name, mixed $default = false): mixed => $default);

        $class::enqueueAssets($hook);

        self::assertArrayHasKey($scriptHandle, $this->scriptDeps, $hook . ' did not enqueue ' . $scriptHandle);
        self::assertContains(AdminAssets::CONFIRM_HANDLE, $this->scriptDeps[$scriptHandle]);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function destructiveScreens(): array
    {
        return [
            'goals'        => [GoalsPage::class, 'convermetry_page_convermetry-goals', 'cvmtry-goals'],
            'funnels'      => [FunnelsPage::class, 'convermetry_page_convermetry-funnels', 'cvmtry-funnels'],
            'webhooks'     => [WebhooksPage::class, 'convermetry_page_convermetry-webhooks', 'cvmtry-webhooks'],
            'submissions'  => [SubmissionsPage::class, 'convermetry_page_convermetry-submissions', 'cvmtry-submissions'],
        ];
    }

    /**
     * Admin screens print no script or style blocks and no inline event
     * handlers: every script and stylesheet is an enqueued file, and
     * confirmations go through data-cvmtry-confirm.
     */
    public function testAdminScreensPrintNoInlineScriptsStylesOrHandlers(): void
    {
        $files = glob(__DIR__ . '/../../src/Admin/{,Pages/}*.php', GLOB_BRACE) ?: [];
        self::assertNotEmpty($files);

        foreach ($files as $file) {
            $source = (string) file_get_contents($file);

            self::assertDoesNotMatchRegularExpression('~<(script|style|noscript)\b~i', $source, basename($file) . ' prints an inline block.');
            self::assertDoesNotMatchRegularExpression('~\son(click|submit|change|load|input)=~i', $source, basename($file) . ' uses an inline event handler.');
        }
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
        self::assertSame(['cvmtry-analytics', 'cvmtry-dashboard'], $this->enqueued);
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
            'home'          => [HomePage::class, 'toplevel_page_convermetry', 'cvmtry-home'],
            'about'         => [AboutPage::class, 'convermetry_page_convermetry-about', 'cvmtry-about'],
            'webhooks'      => [WebhooksPage::class, 'convermetry_page_convermetry-webhooks', 'cvmtry-webhooks'],
            'settings'      => [SettingsPage::class, 'convermetry_page_convermetry-settings', 'cvmtry-settings'],
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
        self::assertSame(['cvmtry-home'], $this->enqueued);
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

    // ------------------------------------------------- Webhooks + its Activity Log

    /**
     * Makes the current user hold exactly these scopes. Each scope is mapped
     * to its own capability through convermetry_admin_capability — the way a
     * site delegates one — and the user holds only those capabilities.
     *
     * @param list<string> $scopes
     */
    private function grantOnly(array $scopes): void
    {
        $capabilityOf = static fn(string $scope): string => 'cvmtry_test_' . strtr($scope, '.', '_');
        $held         = array_map($capabilityOf, $scopes);

        Functions\when('apply_filters')->alias(
            static fn(string $hook, mixed $value, mixed ...$rest): mixed => $hook === 'convermetry_admin_capability'
                ? $capabilityOf((string) $rest[0])
                : $value
        );
        Functions\when('current_user_can')->alias(
            static fn(string $capability): bool => in_array($capability, $held, true)
        );

        Capability::reset();
    }

    /**
     * Who gets which script, and which nonces, on the combined screen.
     *
     * admin.js drives the settings form and is the screen's for
     * webhooks.manage; admin-webhooks.js drives the Activity Log and is the
     * screen's for activity.view. A nonce is issued only for what the user's
     * scopes allow — but every key is always there.
     *
     * @return array<string, array{0: list<string>|null, 1: list<string>, 2: list<string>}>
     */
    public static function webhooksAudiences(): array
    {
        return [
            // scopes held (null: administrator), scripts, CVMTRY_LOG nonces issued
            'administrator'      => [null, ['cvmtry-admin', 'cvmtry-webhooks'], ['logsNonce', 'deleteNonce', 'apiToggleNonce', 'apiRegenNonce']],
            'webhooks only'      => [[Capability::WEBHOOKS_MANAGE], ['cvmtry-admin'], []],
            'log viewer'         => [[Capability::ACTIVITY_VIEW], ['cvmtry-webhooks'], ['logsNonce']],
            'log manager'        => [[Capability::ACTIVITY_VIEW, Capability::ACTIVITY_MANAGE], ['cvmtry-webhooks'], ['logsNonce', 'deleteNonce']],
            'log viewer with API' => [[Capability::ACTIVITY_VIEW, Capability::API_MANAGE], ['cvmtry-webhooks'], ['logsNonce', 'apiToggleNonce', 'apiRegenNonce']],
        ];
    }

    /**
     * @dataProvider webhooksAudiences
     *
     * @param list<string>|null $scopes
     * @param list<string>      $expectedScripts
     * @param list<string>      $issuedNonces
     */
    public function testTheWebhooksScreenLoadsEachAssetOnceForWhatTheUserMayUse(?array $scopes, array $expectedScripts, array $issuedNonces): void
    {
        if ($scopes !== null) {
            $this->grantOnly($scopes);
        }

        WebhooksPage::enqueueAssets('convermetry_page_convermetry-webhooks');

        // One stylesheet carries both sections; it still sits on the shared one.
        self::assertSame(['cvmtry-webhooks'], array_keys($this->styles));
        self::assertSame('https://example.test/wp-content/plugins/convermetry/assets/css/admin-webhooks.css', $this->styles['cvmtry-webhooks']['src']);
        self::assertSame([AdminAssets::COMMON_HANDLE], $this->styles['cvmtry-webhooks']['deps']);

        self::assertSame($expectedScripts, array_keys($this->scripts));
        // The style and script maps are keyed by handle, so an asset enqueued
        // twice would leave more calls than entries.
        self::assertCount(count($this->styles) + count($this->scripts), $this->enqueued, 'An asset was enqueued twice.');
        self::assertNotContains('cvmtry-activity-log', $this->enqueued, 'The retired handle is still enqueued.');

        if (in_array('cvmtry-admin', $expectedScripts, true)) {
            self::assertSame('https://example.test/wp-content/plugins/convermetry/assets/js/admin.js', $this->scripts['cvmtry-admin']['src']);
            self::assertSame(['wp-i18n'], $this->scripts['cvmtry-admin']['deps']);
            self::assertSame(['ajaxUrl', 'testNonce'], array_keys($this->localized['CVMTRY_ADMIN']));
        } else {
            self::assertArrayNotHasKey('CVMTRY_ADMIN', $this->localized, 'The settings script\'s nonce reached a user without webhooks.manage.');
        }

        if (!in_array('cvmtry-webhooks', $expectedScripts, true)) {
            self::assertArrayNotHasKey('CVMTRY_LOG', $this->localized);

            return;
        }

        self::assertSame('https://example.test/wp-content/plugins/convermetry/assets/js/admin-webhooks.js', $this->scripts['cvmtry-webhooks']['src']);
        self::assertSame(['wp-i18n', AdminAssets::CONFIRM_HANDLE], $this->scripts['cvmtry-webhooks']['deps']);

        $log = $this->localized['CVMTRY_LOG'];
        self::assertSame(['ajaxUrl', 'logsNonce', 'deleteNonce', 'apiToggleNonce', 'apiRegenNonce', 'monthNames'], array_keys($log));
        self::assertSame('https://example.test/wp-admin/admin-ajax.php', $log['ajaxUrl']);
        self::assertCount(12, $log['monthNames']);

        foreach (['logsNonce', 'deleteNonce', 'apiToggleNonce', 'apiRegenNonce'] as $key) {
            self::assertSame(in_array($key, $issuedNonces, true) ? 'nonce' : '', $log[$key], $key);
        }
    }

    /**
     * The screen opens for webhooks.manage OR activity.view. add_submenu_page()
     * takes one capability, used both to show the row and to open the page, so
     * it is registered with the capability of a scope the user holds.
     *
     * @return array<string, array{0: list<string>, 1: string}>
     */
    public static function webhooksMenuAudiences(): array
    {
        return [
            'webhooks only'  => [[Capability::WEBHOOKS_MANAGE], 'cvmtry_test_webhooks_manage'],
            'log viewer'     => [[Capability::ACTIVITY_VIEW], 'cvmtry_test_activity_view'],
            'both'           => [[Capability::ACTIVITY_VIEW, Capability::WEBHOOKS_MANAGE], 'cvmtry_test_webhooks_manage'],
            // Neither: registered behind webhooks.manage, which hides it.
            'neither'        => [[Capability::ACTIVITY_MANAGE, Capability::API_MANAGE], 'cvmtry_test_webhooks_manage'],
        ];
    }

    /**
     * @dataProvider webhooksMenuAudiences
     *
     * @param list<string> $scopes
     */
    public function testTheWebhooksMenuOpensForWebhookSettingsOrLogViewing(array $scopes, string $expectedCapability): void
    {
        $this->grantOnly($scopes);

        WebhooksPage::addMenu();

        self::assertSame(WebhooksPage::MENU_SLUG, $this->submenus[0]['slug']);
        self::assertSame($expectedCapability, $this->submenus[0]['capability']);
        self::assertSame(
            in_array(Capability::WEBHOOKS_MANAGE, $scopes, true) || in_array(Capability::ACTIVITY_VIEW, $scopes, true),
            WebhooksPage::currentUserCanView()
        );
    }

    /**
     * The handlers moved with the log, and are registered by Webhooks' own
     * init() — which Plugin::init() runs on every admin request — so they
     * answer AJAX and admin-post requests without the screen being rendered.
     */
    public function testWebhooksRegistersEveryActivityLogHandlerExactlyOnce(): void
    {
        $hooks = [];
        Functions\when('add_action')->alias(
            static function (string $hook, mixed $callback, int $priority = 10) use (&$hooks): bool {
                $hooks[$hook][] = ['callback' => $callback, 'priority' => $priority];

                return true;
            }
        );

        WebhooksPage::init();

        foreach (
            [
                'admin_post_cvmtry_clear_activity_logs'  => 'processClearLogs',
                'admin_post_cvmtry_activity_export_csv'  => 'processExportCsv',
                'admin_post_cvmtry_activity_export_json' => 'processExportJson',
                'wp_ajax_cvmtry_get_activity_logs'       => 'handleGetLogsAjax',
                'wp_ajax_cvmtry_delete_activity_log'     => 'handleDeleteLogAjax',
                'wp_ajax_cvmtry_toggle_delivery_api'     => 'handleApiToggleAjax',
                'wp_ajax_cvmtry_regen_delivery_api_key'  => 'handleApiRegenKeyAjax',
            ] as $hook => $method
        ) {
            self::assertCount(1, $hooks[$hook] ?? [], $hook . ' is not registered exactly once.');
            self::assertSame([ActivityLogSection::class, $method], $hooks[$hook][0]['callback'], $hook);
        }

        // The legacy redirect runs on admin_menu ahead of every menu
        // registration, because wp-admin refuses an unknown slug straight
        // after admin_menu — before admin_init would ever fire.
        self::assertContains(
            ['callback' => [ActivityLogSection::class, 'redirectLegacyScreen'], 'priority' => 0],
            $hooks['admin_menu']
        );

        // The log's assets come through Webhooks' one enqueue callback.
        self::assertCount(1, $hooks['admin_enqueue_scripts']);
    }

    private function stubAdminUrls(): void
    {
        Functions\when('self_admin_url')->alias(static fn(string $path = ''): string => 'https://example.test/wp-admin/' . $path);
        Functions\when('add_query_arg')->alias(static fn(array $args, string $url): string => $url . '?' . http_build_query($args));
    }

    public function testTheRetiredScreenIsSentToTheLogSection(): void
    {
        $this->stubAdminUrls();

        self::assertSame(
            'https://example.test/wp-admin/admin.php?page=convermetry-webhooks#cvmtry-activity-log',
            ActivityLogSection::legacyRedirectTarget('convermetry-activity')
        );

        foreach (['convermetry-webhooks', 'convermetry', 'convermetry-activity-log', ''] as $other) {
            self::assertSame('', ActivityLogSection::legacyRedirectTarget($other), $other . ' was redirected.');
        }
    }

    /**
     * Only someone who could open the old screen is redirected; anyone else
     * gets WordPress's own refusal, as before.
     *
     * @return array<string, array{0: list<string>, 1: bool}>
     */
    public static function legacyAudiences(): array
    {
        return [
            'log viewer'       => [[Capability::ACTIVITY_VIEW], true],
            'webhooks only'    => [[Capability::WEBHOOKS_MANAGE], false],
            'log manager only' => [[Capability::ACTIVITY_MANAGE], false],
            'nobody'           => [[], false],
        ];
    }

    /**
     * @dataProvider legacyAudiences
     *
     * @param list<string> $scopes
     */
    public function testTheLegacyRedirectIsPermissionAware(array $scopes, bool $redirected): void
    {
        $this->stubAdminUrls();
        $this->grantOnly($scopes);

        self::assertSame($redirected, ActivityLogSection::legacyRedirectTarget(ActivityLogSection::LEGACY_SLUG) !== '');
    }

    public function testTheLegacyRedirectLeavesEveryOtherRequestAlone(): void
    {
        Functions\when('sanitize_key')->alias(static fn(string $key): string => strtolower($key));
        Functions\when('wp_unslash')->returnArg();
        Functions\expect('wp_safe_redirect')->never();

        $saved = [$GLOBALS['pagenow'] ?? null, $_GET];

        try {
            foreach (
                [
                    ['admin.php', ['page' => 'convermetry-webhooks']],
                    ['admin.php', ['page' => ['convermetry-activity']]],
                    ['admin.php', []],
                    ['edit.php', ['page' => 'convermetry-activity']],
                ] as [$pagenow, $get]
            ) {
                $GLOBALS['pagenow'] = $pagenow;
                $_GET               = $get;

                ActivityLogSection::redirectLegacyScreen();
            }
        } finally {
            [$GLOBALS['pagenow'], $_GET] = $saved;
        }

        self::assertTrue(true);
    }

    /**
     * Home's Activity Log card and a submission's delivery block open the
     * section, are built through WordPress's URL helpers, and are offered only
     * to someone who may view the log.
     */
    public function testLinksToTheLogOpenTheSectionForThoseWhoMayViewIt(): void
    {
        $this->stubAdminUrls();

        self::assertSame(
            'https://example.test/wp-admin/admin.php?page=convermetry-webhooks#cvmtry-activity-log',
            HomeStatus::urlFor(WebhooksPage::MENU_SLUG, Capability::ACTIVITY_VIEW, ActivityLogSection::ANCHOR)
        );
        self::assertSame(
            ActivityLogSection::url(),
            HomeStatus::urlFor(WebhooksPage::MENU_SLUG, Capability::ACTIVITY_VIEW, ActivityLogSection::ANCHOR)
        );

        $this->grantOnly([Capability::WEBHOOKS_MANAGE]);
        self::assertSame('', HomeStatus::urlFor(WebhooksPage::MENU_SLUG, Capability::ACTIVITY_VIEW, ActivityLogSection::ANCHOR));

        foreach (['HomePage.php', 'SubmissionsPage.php'] as $file) {
            $source = (string) file_get_contents(__DIR__ . '/../../src/Admin/Pages/' . $file);

            self::assertStringContainsString(
                'HomeStatus::urlFor(WebhooksPage::MENU_SLUG, Capability::ACTIVITY_VIEW, ActivityLogSection::ANCHOR)',
                $source,
                $file . ' does not link to the section.'
            );
        }
    }

    /**
     * Nothing still points at the retired screen, stylesheet, or script —
     * except the redirect that catches the old slug, and the privacy exporter,
     * whose 'convermetry-activity' is an export group id, not a URL.
     */
    public function testNothingStillReferencesTheRetiredScreenOrItsAssets(): void
    {
        $root = dirname(__DIR__, 2);

        self::assertFileDoesNotExist($root . '/assets/css/admin-activity-log.css');
        self::assertFileDoesNotExist($root . '/assets/js/activity-log.js');
        self::assertFileExists($root . '/assets/js/admin-webhooks.js');
        self::assertFileExists($root . '/assets/js/admin.js');

        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/src', \FilesystemIterator::SKIP_DOTS));
        $slugUsers = [];

        foreach ($files as $file) {
            $source   = (string) file_get_contents((string) $file);
            $relative = substr((string) $file, strlen($root) + 1);

            foreach (['ActivityLogPage', 'activity-log.js', 'admin-activity-log.css'] as $retired) {
                self::assertStringNotContainsString($retired, $source, $relative . ' still mentions ' . $retired);
            }

            if (str_contains($source, "'convermetry-activity")) {
                $slugUsers[] = $relative;
            }
        }

        sort($slugUsers);
        self::assertSame(['src/Admin/ActivityLogSection.php', 'src/Privacy/PersonalDataExporter.php'], $slugUsers);
    }

    /**
     * .cvmtry-delivery-wrap gives the Deliveries API card the log's tighter
     * card styling. It is on the log section only, so the Webhooks settings
     * cards above it keep theirs.
     */
    public function testTheLogCardStylingIsScopedToTheLogSection(): void
    {
        $root = dirname(__DIR__, 2);
        $css  = (string) file_get_contents($root . '/assets/css/admin-webhooks.css');

        foreach (['.cvmtry-delivery-wrap .cvmtry-card {', '.cvmtry-api-key-row {', '.cvmtry-log-item {', '.cvmtry-log-headers pre {', '.cvmtry-endpoint-tests {'] as $rule) {
            self::assertStringContainsString($rule, $css);
        }

        self::assertStringNotContainsString('cvmtry-delivery-wrap', (string) file_get_contents($root . '/src/Admin/Pages/WebhooksPage.php'));
        self::assertSame(1, substr_count((string) file_get_contents($root . '/src/Admin/ActivityLogSection.php'), 'class="cvmtry-delivery-wrap"'));
        self::assertStringContainsString(
            '<section id="<?php echo esc_attr(self::ANCHOR); ?>" class="cvmtry-delivery-wrap"',
            (string) file_get_contents($root . '/src/Admin/ActivityLogSection.php')
        );
    }

    /**
     * The log script shares the screen with admin.js and the settings form,
     * so it looks only inside its own section.
     */
    public function testTheLogScriptIsScopedToItsSection(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/assets/js/admin-webhooks.js');

        self::assertStringContainsString("document.getElementById('cvmtry-activity-log')", $source);
        self::assertStringNotContainsString('document.querySelector', $source, 'A document-wide query could reach the settings form.');
        self::assertStringNotContainsString("document.addEventListener('cvmtry:log-deleted'", $source);
        self::assertStringContainsString('CVMTRY_LOG', $source);
        self::assertStringNotContainsString('CVMTRY_ADMIN', $source);

        foreach (['cvmtry_get_activity_logs', 'cvmtry_delete_activity_log', 'cvmtry_toggle_delivery_api', 'cvmtry_regen_delivery_api_key'] as $action) {
            self::assertStringContainsString("'" . $action . "'", $source);
        }
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
