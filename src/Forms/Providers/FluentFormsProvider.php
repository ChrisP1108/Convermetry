<?php
declare(strict_types=1);

namespace Convermetry\Forms\Providers;

if (!defined('ABSPATH')) exit;

use Convermetry\Forms\FormProviderInterface;
use Convermetry\Forms\SubmissionService;

/**
 * Fluent Forms integration.
 *
 * Uses the documented fluentform/submission_inserted action (plus its
 * pre-5.0 underscore-form alias) — fired after the entry was stored — with
 * the parsed form data Fluent Forms hands the hook. Because some Fluent
 * Forms versions fire both hook spellings for one submission, a per-request
 * guard keyed by entry id prevents double processing (and the pipeline's
 * conversion-id dedup backs that up).
 *
 * Identity: the numeric Fluent Forms form id (stable across renames).
 */
final class FluentFormsProvider implements FormProviderInterface
{
    /** @var array<int, true> Entry ids already processed in this request. */
    private array $processed = [];

    public function getKey(): string
    {
        return 'fluentforms';
    }

    public function getLabel(): string
    {
        return 'Fluent Forms';
    }

    public function isAvailable(): bool
    {
        return defined('FLUENTFORM') || function_exists('wpFluentForm');
    }

    /**
     * @return array<int, array{native_id: string, name: string}>
     */
    public function getForms(): array
    {
        global $wpdb;

        // Fluent Forms stores forms in its own documented table; listing id
        // and title is the stable, version-independent way to discover them.
        $table = $wpdb->prefix . 'fluentform_forms';

        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        if ($exists !== $table) {
            return [];
        }

        $rows = $wpdb->get_results(
            "SELECT id, title FROM {$table} ORDER BY title ASC LIMIT 200",
            ARRAY_A
        );

        if (!is_array($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'native_id' => (string) $row['id'],
                'name'      => (string) ($row['title'] ?? ('Form ' . $row['id'])),
            ];
        }

        return $out;
    }

    /**
     * Spam handling: UNRESOLVED — no guard is installed here yet.
     *
     * fluentform/submission_inserted fires once the row exists, so whether a
     * spam submission can reach this pipeline depends on when Fluent Forms
     * assigns the entry's 'spam' status: at insert (in which case reading the
     * status here is sufficient) or afterwards (in which case an inline read
     * cannot see it, and a later hook or a deferred check before queuing is
     * required instead). Resolving that needs a live Fluent Forms install,
     * which was not available when this was written.
     *
     * Deliberately left unguarded rather than shipping a status read that may
     * silently never match: a check that looks correct but does nothing is
     * worse than a recorded gap. Until this is settled, spam entries may be
     * recorded and delivered. See also the same open question in
     * {@see WPFormsProvider::registerHooks()}.
     *
     * @param SubmissionService $service The shared pipeline.
     * @return void
     */
    public function registerHooks(SubmissionService $service): void
    {
        $handler = function (mixed $entryId, mixed $formData, mixed $form) use ($service): void {
            $this->handleSubmission($entryId, $formData, $form, $service);
        };

        add_action('fluentform/submission_inserted', $handler, 10, 3);
        add_action('fluentform_submission_inserted', $handler, 10, 3);
    }

    /**
     * Flattens a stored Fluent Forms submission and forwards it to the
     * pipeline.
     *
     * @param mixed             $entryId  The stored entry id.
     * @param mixed             $formData The parsed submission data.
     * @param mixed             $form     The form model (id, title).
     * @param SubmissionService $service  The shared pipeline.
     * @return void
     */
    private function handleSubmission(mixed $entryId, mixed $formData, mixed $form, SubmissionService $service): void
    {
        $entryId = (int) $entryId;
        if ($entryId > 0 && isset($this->processed[$entryId])) {
            return; // Both hook spellings fired for one submission.
        }
        $this->processed[$entryId] = true;

        $formId    = is_object($form) ? (string) ($form->id ?? '') : (is_array($form) ? (string) ($form['id'] ?? '') : '');
        $formTitle = is_object($form) ? (string) ($form->title ?? '') : (is_array($form) ? (string) ($form['title'] ?? '') : '');

        if ($formId === '') {
            return;
        }

        $fields = [];
        foreach ((array) $formData as $key => $value) {
            $key = (string) $key;

            // Skip Fluent Forms' own internals (__fluent_form_embded_post_id,
            // _fluentform_*_fluentformnonce, _wp_http_referer, ...).
            if ($key === '' || str_starts_with($key, '_')) {
                continue;
            }

            $fields[$key] = $value;
        }

        $service->record(
            provider: $this->getKey(),
            nativeId: $formId,
            formName: $formTitle !== '' ? $formTitle : ('Form ' . $formId),
            fields: $fields
        );
    }
}
