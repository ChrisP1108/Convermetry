<?php
declare(strict_types=1);

namespace Convermetry\Admin\Pages;

if (!defined('ABSPATH')) exit;

use Convermetry\Admin\AdminAssets;
use Convermetry\Admin\Capability;
use Convermetry\Admin\HomeSetupStep;
use Convermetry\Admin\HomeStatus;
use Convermetry\Admin\HomeStatusItem;
use Convermetry\Admin\HomeStatusLevel;
use Convermetry\Admin\Icons;
use Convermetry\Forms\FormProviderRegistry;

/**
 * The top-level "Convermetry" admin page — what a site owner sees the first
 * time they click the menu item, and the hub they come back to.
 *
 * Analytics used to occupy this slot, which meant the plugin introduced itself
 * with a chart of an empty database. This page introduces it instead: what
 * Convermetry does, what state this installation is actually in, what to do
 * next, and where everything lives. Analytics keeps every one of its features
 * and moves one slug sideways to 'convermetry-analytics'; no other screen's URL
 * changes, because every one of them was already a distinct slug under this
 * parent.
 *
 * Three things are worth knowing about the implementation:
 *
 *  - NO FABRICATED DATA. Every number, state and tick on this page comes from
 *    {@see HomeStatus}, which observes the installation and reports "unknown"
 *    rather than zero when a read fails. There is no sample data anywhere in
 *    this file.
 *  - NO JAVASCRIPT. The page is semantic HTML and CSS: the setup progress is a
 *    real progressbar element, the journey diagram is an ordered list, and the
 *    only interaction is following links. Nothing needs scripting, so nothing
 *    is enqueued.
 *  - PRESENTATION ONLY. Markup and escaping live here, the design system lives
 *    in assets/css/admin-ui.css, the icons in {@see Icons}, and every fact in
 *    {@see HomeStatus}. This file decides nothing about the site.
 *
 * Links honour capabilities: a link to a screen the current user may not open
 * is not rendered at all, so the page never offers a door that would be shut in
 * their face. The card's information stays; only the link goes.
 */
final class HomePage
{
    /**
     * Menu slug for the top-level page.
     *
     * Unchanged from when Analytics held it, so 'admin.php?page=convermetry',
     * every bookmark to the plugin's front door, and any third-party link to
     * the Convermetry menu all still resolve.
     */
    public const string MENU_SLUG = 'convermetry';

    /** Anchor id for the Getting Started section. */
    private const string ANCHOR_GETTING_STARTED = 'cvm-getting-started';

    /** Anchor id for the Quick Access section. */
    private const string ANCHOR_QUICK_ACCESS = 'cvm-quick-access';

    /** Anchor id for the Learn More section. */
    private const string ANCHOR_LEARN_MORE = 'cvm-learn-more';

    /** The visit-to-lead journey: [label, node modifier]. */
    private const array JOURNEY = [
        ['Traffic Source', ''],
        ['Website Visit', ''],
        ['Landing Page', ''],
        ['Form Submission', ' cvm-ui-flow__node--accent'],
        ['Lead', ' cvm-ui-flow__node--lead'],
        ['Webhook / External System', ' cvm-ui-flow__node--teal'],
    ];

    private static ?FormProviderRegistry $registry = null;

