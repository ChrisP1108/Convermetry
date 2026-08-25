<?php
declare(strict_types=1);

namespace Convermetry\Notifications;

if (!defined('ABSPATH')) exit;

use Convermetry\Settings\Options;

/**
 * Interpretation of the notification settings: sanitizing what the admin
 * submitted, deciding whether a given form notifies, and freezing the subset
 * of configuration that travels with a queued message.
 *
 * Everything here is pure — arrays in, arrays out, no $wpdb, no wp_mail(), no
 * output. {@see Options} owns the defaults and the typed readers; this class
 * owns the rules. The split matters because the guard order (master switch
 * before form rules before recipients) is a correctness property, and keeping
 * it in a pure function makes it directly testable instead of an integration
 * hope.
 */
final class NotificationSettings
{
    /** Valid values for the form-scope selector. */
    public const array SCOPES = ['all', 'selected'];

    /** Valid per-form rules. 'inherit' means "no explicit rule stored". */
    public const array FORM_RULES = ['inherit', 'enabled', 'disabled'];

    /**
     * Maximum internal recipients.
     *
     * Each recipient is a separate wp_mail() call inside a 45-second worker
     * budget, so an unbounded list would make one submission monopolise the
     * queue. Twenty is far beyond any real internal distribution list.
     */
    public const int MAX_RECIPIENTS = 20;

    /** Maximum rendered subject length, in characters. */
    public const int SUBJECT_MAX_LEN = 200;

    /** Snapshot format version, so an older queued row can be read forward. */
    public const int SNAPSHOT_VERSION = 1;

    /**
     * Sanitizes a submitted settings array into the canonical stored shape.
     *
     * Emits a known shape: unknown top-level keys are dropped here, while the
     * READ path ({@see Options::notificationAll()}) merges over defaults and so
     * preserves anything an older version stored. That asymmetry matches how
     * Options already treats the other two option arrays.
     *
     * @param array<string, mixed> $raw Unslashed $_POST subtree.
     * @return array<string, mixed>
     */
    public static function sanitize(array $raw): array
    {
        $scope = sanitize_key((string) ($raw['scope'] ?? 'all'));

        return [
            'enabled'           => !empty($raw['enabled']),
            'recipients'        => self::sanitizeRecipients($raw['recipients'] ?? []),
            'subject'           => self::sanitizeSubject($raw['subject'] ?? ''),
            'scope'             => in_array($scope, self::SCOPES, true) ? $scope : 'all',
            'forms'             => self::sanitizeFormRules($raw['forms'] ?? []),
            'include_fields'    => !empty($raw['include_fields']),
            'include_analytics' => !empty($raw['include_analytics']),
            'include_journey'   => !empty($raw['include_journey']),
            'include_ip'        => !empty($raw['include_ip']),
        ];
    }

    /**
     * Validates and deduplicates recipient addresses.
     *
     * Accepts either a list (the stored shape) or the raw textarea value,
     * where addresses may be separated by newlines, commas, or semicolons.
     *
     * Deduplication compares lowercased but STORES the first-seen spelling:
     * the local part of an address is case-sensitive per RFC 5321, and some
     * ticketing systems route on it, so normalizing the stored value could
     * silently change where mail lands.
     *
     * @param mixed $raw List of addresses, or a separated string.
     * @return list<string>
     */
    public static function sanitizeRecipients(mixed $raw): array
    {
        $candidates = is_array($raw)
            ? $raw
            : preg_split('/[\r\n,;]+/', (string) $raw, -1, PREG_SPLIT_NO_EMPTY);

        $out  = [];
        $seen = [];

        foreach ((array) $candidates as $candidate) {
            if (!is_scalar($candidate)) {
                continue;
            }

            $address = sanitize_email(trim((string) $candidate));
            if ($address === '' || !is_email($address)) {
                continue;
            }

            $key = strtolower($address);
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $out[]      = $address;

            if (count($out) >= self::MAX_RECIPIENTS) {
                break;
            }
        }

        return $out;
    }

    /**
     * Cleans a subject template for storage.
     *
     * CR/LF are stripped HERE and again at render time
     * ({@see EmailBuilder::subject()}). Stripping only at save would leave the
     * real injection vector open: the dangerous newline usually arrives inside
     * a substituted {form_name}, which comes from a third-party form plugin
     * and is typed by whoever built the form.
     *
     * @param mixed $raw Submitted subject template.
     * @return string
     */
    public static function sanitizeSubject(mixed $raw): string
    {
        $subject = is_scalar($raw) ? (string) $raw : '';
        $subject = (string) preg_replace('/[\r\n\t\x00]+/', ' ', $subject);
        $subject = trim((string) preg_replace('/\s+/', ' ', sanitize_text_field($subject)));

        if ($subject === '') {
            return (string) Options::notificationDefaults()['subject'];
        }

        return mb_substr($subject, 0, self::SUBJECT_MAX_LEN);
    }

