<?php
declare(strict_types=1);

namespace Convermetry\Webhook;

if (!defined('ABSPATH')) exit;

use Convermetry\Settings\Options;

/**
 * The client this site belongs to, as configured on the Settings page.
 *
 * The 'client' sub-block of every payload's website_info. All three values
 * are optional and are emitted as EMPTY STRINGS rather than omitted or null
 * when unconfigured — a fleet of sites reporting into one SaaS gets the same
 * keys from every site, so a receiver never has to null-check the block into
 * existence. That is a published property of the schema, not an accident, and
 * is why these are `string` rather than `?string`.
 */
final readonly class ClientInfo
{
    /**
     * @param string $firstName Client contact's first name, or ''.
     * @param string $lastName  Client contact's last name, or ''.
     * @param string $id        Client identifier in the receiving system, or ''.
     */
    public function __construct(
        public string $firstName = '',
        public string $lastName = '',
        public string $id = '',
    ) {
    }

    /**
     * Reads the configured client identity from the plugin's settings.
     *
     * @return self
     */
    public static function fromSettings(): self
    {
        return new self(
            firstName: Options::clientFirstName(),
            lastName: Options::clientLastName(),
            id: Options::clientId(),
        );
    }

    /**
     * The wire form, exactly as it has always appeared under
     * website_info.client.
     *
     * @return array{first_name: string, last_name: string, id: string}
     */
    public function toArray(): array
    {
        return [
            'first_name' => $this->firstName,
            'last_name'  => $this->lastName,
            'id'         => $this->id,
        ];
    }
}