    /**
     * Registers the menu hook.
     *
     * @param FormProviderRegistry|null $registry The shared provider registry,
     *                                            used to report which form
     *                                            integrations are active.
     * @return void
     */
    public static function init(?FormProviderRegistry $registry = null): void
    {
        self::$registry = $registry;

        add_action('admin_menu', [self::class, 'addMenu']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueueAssets']);
    }

    /**
     * Adds the top-level Convermetry menu entry and its own submenu row.
     *
     * The explicit submenu registration is what stops WordPress generating a
     * duplicate first row labelled "Convermetry"; every sibling page registers
     * itself under this slug.
     *
     * @return void
     */
    public static function addMenu(): void
    {
        add_menu_page(
            'Convermetry',
            'Convermetry',
            Capability::required(Capability::ANALYTICS_VIEW),
            self::MENU_SLUG,
            [self::class, 'render'],
            Icons::adminMenuIcon(),
            58
        );

        add_submenu_page(
            self::MENU_SLUG,
            'Convermetry',
            'Home',
            Capability::required(Capability::ANALYTICS_VIEW),
            self::MENU_SLUG,
            [self::class, 'render']
        );
    }

    /**
     * Enqueues this page's own stylesheet on this screen only. The shared
     * stylesheets (design tokens, cross-page components) are already
     * enqueued for every Convermetry screen by {@see AdminAssets}.
     *
     * An exact match against the top-level hook suffix, not the
     * str_contains(...) every other page's enqueueAssets() uses: every other
     * Convermetry slug is 'convermetry-something', of which 'convermetry' —
     * this page's own slug — is a substring. str_contains() here would match
     * every Convermetry screen and load this stylesheet globally.
     *
     * @param string $hook The current admin page hook suffix.
     * @return void
     */
    public static function enqueueAssets(string $hook): void
    {
        if ($hook !== 'toplevel_page_' . self::MENU_SLUG) {
            return;
        }

        wp_enqueue_style(
            'cvm-home',
            CVM_PLUGIN_URL . 'assets/css/admin-home.css',
            [AdminAssets::COMMON_HANDLE],
            CVM_VERSION
        );
    }

    /**
     * Renders the page.
     *
     * @return void
     */
    public static function render(): void
    {
        if (!Capability::currentUserCan(Capability::ANALYTICS_VIEW)) {
            return;
        }

        $status = HomeStatus::create(self::$registry);

        ?>
        <div class="wrap cvm-ui">
        <?php
        // WordPress relocates admin notices to just after this marker. Without
        // it they would be injected after the first heading it finds, which is
        // the <h1> inside the hero — dropping a notice into the middle of the
        // welcome panel.
        ?>
        <hr class="wp-header-end">

        <?php
        self::hero($status);
        self::valueProposition();
        self::gettingStarted($status);
        self::status($status);
        self::quickAccess();
        self::journey();
        self::dataControl();
        self::learnMore();
        self::cloud();
        self::footer();
        ?>
        </div>
        <?php
    }

    // ------------------------------------------------------------------ sections

    /**
     * The welcome panel: what Convermetry is, and this site's headline figures.
     *
     * @param HomeStatus $status The installation snapshot.
     * @return void
     */
    private static function hero(HomeStatus $status): void
    {
        $aboutUrl   = HomeStatus::urlFor(AboutPage::MENU_SLUG, Capability::ANALYTICS_VIEW);
        $tracking   = $status->analyticsTracking();
        $trackLevel = $tracking->level ?? HomeStatusLevel::Neutral;

        // The panel summarizes the site rather than naming a subsystem, so the
        // healthy case reads "Tracking active" here and "Active" on its own
        // status card. Every other state keeps the card's exact wording —
        // prefixing those would produce "Tracking Not Tracking".
        $trackLabel = $trackLevel === HomeStatusLevel::Success ? 'Tracking active' : $tracking->label;

        ?>
        <section class="cvm-ui-hero" aria-labelledby="cvm-home-title">
            <div class="cvm-ui-hero__inner">
                <div class="cvm-ui-hero__main">
                    <div class="cvm-ui-hero__brand">
                        <span class="cvm-ui-icon cvm-ui-icon--brand"><?php Icons::render('logo', 28); ?></span>
                        <span class="cvm-ui-wordmark">Convermetry<sup class="cvm-sup">TM</sup></span>
                        <span class="cvm-ui-tag">
                            <?php echo esc_html(sprintf('for WordPress · v%s', CVM_VERSION)); ?>
                        </span>
                    </div>

                    <h1 class="cvm-ui-title" id="cvm-home-title">Welcome to Convermetry</h1>
                    <p class="cvm-ui-lede">Marketing analytics and lead tracking for WordPress.</p>
                    <p class="cvm-ui-text cvm-ui-text--lg cvm-ui-measure">
                        Convermetry brings website analytics, form submissions, marketing attribution, lead
                        tracking, and delivery monitoring together inside WordPress&mdash;helping you understand
                        not only what visitors are doing, but which marketing activity is generating real leads
                        and conversions.
                    </p>

                    <div class="cvm-ui-hero__actions">
                        <a class="cvm-ui-button cvm-ui-button--primary"
                           href="#<?php echo esc_attr(self::ANCHOR_GETTING_STARTED); ?>">
                            Get Started <?php Icons::render('arrow-down', 14); ?>
                        </a>
                        <?php if ($aboutUrl !== '') : ?>
                            <a class="cvm-ui-button cvm-ui-button--secondary" href="<?php echo esc_url($aboutUrl); ?>">
                                About Convermetry
                            </a>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="cvm-ui-hero__panel">
                    <div class="cvm-ui-status-row">
                        <span class="cvm-ui-eyebrow">At a glance</span>
                        <span class="<?php echo esc_attr($trackLevel->pillClass()); ?>">
                            <?php Icons::render($trackLevel->icon(), 11); ?>
                            <?php echo esc_html($trackLabel); ?>
                        </span>
                    </div>

                    <dl class="cvm-ui-stats">
                        <?php foreach ($status->glance() as $stat) : ?>
                            <div>
                                <dt class="cvm-ui-stat__label"><?php echo esc_html($stat['label']); ?></dt>
                                <dd class="cvm-ui-stat__value<?php echo $stat['isFigure'] ? '' : ' cvm-ui-stat__value--text'; ?>">
                                    <?php echo esc_html($stat['value']); ?>
                                </dd>
                            </div>
                        <?php endforeach; ?>
                    </dl>

                    <p class="cvm-ui-hero__panel-note">
                        Values are read from this site's own collected data each time the page loads.
                    </p>
                </div>
            </div>
        </section>
        <?php
    }

    /**
     * What Convermetry is for, and the four capabilities behind it.
     *
     * @return void
     */
    private static function valueProposition(): void
    {
        $features = [
            [
                'icon'  => 'chart',
                'teal'  => false,
                'title' => 'Understand Your Visitors',
                'body'  => 'Track website activity, sessions, traffic sources, landing pages, campaign '
                    . 'information, and other useful analytics directly inside WordPress.',
                'label' => 'View Analytics',
                'url'   => HomeStatus::urlFor(AnalyticsPage::MENU_SLUG, Capability::ANALYTICS_VIEW),
            ],
            [
                'icon'  => 'document',
                'teal'  => false,
                'title' => 'Track Your Leads',
                'body'  => 'Capture supported form submissions and connect those leads with the visitor, page, '
                    . 'campaign, and attribution information that helped generate them.',
                'label' => 'View Submissions',
                'url'   => HomeStatus::urlFor(SubmissionsPage::MENU_SLUG, Capability::SUBMISSIONS_VIEW),
            ],
            [
                'icon'  => 'share',
                'teal'  => true,
                'title' => 'Deliver Your Data',
                'body'  => 'Send form submissions to external systems using configurable webhooks, field '
                    . 'mapping, delivery logging, retries, and failure handling.',
                'label' => 'Manage Integrations',
                'url'   => HomeStatus::urlFor(WebhooksPage::MENU_SLUG, Capability::WEBHOOKS_MANAGE),
            ],
            [
                'icon'  => 'code',
                'teal'  => true,
                'title' => 'Extend Convermetry',
                'body'  => 'Use WordPress hooks, filters, and extensibility features to connect additional form '
                    . 'providers, customize workflows, and integrate Convermetry with other systems.',
                'label' => 'Learn About Integrations',
                'url'   => self::aboutSection('developer'),
            ],
        ];

        ?>
        <section class="cvm-ui-section cvm-ui-section--loose" aria-labelledby="cvm-home-value">
            <div class="cvm-ui-section-header cvm-ui-section-header--split">
                <h2 class="cvm-ui-heading cvm-ui-heading--lg" id="cvm-home-value">
                    Understand what turns website visitors into leads
                </h2>
                <p class="cvm-ui-text cvm-ui-text--md cvm-ui-text--relaxed">
                    Website traffic is only part of the story. Convermetry connects visitor activity, traffic
                    sources, campaign attribution, form submissions, and lead delivery so you can better
                    understand how your marketing efforts contribute to conversions.
                </p>
            </div>

            <div class="cvm-ui-grid">
                <?php foreach ($features as $feature) : ?>
                    <article class="cvm-ui-card cvm-ui-card--interactive">
                        <span class="cvm-ui-icon<?php echo $feature['teal'] ? ' cvm-ui-icon--teal' : ''; ?>">
                            <?php Icons::render($feature['icon'], 20); ?>
                        </span>
                        <h3 class="cvm-ui-card-title"><?php echo esc_html($feature['title']); ?></h3>
                        <p class="cvm-ui-text cvm-ui-card__fill"><?php echo esc_html($feature['body']); ?></p>
                        <?php if ($feature['url'] !== '') : ?>
                            <a class="cvm-ui-link" href="<?php echo esc_url($feature['url']); ?>">
                                <?php echo esc_html($feature['label']); ?>
                                <span aria-hidden="true">&rarr;</span>
                            </a>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
        <?php
    }

    /**
     * The setup checklist, with real progress.
     *
     * The progress bar reflects steps the plugin has actually observed being
     * done; see {@see HomeSetupStep}. It is a native progressbar role with its
     * value in the accessible name, so a screen reader gets the same "3 of 4"
     * the sighted reader does.
     *
     * @param HomeStatus $status The installation snapshot.
     * @return void
     */
    private static function gettingStarted(HomeStatus $status): void
    {
        $steps     = $status->steps();
        $total     = count($steps);
        $completed = HomeStatus::completedCount($steps);
        $percent   = $total > 0 ? (int) round($completed / $total * 100) : 0;

        $expansions = array_values(array_filter([
            [
                'title' => 'Define Your Goals',
                'body'  => 'Measure valuable actions such as phone clicks, booking clicks, and visits to '
                    . 'key pages.',
                'label' => 'Manage Goals',
                'url'   => HomeStatus::urlFor(GoalsPage::MENU_SLUG, Capability::GOALS_MANAGE),
            ],
            [
                'title' => 'Build Your Funnels',
                'body'  => 'See how visitors move through a sequence of steps and where they drop off '
                    . 'before converting.',
                'label' => 'Manage Funnels',
                'url'   => HomeStatus::urlFor(FunnelsPage::MENU_SLUG, Capability::FUNNELS_MANAGE),
            ],
        ], static fn(array $expansion): bool => $expansion['url'] !== ''));

        ?>
        <section class="cvm-ui-card cvm-ui-card--panel cvm-ui-section"
                 id="<?php echo esc_attr(self::ANCHOR_GETTING_STARTED); ?>"
                 aria-labelledby="cvm-home-start">
            <div class="cvm-ui-split cvm-ui-split--baseline">
                <div class="cvm-ui-split__main cvm-ui-section-header">
                    <h2 class="cvm-ui-heading" id="cvm-home-start">Getting Started</h2>
                    <p class="cvm-ui-text cvm-ui-text--md">
                        New to Convermetry? Follow these steps to begin collecting useful marketing and lead data.
                    </p>
                </div>

                <div class="cvm-ui-progress">
                    <div class="cvm-ui-progress__label">
                        <span>Setup progress</span>
                        <span class="cvm-ui-mono">
                            <?php echo esc_html(sprintf('%d of %d steps', $completed, $total)); ?>
                        </span>
                    </div>
                    <div class="cvm-ui-progress__track"
                         role="progressbar"
                         aria-label="<?php echo esc_attr(sprintf(
                             'Setup progress: %d of %d steps completed',
                             $completed,
                             $total
                         )); ?>"
                         aria-valuenow="<?php echo esc_attr((string) $completed); ?>"
                         aria-valuemin="0"
                         aria-valuemax="<?php echo esc_attr((string) $total); ?>">
                        <?php
                        // The only inline style on the page, and it carries a
                        // value rather than a rule: the width is data. The
                        // declaration that consumes it lives in the stylesheet.
                        ?>
                        <div class="cvm-ui-progress__fill"
                             style="--cvm-ui-progress-value: <?php echo esc_attr((string) $percent); ?>%"></div>
                    </div>
                </div>
            </div>

