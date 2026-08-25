<?php
declare(strict_types=1);

namespace Convermetry\Tracking;

if (!defined('ABSPATH')) exit;

use Convermetry\Settings\Options;

/**
 * The session ↔ submission correlation data carried by a form request.
 *
 * The frontend tracker injects three hidden, internal fields into every
 * form at submit time (refreshed per submission attempt, before any AJAX
 * handler serializes the form):
 *
 *  - cvm_conversion_id — a fresh conversion token for THIS submission
 *    attempt. The tracker's own form_success event reuses the same token,
 *    so the frontend event and the server-confirmed conversion share one
 *    conversion_id and can never double-count.
 *  - cvm_session_id    — the visitor's current analytics session id.
 *  - cvm_context       — a compact JSON snapshot of the session's
 *    attribution (utm fields, click-id TYPE, entrance referrer / verified
 *    direct marker, landing page, submitting page URL).
 *
 * This class extracts and STRICTLY validates those fields from the current
 * request — nothing a browser sends is trusted: the conversion id and
 * session id must match their exact formats, the context must be small,
 * valid JSON, campaign values pass the same email-dropping filter as the
 * tracking endpoint, URLs are normalized, and the page URL must belong to
 * this site's host. Correlation is token-based end to end; timestamps are
 * never used to match a submission to a session.
 *
 * When the fields are absent (tracker disabled, privacy signals honored,
 * JavaScript blocked, a server-to-server submission), a valid object with
 * a server-generated conversion id and no session is returned — the
 * submission still records and delivers, just without analytics context.
 */
final class Correlation
{
    /** Hidden input: the per-attempt conversion token. */
    public const string FIELD_CONVERSION = 'cvm_conversion_id';

    /** Hidden input: the analytics session id. */
    public const string FIELD_SESSION = 'cvm_session_id';

    /** Hidden input: the JSON attribution context snapshot. */
    public const string FIELD_CONTEXT = 'cvm_context';

    /** Maximum accepted bytes for the cvm_context JSON. */
    private const int MAX_CONTEXT_BYTES = 4096;

    /**
     * @param string                $conversionId    Validated conversion id (always non-empty).
     * @param string                $sessionId       Validated session id, or ''.
     * @param bool                  $fromTracker     Whether the browser actually supplied correlation fields.
     * @param array<string, string> $attribution     utm_source/medium/campaign/id/term/content + click_id_type.
     * @param string                $sessionReferrer The session's entrance referrer, or ''.
     * @param bool                  $sessionDirect   Explicit "verified Direct" marker from the tracker.
     * @param string                $landingPage     The session's landing page URL, or ''.
     * @param string                $pageUrl         The submitting page URL (same-host validated), or ''.
     */
    private function __construct(
        public readonly string $conversionId,
        public readonly string $sessionId,
        public readonly bool $fromTracker,
        public readonly array $attribution,
        public readonly string $sessionReferrer,
        public readonly bool $sessionDirect,
        public readonly string $landingPage,
        public readonly string $pageUrl,
    ) {
    }

    /**
     * Extracts correlation data from the current form request.
     *
     * Field lookup covers the two transport shapes the supported providers
     * use: direct $_POST fields (Elementor, Gravity Forms, WPForms, Contact
     * Form 7), and Fluent Forms' URL-encoded blob in $_POST['data'].
     *
     * @return self
     */
    public static function fromCurrentRequest(): self
    {
        $post = wp_unslash($_POST);
        $post = is_array($post) ? $post : [];

        // Fluent Forms posts the serialized form as one 'data' field.
        if (
            (!isset($post[self::FIELD_CONVERSION]) || !is_string($post[self::FIELD_CONVERSION]))
            && isset($post['data']) && is_string($post['data'])
        ) {
            $parsed = [];
            wp_parse_str($post['data'], $parsed);
            foreach ([self::FIELD_CONVERSION, self::FIELD_SESSION, self::FIELD_CONTEXT] as $field) {
                if (isset($parsed[$field]) && is_string($parsed[$field]) && !isset($post[$field])) {
                    $post[$field] = $parsed[$field];
                }
            }
        }

        return self::fromFields($post);
    }

    /**
     * Builds correlation data from an arbitrary field map (used by
     * providers whose posted data is only available pre-extracted, and by
     * the public custom-form API).
     *
     * @param array<string, mixed> $fields Raw field map (already unslashed).
     * @return self
     */
    public static function fromFields(array $fields): self
    {
        $conversionId = self::validConversionId($fields[self::FIELD_CONVERSION] ?? null);
        $sessionId    = self::validSessionId($fields[self::FIELD_SESSION] ?? null);
        $fromTracker  = $conversionId !== '';

        if ($conversionId === '') {
            // No tracker token — generate the conversion id server-side so
            // the submission still has a conversion identity ('c' + 16 hex,
            // the same shape the tracker generates).
            $conversionId = 'c' . substr(md5(wp_generate_uuid4() . wp_rand()), 0, 16);
        }

        $context = self::parseContext($fields[self::FIELD_CONTEXT] ?? null);

        return new self(
            conversionId: $conversionId,
            sessionId: $sessionId,
            fromTracker: $fromTracker,
            attribution: $context['attribution'],
            sessionReferrer: $context['session_referrer'],
            sessionDirect: $context['session_direct'],
            landingPage: $context['landing_page'],
            pageUrl: $context['page_url'],
        );
    }

