<?php

namespace App\Support\ModuleIntegrations\Forms;

use App\Modules\Forms\Data\HostedFormContextReference;
use App\Support\ModuleIntegrations\Forms\Contracts\FormSubmissionAutomationWorkspace;

final class UnavailableFormSubmissionAutomationWorkspace implements FormSubmissionAutomationWorkspace
{
    public function readForForm(
        string $formKey,
        string $formName,
        bool $contactAvailable,
        ?HostedFormContextReference $context = null,
        ?string $contextLabel = null,
    ): array {
        return [
            'available' => false,
            'contact_available' => $contactAvailable,
            'actions' => [],
            'automations' => [],
        ];
    }
}