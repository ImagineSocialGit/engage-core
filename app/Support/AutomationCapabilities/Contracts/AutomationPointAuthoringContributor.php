<?php

namespace App\Support\AutomationCapabilities\Contracts;

use App\Support\AutomationCapabilities\Data\AutomationPointAuthoringContext;
use App\Support\AutomationCapabilities\Data\AutomationPointAuthoringDefinition;

interface AutomationPointAuthoringContributor
{
    /** @return iterable<int, AutomationPointAuthoringDefinition> */
    public function definitions(): iterable;

    public function available(
        string $pointType,
        AutomationPointAuthoringContext $context,
    ): bool;

    /**
     * @param array<string, mixed> $definition
     * @return array<int, array<string, mixed>>
     */
    public function fields(
        string $pointType,
        array $definition,
        AutomationPointAuthoringContext $context,
    ): array;

    /** @return array<string, array<int, mixed>> */
    public function rules(
        string $pointType,
        AutomationPointAuthoringContext $context,
    ): array;

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function buildDefinition(
        string $pointType,
        array $input,
        AutomationPointAuthoringContext $context,
    ): array;

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $definition
     */
    public function pointName(
        string $pointType,
        string $fallback,
        array $input,
        array $definition,
        AutomationPointAuthoringContext $context,
    ): string;

    /** @param array<string, mixed> $definition */
    public function summary(
        string $pointType,
        array $definition,
        AutomationPointAuthoringContext $context,
    ): string;

    /** @param array<string, mixed> $definition */
    public function editorSummary(
        string $pointType,
        array $definition,
        AutomationPointAuthoringContext $context,
    ): string;
}
