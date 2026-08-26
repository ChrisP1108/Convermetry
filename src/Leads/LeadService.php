<?php
declare(strict_types=1);

namespace Convermetry\Leads;

if (!defined('ABSPATH')) exit;

use Convermetry\Database\FormSubmissions;
use Convermetry\Settings\Options;

/**
 * Applies a lead status/value change to a submission.
 *
 * One entry point, {@see update()}, so every path that can change a lead's
 * outcome — the Submissions screen today, WP-CLI or a future webhook receiver
 * tomorrow — shares the same validation, the same currency-stamping rule, and
 * the same history record.
 *
 * THE UPDATE AND ITS HISTORY ROW ARE ONE TRANSACTION. A status change that
 * applied without being recorded would leave a lead whose history says it is
 * still 'new', and a history row without the change would say a lead was won
 * when it was not. Neither is recoverable by looking at the other, so they
 * commit together or not at all.
 *
 * CURRENCY IS STAMPED, NOT REFERENCED. The site's configured currency is copied
 * onto the row at the moment a value is first recorded, and never re-read
 * afterwards. A site that switches from USD to EUR next year must not silently
 * relabel two years of historical values as EUR — so the code travels with the
 * amount, and reports group by it rather than summing across codes.
 */
final class LeadService
{
    /**
     * The capability required to change a lead's outcome.
     *
     * Deliberately the same capability the rest of the plugin's admin surfaces
     * use. Lead value is commercially sensitive and, unlike most analytics, it
     * is WRITTEN by a human — so it gets the same gate as deleting submissions,
     * not a lesser one.
     */
    public const string CAPABILITY = 'manage_options';

    /**
     * Applies a change and records it.
     *
     * @param string      $submissionId The submission's globally unique id.
     * @param string|null $status       New status, or null to leave unchanged.
     * @param mixed       $value        New value (raw, as typed), or null to leave unchanged.
     *                                  Pass the empty string to CLEAR a recorded value.
     * @param int         $userId       The user making the change.
     * @return array{ok: bool, status: string, value: string|null, currency: string, message: string}
     */
    public static function update(string $submissionId, ?string $status, mixed $value, int $userId): array
    {
        $current = FormSubmissions::getLead($submissionId);

        if ($current === null) {
            return self::failure('That submission no longer exists.');
        }

        $fromStatus = LeadStatus::normalize($current['lead_status']);
        $toStatus   = $fromStatus;

        if ($status !== null) {
            if (!LeadStatus::isValid($status)) {
                // Rejected rather than coerced. Silently storing 'new' when
                // somebody meant 'won' would be worse than refusing.
                return self::failure('That is not a recognized lead status.');
            }

            $toStatus = $status;
        }

        $newValue = $current['lead_value'];
        $currency = (string) $current['lead_currency'];

        if ($value !== null) {
            if (is_string($value) && trim($value) === '') {
                // An explicitly emptied field clears the value. The currency
                // goes with it: a code attached to no amount is noise, and it
                // would make the row look like it holds a zero.
                $newValue = null;
                $currency = '';
            } else {
                $parsed = Money::parse($value);

                if ($parsed === null) {
                    return self::failure(
                        'That value could not be read as an amount. Enter a number, optionally with a '
                        . 'currency symbol and separators, for example 12,500.00'
                    );
                }

                $newValue = $parsed;

                // Stamped only when there is an amount to stamp it against, and
                // only when the row does not already carry one — an edit to an
                // existing value keeps the currency it was entered in.
                if ($currency === '') {
                    $currency = Options::leadCurrency();
                }
            }
        }

        $stored = FormSubmissions::updateLead($submissionId, $toStatus, $newValue, $currency, $userId, $fromStatus);

        if (!$stored) {
            return self::failure('The lead could not be updated.');
        }

        /**
         * Fires after a lead's status or value has changed.
         *
         * @param string      $submissionId The submission's globally unique id.
         * @param string      $toStatus     The new status.
         * @param string      $fromStatus   The previous status.
         * @param string|null $newValue     The new value as a decimal string, or null.
         * @param string      $currency     The currency stamped on the value, or ''.
         */
        do_action('convermetry_lead_status_updated', $submissionId, $toStatus, $fromStatus, $newValue, $currency);

        return [
            'ok'       => true,
            'status'   => $toStatus,
            'value'    => $newValue,
            'currency' => $currency,
            'message'  => '',
        ];
    }

    /**
     * Whether the current user may change lead outcomes.
     *
     * @return bool
     */
    public static function userCanEdit(): bool
    {
        return current_user_can(self::CAPABILITY);
    }

    /**
     * A failed result.
     *
     * @param string $message Reason, shown to the administrator.
     * @return array{ok: bool, status: string, value: string|null, currency: string, message: string}
     */
    private static function failure(string $message): array
    {
        return [
            'ok'       => false,
            'status'   => LeadStatus::DEFAULT,
            'value'    => null,
            'currency' => '',
            'message'  => $message,
        ];
    }
}
