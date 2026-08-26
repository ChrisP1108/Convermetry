<?php
declare(strict_types=1);

namespace Convermetry\Goals;

if (!defined('ABSPATH')) exit;

use Convermetry\Database\PreparedEvent;

/**
 * Decides which configured goals an incoming event completes.
 *
 * PURE. No $wpdb, no options, no WordPress state, no output — goals are passed
 * in, an event envelope is passed in, goal ids come out. That is what makes the
 * matching rules directly testable rather than something only observable by
 * watching a database fill up.
 *
 * MATCHING RUNS ON THE SERVER, AGAINST DATA THE TRACKER ALREADY SENDS.
 *
 * The tracker is not told what the goals are, with one unavoidable exception.
 * That has three consequences worth stating, because they are the reason the
 * design is shaped this way:
 *
 *  - Goal configuration is not published to every visitor. A site's list of
 *    "valuable actions" is competitive information and it stays server-side.
 *  - Phone and email goals need NO configuration beyond picking them. The
 *    tracker already reports click destinations, and already keeps `tel:` and
 *    `mailto:` URLs whole while stripping query strings from everything else.
 *    A marketer never has to learn a CSS selector to count phone taps, which was
 *    the single most important usability requirement here.
 *  - A malicious client cannot manufacture a conversion by claiming one. It can
 *    only report the same raw activity any visitor reports, and the server
 *    decides what that means.
 *
 * THE EXCEPTION is a CSS selector, which genuinely cannot be evaluated without
 * the DOM. Selector goals — and only selector goals — have their selectors sent
 * to the tracker, which reports back the goal ids it matched. Those ids are
 * re-validated here against the enabled selector goals before anything is
 * recorded, so a client can at most claim a goal that really is configured as a
 * selector goal and really is enabled. It cannot invent one, cannot reach a
 * URL or custom-event goal this way, and cannot supply its own value.
 *
 * URL MATCHING COMPARES PATHS WHEN THE RULE LOOKS LIKE A PATH. A site owner
 * types "/thank-you/", not "https://www.example.com/thank-you/", and both should
 * work — so a rule beginning with "/" is compared against the stored URL's path
 * and a rule that names a host is compared against the whole URL.
 */
final class GoalMatcher
{
    /** Maximum goals one event may complete, however many rules it satisfies. */
    public const int MAX_MATCHES_PER_EVENT = 5;

    /**
     * Returns the goals an event completes, in the order they were configured.
     *
     * Order is deliberate rather than incidental: it is what makes the
     * {@see MAX_MATCHES_PER_EVENT} cut deterministic. An event satisfying six
     * rules must complete the same five every time, on every request and every
     * replay, or a retried batch would record a different set than the original
     * and the two would not deduplicate.
     *
     * @param PreparedEvent                          $event The event being stored.
     * @param array<int, array<string, mixed>>       $goals Normalized goals, in configuration order.
     * @return array{matched: list<array<string, mixed>>, overflow: int} Matched goals and
     *         the number dropped by the cap.
     */
    public static function match(PreparedEvent $event, array $goals): array
    {
        $matched = [];
        $type    = $event->type();

        foreach ($goals as $goal) {
            if (!is_array($goal) || !GoalSettings::isActive($goal)) {
                continue;
            }

            if (GoalSettings::requiredEventType($goal) !== $type) {
                continue;
            }

            if (self::matchesGoal($event, $goal)) {
                $matched[] = $goal;
            }
        }

        $overflow = max(0, count($matched) - self::MAX_MATCHES_PER_EVENT);

        return [
            'matched'  => array_slice($matched, 0, self::MAX_MATCHES_PER_EVENT),
            'overflow' => $overflow,
        ];
    }

    /**
     * Whether one event satisfies one goal's rule.
     *
     * @param PreparedEvent        $event The event being stored.
     * @param array<string, mixed> $goal  A normalized, active goal.
     * @return bool
     */
    private static function matchesGoal(PreparedEvent $event, array $goal): bool
    {
        // Every field is read with a fallback. Goals arrive from a stored option
        // that a filter, WP-CLI, or a partially-written migration could have
        // left incomplete, and a matcher that emits a PHP warning on a
        // half-formed rule would turn a configuration mistake into broken
        // ingestion for every event in the batch.
        return match ((string) ($goal['type'] ?? '')) {
            'url'          => self::matchesUrl($event, $goal),
            'click'        => self::matchesClick($event, $goal),
            'custom_event' => self::matchesCustomEvent($event, $goal),
            default        => false,
        };
    }

    /**
     * Whether a pageview reached the page a URL goal names.
     *
     * @param PreparedEvent        $event The event being stored.
     * @param array<string, mixed> $goal  A normalized url goal.
     * @return bool
     */
    private static function matchesUrl(PreparedEvent $event, array $goal): bool
    {
        $pattern = (string) ($goal['value'] ?? '');
        $pageUrl = $event->column('page_url');

        if ($pattern === '' || $pageUrl === '') {
            return false;
        }

        // A rule written as a path is compared against the path; a rule naming a
        // host is compared against the whole URL. Anything else would make
        // "/pricing/" fail against "https://example.com/pricing/", which is what
        // every site owner will type.
        $subject = str_starts_with($pattern, '/') ? self::pathOf($pageUrl) : $pageUrl;

        return self::compare($subject, $pattern, (string) ($goal['operator'] ?? ''));
    }

