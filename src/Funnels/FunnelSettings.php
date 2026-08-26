<?php
declare(strict_types=1);

namespace Convermetry\Funnels;

if (!defined('ABSPATH')) exit;

/**
 * Interpretation of a funnel definition: what a valid one looks like and what
 * an administrator submitted reduces to.
 *
 * PURE — arrays in, arrays out. {@see FunnelRepository} owns storage,
 * {@see StepCompiler} owns turning a step into SQL, and
 * {@see \Convermetry\Analytics\FunnelReport} owns the arithmetic.
 *
 * A funnel has no monetary value and no counting behaviour, so it is a much
 * simpler object than a goal. What it does share is an IMMUTABLE id and a
 * definition hash — the hash is not used for deduplication here (funnels store
 * nothing), but it keys the report cache, so editing a step invalidates the
 * cached numbers by construction rather than by someone remembering to clear
 * them.
 */
final class FunnelSettings
{
    /** Maximum funnels a site may define. */
    public const int MAX_FUNNELS = 20;

    /**
     * Maximum steps in one funnel.
     *
     * Each step beyond the first adds a correlated subquery to the report's
     * statement, so this bounds the work one render can ask of the database. It
     * is also well past the point where a funnel stops being readable: eight
     * bars is already a lot to look at.
     */
    public const int MAX_STEPS = 8;

    /** Minimum steps — a one-step funnel is just a count. */
    public const int MIN_STEPS = 2;

    /** Maximum length of a funnel or step name. */
    public const int MAX_NAME_LEN = 120;

    /** Maximum length of a step's match value. */
    public const int MAX_VALUE_LEN = 191;

    /** Length of the stored definition hash. */
    public const int HASH_LEN = 12;

    /**
     * The canonical empty funnel.
     *
     * @return array<string, mixed>
     */
    public static function blank(): array
    {
        return [
            'funnel_id'       => '',
            'name'            => '',
            'enabled'         => true,
            'steps'           => [],
            'definition_hash' => '',
            'created_at'      => '',
            'updated_at'      => '',
            'deleted_at'      => null,
        ];
    }

    /**
     * Sanitizes a submitted funnel into the canonical stored shape.
     *
     * @param array<string, mixed>      $raw      Unslashed submitted funnel.
     * @param array<string, mixed>|null $existing The stored funnel being edited, or null.
     * @param string                    $now      UTC 'Y-m-d H:i:s'.
     * @return array<string, mixed>|null The canonical funnel, or null when unusable.
     */
    public static function sanitize(array $raw, ?array $existing, string $now): ?array
    {
        $name = self::text($raw['name'] ?? '', self::MAX_NAME_LEN);
        if ($name === '') {
            return null;
        }

        $steps = self::sanitizeSteps($raw['steps'] ?? []);
        if (count($steps) < self::MIN_STEPS) {
            return null;
        }

        $funnel = array_merge(self::blank(), [
            'funnel_id'  => self::immutableId($existing),
            'name'       => $name,
            'enabled'    => !empty($raw['enabled']),
            'steps'      => $steps,
            'created_at' => (string) ($existing['created_at'] ?? '') !== ''
                ? (string) $existing['created_at']
                : $now,
            'updated_at' => $now,
            'deleted_at' => null,
        ]);

        $funnel['definition_hash'] = self::definitionHash($funnel);

        return $funnel;
    }

    /**
     * Sanitizes an ordered list of steps.
     *
     * A step that does not validate is DROPPED rather than aborting the whole
     * funnel, because the submitted list routinely contains empty rows from the
     * editor's "add step" control. A funnel left with fewer than MIN_STEPS after
     * that is rejected by the caller.
     *
     * @param mixed $raw Submitted steps.
     * @return list<array<string, mixed>>
     */
    public static function sanitizeSteps(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $steps = [];

        foreach ($raw as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }

            $step = self::sanitizeStep($candidate);
            if ($step !== null) {
                $steps[] = $step;
            }

            if (count($steps) >= self::MAX_STEPS) {
                break;
            }
        }

