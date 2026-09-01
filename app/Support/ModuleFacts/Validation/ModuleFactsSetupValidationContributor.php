<?php

namespace App\Support\ModuleFacts\Validation;

use App\Support\ModuleFacts\ModuleFactRegistry;
use App\Support\SetupValidation\Contracts\SetupValidationContributor;
use App\Support\SetupValidation\Data\SetupValidationFinding;
use Throwable;

final class ModuleFactsSetupValidationContributor implements SetupValidationContributor
{
    public function __construct(
        private readonly ModuleFactRegistry $moduleFacts,
    ) {}

    public function findings(): iterable
    {
        try {
            $facts = $this->moduleFacts->all();
        } catch (Throwable $exception) {
            yield new SetupValidationFinding(
                severity: SetupValidationFinding::SEVERITY_ERROR,
                code: 'module_facts.registry_invalid',
                message: $exception->getMessage(),
                source: 'module_facts',
                path: 'module_facts.providers',
                context: ['exception' => $exception::class],
            );

            return;
        }

        if ($facts === []) {
            yield new SetupValidationFinding(
                severity: SetupValidationFinding::SEVERITY_ERROR,
                code: 'module_facts.registry_empty',
                message: 'No module facts are registered. Core must contribute the Contact fact catalog.',
                source: 'module_facts',
                path: 'module_facts.providers',
            );
        }
    }
}