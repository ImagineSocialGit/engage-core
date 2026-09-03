<?php

namespace App\Support\ModuleIntegrations\Forms\Contracts;

interface FormSubmissionAutomationWorkspace
{
    /**
     * @return array{
     *     available: bool,
     *     contact_available: bool,
     *     actions: array<int, array{
     *         key: string,
     *         module_key: string,
     *         label: string,
     *         detail: string,
     *         url: string
     *     }>,
     *     automations: array<int, array{
     *         id: int,
     *         name: string,
     *         kind: string,
     *         is_enabled: bool,
     *         step_count: int,
     *         url: string
     *     }>
     * }
     */
    public function readForForm(
        string $formKey,
        string $formName,
        bool $contactAvailable,
    ): array;
}