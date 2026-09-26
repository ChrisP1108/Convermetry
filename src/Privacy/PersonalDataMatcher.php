<?php
declare(strict_types=1);

namespace Convermetry\Privacy;

if (!defined('ABSPATH')) exit;

use Convermetry\Forms\SubmissionFields;
use Convermetry\Forms\SubmissionFieldList;

/**
 * Decides whether a stored submission belongs to an email address.
 *
 * WordPress's privacy tools identify a person by email address alone, and a
 * Convermetry submission has no user id or email column — the address, when
 * there is one, is simply one of the values the visitor typed. So the rule is:
 * a submission belongs to an address when ANY submitted field value (or any
 * item of a multi-value field) is exactly that address, compared
 * case-insensitively after trimming.
 *
 * Exact equality, not "contains", on purpose. The eraser deletes what this
 * matches, and a message field that happens to mention an address is somebody
 * else's lead: "please reply to ann@example.com" in Bob's enquiry does not make
 * Bob's submission Ann's to erase.
 *
 * Pure: no database, no WordPress state. The SQL that narrows the table down to
 * candidates lives in FormSubmissions::privacyCandidates(); this class is what
 * turns a candidate into a match.
 */
final class PersonalDataMatcher
{
    /**
     * Canonical form of an address for matching: trimmed and lowercased, or ''
     * when it cannot be an email address at all.
     *
     * @param string $email Address as supplied by the privacy request.
     * @return string
     */
    public static function normalizeEmail(string $email): string
    {
        $email = strtolower(trim($email));

        return (str_contains($email, '@') && strlen($email) <= 254) ? $email : '';
    }

    /**
     * The two lowercase substrings a stored submission_data column may contain
     * the address as: verbatim, and the way PHP's default JSON encoder spells
     * it (escaped "/" and non-ASCII), which is how rows written by older
     * versions stored it. Identical when the address needs no escaping.
     *
     * @param string $email Normalized address.
     * @return array{0: string, 1: string}
     */
    public static function likePatterns(string $email): array
    {
        $encoded = json_encode($email);
        $escaped = is_string($encoded) ? strtolower(trim($encoded, '"')) : $email;

        return [$email, $escaped];
    }

    /**
     * Whether any field value in a stored submission_data column is the address.
     *
     * @param string $submissionData Stored submission_data JSON (either schema).
     * @param string $email          Normalized address.
     * @return bool
     */
    public static function storedDataMatches(string $submissionData, string $email): bool
    {
        return self::fieldsMatch(SubmissionFields::fromStoredJson($submissionData), $email);
    }

    /**
     * Whether any field value is the address.
     *
     * @param SubmissionFieldList $fields Parsed submission fields.
     * @param string              $email  Normalized address.
     * @return bool
     */
    public static function fieldsMatch(SubmissionFieldList $fields, string $email): bool
    {
        if ($email === '') {
            return false;
        }

        foreach ($fields->all() as $field) {
            $values = is_array($field->value) ? $field->value : [$field->value];

            foreach ($values as $value) {
                if (strtolower(trim($value)) === $email) {
                    return true;
                }
            }
        }

        return false;
    }
}
