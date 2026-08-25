<?php
declare(strict_types=1);

namespace Convermetry\Forms\Providers;

if (!defined('ABSPATH')) exit;

use Convermetry\Forms\FormProviderInterface;
use Convermetry\Forms\SubmissionService;

/**
 * Contact Form 7 integration.
 *
 * Uses the documented wpcf7_mail_sent action — fired only after the
 * submission's mail was successfully sent (the closest thing CF7 has to a
 * server-confirmed success) — reading the posted data from the
 * WPCF7_Submission singleton, CF7's public API for exactly this.
 *
 * Identity: the contact form's post ID (stable across renames).
 */
final class ContactForm7Provider implements FormProviderInterface
{
    public function getKey(): string
    {
        return 'contactform7';
    }

    public function getLabel(): string
    {
        return 'Contact Form 7';
    }

    public function isAvailable(): bool
    {
        return class_exists('WPCF7_ContactForm');
    }

    /**
     * @return array<int, array{native_id: string, name: string}>
     */
    public function getForms(): array
    {
        $forms = \WPCF7_ContactForm::find(['posts_per_page' => 200]);
        if (!is_array($forms)) {
            return [];
        }

        $out = [];
        foreach ($forms as $form) {
            if (!is_object($form) || !method_exists($form, 'id') || !method_exists($form, 'title')) {
                continue;
            }

            $out[] = [
                'native_id' => (string) $form->id(),
                'name'      => (string) $form->title(),
            ];
        }

        return $out;
    }

    public function registerHooks(SubmissionService $service): void
    {
        add_action(
            'wpcf7_mail_sent',
            function (mixed $contactForm) use ($service): void {
                $this->handleSubmission($contactForm, $service);
            }
        );
    }

    /**
     * Reads the posted data for a successfully sent CF7 submission and
     * forwards it to the pipeline.
     *
     * @param mixed             $contactForm WPCF7_ContactForm instance.
     * @param SubmissionService $service     The shared pipeline.
     * @return void
     */
    private function handleSubmission(mixed $contactForm, SubmissionService $service): void
    {
        if (
            !is_object($contactForm)
            || !method_exists($contactForm, 'id')
            || !method_exists($contactForm, 'title')
            || !class_exists('WPCF7_Submission')
        ) {
            return;
        }

        $submission = \WPCF7_Submission::get_instance();
        if ($submission === null || !method_exists($submission, 'get_posted_data')) {
            return;
        }

        $posted = $submission->get_posted_data();
        $fields = [];

        foreach ((array) $posted as $key => $value) {
            $key = (string) $key;

            // CF7 prefixes its own internals with an underscore; skip those.
            if ($key === '' || str_starts_with($key, '_')) {
                continue;
            }

            // CF7's posted data is keyed by the form tag's name ('your-email')
            // and carries no label. A label would mean parsing the form's
            // markup, which is not a public API and breaks on any custom
            // template — so the tag name is used as both id and label, and the
            // normalizer records that fallback honestly.
            $fields[] = ['id' => $key, 'label' => $key, 'value' => $value];
        }

        $service->record(
            provider: $this->getKey(),
            nativeId: (string) $contactForm->id(),
            formName: (string) $contactForm->title(),
            fields: $fields
        );
    }
}
