<?php
declare(strict_types=1);

namespace Convermetry\Settings;

if (!defined('ABSPATH')) exit;

/**
 * One webhook endpoint as configured on the Webhooks page.
 *
 * Read out of the 'cvm_webhook_settings' option, which is why
 * {@see fromStoredArray()} coerces every field rather than trusting the
 * shape: the option is administrator-editable through the UI, through WP-CLI,
 * and through any filter a site has on option reads.
 *
 * $analytics and $forms are the two DELIVERY-TYPE flags, and they are
 * independent — an endpoint can receive scheduled reports, form submissions,
 * both, or (harmlessly) neither. They are the reason
 * {@see Options::analyticsEndpoints()} and {@see Options::formEndpoints()}
 * exist as separate lists.
 *
 * $secret is the endpoint's own signing secret. It is carried here because
 * the endpoint list is where it is configured, and it is deliberately NOT
 * exposed anywhere a delivery context, an Activity Log row, or a REST
 * response can reach — {@see Options::secretFor()} is the only reader.
 */
final readonly class WebhookEndpoint
{
    /**
     * @param string $url       Absolute endpoint URL, trimmed and non-empty.
     * @param string $label     Human-readable label, or ''.
     * @param string $secret    Per-endpoint signing secret, or '' to fall back to the shared one.
     * @param bool   $analytics Whether this endpoint receives scheduled analytics reports.
     * @param bool   $forms     Whether this endpoint receives form submissions.
     */
    public function __construct(
        public string $url,
        public string $label = '',
        public string $secret = '',
        public bool $analytics = false,
        public bool $forms = false,
    ) {
    }

    /**
     * Reads one stored endpoint row, or null when it carries no URL.
     *
     * A row without a URL is not an endpoint — it is a half-filled form the
     * administrator never completed — and has always been skipped rather than
     * stored as an empty destination.
     *
     * @param array<string, mixed> $entry A row from the endpoints option.
     * @return self|null
     */
    public static function fromStoredArray(array $entry): ?self
    {
        $url = trim((string) ($entry['url'] ?? ''));
        if ($url === '') {
            return null;
        }

        return new self(
            url: $url,
            label: trim((string) ($entry['label'] ?? '')),
            secret: trim((string) ($entry['secret'] ?? '')),
            analytics: !empty($entry['analytics']),
            forms: !empty($entry['forms']),
        );
    }

    /**
     * The stored form, with the keys the option has always held.
     *
     * @return array{url: string, label: string, secret: string, analytics: bool, forms: bool}
     */
    public function toArray(): array
    {
        return [
            'url'       => $this->url,
            'label'     => $this->label,
            'secret'    => $this->secret,
            'analytics' => $this->analytics,
            'forms'     => $this->forms,
        ];
    }
}
