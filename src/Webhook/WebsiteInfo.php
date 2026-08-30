<?php
declare(strict_types=1);

namespace Convermetry\Webhook;

if (!defined('ABSPATH')) exit;

use Convermetry\Settings\Options;

/**
 * The 'website_info' block carried by every outbound Convermetry webhook
 * payload — analytics reports and form submissions alike — so the identity
 * schema can never drift between message types.
 *
 * Every key is always present (empty string when not configured), giving
 * downstream systems a predictable schema they never have to null-check:
 *
 *     "website_info": {
 *         "name": "…", "url": "…", "domain": "…", "id": "…",
 *         "client": { "first_name": "…", "last_name": "…", "id": "…" }
 *     }
 *
 * plus a "page" block on form submissions only.
 *
 * 'domain' is derived automatically from the site's home URL with a leading
 * "www." stripped, so a fleet of sites reporting into one SaaS can be keyed by
 * bare domain without per-site configuration.
 *
 * THE WIRE SHAPE IS THE CONTRACT. {@see toArray()} is the only thing that may
 * decide which keys exist and in what order; every payload goes through it.
 * The properties here exist so the plugin can talk about a site's identity in
 * typed terms, not so the block can be assembled differently somewhere else.
 */
final readonly class WebsiteInfo
{
    /**
     * @param string     $name   Site name (WordPress "blogname").
     * @param string     $url    Site home URL.
     * @param string     $domain Home URL's host, lowercased, "www." stripped.
     * @param string     $id     Website identifier in the receiving system, or ''.
     * @param ClientInfo $client The client this site belongs to.
     * @param PageInfo|null $page The submitting page, on form submissions only.
     */
    public function __construct(
        public string $name,
        public string $url,
        public string $domain,
        public string $id,
        public ClientInfo $client,
        public ?PageInfo $page = null,
    ) {
    }

    /**
     * Reads this site's identity from WordPress and the plugin's settings.
     *
     * @param PageInfo|null $page The submitting page, for a form submission.
     * @return self
     */
    public static function current(?PageInfo $page = null): self
    {
        return new self(
            name: (string) get_bloginfo('name'),
            url: (string) home_url(),
            domain: self::domain(),
            id: Options::websiteId(),
            client: ClientInfo::fromSettings(),
            page: $page,
        );
    }

    /**
     * The site's normalized domain: the home URL's host, lowercased, with a
     * leading "www." removed.
     *
     * @return string
     */
    public static function domain(): string
    {
        $host = strtolower((string) wp_parse_url((string) home_url(), PHP_URL_HOST));

        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }

    /**
     * The wire form. Key order is load-bearing only in the sense that it has
     * always been this order; 'page' is appended last and only when present,
     * exactly as it always has been.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $info = [
            'name'   => $this->name,
            'url'    => $this->url,
            'domain' => $this->domain,
            'id'     => $this->id,
            'client' => $this->client->toArray(),
        ];

        if ($this->page !== null) {
            $info['page'] = $this->page->toArray();
        }

        return $info;
    }
}
