<?php

namespace App\Support\ModuleIntegrations\Forms\Messaging;

use App\Modules\Core\Models\Contact;
use App\Modules\Forms\Data\FormSubmissionConsentIntent;
use App\Modules\Forms\Data\PublishedForm;
use App\Modules\Forms\Models\FormSubmission;
use App\Modules\Messaging\Actions\GrantMessageConsentAction;
use App\Modules\Messaging\Services\ConsentDomainRegistry;
use App\Support\ModuleIntegrations\Forms\Contracts\FormSubmissionConsentHandler;
use DomainException;
use InvalidArgumentException;

final class GrantFormSubmissionMessagingConsent implements FormSubmissionConsentHandler
{
    private const REQUESTED_SCOPE = 'forms';

    public function __construct(
        private readonly ConsentDomainRegistry $consentDomains,
        private readonly GrantMessageConsentAction $grantConsent,
    ) {}

    /**
     * @param array<int, FormSubmissionConsentIntent> $intents
     */
    public function validateConfiguration(
        PublishedForm $form,
        array $intents,
    ): void {
        foreach ($intents as $intent) {
            try {
                $domain = $this->consentDomains->channelPurposeDomainFor(
                    channel: $intent->channel,
                    purpose: $intent->purpose,
                );
            } catch (InvalidArgumentException $exception) {
                throw $this->configurationException(
                    form: $form,
                    intent: $intent,
                    detail: $exception->getMessage(),
                    previous: $exception,
                );
            }

            if ($domain === null) {
                throw $this->configurationException(
                    form: $form,
                    intent: $intent,
                    detail: 'an explicit channel-purpose consent domain is required',
                );
            }
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<int, FormSubmissionConsentIntent> $intents
     */
    public function apply(
        PublishedForm $form,
        FormSubmission $submission,
        Contact $contact,
        array $payload,
        array $intents,
    ): void {
        foreach ($intents as $intent) {
            if (($payload[$intent->field] ?? null) !== true) {
                continue;
            }

            $this->grantConsent->handle(
                contact: $contact,
                data: [
                    'channel' => $intent->channel,
                    'purpose' => $intent->purpose,
                    'scope' => self::REQUESTED_SCOPE,
                    'consented_at' => $submission->submitted_at ?? now(),
                    'ip_address' => $submission->ip_address,
                    'user_agent' => $submission->user_agent,
                    'source' => 'forms_submission',
                    'meta' => [
                        'forms' => [
                            'submission_id' => (int) $submission->getKey(),
                            'form_version_id' => (int) $submission->form_version_id,
                            'field' => $intent->field,
                        ],
                    ],
                ],
                context: $submission,
            );
        }
    }

    private function configurationException(
        PublishedForm $form,
        FormSubmissionConsentIntent $intent,
        string $detail,
        ?\Throwable $previous = null,
    ): DomainException {
        return new DomainException(
            "Published form [{$form->key}] consent field [{$intent->field}] cannot grant [{$intent->channel}:{$intent->purpose}] permission because {$detail}.",
            previous: $previous,
        );
    }
}