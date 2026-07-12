<?php

namespace App\Support\ConfigContracts\Contracts;

use App\Support\ConfigContracts\Data\ConfigField;
use App\Support\ConfigContracts\Data\ConfigSchema;

class ModuleDefinitionConfigContract implements ConfigContract
{
    public function key(): string
    {
        return 'app.module_definition';
    }

    public function owner(): string
    {
        return 'app';
    }

    public function sourcePattern(): string
    {
        return 'modules.modules.{module_key}';
    }

    public function schema(): ConfigSchema
    {
        $navItem = ConfigSchema::object([
            'label' => ConfigField::optional(ConfigSchema::string()),
            'label_config' => ConfigField::optional(ConfigSchema::string()),
            'route' => ConfigField::required(ConfigSchema::string()),
            'priority' => ConfigField::defaulted(ConfigSchema::integer(), 100),
            'class' => ConfigField::optional(ConfigSchema::string()),
        ]);

        return ConfigSchema::object([
            'name' => ConfigField::required(ConfigSchema::string()),
            'ui' => ConfigField::optional(ConfigSchema::object([
                'tone' => ConfigField::required(ConfigSchema::string()),
            ])),
            'nav' => ConfigField::optional(ConfigSchema::oneOf([
                $navItem,
                ConfigSchema::listOf($navItem),
            ])),
            'always_on' => ConfigField::defaulted(ConfigSchema::boolean(), false),
            'depends_on' => ConfigField::defaulted(
                ConfigSchema::listOf(ConfigSchema::string()),
                [],
                referenceTarget: 'app.module_definition',
            ),
            'requires_provider' => ConfigField::defaulted(ConfigSchema::boolean(), true),
            'preset_contributors' => ConfigField::defaulted(
                ConfigSchema::listOf(ConfigSchema::string()),
                [],
            ),
            'providers' => ConfigField::defaulted(
                ConfigSchema::listOf(ConfigSchema::string()),
                [],
            ),
        ]);
    }

    public function example(): array
    {
        return [
            'name' => 'Tasks',
            'ui' => ['tone' => 'emerald'],
            'depends_on' => ['core'],
            'providers' => ['App\\Modules\\Tasks\\Providers\\TasksModuleServiceProvider'],
        ];
    }
}
