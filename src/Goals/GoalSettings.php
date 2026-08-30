<?php
declare(strict_types=1);

namespace Convermetry\Goals;

if (!defined('ABSPATH')) exit;

use Convermetry\Leads\Money;

/**
 * Interpretation of a conversion goal: what a valid definition looks like,
 * what an administrator submitted reduces to, and when an edit is significant
 * enough to start a new measurement series.
 *
 * Everything here is pure — arrays in, arrays out, no $wpdb, no output.
 * {@see GoalRepository} owns storage; {@see GoalMatcher} owns matching; this
 * class owns the RULES. The split matters because "does this edit invalidate
 * the existing numbers?" is a correctness property, and keeping it in a pure
 * function makes it directly testable rather than an integration hope.
 *
 * WHY A GOAL HAS A VERSION AND A DEFINITION HASH
 *
 * A goal keeps its goal_id forever — that is what makes it a stable entity for
 * reporting and, eventually, for Cloud sync. But its RULE can be edited, and a
 * goal that used to mean "/pricing/" and now means "/plans/" is not the same
 * metric. Two things would go wrong if edits were invisible:
 *
 *  1. A chart would blend two different definitions into one line with no
 *     indication that the question changed halfway through.
 *  2. Worse, a once-per-session goal deduplicates on (goal, session). Redefine
 *     the rule and a visitor who already completed the OLD goal in this session
 *     could never complete the new one, because the dedup key would collide.
 *
 * So the fields that affect MATCHING are hashed, that hash is stored on every
 * completion and forms part of the dedup key, and editing a rule bumps a version
 * counter. Renaming a goal, toggling it off, or changing its monetary value are
 * deliberately NOT part of the hash: none of them changes what is being
 * measured, and needlessly resetting a series would be its own kind of wrong.
 */
final class GoalSettings
{
    /**
     * Goal types.
     *
     * 'url'          — the visitor reached a page (matched against pageviews).
     * 'click'        — the visitor clicked something (matched against clicks).
     * 'custom_event' — site code called Convermetry.track('name').
     *
     * @var string[]
     */
    public const array TYPES = ['url', 'click', 'custom_event'];

    /**
     * Valid operators per type.
     *
     * The click operators are deliberately lopsided towards things a marketer
     * can describe without reading the page source. 'tel' and 'mailto' need no
     * value at all, and they are the two most-requested goals in the product —
     * a phone tap and an email-link click — so requiring a CSS selector for them
     * would have made the common case the hardest one.
     *
     * @var array<string, string[]>
     */
    public const array OPERATORS = [
        'url'          => ['equals', 'contains', 'starts_with', 'ends_with'],
        'click'        => ['tel', 'mailto', 'external', 'contains', 'equals', 'selector'],
        'custom_event' => ['name'],
    ];

    /**
     * Click operators that need no configured value — the operator alone fully
     * describes the rule.
     *
     * @var string[]
     */
    public const array VALUELESS_OPERATORS = ['tel', 'mailto', 'external'];

    /**
     * The only operator resolved in the BROWSER rather than on the server.
     *
     * Everything else is matched server-side against data the tracker already
     * sends. A CSS selector is the one rule that genuinely needs the DOM, so
     * selector goals — and only selector goals — have their selectors shipped to
     * the tracker, and the goal ids it reports back are re-validated here before
     * anything is recorded.
     */
    public const string BROWSER_OPERATOR = 'selector';

    /** Maximum goals a site may define. */
    public const int MAX_GOALS = 50;

    /** Maximum length of a goal's name. */
    public const int MAX_NAME_LEN = 120;

    /** Maximum length of a goal's match value (URL, selector, or event name). */
    public const int MAX_VALUE_LEN = 191;

    /** Length of the stored definition hash. */
    public const int HASH_LEN = 12;

    /**
     * Fields whose change means the goal is measuring something DIFFERENT, and
     * therefore that a new measurement series must begin.
     *
     * Note what is absent: name, enabled, goal_value, once_per_session. A rename
     * is cosmetic; disabling and re-enabling should resume the same series; and
     * changing what a conversion is worth reprices a metric without redefining
     * it. once_per_session is the interesting exclusion — it changes the COUNT
     * but not the definition of the event being counted, and it is already part
     * of the dedup key's own prefix, so a change takes effect immediately
     * without needing to invalidate history.
     *
     * @var string[]
     */
    private const array MATCHING_FIELDS = ['type', 'operator', 'value'];

