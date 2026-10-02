<?php
declare(strict_types=1);

namespace Convermetry\Forms\Providers;

if (!defined('ABSPATH')) exit;

use Convermetry\Forms\Bricks\BricksFormsBridge;
use Convermetry\Forms\FormProviderInterface;
use Convermetry\Forms\SubmissionService;

/**
 * Bricks Builder native Form element integration.
 *
 * THE ONE THING THAT MAKES THIS PROVIDER DIFFERENT FROM EVERY OTHER ONE:
 * Bricks is a THEME. Convermetry initialises on plugins_loaded, and WordPress
 * loads a theme's functions.php only after that has finished — so at the moment
 * the registry first asks every provider whether it is available, a site
 * running Bricks answers "no", truthfully. Registering on that answer would mean
 * a Bricks site silently never gets its hooks.
 *
 * {@see \Convermetry\Forms\FormProviderRegistry::registerHooks()} therefore runs
 * a second, idempotent pass on after_setup_theme, by which point the theme —
 * parent or child, since a Bricks child theme still loads the parent's
 * functions.php — has defined BRICKS_VERSION. Every provider that was already
 * registered on plugins_loaded is skipped by key, so existing provider timing is
 * untouched and nothing is wired twice.
 *
 * SCOPE IS THE NATIVE BRICKS FORM ELEMENT, and nothing else. Third-party Bricks
 * form add-ons ship their own elements, their own storage and their own submit
 * paths; none of them is discovered here and none is claimed anywhere in the
 * plugin's documentation.
 *
 * Like the Elementor Atomic provider, capture is OPT-IN PER FORM: a Bricks form
 * runs the actions its owner selected under "Actions after successful form
 * submit", so nothing is captured until "Convermetry" is one of them. Discovery
 * is deliberately independent of that — an administrator has to be able to see
 * and configure a form before wiring it up — which is why the Forms screen
 * states, beside these rows, that listing a form is not capture.
 */
final class BricksFormsProvider implements FormProviderInterface
{
    /** The bridge instance whose hooks were registered, for reuse and for tests. */
    private ?BricksFormsBridge $bridge = null;

    public function getKey(): string
    {
        return BricksFormsBridge::PROVIDER_KEY;
    }

    public function getLabel(): string
    {
        return 'Bricks Builder';
    }

    /**
     * Whether a supported Bricks theme is loaded RIGHT NOW.
     *
     * Two conditions, not one. Bricks must be loaded (its version constant is
     * defined — by the parent theme's functions.php, so a child theme counts),
     * and it must be new enough to dispatch named custom form actions. On an
     * older Bricks the editor choice would save an action nothing ever
     * dispatches, which is worse than offering no choice at all, so this reports
     * unavailable and the provider registers nothing.
     *
     * Deliberately evaluated on every call rather than memoized: the answer
     * genuinely changes between plugins_loaded and after_setup_theme, and this
     * method is what the registry's deferred pass re-asks.
     *
     * @return bool
     */
    public function isAvailable(): bool
    {
        return BricksFormsBridge::isSupported();
    }

    /**
     * Discovers every native Bricks form element on the site.
     *
     * Runs against Bricks' three content-area meta keys directly, for the same
     * reason the Elementor providers do: a get_posts() with post_type 'any'
     * searches only PUBLIC post types and would silently skip bricks_template,
     * where header, footer, popup and reusable-section forms live. Joining
     * wp_posts instead means every post type that stores Bricks content is
     * covered — pages, posts, custom types and templates — while revisions,
     * trashed posts and auto-drafts are excluded, because none of those is a
     * form anyone can submit.
     *
     * Values are read with get_post_meta(), so WordPress unserializes them: the
     * stored arrays are never unserialized by hand.
     *
     * Results are deduplicated by element id across every document and every
     * content area, so a template rendered site-wide is one row rather than one
     * row per page — matching the identity a submission from it arrives under.
     *
     * The registry caches this for five minutes per provider
     * ({@see \Convermetry\Forms\FormProviderRegistry::discoveredForms()}), which
     * is what keeps a postmeta scan off every admin page load; nothing here
     * caches separately.
     *
     * @return array<int, array{native_id: string, name: string}>
     */
    public function getForms(): array
    {
        $forms = [];

        foreach ($this->documentIds() as $postId) {
            foreach (BricksFormsBridge::CONTENT_META_KEYS as $metaKey) {
                $elements = get_post_meta($postId, $metaKey, true);

                if (is_array($elements)) {
                    BricksFormsBridge::collectForms($elements, $forms);
                }
            }
        }

        return array_values($forms);
    }

    /**
     * The posts that hold Bricks content and can actually be submitted from.
     *
     * TWO QUERIES RATHER THAN ONE JOIN, deliberately. A single joined statement
     * leaves the row order to the optimizer, and on a site whose postmeta holds
     * no Bricks keys at all it will happily drive from wp_posts and scan every
     * row there. Split, both halves are index-driven whatever the statistics
     * say: the first is a narrow range scan of postmeta's meta_key index over
     * three exact values, and the second is a primary-key lookup of the handful
     * of ids that came back.
     *
     * REVISIONS, TRASH AND AUTO-DRAFTS ARE EXCLUDED. WordPress copies post meta
     * onto revisions, so a document saved fifty times would otherwise be walked
     * fifty times and a form deleted from a page would keep being discovered
     * from the revision that still has it.
     *
     * @return list<int> Post ids, deduplicated.
     */
    private function documentIds(): array
    {
        global $wpdb;

        $metaKeys = BricksFormsBridge::CONTENT_META_KEYS;

        /** @var string[] $candidates */
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- form discovery on admin screens; the result is cached by FormProviderRegistry::discoveredForms().
        $candidates = $wpdb->get_col($wpdb->prepare(
            'SELECT DISTINCT post_id FROM %i WHERE meta_key IN (' . implode(', ', array_fill(0, count($metaKeys), '%s')) . ')',
            array_merge([$wpdb->postmeta], $metaKeys)
        ));

        $candidates = array_values(array_unique(array_filter(
            array_map(static fn(mixed $id): int => (int) $id, (array) $candidates),
            static fn(int $id): bool => $id > 0
        )));

        if ($candidates === []) {
            return [];
        }

        /** @var string[] $postIds */
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- form discovery on admin screens; the result is cached by FormProviderRegistry::discoveredForms().
        $postIds = $wpdb->get_col($wpdb->prepare(
            'SELECT ID FROM %i WHERE ID IN (' . implode(', ', array_fill(0, count($candidates), '%d')) . ')'
            . " AND post_type <> 'revision' AND post_status NOT IN ('trash', 'auto-draft')",
            array_merge([$wpdb->posts], $candidates)
        ));

        return array_values(array_map(static fn(mixed $id): int => (int) $id, (array) $postIds));
    }

    /**
     * Registers the editor choice, its control group, the submission action and
     * the rendered tracking attributes.
     *
     * Nothing here touches a Bricks class: the callbacks duck-type the form
     * object Bricks hands them, once Bricks calls them.
     * {@see BricksFormsBridge::register()} is idempotent, so the registry's
     * deferred theme pass, a test, or a plugin re-initialising adds no duplicate
     * hooks.
     *
     * @param SubmissionService $service The shared submission pipeline.
     * @return void
     */
    public function registerHooks(SubmissionService $service): void
    {
        $this->bridge ??= new BricksFormsBridge($service);
        $this->bridge->register();
    }
}
