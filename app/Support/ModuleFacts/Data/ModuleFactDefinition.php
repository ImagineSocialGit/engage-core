<?php

namespace App\Support\ModuleFacts\Data;

use App\Support\ModuleFacts\Contracts\ModuleFactQueryResolver;
use App\Support\ModuleFacts\Contracts\ModuleFactValueResolver;
use App\Support\ModuleFacts\Enums\ModuleFactCapability;
use App\Support\ModuleFacts\Enums\ModuleFactType;
use InvalidArgumentException;

final readonly class ModuleFactDefinition
{
    /**
     * @param class-string<\Illuminate\Database\Eloquent\Model> $subject
     * @param array<int, ModuleFactCapability> $capabilities
     * @param array<int, string> $aliases
     */
    public function __construct(
        public string $key,
        public string $owner,
        public string $label,
        public string $description,
        public string $subject,
        public ModuleFactType $type,
        public array $capabilities,
        public ModuleFactValueResolver $valueResolver,
        public ?ModuleFactQueryResolver $queryResolver = null,
        public array $aliases = [],
    ) {
        if (trim($this->key) === '' || trim($this->owner) === '') {
            throw new InvalidArgumentException('Module facts require non-empty keys and owners.');
        }

        if (! str_starts_with($this->key, $this->owner.'.')) {
            throw new InvalidArgumentException(
                "Module fact [{$this->key}] must use its owner [{$this->owner}] as the key prefix.",
            );
        }

        if (! class_exists($this->subject)) {
            throw new InvalidArgumentException("Module fact [{$this->key}] has an unavailable subject model.");
        }

        foreach ($this->capabilities as $capability) {
            if (! $capability instanceof ModuleFactCapability) {
                throw new InvalidArgumentException("Module fact [{$this->key}] has an invalid capability.");
            }
        }

        if ($this->has(ModuleFactCapability::Annualizable)
            && ($this->type !== ModuleFactType::Date || $this->queryResolver === null)
        ) {
            throw new InvalidArgumentException(
                "Annualizable module fact [{$this->key}] must be a queryable date.",
            );
        }
    }

    public function has(ModuleFactCapability $capability): bool
    {
        return in_array($capability, $this->capabilities, true);
    }
}