    /**
     * The canonical empty goal, so every reader sees every key.
     *
     * @return array<string, mixed>
     */
    public static function blank(): array
    {
        return [
            'goal_id'          => '',
            'name'             => '',
            'enabled'          => true,
            'type'             => 'url',
            'operator'         => 'equals',
            'value'            => '',
            'once_per_session' => true,
            'goal_value'       => null,
            'dynamic_value'    => false,
            'version'          => 1,
            'definition_hash'  => '',
            'created_at'       => '',
            'updated_at'       => '',
            'deleted_at'       => null,
        ];
    }

    /**
     * Sanitizes one submitted goal into the canonical stored shape.
     *
     * $existing is the currently stored version of the same goal, when this is
     * an edit. It is what makes goal_id and created_at immutable — a submitted
     * form cannot change either, whatever it posts — and it is what lets the
     * version counter advance only on a genuine rule change.
     *
     * @param array<string, mixed>      $raw      Unslashed submitted goal.
     * @param array<string, mixed>|null $existing The stored goal being edited, or null for a new one.
     * @param string                    $now      UTC 'Y-m-d H:i:s' (injected so this stays pure).
     * @return array<string, mixed>|null The canonical goal, or null when it is not valid enough to store.
     */
    public static function sanitize(array $raw, ?array $existing, string $now): ?array
    {
        $goal = self::blank();

        $name = self::text($raw['name'] ?? '', self::MAX_NAME_LEN);
        if ($name === '') {
            // A goal with no name is unusable in every report that lists it, and
            // silently storing one would produce blank rows nobody can act on.
            return null;
        }

        $type = self::key($raw['type'] ?? '');
        if (!in_array($type, self::TYPES, true)) {
            return null;
        }

        $operator = self::key($raw['operator'] ?? '');
        if (!in_array($operator, self::OPERATORS[$type], true)) {
            return null;
        }

        $value = self::text($raw['value'] ?? '', self::MAX_VALUE_LEN);
        if ($value === '' && !in_array($operator, self::VALUELESS_OPERATORS, true)) {
            // Every other operator compares against something. An empty pattern
            // would match everything ('contains' of '') or nothing, and either
            // way the site owner did not ask for that.
            return null;
        }

        $goal['goal_id']          = self::immutableId($existing);
        $goal['name']             = $name;
        $goal['type']             = $type;
        $goal['operator']         = $operator;
        $goal['value']            = in_array($operator, self::VALUELESS_OPERATORS, true) ? '' : $value;
        $goal['enabled']          = !empty($raw['enabled']);
        $goal['once_per_session'] = !empty($raw['once_per_session']);
        $goal['goal_value']       = Money::parse($raw['goal_value'] ?? null);
        // A dynamic value is only meaningful for custom events: a URL or a click
        // has nowhere to carry one. Accepting the flag elsewhere would let the
        // UI offer a control that can never do anything.
        $goal['dynamic_value']    = $type === 'custom_event' && !empty($raw['dynamic_value']);
        // Read once: the ?? in a condition does not carry over to a second
        // access in the branch, so the original re-read the offset without a
        // guard on a value that can be null.
        $createdAt                = (string) ($existing['created_at'] ?? '');
        $goal['created_at']       = $createdAt !== '' ? $createdAt : $now;
        $goal['updated_at']       = $now;
        $goal['deleted_at']       = null;

        $goal['definition_hash'] = self::definitionHash($goal);

        // The version advances only when the MATCHING rule changed. Renames and
        // repricing keep the series intact, which is what a site owner means by
        // "fix the label on that goal".
        $previousHash    = (string) ($existing['definition_hash'] ?? '');
        $goal['version'] = ($existing === null || $previousHash === $goal['definition_hash'])
            ? max(1, (int) ($existing['version'] ?? 1))
            : (int) ($existing['version'] ?? 1) + 1;

        return $goal;
    }

    /**
     * A goal's identity: preserved from the stored goal when editing, minted
     * once otherwise.
     *
     * Never taken from submitted input. A goal id is the join key for every
     * completion ever recorded against it, so accepting one from a form would
     * let an edit silently re-point a goal at another goal's history.
     *
     * @param array<string, mixed>|null $existing The stored goal being edited, or null.
     * @return string
     */
    private static function immutableId(?array $existing): string
    {
        $current = (string) ($existing['goal_id'] ?? '');

        return self::isValidId($current) ? $current : self::mintId();
    }

    /**
     * Mints a new immutable goal id.
     *
     * Deliberately not an auto-increment: goal ids appear in reports, in admin
     * URLs, and eventually in a Cloud sync, and a database sequence is a poor
     * public contract — it collides across sites and leaks volume.
     *
     * @return string
     */
    public static function mintId(): string
    {
        return 'g' . substr(md5(wp_generate_uuid4() . wp_rand()), 0, 16);
    }

