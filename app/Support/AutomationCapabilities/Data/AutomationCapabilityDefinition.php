<?php

namespace App\Support\AutomationCapabilities\Data;

use InvalidArgumentException;

class AutomationCapabilityDefinition
{
    public const TYPE_ACTION = 'action';
    public const TYPE_EVENT = 'event';
    public const TYPE_CONDITION = 'condition';
    public const TYPE_WAIT = 'wait';

    public const TYPES = [
        self::TYPE_ACTION,
        self::TYPE_EVENT,
        self::TYPE_CONDITION,
        self::TYPE_WAIT,
    ];

    /**
     * @param array<int, string> $supportedSubjects
     * @param array<int, string> $requiredModules
     * @param array<string, mixed> $inputSchema
     * @param array<string, mixed> $outputSchema
     * @param array<int, string> $availableFields
     * @param array<string, mixed> $defaults
     * @param array<string, mixed> $meta
     */
    public function __construct(
        public readonly string $key,
        public readonly string $moduleKey,
        public readonly string $capabilityType,
        public readonly string $pointType,
        public readonly string $name,
        public readonly ?string $handlerKey = null,
        public readonly ?string $eventKey = null,
        public readonly ?string $actionKey = null,
        public readonly ?string $description = null,
        public readonly ?string $category = null,
        public readonly ?string $surface = null,
        public readonly array $supportedSubjects = [],
        public readonly array $requiredModules = [],
        public readonly array $inputSchema = [],
        public readonly array $outputSchema = [],
        public readonly array $availableFields = [],
        public readonly array $defaults = [],
        public readonly bool $isActive = true,
        public readonly ?string $sourceVersion = null,
        public readonly array $meta = [],
    ) {
        foreach (['key' => $key, 'moduleKey' => $moduleKey, 'pointType' => $pointType, 'name' => $name] as $field => $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException("Automation capability [{$field}] must be a non-empty string.");
            }
        }

        if (! in_array($capabilityType, self::TYPES, true)) {
            throw new InvalidArgumentException("Unsupported automation capability type [{$capabilityType}] for capability [{$key}].");
        }
    }
}