    /**
     * The analytics_context block for this correlation, in the exact shape
     * the form-submission payload documents. The channel is classified by
     * the SAME engine as the analytics dashboard ({@see Channels}).
     *
     * @param string $device Server-side device bucket ('desktop'/'mobile').
     * @return array<string, mixed>
     */
    public function toAnalyticsContext(string $device): array
    {
        $channelRow = array_merge($this->attribution, [
            'referrer'         => $this->sessionReferrer,
            'session_referrer' => $this->sessionReferrer,
            'session_direct'   => $this->sessionDirect ? '1' : '',
        ]);

        return [
            'session_id'        => $this->sessionId,
            'channel'           => $this->fromTracker ? Channels::classify($channelRow, 'form_success') : '',
            'attribution'       => [
                'utm_source'    => $this->attribution['utm_source'],
                'utm_medium'    => $this->attribution['utm_medium'],
                'utm_campaign'  => $this->attribution['utm_campaign'],
                'utm_id'        => $this->attribution['utm_id'],
                'utm_term'      => $this->attribution['utm_term'],
                'utm_content'   => $this->attribution['utm_content'],
                'click_id_type' => $this->attribution['click_id_type'],
            ],
            'entrance_referrer' => $this->sessionReferrer,
            'landing_page'      => ['url' => $this->landingPage],
            'device'            => $device,
        ];
    }

    /**
     * Validates a conversion token: 8–100 characters of A-Za-z0-9_.:- (the
     * same rule the events table enforces on form_success rows).
     *
     * @param mixed $value Raw field value.
     * @return string The token, or '' when invalid/absent.
     */
    private static function validConversionId(mixed $value): string
    {
        if (!is_string($value) || !preg_match('~^[A-Za-z0-9_.:\-]{8,100}$~', $value)) {
            return '';
        }

        return $value;
    }

    /**
     * Validates a session id: 16–64 lowercase hex characters (the tracker
     * generates 32).
     *
     * @param mixed $value Raw field value.
     * @return string The id, or '' when invalid/absent.
     */
    private static function validSessionId(mixed $value): string
    {
        if (!is_string($value) || !preg_match('~^[a-f0-9]{16,64}$~i', $value)) {
            return '';
        }

        return strtolower($value);
    }

    /**
     * Parses and sanitizes the cvm_context JSON snapshot.
     *
     * Every field is validated exactly like the tracking REST endpoint
     * validates it: utm values are text-sanitized, capped, and dropped when
     * they look like an email address; only whitelisted click-id TYPES pass;
     * referrer and landing/page URLs are normalized to scheme://host/path;
     * and the page URL must belong to this site's host.
     *
     * @param mixed $raw Raw field value.
     * @return array{attribution: array<string, string>, session_referrer: string, session_direct: bool, landing_page: string, page_url: string}
     */
    private static function parseContext(mixed $raw): array
    {
        $empty = [
            'attribution'      => [
                'utm_source' => '', 'utm_medium' => '', 'utm_campaign' => '',
                'utm_id' => '', 'utm_term' => '', 'utm_content' => '',
                'click_id_type' => '',
            ],
            'session_referrer' => '',
            'session_direct'   => false,
            'landing_page'     => '',
            'page_url'         => '',
        ];

        if (!is_string($raw) || $raw === '' || strlen($raw) > self::MAX_CONTEXT_BYTES || !json_validate($raw)) {
            return $empty;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return $empty;
        }

        $out = $empty;

        foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_id', 'utm_term', 'utm_content'] as $param) {
            $value = $decoded[$param] ?? '';
            if (!is_scalar($value)) {
                continue;
            }
            $value = sanitize_text_field((string) $value);
            // Same PII guard as the tracking endpoint: a mailer interpolating
            // the recipient's address into a utm value must never be stored.
            if ($value === '' || str_contains($value, '@')) {
                continue;
            }
            $max = in_array($param, ['utm_source', 'utm_medium', 'utm_id'], true) ? 100 : 191;
            $out['attribution'][$param] = mb_substr($value, 0, $max);
        }

        $out['attribution']['utm_source'] = Channels::normalizeSource($out['attribution']['utm_source']);
        $out['attribution']['utm_medium'] = strtolower($out['attribution']['utm_medium']);

        $clickId = $decoded['click_id_type'] ?? '';
        if (is_string($clickId) && in_array(sanitize_key($clickId), Channels::CLICK_ID_TYPES, true)) {
            $out['attribution']['click_id_type'] = sanitize_key($clickId);
        }

        $out['session_referrer'] = self::normalizeUrl($decoded['session_referrer'] ?? '', false);
        $out['session_direct']   = !empty($decoded['session_direct']);
        $out['landing_page']     = self::normalizeUrl($decoded['landing_page'] ?? '', true);
        $out['page_url']         = self::normalizeUrl($decoded['page_url'] ?? '', true);

        return $out;
    }

    /**
     * Normalizes a URL to scheme://host/path — query strings and fragments
     * are never kept (they can carry tokens or emails). When $sameHost is
     * true, the host must additionally belong to this site.
     *
     * @param mixed $value    Raw URL value.
     * @param bool  $sameHost Require the host to be one of this site's allowed hosts.
     * @return string Normalized URL, or '' when invalid.
     */
    private static function normalizeUrl(mixed $value, bool $sameHost): string
    {
        if (!is_string($value) || $value === '') {
            return '';
        }

        $parts = wp_parse_url($value);
        if (!is_array($parts) || empty($parts['host'])) {
            return '';
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if ($scheme !== 'http' && $scheme !== 'https') {
            return '';
        }

        $host = strtolower((string) $parts['host']);
        if ($sameHost && !in_array($host, Options::allowedHosts(), true)) {
            return '';
        }

        return mb_substr($scheme . '://' . $parts['host'] . ($parts['path'] ?? '/'), 0, 255);
    }
}
