<?php
declare(strict_types=1);

namespace Convermetry\Funnels;

if (!defined('ABSPATH')) exit;

use Convermetry\Settings\Options;

/**
 * Storage for funnel definitions.
 *
 * Mirrors {@see \Convermetry\Goals\GoalRepository} — one non-autoloaded option,
 * immutable ids, soft deletion. The duplication is deliberate and small: the
 * two differ in their validation, their caps, and what "active" means, and an
 * abstract base for two implementations would trade a readable class for an
 * inheritance seam nothing else can use. This codebase is uniformly final and
 * all-static; follow the pattern rather than unify it.
 *
 * Funnels are read even less often than goals: they are pure reporting
 * configuration, touched only when a funnel report is rendered. Nothing in the
 * ingestion path reads this option at all.
 */
final class FunnelRepository
{
    /** Memoized decode of the stored option, per request. */
    private static ?array $cache = null;

    /**
     * Every stored funnel, including soft-deleted ones, in configuration order.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $stored  = get_option(Options::FUNNELS_OPTION_KEY, []);
        $funnels = [];

        foreach (is_array($stored) ? $stored : [] as $raw) {
            $funnel = FunnelSettings::normalize($raw);
            if ($funnel !== null) {
                $funnels[] = $funnel;
            }
        }

        return self::$cache = $funnels;
    }

    /**
     * Funnels shown on the management screen (everything not soft-deleted).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function visible(): array
    {
        return array_values(array_filter(
            self::all(),
            static fn(array $funnel): bool => ($funnel['deleted_at'] ?? null) === null
        ));
    }

    /**
     * Funnels currently reportable.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function active(): array
    {
        return array_values(array_filter(self::all(), FunnelSettings::isActive(...)));
    }

    /**
     * One funnel by id.
     *
     * @param string $funnelId Immutable funnel id.
     * @return array<string, mixed>|null
     */
    public static function find(string $funnelId): ?array
    {
        foreach (self::all() as $funnel) {
            if ((string) $funnel['funnel_id'] === $funnelId) {
                return $funnel;
            }
        }

        return null;
    }

    /**
     * Replaces one funnel (or appends it when new) and persists.
     *
     * @param array<string, mixed> $funnel A funnel from {@see FunnelSettings::sanitize()}.
     * @return bool True when stored.
     */
    public static function save(array $funnel): bool
    {
        $funnelId = (string) ($funnel['funnel_id'] ?? '');
        if (!FunnelSettings::isValidId($funnelId)) {
            return false;
        }

        $funnels  = self::all();
        $replaced = false;
        $previous = null;

        foreach ($funnels as $index => $existing) {
            if ((string) $existing['funnel_id'] === $funnelId) {
                $previous        = $existing;
                $funnels[$index] = $funnel;
                $replaced        = true;
                break;
            }
        }

        if (!$replaced) {
            // Soft-deleted funnels are history and must not block a new one.
            if (count(self::visible()) >= FunnelSettings::MAX_FUNNELS) {
                return false;
            }

            $funnels[] = $funnel;
        }

        if (!self::persist($funnels)) {
            return false;
        }

        /**
         * Fires after a funnel definition is persisted.
         *
         * Fires from the repository rather than the admin screen, so a WP-CLI
         * command or a future REST endpoint raises the same event. Only fires
         * when the write actually succeeded.
         *
         * $previous is null for a newly created funnel and the stored definition
         * for an edit. A funnel stores no data of its own — it is a question
         * asked of existing events — so editing one changes what every past
         * report says, retroactively. That is worth knowing if you cache
         * anything derived from a funnel.
         *
         * @param string                    $funnelId Immutable funnel id.
         * @param array<string, mixed>      $funnel   The stored funnel definition.
         * @param array<string, mixed>|null $previous The definition it replaced, or null for a new funnel.
         */
        do_action('convermetry_funnel_saved', $funnelId, $funnel, $previous);

        return true;
    }

    /**
     * Soft-deletes one funnel.
     *
     * Unlike a goal, a funnel stores nothing of its own — it is a question, not
     * a record — so hard deletion would lose nothing. It is soft-deleted anyway
     * for one reason: a funnel removed by accident is otherwise unrecoverable,
     * and reconstructing an eight-step definition from memory is exactly the
     * kind of work nobody should have to redo.
     *
     * @param string $funnelId Immutable funnel id.
     * @param string $now      UTC 'Y-m-d H:i:s'.
     * @return bool
     */
    public static function softDelete(string $funnelId, string $now): bool
    {
        $funnels = self::all();
        $found   = false;

        foreach ($funnels as $index => $funnel) {
            if ((string) $funnel['funnel_id'] === $funnelId) {
                $funnels[$index]['deleted_at'] = $now;
                $funnels[$index]['enabled']    = false;
                $found                         = true;
                break;
            }
        }

        if (!$found || !self::persist($funnels)) {
            return false;
        }

        /**
         * Fires after a funnel is deleted.
         *
         * Fires only when a funnel with this id actually existed AND the write
         * succeeded — the admin screen redirects with a "deleted" notice either
         * way, so a listener here would otherwise be told about funnels that
         * were already gone.
         *
         * The deletion is soft: the definition is retained so an accidental
         * deletion is recoverable, but the funnel stops appearing in reports.
         *
         * @param string $funnelId Immutable funnel id.
         * @param string $now      UTC 'Y-m-d H:i:s' deletion timestamp.
         */
        do_action('convermetry_funnel_deleted', $funnelId, $now);

        return true;
    }

    /**
     * Writes the funnel list and clears the per-request memo.
     *
     * @param array<int, array<string, mixed>> $funnels Funnels to store.
     * @return bool
     */
    private static function persist(array $funnels): bool
    {
        self::$cache = null;

        return update_option(Options::FUNNELS_OPTION_KEY, array_values($funnels), false);
    }

    /**
     * Discards the per-request memo. For tests and long-running processes.
     *
     * @return void
     */
    public static function flushCache(): void
    {
        self::$cache = null;
    }
}