    /**
     * Whether a string is a well-formed goal id.
     *
     * @param string $id Candidate id.
     * @return bool
     */
    public static function isValidId(string $id): bool
    {
        return preg_match('~^g[a-f0-9]{16}$~', $id) === 1;
    }

    /**
     * The hash of the fields that determine what this goal MATCHES.
     *
     * Stored on every completion, and part of the once-per-session dedup key —
     * see the class docblock for why both matter.
     *
     * @param array<string, mixed> $goal A goal (canonical or partial).
     * @return string A HASH_LEN-character hex string.
     */
    public static function definitionHash(array $goal): string
    {
        $parts = [];
        foreach (self::MATCHING_FIELDS as $field) {
            $parts[] = (string) ($goal[$field] ?? '');
        }

        return substr(md5(implode("\x1f", $parts)), 0, self::HASH_LEN);
    }

    /**
     * Whether two goal definitions would match the same things.
     *
     * @param array<string, mixed> $a First goal.
     * @param array<string, mixed> $b Second goal.
     * @return bool
     */
    public static function isSameDefinition(array $a, array $b): bool
    {
        return self::definitionHash($a) === self::definitionHash($b);
    }

    /**
     * Normalizes a stored goal read back from the option, filling anything an
     * older plugin version omitted.
     *
     * A goal stored by 0.5.0 may be read after an upgrade adds a field; without
     * this, matching would read a missing index. New flags default to their
     * SAFEST value, not their most useful one — a goal saved before a flag
     * existed carries no intent about it.
     *
     * @param mixed $raw A decoded stored goal, or anything at all.
     * @return array<string, mixed>|null The normalized goal, or null when unusable.
     */
    public static function normalize(mixed $raw): ?array
    {
        if (!is_array($raw)) {
            return null;
        }

        $goal = array_merge(self::blank(), $raw);

        if (!self::isValidId((string) $goal['goal_id'])) {
            return null;
        }

        if (!in_array($goal['type'], self::TYPES, true)) {
            return null;
        }

        if (!in_array($goal['operator'], self::OPERATORS[$goal['type']], true)) {
            return null;
        }

        $goal['name']             = self::text($goal['name'], self::MAX_NAME_LEN);
        $goal['value']            = self::text($goal['value'], self::MAX_VALUE_LEN);
        $goal['enabled']          = !empty($goal['enabled']);
        $goal['once_per_session'] = !empty($goal['once_per_session']);
        $goal['dynamic_value']    = $goal['type'] === 'custom_event' && !empty($goal['dynamic_value']);
        $goal['goal_value']       = Money::parse($goal['goal_value']);
        $goal['version']          = max(1, (int) $goal['version']);

        // A goal stored before definition hashing existed gets its hash computed
        // from its current rule, which is the correct answer: nothing has
        // changed, so this IS its original definition.
        if (strlen((string) $goal['definition_hash']) !== self::HASH_LEN) {
            $goal['definition_hash'] = self::definitionHash($goal);
        }

        return $goal;
    }

    /**
     * Whether a normalized goal is currently collecting completions.
     *
     * @param array<string, mixed> $goal A normalized goal.
     * @return bool
     */
    public static function isActive(array $goal): bool
    {
        return !empty($goal['enabled']) && ($goal['deleted_at'] ?? null) === null;
    }

    /**
     * The event type a goal is matched against, or '' when it matches none.
     *
     * This is also what the Goals screen uses to warn that a goal cannot fire:
     * a click goal is dead weight if click tracking is switched off in Settings,
     * and silently collecting nothing would be the worst of both worlds.
     *
     * @param array<string, mixed> $goal A normalized goal.
     * @return string An Options::EVENT_TYPES value, or ''.
     */
    public static function requiredEventType(array $goal): string
    {
        return match ((string) ($goal['type'] ?? '')) {
            'url'          => 'pageview',
            'click'        => 'click',
            'custom_event' => 'custom_event',
            default        => '',
        };
    }

    /**
     * Sanitizes a free-text field to a bounded plain string.
     *
     * @param mixed $value Raw value.
     * @param int   $max   Maximum length in characters.
     * @return string
     */
    private static function text(mixed $value, int $max): string
    {
        if (!is_scalar($value)) {
            return '';
        }

        return mb_substr(trim(sanitize_text_field((string) $value)), 0, $max);
    }

    /**
     * Sanitizes a machine key.
     *
     * @param mixed $value Raw value.
     * @return string
     */
    private static function key(mixed $value): string
    {
        return is_scalar($value) ? sanitize_key((string) $value) : '';
    }
}
