<?php
declare(strict_types=1);

namespace Convermetry\Support;

if (!defined('ABSPATH')) exit;

/**
 * Announces a *verified* storage failure to integrations.
 *
 * The bar for firing this is deliberately high, because a monitoring hook that
 * cries wolf is worse than none. It fires only where Convermetry has positive
 * evidence that a database operation the plugin needed did not happen:
 *
 *  - a multi-row event INSERT that returned false,
 *  - a lead-update transaction that rolled back,
 *  - a lead-history row that failed to insert inside that transaction.
 *
 * Things that look like failures and are not, and therefore never fire it:
 *
 *  - FormSubmissions::insert() returning null. That table's writes are
 *    INSERT IGNORE against a unique conversion id, so null is the *expected*
 *    result of a duplicate submission — the ordinary case, not an error.
 *  - A notification abandoned after exhausting its retries. The queue row was
 *    written and read back correctly; the mail transport is what gave up.
 *  - A migration that is still pending after a run. Owners deliberately leave
 *    their version unstamped so they are asked again next pass.
 *
 * No argument ever carries SQL, a request body, submitted fields, an IP
 * address, or a secret. In particular $wpdb->last_error is never passed: it
 * quotes the failing statement verbatim, which on this plugin's write paths
 * means submitted form values and visitor addresses would land wherever a
 * listener chooses to log them. Callers pass a stable machine-readable code
 * instead.
 */
final class Errors
{
    /**
     * Fires convermetry_storage_error for one verified database failure.
     *
     * @param string               $subsystem Component that owns the write ('events', 'leads', …).
     * @param string               $operation Operation that failed ('insert', 'update', 'transaction', …).
     * @param string               $code      Stable machine-readable failure code.
     * @param array<string, mixed> $context   Safe scalar context (ids, counts). Never SQL, PII, or secrets.
     * @return void
     */
    public static function storage(string $subsystem, string $operation, string $code, array $context = []): void
    {
        /**
         * Fires when a database operation Convermetry needed verifiably failed.
         *
         * Observational only — returning a value does nothing, and the write is
         * not retried on your behalf. Fires synchronously on the failing
         * request, which may be a cron pass, a REST call, or a form submission.
         *
         * Nothing passed here contains SQL, submitted fields, IP addresses, or
         * secrets; $context holds scalar identifiers and counts only.
         *
         * @param string               $subsystem Component that owns the write.
         * @param string               $operation Operation that failed.
         * @param string               $code      Stable machine-readable failure code.
         * @param array<string, mixed> $context   Safe scalar context.
         */
        do_action('convermetry_storage_error', $subsystem, $operation, $code, $context);
    }
}
