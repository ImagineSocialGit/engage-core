<?php

namespace App\Support\ModuleIntegrations\Forms;

use App\Support\ModuleIntegrations\Forms\Contracts\FormSubmissionAutomationWorkspace;

final class UnavailableFormSubmissionAutomationWorkspace implements FormSubmissionAutomationWorkspace
{
    public function readForForm(
        string $formKey,
        string $formName,
        bool $contactAvailable,
    ): array {
        return [
            'available' => false,
            'contact_available' => $contactAvailable,
            'actions' => [],
            'automations' => [],
        ];
    }
}