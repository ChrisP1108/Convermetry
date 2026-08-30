<?php
declare(strict_types=1);

namespace Convermetry\Webhook;

if (!defined('ABSPATH')) exit;

/**
 * The page a form was submitted from.
 *
 * Appears as website_info.page on form-submission payloads only; analytics
 * reports have no single page and omit the block entirely, which is why
 * {@see WebsiteInfo::$page} is nullable and this is a separate object rather
 * than two more fields on it.
 *
 * The URL has already been same-host validated by
 * {@see \Convermetry\Tracking\Correlation}, and the query parameters have
 * already been sanitized, by the time one of these is built — nothing here
 * re-validates, because the submission row it is read from was written from
 * validated values.
 */
final readonly class PageInfo
{
    /**
     * @param string                $url   The submitting page's URL, or ''.
     * @param array<string, string> $query That page's query parameters.
     */
    public function __construct(
        public string $url = '',
        public array $query = [],
    ) {
    }

    /**
     * The wire form, exactly as it has always appeared under
     * website_info.page.
     *
     * @return array{url: string, query: array<string, string>}
     */
    public function toArray(): array
    {
        return ['url' => $this->url, 'query' => $this->query];
    }
}
