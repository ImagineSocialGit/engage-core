<?php

namespace App\Modules\Forms\Services;

use App\Modules\Forms\Data\PublishedForm;
use App\Support\ModuleIntegrations\Forms\FormSubmissionConsentBridge;

final class HostedFormRuntimeValidator
{
    public function __construct(
        private readonly HostedFormLayoutResolver $layouts,
        private readonly FormSubmissionValidator $submissions,
        private readonly FormSubmissionContactMapper $contacts,
        private readonly FormSubmissionConsentIntentResolver $consentIntents,
        private readonly FormSubmissionConsentBridge $consentBridge,
        private readonly FormSubmissionVerificationPolicy $verifications,
    ) {}

    public function validate(PublishedForm $form): void
    {
        $this->layouts->validate($form);
        $this->submissions->validateConfiguration($form);
        $this->contacts->validateConfiguration($form);
        $intents = $this->consentIntents->resolve($form);
        $this->consentBridge->validateConfiguration($form, $intents);
        $this->verifications->validateConfiguration($form);
    }
}