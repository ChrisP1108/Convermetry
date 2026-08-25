<?php
declare(strict_types=1);

namespace Convermetry\Notifications;

if (!defined('ABSPATH')) exit;

use Convermetry\Database\FormSubmissions;
use Convermetry\Settings\Options;

/**
 * Turns a recorded submission into queued notification work.
 *
 * WHY 'convermetry_submission_recorded' IS THE RIGHT HOOK. It fires only after
 * a genuinely new, durable submission row exists (the duplicate-conversion
 * branch returns before it), and — critically — it fires BEFORE
 * SubmissionService checks whether any webhook endpoint is configured. Email
 * notifications must work on a site with no webhooks at all, which is the
 * default. Do not "tidy" that hook's position in record().
 *
 * The action carries only ($submissionId, $conversionId, $context) — no form
 * key. Rather than widening a documented public extension point for one
 * internal consumer, the listener looks up the few identity columns it needs.
 * On a site with notifications off (the default) it never gets that far: the
 * entire cost in the visitor's request is one read of a non-autoloaded option.
 */
final class NotificationDispatcher
{
    /**
     * Registers the submission listener.
     *
     * @return void
     */
    public static function init(): void
    {
        add_action('convermetry_submission_recorded', [self::class, 'onSubmissionRecorded'], 10, 3);
    }

    /**
     * Queues notifications for a newly recorded submission.
     *
     * @param string               $submissionId The submission's globally unique id.
     * @param string               $conversionId The conversion id shared with analytics.
     * @param array<string, mixed> $context      The captured analytics context.
     * @return void
     */
    public static function onSubmissionRecorded(string $submissionId, string $conversionId, array $context): void
    {
        // Guard 1, the cheap one. On a default install this is where the
        // notification subsystem's cost in a visitor's request begins and ends.
        if (!Options::notificationsEnabled()) {
            return;
        }

        // Deliberately NOT getBySubmissionId(): that pulls three LONGTEXT
        // columns (submission_data, context, runtime) into the visitor's
        // request just to read a form key. Do not "simplify" it back.
        $identity = FormSubmissions::getIdentity($submissionId);
        if ($identity === null) {
            return;
        }

        $plan = self::plan(Options::notificationAll(), $identity, EmailBuilder::siteInfo());
        if ($plan === null) {
            return;
        }

        NotificationQueue::enqueue($submissionId, $plan['recipients'], $plan['snapshot']);
    }

    /**
     * The whole enqueue decision, as a pure function.
     *
     * Guard ORDER is a correctness property, not an optimization: the master
     * switch is checked before any form rule, and the form rule before any
     * recipient work, so a site that has switched notifications off cannot
     * queue mail no matter what else is configured. Keeping the chain here
     * makes that order directly testable.
     *
     * @param array<string, mixed>  $settings Full notification settings.
     * @param array<string, mixed>  $identity Submission identity columns.
     * @param array<string, string> $siteInfo {@see EmailBuilder::siteInfo()}.
     * @return array{recipients: list<string>, snapshot: array<string, mixed>}|null Null when nothing should be queued.
     */
    public static function plan(array $settings, array $identity, array $siteInfo): ?array
    {
        if (empty($settings['enabled'])) {
            return null;
        }

        $formKey = (string) ($identity['form_key'] ?? '');
        if (!NotificationSettings::shouldNotify($settings, $formKey)) {
            return null;
        }

        // Re-validated here rather than trusted from the option: an address
        // written by WP-CLI, a migration, or a filter must never reach
        // wp_mail() unchecked. Recipients are always administrator-configured
        // and are never derived from submitted visitor data.
        $recipients = NotificationSettings::sanitizeRecipients($settings['recipients'] ?? []);
        if ($recipients === []) {
            return null;
        }

        return [
            'recipients' => $recipients,
            'snapshot'   => NotificationSettings::snapshot($settings, $formKey, $siteInfo),
        ];
    }
}
