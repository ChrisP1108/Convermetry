<?php
declare(strict_types=1);

namespace Convermetry\Database;

if (!defined('ABSPATH')) exit;

/**
 * One confirmed form submission, ready to be inserted.
 *
 * {@see FormSubmissions::insert()} took a twenty-key associative array, and
 * the shape drifted: its declared array shape said submission_data was an
 * `array<string, mixed>` (the pre-2.0 label-keyed map) while every caller had
 * been passing the schema 2.0 descriptor LIST since structured fields landed.
 * Nothing broke, because the value was JSON-encoded either way — which is
 * exactly why it went unnoticed for a release. The columns are constructor
 * parameters now, so a mismatch is a type error rather than a documentation
 * error.
 *
 * The six DENORMALIZED columns — channel, the four utm values, and
 * landing_page — are copies of values that also live inside {@see $context}.
 * They are written at insert time so the Submissions page and the campaign /
 * landing-page lead reports can filter and group without decoding every row's
 * JSON blob, and so a new row never enters the derived-column backfill queue.
 * {@see fromContext()} derives them from the context rather than asking the
 * caller to keep the two in step by hand.
 *
 * CARRIES PERSONAL DATA: {@see $fields} is the visitor's submitted values and
 * {@see $ipAddress} their address (empty when the setting is off or a privacy
 * signal was honored).
 */
final readonly class NewSubmission
{
    /**
     * @param string                                     $submissionId  Globally unique submission id.
     * @param string                                     $conversionId  Conversion id shared with analytics (the dedup key).
     * @param string                                     $sessionId     Analytics session id, or ''.
     * @param string                                     $provider      Provider key (e.g. 'elementor').
     * @param string                                     $formKey       Provider-scoped form key.
     * @param string                                     $formName      Human-readable form name.
     * @param string                                     $nativeFormId  Provider-native form identity.
     * @param string                                     $formId        Effective form id (per-form settings may override).
     * @param string                                     $pageUrl       Submitting page URL.
     * @param string                                     $ipAddress     Submitter's IP, or '' (PII).
     * @param string                                     $channel       Denormalized: marketing channel.
     * @param string                                     $utmCampaign   Denormalized: utm_campaign.
     * @param string                                     $utmSource     Denormalized: utm_source.
     * @param string                                     $utmMedium     Denormalized: utm_medium.
     * @param string                                     $utmId         Denormalized: utm_id.
     * @param string                                     $landingPage   Denormalized: landing page URL.
     * @param array<string, string>                      $pageQuery     Submitting page's query parameters.
     * @param list<array{id: string, label: string, value: string|list<string>}> $fields Schema 2.0 descriptors (PII).
     * @param array<string, mixed>                       $context       The captured analytics context.
     * @param array{query: array<string, string>, headers: array<string, string>} $runtime Per-submission overrides.
     */
    public function __construct(
        public string $submissionId,
        public string $conversionId,
        public string $sessionId,
        public string $provider,
        public string $formKey,
        public string $formName,
        public string $nativeFormId,
        public string $formId,
        public string $pageUrl,
        public string $ipAddress,
        public string $channel,
        public string $utmCampaign,
        public string $utmSource,
        public string $utmMedium,
        public string $utmId,
        public string $landingPage,
        public array $pageQuery,
        public array $fields,
        public array $context,
        public array $runtime,
    ) {
    }

    /**
     * Builds the record, deriving the six denormalized columns from the
     * analytics context so the copies cannot disagree with the original.
     *
     * @param string                                     $submissionId Globally unique submission id.
     * @param string                                     $conversionId Conversion id shared with analytics.
     * @param string                                     $sessionId    Analytics session id, or ''.
     * @param string                                     $provider     Provider key.
     * @param string                                     $formKey      Provider-scoped form key.
     * @param string                                     $formName     Human-readable form name.
     * @param string                                     $nativeFormId Provider-native form identity.
     * @param string                                     $formId       Effective form id.
     * @param string                                     $pageUrl      Submitting page URL.
     * @param string                                     $ipAddress    Submitter's IP, or ''.
     * @param array<string, string>                      $pageQuery    Submitting page's query parameters.
     * @param list<array{id: string, label: string, value: string|list<string>}> $fields Schema 2.0 descriptors.
     * @param array<string, mixed>                       $context      The captured analytics context.
     * @param array{query: array<string, string>, headers: array<string, string>} $runtime Per-submission overrides.
     * @return self
     */
    public static function fromContext(
        string $submissionId,
        string $conversionId,
        string $sessionId,
        string $provider,
        string $formKey,
        string $formName,
        string $nativeFormId,
        string $formId,
        string $pageUrl,
        string $ipAddress,
        array $pageQuery,
        array $fields,
        array $context,
        array $runtime,
    ): self {
        $attribution = is_array($context['attribution'] ?? null) ? $context['attribution'] : [];
        $landing     = is_array($context['landing_page'] ?? null) ? $context['landing_page'] : [];

        $value = static fn(array $source, string $key): string
            => is_scalar($source[$key] ?? null) ? (string) $source[$key] : '';

        return new self(
            submissionId: $submissionId,
            conversionId: $conversionId,
            sessionId: $sessionId,
            provider: $provider,
            formKey: $formKey,
            formName: $formName,
            nativeFormId: $nativeFormId,
            formId: $formId,
            pageUrl: $pageUrl,
            ipAddress: $ipAddress,
            channel: $value($context, 'channel'),
            utmCampaign: $value($attribution, 'utm_campaign'),
            utmSource: $value($attribution, 'utm_source'),
            utmMedium: $value($attribution, 'utm_medium'),
            utmId: $value($attribution, 'utm_id'),
            landingPage: $value($landing, 'url'),
            pageQuery: $pageQuery,
            fields: $fields,
            context: $context,
            runtime: $runtime,
        );
    }
}
