<?php

namespace App\Support\ModuleIntegrations\Forms;

use App\Modules\Forms\Data\HostedFormContextReference;
use App\Support\ModuleIntegrations\Forms\Contracts\FormSubmissionAutomationWorkspace;
use Illuminate\Validation\ValidationException;

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
    public function setQuickActionEnabledForForm(
        string $formKey,
        bool $contactAvailable,
        int $automationId,
        bool $enabled,
        ?HostedFormContextReference $context = null,
    ): void {
        throw ValidationException::withMessages([
            'quick_action' => 'Automation is not available for this client.',
        ]);
    }

    public function createQuickActionForForm(
        string $formKey,
        string $formName,
        bool $contactAvailable,
        string $capabilityKey,
        array $input,
        ?HostedFormContextReference $context = null,
        ?string $contextLabel = null,
    ): int {
        throw ValidationException::withMessages([
            'quick_action' => 'Automation is not available for this client.',
        ]);
    }
}