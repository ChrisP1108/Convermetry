<?php
declare(strict_types=1);

namespace Convermetry\Forms;

if (!defined('ABSPATH')) exit;

use Convermetry\Settings\Options;
use Convermetry\Support\SensitiveKeys;

/**
 * The plugin's single policy for "which of a submitting page's URL parameters
 * may be kept?".
 *
 * A page URL can carry anything: an ad platform's click id, an address a mailer
 * interpolated, a password-reset key, a magic-link token. Four surfaces must
 * agree on what survives, and each reads through here:
 *
 *  - the submission row, written by {@see SubmissionService};
 *  - website_info.page.query in the webhook payload;
 *  - the webhook URL, when parameter forwarding is on; and
 *  - the Submissions screen.
 *
 * The readers apply it as well as the writer because rows recorded before this
 * policy existed hold every parameter until retention removes them, and they
 * must not be shown or sent again in the meantime.
 *
 * Two modes, because two genuinely different promises are being kept:
 *
 *  - Forwarding off, the default, is an ALLOWLIST. Only the campaign parameters
 *    survive, which the plugin already stores as their own columns. Nothing a
 *    site did not choose to keep can get through, whatever it is called.
 *  - Forwarding on is the administrator asking for the page's parameters, so it
 *    is a BLOCKLIST. Ad-click ids are kept: handing them to a CRM for offline
 *    conversion imports is what the setting is for. Names that look like a
 *    secret or contact details are dropped, which is best effort by nature — a
 *    token in a parameter called "ref" cannot be recognized.
 *
 * Either way a value containing '@' is dropped as a likely email address, the
 * same guard campaign values pass at the tracking endpoint.
 */
final class PageQuery
{
    /** The parameters kept whatever the settings say. */
    private const array CAMPAIGN_PARAMS = [
        'utm_source', 'utm_medium', 'utm_campaign', 'utm_id', 'utm_term', 'utm_content',
    ];

    /**
     * URL-specific names, on top of {@see SensitiveKeys}. Tested against the
     * canonical name, as whole words: 'reset_key' matches 'key' and 'keyword'
     * does not. 'code' and the personal names must be the entire parameter,
     * because 'promo_code' and 'campaign_name' are ordinary tracking data.
     */
    private const string PRIVATE_NAME = '~(^|_)(key|nonce|wpnonce|otp|sig|signature|hash|auth|jwt|session|sessionid|sessid|phpsessid|login|email|mail|phone|verification)(_|$)'
        . '|^(code|name|firstname|first_name|lastname|last_name|fullname|full_name)$~';

    /**
     * Whether a form forwards its page's parameters: the global setting, or the
     * form's own override.
     *
     * @param string $formKey Provider-scoped form key, or ''.
     * @return bool
     */
    public static function isForwarded(string $formKey): bool
    {
        return Options::includePageParams()
            || ($formKey !== '' && FormSettings::forForm($formKey)['include_page_params']);
    }

    /**
     * What may be kept of one form's page parameters, under its current setting.
     *
     * @param array<array-key, mixed> $params  Decoded parameters.
     * @param string                  $formKey Provider-scoped form key, or ''.
     * @return array<string, string>
     */
    public static function forForm(array $params, string $formKey): array
    {
        return self::filter($params, self::isForwarded($formKey));
    }

    /**
     * Applies the policy, and sanitizes what it keeps.
     *
     * Expects DECODED values. sanitize_text_field() strips percent-encoded
     * octets, so running it over a raw query string turned
     * 'jo%40example.com' into 'joexample.com' and hid the '@' from this guard.
     *
     * @param array<array-key, mixed> $params     Decoded parameters.
     * @param bool                    $forwarding Whether parameter forwarding is on.
     * @return array<string, string>
     */
    public static function filter(array $params, bool $forwarding): array
    {
        $kept = [];

        foreach ($params as $name => $value) {
            if (!is_scalar($value) || str_contains(rawurldecode((string) $value), '@')) {
                continue;
            }

            $name = sanitize_text_field((string) $name);

            if ($name === '') {
                continue;
            }

            if (in_array(strtolower($name), self::CAMPAIGN_PARAMS, true)
                || ($forwarding && !self::looksPrivate($name))
            ) {
                $kept[$name] = sanitize_text_field((string) $value);
            }
        }

        return $kept;
    }

    /**
     * Whether a parameter's name suggests a secret or contact details.
     *
     * @param string $name Parameter name.
     * @return bool
     */
    private static function looksPrivate(string $name): bool
    {
        return SensitiveKeys::matches($name)
            || preg_match(self::PRIVATE_NAME, SensitiveKeys::canonicalize($name)) === 1;
    }
}