        return $steps;
    }

    /**
     * Sanitizes one step.
     *
     * @param array<string, mixed> $raw Submitted step.
     * @return array<string, mixed>|null
     */
    private static function sanitizeStep(array $raw): ?array
    {
        $type = is_scalar($raw['type'] ?? null) ? sanitize_key((string) $raw['type']) : '';

        if (!in_array($type, StepCompiler::TYPES, true)) {
            return null;
        }

        $value = self::text($raw['value'] ?? '', self::MAX_VALUE_LEN);

        $operator = is_scalar($raw['operator'] ?? null) ? sanitize_key((string) $raw['operator']) : '';

        if ($type === 'page') {
            if (!in_array($operator, StepCompiler::PAGE_OPERATORS, true)) {
                $operator = 'equals';
            }
            if ($value === '') {
                // A page step with no pattern would match everything or nothing.
                return null;
            }
        } elseif ($type === 'goal') {
            $operator = '';
            if ($value === '') {
                return null;
            }
        } else {
            // A form step's value is an optional form key: empty legitimately
            // means "any form on the site".
            $operator = '';
        }

        return [
            'type'     => $type,
            'operator' => $operator,
            'value'    => $value,
            'label'    => self::text($raw['label'] ?? '', self::MAX_NAME_LEN),
        ];
    }

    /**
     * A funnel's identity: preserved when editing, minted once otherwise.
     *
     * Never taken from submitted input, for the same reason a goal's is not —
     * it is the entity's public identifier and, in a future Cloud sync, its
     * join key.
     *
     * @param array<string, mixed>|null $existing The stored funnel, or null.
     * @return string
     */
    private static function immutableId(?array $existing): string
    {
        $current = (string) ($existing['funnel_id'] ?? '');

        return self::isValidId($current) ? $current : self::mintId();
    }

    /**
     * Mints a new immutable funnel id.
     *
     * @return string
     */
    public static function mintId(): string
    {
        return 'f' . substr(md5(wp_generate_uuid4() . wp_rand()), 0, 16);
    }

    /**
     * Whether a string is a well-formed funnel id.
     *
     * @param string $id Candidate id.
     * @return bool
     */
    public static function isValidId(string $id): bool
    {
        return preg_match('~^f[a-f0-9]{16}$~', $id) === 1;
    }

    /**
     * The hash of everything that determines what this funnel measures.
     *
     * Keys the report cache. Step ORDER is part of it — A→B and B→A are
     * different funnels with different answers, so they must not share a cache
     * entry.
     *
     * @param array<string, mixed> $funnel A funnel.
     * @return string
     */
    public static function definitionHash(array $funnel): string
    {
        $parts = [];

        foreach (is_array($funnel['steps'] ?? null) ? $funnel['steps'] : [] as $step) {
            $parts[] = implode("\x1e", [
                (string) ($step['type'] ?? ''),
                (string) ($step['operator'] ?? ''),
                (string) ($step['value'] ?? ''),
            ]);
        }

        return substr(md5(implode("\x1f", $parts)), 0, self::HASH_LEN);
    }

    /**
     * Normalizes a stored funnel read back from the option.
     *
     * @param mixed $raw A decoded stored funnel, or anything at all.
     * @return array<string, mixed>|null
     */
    public static function normalize(mixed $raw): ?array
    {
        if (!is_array($raw)) {
            return null;
        }

        $funnel = array_merge(self::blank(), $raw);

        if (!self::isValidId((string) $funnel['funnel_id'])) {
            return null;
        }

        $funnel['name']    = self::text($funnel['name'], self::MAX_NAME_LEN);
        $funnel['enabled'] = !empty($funnel['enabled']);
        $funnel['steps']   = self::sanitizeSteps($funnel['steps']);

        if (count($funnel['steps']) < self::MIN_STEPS) {
            return null;
        }

        if (strlen((string) $funnel['definition_hash']) !== self::HASH_LEN) {
            $funnel['definition_hash'] = self::definitionHash($funnel);
        }

        return $funnel;
    }

    /**
     * Whether a funnel is currently reportable.
     *
     * @param array<string, mixed> $funnel A normalized funnel.
     * @return bool
     */
    public static function isActive(array $funnel): bool
    {
        return !empty($funnel['enabled']) && ($funnel['deleted_at'] ?? null) === null;
    }

    /**
     * A default label for a step that was not given one.
     *
     * @param array<string, mixed> $step A normalized step.
     * @return string
     */
    public static function stepLabel(array $step): string
    {
        $label = (string) ($step['label'] ?? '');
        if ($label !== '') {
            return $label;
        }

        $value = (string) ($step['value'] ?? '');

        return match ((string) ($step['type'] ?? '')) {
            'page'         => $value !== '' ? $value : 'A page',
            'goal'         => 'Goal completed',
            'form_view'    => 'Form seen',
            'form_start'   => 'Form started',
            'form_submit'  => 'Submission attempted',
            'form_success' => 'Submission confirmed',
            default        => 'Step',
        };
    }

    /**
     * Sanitizes a free-text field to a bounded plain string.
     *
     * @param mixed $value Raw value.
     * @param int   $max   Maximum length.
     * @return string
     */
    private static function text(mixed $value, int $max): string
    {
        if (!is_scalar($value)) {
            return '';
        }

        return mb_substr(trim(sanitize_text_field((string) $value)), 0, $max);
    }
}
