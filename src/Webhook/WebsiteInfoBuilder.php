<?php
declare(strict_types=1);

namespace Convermetry\Webhook;

if (!defined('ABSPATH')) exit;

use Convermetry\Settings\Options;

/**
 * The one producer of the 'website_info' block carried by every outbound
 * webhook payload — analytics reports and form submissions alike — so the
 * identity schema can never drift between message types.
 *
 * Every key is always present (empty string when not configured), giving
 * downstream systems a predictable schema they never have to null-check:
 *
 *     "website_info": {
 *         "name": "…", "url": "…", "domain": "…", "id": "…",
 *         "client": { "first_name": "…", "last_name": "…", "id": "…" }
 *     }
 *
 * 'domain' is derived automatically from the site's home URL with a leading
 * "www." stripped, so a fleet of sites reporting into one SaaS can be keyed
 * by bare domain without per-site configuration.
 */
final class WebsiteInfoBuilder
{
    /**
     * Builds the website_info block, optionally with a 'page' sub-block for
     * form-submission payloads (the page the form was submitted from).
     *
     * @param array{url: string, query: array<string, string>}|null $page Page info, or null to omit.
     * @return array<string, mixed>
     */
    public static function build(?array $page = null): array
    {
        $info = [
            'name'   => get_bloginfo('name'),
            'url'    => home_url(),
            'domain' => self::domain(),
            'id'     => Options::websiteId(),
            'client' => [
                'first_name' => Options::clientFirstName(),
                'last_name'  => Options::clientLastName(),
                'id'         => Options::clientId(),
            ],
        ];

        if ($page !== null) {
            $info['page'] = [
                'url'   => (string) ($page['url'] ?? ''),
                'query' => (array) ($page['query'] ?? []),
            ];
        }

        return $info;
    }

    /**
     * The site's normalized domain: the home URL's host, lowercased, with a
     * leading "www." removed.
     *
     * @return string
     */
    public static function domain(): string
    {
        $host = strtolower((string) wp_parse_url(home_url(), PHP_URL_HOST));

        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }
}
