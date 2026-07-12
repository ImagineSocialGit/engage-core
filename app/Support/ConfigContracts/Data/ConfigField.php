<?php

namespace App\Support\ConfigContracts\Data;

class ConfigField
{
    public function __construct(
        public readonly ConfigSchema $schema,
        public readonly bool $required = false,
        public readonly bool $hasDefault = false,
        public readonly mixed $default = null,
        public readonly bool $deprecated = false,
        public readonly ?string $description = null,
        public readonly ?string $referenceTarget = null,
    ) {}

    public static function required(
        ConfigSchema $schema,
        ?string $description = null,
        ?string $referenceTarget = null,
    ): self {
        return new self(
            schema: $schema,
            required: true,
            description: $description,
            referenceTarget: $referenceTarget,
        );
    }

    public static function optional(
        ConfigSchema $schema,
        ?string $description = null,
        ?string $referenceTarget = null,
    ): self {
        return new self(
            schema: $schema,
            description: $description,
            referenceTarget: $referenceTarget,
        );
    }

    public static function defaulted(
        ConfigSchema $schema,
        mixed $default,
        ?string $description = null,
        ?string $referenceTarget = null,
    ): self {
        return new self(
            schema: $schema,
            hasDefault: true,
            default: $default,
            description: $description,
            referenceTarget: $referenceTarget,
        );
    }

    public function deprecated(): self
    {
        return new self(
            schema: $this->schema,
            required: $this->required,
            hasDefault: $this->hasDefault,
            default: $this->default,
            deprecated: true,
            description: $this->description,
            referenceTarget: $this->referenceTarget,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function describe(): array
    {
        return [
            'required' => $this->required,
            'has_default' => $this->hasDefault,
            'default' => $this->default,
            'deprecated' => $this->deprecated,
            'description' => $this->description,
            'reference_target' => $this->referenceTarget,
            'schema' => $this->schema->describe(),
        ];
    }
}