            <ol class="cvm-ui-grid cvm-ui-grid--steps">
                <?php foreach ($steps as $index => $step) : ?>
                    <?php self::stepCard($step, $index + 1); ?>
                <?php endforeach; ?>
            </ol>

            <?php if ($expansions !== []) : ?>
                <div class="cvm-ui-section-header">
                    <h3 class="cvm-ui-heading cvm-ui-heading--xs" id="cvm-home-expand">
                        Go Further with Goals and Funnels
                    </h3>
                </div>

                <div class="cvm-ui-grid cvm-ui-grid--steps">
                    <?php foreach ($expansions as $expansion) : ?>
                        <?php self::expansionCard($expansion); ?>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
        <?php
    }

    /**
     * The status grid.
     *
     * @param HomeStatus $status The installation snapshot.
     * @return void
     */
    private static function status(HomeStatus $status): void
    {
        ?>
        <section class="cvm-ui-section cvm-ui-section--tight" aria-labelledby="cvm-home-status">
            <div class="cvm-ui-section-header">
                <h2 class="cvm-ui-heading" id="cvm-home-status">Convermetry Status</h2>
                <p class="cvm-ui-text cvm-ui-text--md cvm-ui-measure--wide">
                    Quickly verify that the major parts of Convermetry are operating as expected.
                </p>
            </div>

            <div class="cvm-ui-grid cvm-ui-grid--status">
                <?php foreach ($status->items() as $item) : ?>
                    <?php self::statusCard($item); ?>
                <?php endforeach; ?>
            </div>
        </section>
        <?php
    }

    /**
     * Shortcuts to the rest of the plugin.
     *
     * @return void
     */
    private static function quickAccess(): void
    {
        $links = [
            [
                'icon'  => 'chart-sm',
                'teal'  => false,
                'title' => 'Analytics',
                'body'  => 'View visitor activity, sessions, traffic sources, landing pages, and marketing data.',
                'url'   => HomeStatus::urlFor(AnalyticsPage::MENU_SLUG, Capability::ANALYTICS_VIEW),
            ],
            [
                'icon'  => 'document-sm',
                'teal'  => false,
                'title' => 'Submissions',
                'body'  => 'Review captured leads and associated attribution information.',
                'url'   => HomeStatus::urlFor(SubmissionsPage::MENU_SLUG, Capability::SUBMISSIONS_VIEW),
            ],
            [
                'icon'  => 'chart-sm',
                'teal'  => false,
                'title' => 'Goals',
                'body'  => 'Define conversion goals and review their performance.',
                'url'   => HomeStatus::urlFor(GoalsPage::MENU_SLUG, Capability::GOALS_MANAGE),
            ],
            [
                'icon'  => 'share',
                'teal'  => false,
                'title' => 'Funnels',
                'body'  => 'Explore conversion paths and identify where visitors drop off.',
                'url'   => HomeStatus::urlFor(FunnelsPage::MENU_SLUG, Capability::FUNNELS_MANAGE),
            ],
            [
                'icon'  => 'list-sm',
                'teal'  => false,
                'title' => 'Activity Log',
                'body'  => 'Review important Convermetry events and system activity.',
                'url'   => HomeStatus::urlFor(ActivityLogPage::MENU_SLUG, Capability::ACTIVITY_VIEW),
            ],
            [
                'icon'  => 'gear-sm',
                'teal'  => false,
                'title' => 'Settings',
                'body'  => 'Configure tracking, integrations, notifications, and plugin behavior.',
                'url'   => HomeStatus::urlFor(SettingsPage::MENU_SLUG, Capability::SETTINGS_MANAGE),
            ],
            [
                'icon'  => 'info-sm',
                'teal'  => true,
                'title' => 'About Convermetry',
                'body'  => 'Learn more about features, architecture, integrations, privacy approach, and '
                    . 'extensibility.',
                'url'   => HomeStatus::urlFor(AboutPage::MENU_SLUG, Capability::ANALYTICS_VIEW),
            ],
        ];

        $links = array_values(array_filter($links, static fn(array $link): bool => $link['url'] !== ''));

        if ($links === []) {
            return;
        }

        ?>
        <section class="cvm-ui-section cvm-ui-section--tight"
                 id="<?php echo esc_attr(self::ANCHOR_QUICK_ACCESS); ?>"
                 aria-labelledby="cvm-home-quick">
            <h2 class="cvm-ui-heading" id="cvm-home-quick">Quick Access</h2>

            <div class="cvm-ui-grid cvm-ui-grid--links">
                <?php foreach ($links as $link) : ?>
                    <a class="cvm-ui-quick-link" href="<?php echo esc_url($link['url']); ?>">
                        <span class="cvm-ui-icon cvm-ui-icon--sm<?php echo $link['teal'] ? ' cvm-ui-icon--teal' : ''; ?>">
                            <?php Icons::render($link['icon'], 16); ?>
                        </span>
                        <span class="cvm-ui-quick-link__body">
                            <span class="cvm-ui-quick-link__title">
                                <?php echo esc_html($link['title']); ?>
                                <span class="cvm-ui-quick-link__arrow<?php echo $link['teal'] ? ' cvm-ui-quick-link__arrow--teal' : ''; ?>"
                                      aria-hidden="true">&rarr;</span>
                            </span>
                            <span class="cvm-ui-text cvm-ui-text--xs"><?php echo esc_html($link['body']); ?></span>
                        </span>
                    </a>
                <?php endforeach; ?>
            </div>
        </section>
        <?php
    }

    /**
     * The visit-to-lead journey.
     *
     * An ordered list rather than a drawing: the sequence is the content, so
     * it reads correctly in a screen reader and wraps by itself on a narrow
     * screen. The arrows between nodes are decorative and hidden.
     *
     * @return void
     */
    private static function journey(): void
    {
        $last = count(self::JOURNEY) - 1;

        ?>
        <section class="cvm-ui-card cvm-ui-card--panel" aria-labelledby="cvm-home-journey">
            <h2 class="cvm-ui-heading" id="cvm-home-journey">Connect the Journey From Visit to Lead</h2>

            <ol class="cvm-ui-flow">
                <?php foreach (self::JOURNEY as $index => [$label, $modifier]) : ?>
                    <li class="cvm-ui-flow__item">
                        <span class="cvm-ui-flow__node<?php echo esc_attr($modifier); ?>">
                            <?php echo esc_html($label); ?>
                        </span>
                        <?php if ($index < $last) : ?>
                            <span class="cvm-ui-flow__arrow" aria-hidden="true">&rarr;</span>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ol>

            <p class="cvm-ui-text cvm-ui-text--relaxed cvm-ui-measure--wide">
                Convermetry connects marketing attribution with real form submissions so you can see more of the
                journey between a visitor arriving on your website and becoming a lead.
            </p>
        </section>
        <?php
    }

    /**
     * The data-control note.
     *
     * Deliberately makes no compliance claim: the plugin is a tool the site
     * configures, not a certification.
     *
     * @return void
     */
    private static function dataControl(): void
    {
        ?>
        <section class="cvm-ui-card cvm-ui-card--tinted cvm-ui-notice" aria-labelledby="cvm-home-data">
            <span class="cvm-ui-icon cvm-ui-icon--md"><?php Icons::render('shield', 18); ?></span>
            <div class="cvm-ui-notice__body">
                <h2 class="cvm-ui-heading cvm-ui-heading--xs" id="cvm-home-data">
                    Your WordPress Data, Under Your Control
                </h2>
                <p class="cvm-ui-text cvm-ui-text--relaxed cvm-ui-measure--wide">
                    Convermetry is designed to provide useful marketing and conversion insights while keeping the
                    WordPress website at the center of the data collection process. Configure the plugin according
                    to your organization's privacy, retention, and compliance requirements.
                </p>
            </div>
        </section>
        <?php
    }

    /**
     * The pointer to the in-plugin documentation.
     *
     * @return void
     */
    private static function learnMore(): void
    {
        $aboutUrl = HomeStatus::urlFor(AboutPage::MENU_SLUG, Capability::ANALYTICS_VIEW);

        ?>
        <section class="cvm-ui-card cvm-ui-card--panel cvm-ui-split"
                 id="<?php echo esc_attr(self::ANCHOR_LEARN_MORE); ?>"
                 aria-labelledby="cvm-home-learn">
            <div class="cvm-ui-split__main cvm-ui-section-header">
                <h2 class="cvm-ui-heading cvm-ui-heading--sm" id="cvm-home-learn">
                    Want to Learn More About Convermetry?
                </h2>
                <p class="cvm-ui-text cvm-ui-text--relaxed cvm-ui-measure--wide">
                    The About Convermetry page provides a more detailed explanation of analytics, attribution,
                    form tracking, submissions, webhook delivery, integrations, developer hooks, and other
                    Convermetry capabilities.
                </p>
            </div>
            <?php if ($aboutUrl !== '') : ?>
                <a class="cvm-ui-button cvm-ui-button--primary cvm-ui-split__aside"
                   href="<?php echo esc_url($aboutUrl); ?>">
                    Explore Convermetry <span aria-hidden="true">&rarr;</span>
                </a>
            <?php endif; ?>
        </section>
        <?php
    }

    /**
     * The Convermetry Cloud note.
     *
     * Kept visually subordinate on purpose — dashed, unshaded, below every
     * plugin feature — because the plugin is what the reader installed. No
     * pricing, no upgrade prompt, and no claim that it exists yet.
     *
     * @return void
     */
    private static function cloud(): void
    {
        ?>
        <section class="cvm-ui-card cvm-ui-card--quiet cvm-ui-split" aria-labelledby="cvm-home-cloud">
            <div class="cvm-ui-split__main cvm-ui-section-header">
                <h2 class="cvm-ui-heading cvm-ui-heading--xs" id="cvm-home-cloud">
                    Managing Multiple WordPress Websites?
                </h2>
                <p class="cvm-ui-text cvm-ui-text--sm cvm-ui-measure--wide">
                    Convermetry Cloud is being developed to bring analytics, leads, attribution, and reporting
                    from multiple Convermetry-powered WordPress websites into one centralized platform.
                </p>
                <p class="cvm-ui-text cvm-ui-text--xs cvm-ui-text--subtle">
                    Convermetry Cloud will be especially useful for agencies and organizations that manage
                    marketing across multiple websites.
                </p>
            </div>
            <span class="cvm-ui-badge cvm-ui-badge--neutral cvm-ui-split__aside">Coming Soon</span>
        </section>
        <?php
    }

    /**
     * The page footer.
     *
     * The design's footer carries Documentation, Support and Website links.
     * Convermetry has no published URLs for those, and inventing them would
     * ship dead links, so the one destination that does exist — the in-plugin
     * documentation — is the one that is rendered.
     *
     * @return void
     */
    private static function footer(): void
    {
        $aboutUrl = HomeStatus::urlFor(AboutPage::MENU_SLUG, Capability::ANALYTICS_VIEW);

        ?>
        <footer class="cvm-ui-footer">
            <div>
                <p class="cvm-ui-text cvm-ui-text--sm cvm-ui-text--strong">Convermetry</p>
                <p class="cvm-ui-text cvm-ui-text--xs cvm-ui-text--subtle">
                    Marketing analytics and lead tracking for WordPress.
                </p>
            </div>
            <div class="cvm-ui-footer__links">
                <?php if ($aboutUrl !== '') : ?>
                    <a class="cvm-ui-link cvm-ui-link--meta" href="<?php echo esc_url($aboutUrl); ?>">Documentation</a>
                <?php endif; ?>
                <span class="cvm-ui-mono cvm-ui-text cvm-ui-text--xs cvm-ui-text--subtle">
                    <?php echo esc_html(sprintf('Version %s', CVM_VERSION)); ?>
                </span>
            </div>
        </footer>
        <?php
    }

    // ------------------------------------------------------------- small pieces

    /**
     * One Getting Started step.
     *
     * The number tile is colour-coded, and every state also carries a word: a
     * completed step shows "Done", the next one shows "Next". Nothing here
     * depends on distinguishing green from indigo.
     *
     * @param HomeSetupStep $step   The step.
     * @param int           $number Its 1-based position.
     * @return void
     */
    private static function stepCard(HomeSetupStep $step, int $number): void
    {
        $numberClass = 'cvm-ui-step__number';
        if ($step->current) {
            $numberClass .= ' cvm-ui-step__number--current';
        } elseif (!$step->complete) {
            $numberClass .= ' cvm-ui-step__number--todo';
        }

        ?>
        <li class="cvm-ui-card cvm-ui-card--nested<?php echo $step->current ? ' cvm-ui-card--current' : ''; ?>">
            <div class="cvm-ui-step__header">
                <span class="<?php echo esc_attr($numberClass); ?>" aria-hidden="true">
                    <?php echo esc_html((string) $number); ?>
                </span>
                <h3 class="cvm-ui-card-title cvm-ui-card-title--sm">
                    <span class="screen-reader-text"><?php echo esc_html(sprintf('Step %d: ', $number)); ?></span>
                    <?php echo esc_html($step->title); ?>
                </h3>
                <?php if ($step->complete) : ?>
                    <span class="cvm-ui-badge cvm-ui-badge--success cvm-ui-step__badge">Done</span>
                <?php elseif ($step->current) : ?>
                    <span class="cvm-ui-badge cvm-ui-step__badge">Next</span>
                <?php endif; ?>
            </div>

            <p class="cvm-ui-text cvm-ui-text--sm cvm-ui-card__fill"><?php echo esc_html($step->description); ?></p>

            <?php if ($step->actionUrl !== '') : ?>
                <a class="cvm-ui-link" href="<?php echo esc_url($step->actionUrl); ?>">
                    <?php echo esc_html($step->actionLabel); ?> <span aria-hidden="true">&rarr;</span>
                </a>
            <?php endif; ?>
        </li>
        <?php
    }

    /**
     * One "Go Further with Goals and Funnels" card.
     *
     * Styled as a nested card exactly like {@see stepCard()} — same panel,
     * same visual family — but carrying an "Optional" badge in place of a
     * Done/Next one, and left out of the numbered list entirely: neither
     * counts towards setup progress, because going further than the four
     * required steps is, by definition, not required.
     *
     * @param array{title: string, body: string, label: string, url: string} $expansion One card's content.
     * @return void
     */
    private static function expansionCard(array $expansion): void
    {
        ?>
        <article class="cvm-ui-card cvm-ui-card--nested">
            <div class="cvm-ui-step__header">
                <h4 class="cvm-ui-card-title cvm-ui-card-title--sm"><?php echo esc_html($expansion['title']); ?></h4>
                <span class="cvm-ui-badge cvm-ui-badge--neutral cvm-ui-step__badge">Optional</span>
            </div>

            <p class="cvm-ui-text cvm-ui-text--sm cvm-ui-card__fill"><?php echo esc_html($expansion['body']); ?></p>

            <a class="cvm-ui-link" href="<?php echo esc_url($expansion['url']); ?>">
                <?php echo esc_html($expansion['label']); ?> <span aria-hidden="true">&rarr;</span>
            </a>
        </article>
        <?php
    }

    /**
     * One status card.
     *
     * @param HomeStatusItem $item The status.
     * @return void
     */
    private static function statusCard(HomeStatusItem $item): void
    {
        $level = $item->level;

        ?>
        <div class="cvm-ui-card cvm-ui-card--compact<?php echo esc_attr($level?->cardClass() ?? ''); ?>">
            <div class="cvm-ui-status-row">
                <h3 class="cvm-ui-card-title cvm-ui-card-title--xs"><?php echo esc_html($item->title); ?></h3>
                <?php if ($level !== null) : ?>
                    <span class="<?php echo esc_attr($level->pillClass()); ?>">
                        <?php Icons::render($level->icon(), 12); ?>
                        <?php echo esc_html($item->label); ?>
                    </span>
                <?php else : ?>
                    <span class="cvm-ui-status-value"><?php echo esc_html($item->label); ?></span>
                <?php endif; ?>
            </div>
            <p class="cvm-ui-text cvm-ui-text--sm"><?php echo esc_html($item->description); ?></p>
        </div>
        <?php
    }

    /**
     * A link to one anchored section of the About page, or '' when the current
     * user may not open it.
     *
     * @param string $anchor The About page section id.
     * @return string
     */
    private static function aboutSection(string $anchor): string
    {
        $url = HomeStatus::urlFor(AboutPage::MENU_SLUG, Capability::ANALYTICS_VIEW);

        return $url === '' ? '' : $url . '#' . $anchor;
    }
}
