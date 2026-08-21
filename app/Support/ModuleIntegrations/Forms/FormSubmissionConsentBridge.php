<?php

namespace App\Support\ModuleIntegrations\Forms;

use App\Modules\Core\Models\Contact;
use App\Modules\Forms\Data\FormSubmissionConsentIntent;
use App\Modules\Forms\Data\PublishedForm;
use App\Modules\Forms\Models\FormSubmission;
use App\Support\ModuleIntegrations\Forms\Contracts\FormSubmissionConsentHandler;

final class FormSubmissionConsentBridge
{
    /**
     * @param iterable<FormSubmissionConsentHandler> $handlers
     */
    public function __construct(
        private readonly iterable $handlers,
    ) {}

    /**
     * @param array<int, FormSubmissionConsentIntent> $intents
     */
    public function validateConfiguration(
        PublishedForm $form,
        array $intents,
    ): void {
        if ($intents === []) {
            return;
        }

        foreach ($this->handlers as $handler) {
            $handler->validateConfiguration($form, $intents);
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<int, FormSubmissionConsentIntent> $intents
     */
    public function apply(
        PublishedForm $form,
        FormSubmission $submission,
        ?Contact $contact,
        array $payload,
        array $intents,
    ): void {
        if ($contact === null || $intents === []) {
            return;
        }

        foreach ($this->handlers as $handler) {
            $handler->apply(
                form: $form,
                submission: $submission,
                contact: $contact,
                payload: $payload,
                intents: $intents,
            );
        }
    }
}