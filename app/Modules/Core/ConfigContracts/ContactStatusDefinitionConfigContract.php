<?php

namespace App\Modules\Core\ConfigContracts;

use App\Support\ConfigContracts\Contracts\ConfigContract;
use App\Support\ConfigContracts\Data\ConfigField;
use App\Support\ConfigContracts\Data\ConfigSchema;

class ContactStatusDefinitionConfigContract implements ConfigContract
{
    public function key(): string
    {
        return 'core.contact_status_definition';
    }

    public function owner(): string
    {
        return 'core';
    }

    public function sourcePattern(): string
    {
        return 'presets.modules.{contributor}.contact-statuses.definitions.{status_key}';
    }

    public function schema(): ConfigSchema
    {
        return ConfigSchema::object([
            'key' => ConfigField::required(ConfigSchema::string()),
            'name' => ConfigField::required(ConfigSchema::string()),
            'description' => ConfigField::optional(ConfigSchema::string(nullable: true)),
            'category' => ConfigField::optional(ConfigSchema::string(nullable: true)),
            'color' => ConfigField::optional(ConfigSchema::string(nullable: true)),
            'is_core' => ConfigField::defaulted(ConfigSchema::boolean(), true),
            'is_active' => ConfigField::defaulted(ConfigSchema::boolean(), true),
            'sort_order' => ConfigField::defaulted(ConfigSchema::integer(), 0),
            'source_version' => ConfigField::optional(ConfigSchema::oneOf([
                ConfigSchema::string(),
                ConfigSchema::integer(),
                ConfigSchema::number(),
            ], nullable: true)),
            'meta' => ConfigField::defaulted(ConfigSchema::object([], allowUnknown: true), []),
        ]);
    }

    public function example(): array
    {
        return [
            'key' => 'engaged',
            'name' => 'Engaged',
            'description' => 'Contact has shown meaningful interest or interaction.',
            'category' => 'default',
            'sort_order' => 20,
            'is_active' => true,
            'source_version' => '2026_07',
            'meta' => [],
        ];
    }
}