    /**
     * Whether a click satisfies a click goal.
     *
     * @param PreparedEvent        $event The event being stored.
     * @param array<string, mixed> $goal  A normalized click goal.
     * @return bool
     */
    private static function matchesClick(PreparedEvent $event, array $goal): bool
    {
        $operator = (string) ($goal['operator'] ?? '');
        $target   = $event->column('target_url');

        // The browser-resolved case. The claim is only honored for a goal that
        // really is a selector goal — reached via this branch only — so a client
        // reporting the id of a URL goal achieves nothing.
        if ($operator === GoalSettings::BROWSER_OPERATOR) {
            return in_array((string) ($goal['goal_id'] ?? ''), $event->selectorGoals, true);
        }

        if ($target === '') {
            return false;
        }

        return match ($operator) {
            'tel'      => self::hasScheme($target, 'tel'),
            'mailto'   => self::hasScheme($target, 'mailto'),
            'external' => self::isExternal($target, $event->column('page_url')),
            default    => self::compare($target, (string) ($goal['value'] ?? ''), $operator),
        };
    }

    /**
     * Whether a custom event carries the name a custom-event goal names.
     *
     * Compared case-insensitively on the trimmed name. Site code calls
     * Convermetry.track('Appointment_Booked') and an administrator types
     * "appointment_booked"; failing on capitalization would be an afternoon lost
     * to a difference nobody can see.
     *
     * @param PreparedEvent        $event The event being stored.
     * @param array<string, mixed> $goal  A normalized custom_event goal.
     * @return bool
     */
    private static function matchesCustomEvent(PreparedEvent $event, array $goal): bool
    {
        $name = strtolower(trim($event->customEventName));

        return $name !== '' && $name === strtolower(trim((string) ($goal['value'] ?? '')));
    }

    /**
     * Applies one string operator.
     *
     * Case-insensitive throughout. URLs are case-insensitive in their host and
     * conventionally lowercase in their path, and a goal that silently stopped
     * counting because a page was linked as "/Pricing/" would be reported as the
     * plugin being broken — correctly.
     *
     * @param string $subject  The value from the event.
     * @param string $pattern  The value from the goal.
     * @param string $operator One of GoalSettings::OPERATORS.
     * @return bool
     */
    private static function compare(string $subject, string $pattern, string $operator): bool
    {
        if ($pattern === '') {
            return false;
        }

        $subject = strtolower($subject);
        $pattern = strtolower($pattern);

        return match ($operator) {
            'equals'      => self::equalsIgnoringTrailingSlash($subject, $pattern),
            'contains'    => str_contains($subject, $pattern),
            'starts_with' => str_starts_with($subject, $pattern),
            'ends_with'   => str_ends_with($subject, $pattern),
            default       => false,
        };
    }

    /**
     * Exact comparison that forgives a trailing slash.
     *
     * "/thank-you" and "/thank-you/" are the same page to every visitor and to
     * WordPress, which will happily serve and canonicalize both. Treating them
     * as different goals would produce a goal that counts roughly half of what
     * actually happened, with no visible cause.
     *
     * @param string $subject Lowercased subject.
     * @param string $pattern Lowercased pattern.
     * @return bool
     */
    private static function equalsIgnoringTrailingSlash(string $subject, string $pattern): bool
    {
        return rtrim($subject, '/') === rtrim($pattern, '/');
    }

    /**
     * Whether a destination uses a given scheme.
     *
     * @param string $target Stored target_url.
     * @param string $scheme 'tel' or 'mailto'.
     * @return bool
     */
    private static function hasScheme(string $target, string $scheme): bool
    {
        return stripos($target, $scheme . ':') === 0;
    }

    /**
     * Whether a destination leaves this site.
     *
     * Compared host-to-host against the page the click happened on, rather than
     * against the site's configured hosts, because that is the comparison
     * available without WordPress state — and it is the same answer: a tracked
     * pageview's URL has already been validated as belonging to this site.
     *
     * A destination with no host (a relative path) is internal. A tel: or
     * mailto: link is NOT external — it does not go to another website, and
     * counting phone taps as "external link clicks" would double-count the
     * single most common goal.
     *
     * @param string $target  Stored target_url.
     * @param string $pageUrl Stored page_url.
     * @return bool
     */
    private static function isExternal(string $target, string $pageUrl): bool
    {
        if (preg_match('~^(tel|mailto):~i', $target)) {
            return false;
        }

        $targetHost = self::hostOf($target);
        if ($targetHost === '') {
            return false;
        }

        return $targetHost !== self::hostOf($pageUrl);
    }

    /**
     * The lowercase host of an absolute URL, or '' when it has none.
     *
     * Deliberately not wp_parse_url(): this class is pure so that its rules can
     * be tested without a WordPress runtime, and a host is the one piece needed.
     *
     * @param string $url Absolute or relative URL.
     * @return string
     */
    private static function hostOf(string $url): string
    {
        if (preg_match('~^[a-z][a-z0-9+.\-]*://([^/?#]+)~i', $url, $matches) !== 1) {
            return '';
        }

        // Strip credentials and port, keeping only the host.
        $host = (string) preg_replace('~^[^@]*@~', '', $matches[1]);

        return strtolower((string) preg_replace('~:\d+$~', '', $host));
    }

    /**
     * The path of a stored URL, defaulting to '/'.
     *
     * @param string $url Absolute URL.
     * @return string
     */
    private static function pathOf(string $url): string
    {
        if (preg_match('~^[a-z][a-z0-9+.\-]*://[^/?#]+(/[^?#]*)?~i', $url, $matches) !== 1) {
            return $url;
        }

        return ($matches[1] ?? '') !== '' ? $matches[1] : '/';
    }
}
