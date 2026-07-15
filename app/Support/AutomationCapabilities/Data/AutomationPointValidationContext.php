<?php

namespace App\Support\AutomationCapabilities\Data;

use App\Support\SetupValidation\Data\SetupValidationFinding;

class AutomationPointValidationContext
{
    /**
     * @param array<int, string> $siblingKeys
     * @param array<string, mixed> $context
     */
    public function __construct(
        public readonly string $containerKey,
        public readonly string $pointKey,
        public readonly string $pointType,
        public readonly string $path,
        public readonly array $siblingKeys = [],
        public readonly array $context = [],
        public readonly string $source = 'preset_composition.flow_routes',
        public readonly string $module = 'flow_routes',
    ) {}

    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $meta
     */
    public function error(
        string $code,
        string $message,
        ?string $path = null,
        array $context = [],
        array $meta = [],
    ): SetupValidationFinding {
        return new SetupValidationFinding(
            severity: SetupValidationFinding::SEVERITY_ERROR,
            code: $code,
            message: $message,
            source: $this->source,
            path: $path ?? $this->path,
            module: $this->module,
            context: $this->context + $context,
            meta: $meta,
        );
    }
}
