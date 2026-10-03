<?php
declare(strict_types=1);

namespace Convermetry\Admin;

if (!defined('ABSPATH')) exit;

/**
 * The reporting period selected on the Analytics, Goals and Funnels screens.
 *
 * The period is a read-only filter — it only chooses which days a report
 * covers — but a supplied value is still accepted only from a link the screen
 * itself issued. Each period link carries a nonce for that screen's FILTER
 * action, which is separate from every save and delete nonce: a filter nonce
 * authorizes nothing but reading the period, and no save or delete nonce is
 * ever accepted in its place.
 *
 * Without a period in the URL there is nothing to verify and the default range
 * is shown, so ordinary navigation never needs a nonce. A supplied period whose
 * nonce is missing, malformed, expired, or for another screen is ignored — the
 * default range is shown and {@see self::$refused} lets the screen say so,
 * which is what an old bookmarked report link should do.
 */
final class ReportPeriod
{
    /** Query argument carrying the filter nonce. */
    public const string NONCE_FIELD = 'cvmtry_period_nonce';

    /** The range shown when no period was requested or one was refused. */
    public const int DEFAULT_DAYS = 30;

    /**
     * @param int  $days    The period to report on, in days.
     * @param bool $refused Whether a supplied period was ignored.
     */
    private function __construct(
        public readonly int $days,
        public readonly bool $refused
    ) {
    }

    /**
     * Reads the requested period from the query string.
     *
     * @param string    $nonceAction The screen's filter nonce action.
     * @param int[]     $allowed     The periods this screen offers.
     * @param int       $default     The period shown otherwise.
     * @return self
     */
    public static function fromRequest(string $nonceAction, array $allowed, int $default = self::DEFAULT_DAYS): self
    {
        // Ordinary navigation: no period requested, nothing to verify.
        if (!isset($_GET['period'])) {
            return new self($default, false);
        }

        // A supplied period is honoured only with this screen's filter nonce,
        // present as a single string.
        if (!isset($_GET[self::NONCE_FIELD]) || !is_string($_GET[self::NONCE_FIELD])) {
            return new self($default, true);
        }

        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_GET[self::NONCE_FIELD])), $nonceAction)) {
            return new self($default, true);
        }

        // The value itself: one string of digits naming an offered period.
        if (!is_string($_GET['period'])) {
            return new self($default, true);
        }

        $raw = sanitize_text_field(wp_unslash($_GET['period']));
        if (preg_match('/^[1-9][0-9]{0,3}$/', $raw) !== 1) {
            return new self($default, true);
        }

        $days = (int) $raw;

        return in_array($days, $allowed, true) ? new self($days, false) : new self($default, true);
    }

    /**
     * The query arguments that select one period on a screen.
     *
     * Used for the period links themselves and for every form and redirect
     * that has to keep the current period, so a fresh filter nonce always
     * travels with it.
     *
     * @param string $nonceAction The screen's filter nonce action.
     * @param int    $days        The period, in days.
     * @return array{period: string, cvmtry_period_nonce: string}
     */
    public static function queryArgs(string $nonceAction, int $days): array
    {
        return [
            'period'           => (string) $days,
            self::NONCE_FIELD  => wp_create_nonce($nonceAction),
        ];
    }

    /**
     * The query arguments that keep this period across a form post or redirect.
     *
     * Empty for the default range, so a screen that was never filtered keeps
     * clean URLs.
     *
     * @param string $nonceAction The screen's filter nonce action.
     * @param int    $default     The screen's default period.
     * @return array<string, string>
     */
    public function carryArgs(string $nonceAction, int $default = self::DEFAULT_DAYS): array
    {
        return $this->days === $default ? [] : self::queryArgs($nonceAction, $this->days);
    }

    /**
     * The notice shown when a supplied period was refused.
     *
     * @param int $default The period shown instead, in days.
     * @return string
     */
    public static function refusedMessage(int $default = self::DEFAULT_DAYS): string
    {
        return sprintf(
            /* translators: %d: number of days in the default reporting period. */
            _n(
                'That report link has expired or is not valid, so the last %d day is shown. Choose a period below to change it.',
                'That report link has expired or is not valid, so the last %d days are shown. Choose a period below to change it.',
                $default,
                'convermetry'
            ),
            $default
        );
    }
}
