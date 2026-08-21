<?php

namespace App\Support\ModuleIntegrations\Forms\Contracts;

use App\Modules\Core\Models\Contact;
use App\Modules\Forms\Data\FormSubmissionConsentIntent;
use App\Modules\Forms\Data\PublishedForm;
use App\Modules\Forms\Models\FormSubmission;

interface FormSubmissionConsentHandler
{
    /**
     * @param array<int, FormSubmissionConsentIntent> $intents
     */
    public function validateConfiguration(
        PublishedForm $form,
        array $intents,
    ): void;

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
    ): void;
}