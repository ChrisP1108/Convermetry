<?php
declare(strict_types=1);

namespace Convermetry\Notifications;

if (!defined('ABSPATH')) exit;

/**
 * The three site facts a notification email needs: what to call the site, where
 * it lives, and where the admin screens are.
 *
 * Read from WordPress by {@see current()}, and then — critically — allowed to
 * be OVERRIDDEN by the settings snapshot frozen onto each queue row. A
 * notification renders in a background worker, possibly hours after the lead
 * arrived and possibly after the site was renamed; the snapshot is what makes
 * the email describe the site as it was when the visitor submitted, which is
 * what {@see withName()} exists for.
 *
 * Distinct from {@see \Convermetry\Webhook\WebsiteInfo}, which is the identity
 * block on the wire and carries the configured website/client ids a receiving
 * system keys on. This one never leaves the email.
 */
final readonly class SiteInfo
{
    /**
     * @param string $siteName Site name (WordPress "blogname").
     * @param string $homeUrl  Site home URL, with a trailing slash.
     * @param string $adminUrl URL of the admin's admin.php entry point.
     */
    public function __construct(
        public string $siteName,
        public string $homeUrl,
        public string $adminUrl,
    ) {
    }

    /**
     * Reads the current site's details from WordPress.
     *
     * @return self
     */
    public static function current(): self
    {
        return new self(
            siteName: (string) get_bloginfo('name'),
            homeUrl: (string) home_url('/'),
            adminUrl: (string) admin_url('admin.php'),
        );
    }

    /**
     * A copy under a different site name — the one the queue row's frozen
     * settings snapshot recorded. A blank name changes nothing.
     *
     * @param string $siteName Snapshot site name.
     * @return self
     */
    public function withName(string $siteName): self
    {
        return $siteName === '' ? $this : new self($siteName, $this->homeUrl, $this->adminUrl);
    }
}
