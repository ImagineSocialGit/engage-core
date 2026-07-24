<?php

namespace App\Support\ConfigContracts\Contracts;

use App\Support\ConfigContracts\Data\ConfigField;
use App\Support\ConfigContracts\Data\ConfigSchema;

class PresetPackageConfigContract implements ConfigContract
{
    public function key(): string
    {
        return 'app.preset_package';
    }

    public function owner(): string
    {
        return 'app';
    }

    public function sourcePattern(): string
    {
        return 'presets.packages.{package_key}';
    }

    public function schema(): ConfigSchema
    {
        $stringList = ConfigSchema::listOf(ConfigSchema::string());

        return ConfigSchema::object([
            'name' => ConfigField::optional(ConfigSchema::string()),
            'description' => ConfigField::optional(ConfigSchema::string()),
            'contacts' => ConfigField::optional(ConfigSchema::object([
                'labels' => ConfigField::optional(ConfigSchema::object([
                    'singular' => ConfigField::required(ConfigSchema::string()),
                    'plural' => ConfigField::required(ConfigSchema::string()),
                ])),
                'routes' => ConfigField::optional(ConfigSchema::object([
                    'plural' => ConfigField::required(ConfigSchema::string()),
                ])),
                'sources' => ConfigField::optional(ConfigSchema::mapOf(ConfigSchema::object([
                    'enabled' => ConfigField::defaulted(ConfigSchema::boolean(), true),
                ]))),
            ])),
            'groups' => ConfigField::required(ConfigSchema::object([
                'contact_statuses' => ConfigField::required($stringList, referenceTarget: 'preset_group.contact_statuses'),
                'tasks' => ConfigField::required($stringList, referenceTarget: 'preset_group.tasks'),
                'campaigns' => ConfigField::required($stringList, referenceTarget: 'preset_group.campaigns'),
                'flow_routes' => ConfigField::required($stringList, referenceTarget: 'preset_group.flow_routes'),
            ])),
        ]);
    }

    public function example(): array
    {
        return [
            'name' => 'Basic',
            'groups' => [
                'contact_statuses' => ['default'],
                'tasks' => ['default'],
                'campaigns' => [],
                'flow_routes' => [],
            ],
        ];
    }
}