    /**
     * Keeps only known form keys carrying an explicit rule.
     *
     * 'inherit' entries are dropped rather than stored: inheritance IS the
     * absence of a rule, and persisting one row per form ever discovered would
     * grow the option without adding information.
     *
     * Keys pass through sanitize_text_field(), not sanitize_key(): a form key
     * is "provider:identity", and Elementor keys by the form's NAME, which may
     * contain spaces and capitals.
     *
     * @param mixed $raw Submitted formKey => rule map.
     * @return array<string, string>
     */
    public static function sanitizeFormRules(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $formKey => $rule) {
            if (!is_scalar($rule)) {
                continue;
            }

            $key  = sanitize_text_field((string) $formKey);
            $rule = sanitize_key((string) $rule);

            if ($key === '' || !str_contains($key, ':')) {
                continue;
            }
            if ($rule !== 'enabled' && $rule !== 'disabled') {
                continue;
            }

            $out[$key] = $rule;
        }

        return $out;
    }

    /**
     * Whether a form should produce a notification.
     *
     * | scope      | rule            | notify |
     * |------------|-----------------|--------|
     * | all        | inherit/absent  | yes    |
     * | all        | enabled         | yes    |
     * | all        | disabled        | no     |
     * | selected   | inherit/absent  | no     |
     * | selected   | enabled         | yes    |
     * | selected   | disabled        | no     |
     *
     * Under 'all' a newly discovered form starts notifying with no
     * configuration — mirroring the Forms page, where detected forms are
     * included by default. Under 'selected' a new form stays silent, so an
     * opt-in site never gets surprise email.
     *
     * @param array<string, mixed> $settings Full notification settings.
     * @param string               $formKey  Provider-qualified form key.
     * @return bool
     */
    public static function shouldNotify(array $settings, string $formKey): bool
    {
        $forms = is_array($settings['forms'] ?? null) ? $settings['forms'] : [];
        $rule  = (string) ($forms[$formKey] ?? 'inherit');

        if ($rule === 'enabled') {
            return true;
        }
        if ($rule === 'disabled') {
            return false;
        }

        $scope = (string) ($settings['scope'] ?? 'all');

        return !in_array($scope, self::SCOPES, true) || $scope === 'all';
    }

    /**
     * The subset of configuration frozen onto each queued row.
     *
     * Deliberately small and deliberately free of lead data. It holds the
     * subject TEMPLATE rather than a rendered subject, because rendering needs
     * the submission — which is fetched fresh at send time so that deleting a
     * submission really does prevent the email.
     *
     * The recipient is NOT in here: it is its own column, one row per
     * (submission, recipient), which is what makes retries independent and
     * what the uniqueness index is built on.
     *
     * @param array<string, mixed>  $settings Full notification settings.
     * @param string                $formKey  Form key that triggered this.
     * @param array<string, string> $siteInfo {@see EmailBuilder::siteInfo()}.
     * @return array<string, mixed>
     */
    public static function snapshot(array $settings, string $formKey, array $siteInfo): array
    {
        return [
            'v'         => self::SNAPSHOT_VERSION,
            'subject'   => (string) ($settings['subject'] ?? ''),
            'site_name' => (string) ($siteInfo['site_name'] ?? ''),
            'form_key'  => $formKey,
            'include'   => [
                'fields'    => !empty($settings['include_fields']),
                'analytics' => !empty($settings['include_analytics']),
                'journey'   => !empty($settings['include_journey']),
                'ip'        => !empty($settings['include_ip']),
            ],
        ];
    }

    /**
     * Reads a stored snapshot back, filling anything an older plugin version
     * omitted.
     *
     * A row written by 0.4.0 may be processed after an upgrade adds a toggle;
     * without this, that send would read a missing index. New toggles default
     * to OFF here regardless of their global default, because a message queued
     * before the toggle existed carries no consent for it.
     *
     * @param mixed $raw Decoded settings_json, or anything at all.
     * @return array<string, mixed>
     */
    public static function normalizeSnapshot(mixed $raw): array
    {
        $snapshot = is_array($raw) ? $raw : [];
        $include  = is_array($snapshot['include'] ?? null) ? $snapshot['include'] : [];

        return [
            'v'         => (int) ($snapshot['v'] ?? 0),
            'subject'   => (string) ($snapshot['subject'] ?? ''),
            'site_name' => (string) ($snapshot['site_name'] ?? ''),
            'form_key'  => (string) ($snapshot['form_key'] ?? ''),
            'include'   => [
                'fields'    => !empty($include['fields']),
                'analytics' => !empty($include['analytics']),
                'journey'   => !empty($include['journey']),
                'ip'        => !empty($include['ip']),
            ],
        ];
    }
}
