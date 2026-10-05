<?php

namespace App\Support\ModuleIntegrations\Documents\Contracts;

use App\Support\ModuleIntegrations\Documents\Data\DocumentRequirementDefinitionContribution;

interface DocumentRequirementDefinitionContributor
{
    public const TAG = 'documents.requirement_definition_contributors';

    public function contributorKey(): string;

    /**
     * @return iterable<int, DocumentRequirementDefinitionContribution>
     */
    public function definitions(): iterable;
}