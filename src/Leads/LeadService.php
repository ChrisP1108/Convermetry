<?php
declare(strict_types=1);

namespace Convermetry\Leads;

if (!defined('ABSPATH')) exit;

use Convermetry\Admin\Capability;
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
     * Kept for compatibility with anything that read it, and still the default
     * this resolves to. It is no longer used internally: a constant cannot be
     * filtered, and {@see userCanEdit()} now asks {@see Capability} for the
     * 'leads.edit' scope so a site can delegate lead editing without granting
     * every other Convermetry permission. Read
     * Capability::required(Capability::LEADS_EDIT) rather than this constant.
     *
     * @deprecated Use {@see Capability::LEADS_EDIT} with {@see Capability::required()}.
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

        // Minted before the transaction rather than read back after it. The
        // history row's id is decided here, passed down, and written inside the
        // same transaction as always — the transaction's shape, order, and
        // rollback conditions are untouched, and the id is available to report
        // once it has committed.
        $eventId = LeadEvents::mintId();

        $stored = FormSubmissions::updateLead(
            $submissionId,
            $toStatus,
            $newValue,
            $currency,
            $userId,
            $fromStatus,
            $eventId
        );

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

        /**
         * Fires after a lead update and its history row have both committed.
         *
         * Fires immediately after convermetry_lead_status_updated, which is
         * unchanged and keeps its five arguments. This one carries what that
         * action's fixed signature cannot: the before/after value, the user who
         * made the change, and the id of the history row recording it.
         *
         * Both fire AFTER the transaction commits, never inside it, so a
         * listener that queries the submission or its history sees the new state
         * and cannot roll the write back by throwing.
         *
         * Values are exact decimal STRINGS, never floats — '1234.50', not
         * 1234.5. Currency is stamped onto a value when it is first set and is
         * not a conversion: two leads with different currencies are two
         * different amounts and Convermetry never adds them together. A null
         * value means no amount is recorded, which is not the same as '0.00'.
         *
         * @param string      $submissionId  The submission's globally unique id.
         * @param array{status: string, value: string|null, currency: string} $to   State after the change.
         * @param array{status: string, value: string|null, currency: string} $from State before the change.
         * @param int         $userId        WordPress user id that made the change (0 when unknown).
         * @param string      $leadEventId   Id of the lead-history row recording this change.
         */
        do_action(
            'convermetry_lead_updated',
            $submissionId,
            ['status' => $toStatus, 'value' => $newValue, 'currency' => $currency],
            [
                'status'   => $fromStatus,
                'value'    => $current['lead_value'],
                'currency' => (string) $current['lead_currency'],
            ],
            $userId,
            $eventId
        );

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
        return Capability::currentUserCan(Capability::LEADS_EDIT);
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
