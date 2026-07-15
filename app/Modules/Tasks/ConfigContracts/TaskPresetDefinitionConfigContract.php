<?php

namespace App\Modules\Tasks\ConfigContracts;

use App\Modules\Tasks\Models\Task;
use App\Modules\Tasks\Models\TaskLink;
use App\Modules\Tasks\Models\TaskTemplate;
use App\Support\ConfigContracts\Contracts\ConfigContract;
use App\Support\ConfigContracts\Data\ConfigField;
use App\Support\ConfigContracts\Data\ConfigSchema;

class TaskPresetDefinitionConfigContract implements ConfigContract
{
    public function key(): string
    {
        return 'tasks.preset_definition';
    }

    public function owner(): string
    {
        return 'tasks';
    }

    public function sourcePattern(): string
    {
        return 'presets.modules.{contributor}.tasks.definitions.{task_template_key}';
    }

    public function schema(): ConfigSchema
    {
        $nullableString = ConfigSchema::string(nullable: true);
        $nullableInteger = ConfigSchema::integer(nullable: true);
        $version = ConfigSchema::oneOf([
            ConfigSchema::string(),
            ConfigSchema::integer(),
            ConfigSchema::number(),
        ], nullable: true);

        $linkDefault = ConfigSchema::object([
            'role' => ConfigField::required(ConfigSchema::string(
                allowedValues: TaskLink::ROLES,
            )),
            'source' => ConfigField::required(ConfigSchema::string(
                allowedValues: TaskTemplate::LINK_SOURCES,
            )),
        ]);

        return ConfigSchema::object([
            'key' => ConfigField::optional(ConfigSchema::string()),
            'name' => ConfigField::optional($nullableString),
            'title' => ConfigField::required(ConfigSchema::string()),
            'description' => ConfigField::optional($nullableString),
            'task_description' => ConfigField::optional($nullableString),
            'responsible_party' => ConfigField::defaulted(
                ConfigSchema::string(
                    allowedValues: Task::RESPONSIBLE_PARTY_OPTIONS,
                ),
                Task::RESPONSIBLE_PARTY_INTERNAL,
            ),
            'responsible_type' => ConfigField::optional($nullableString),
            'responsible_id' => ConfigField::optional($nullableInteger),
            'assigned_to_type' => ConfigField::optional($nullableString),
            'assigned_to_id' => ConfigField::optional($nullableInteger),
            'assigned_to_strategy' => ConfigField::optional($nullableString),
            'assigned_to' => ConfigField::optional($nullableString)->deprecated(),
            'priority' => ConfigField::optional($nullableString),
            'due_offset_minutes' => ConfigField::optional($nullableInteger),
            'due_offset_days' => ConfigField::optional(
                $nullableInteger,
            )->deprecated(),
            'source' => ConfigField::defaulted(
                ConfigSchema::string(),
                TaskTemplate::SOURCE_PRESET,
            ),
            'source_version' => ConfigField::optional($version),
            'version' => ConfigField::optional($version)->deprecated(),
            'owner_group' => ConfigField::optional($nullableString),
            'category' => ConfigField::optional($nullableString),
            'is_active' => ConfigField::defaulted(
                ConfigSchema::boolean(),
                true,
            ),
            'link_defaults' => ConfigField::defaulted(
                ConfigSchema::listOf($linkDefault),
                [],
            ),
            'defaults' => ConfigField::defaulted(
                ConfigSchema::object([
                    'due' => ConfigField::optional(ConfigSchema::object([
                        'type' => ConfigField::defaulted(
                            ConfigSchema::string(
                                allowedValues: ['delay'],
                            ),
                            'delay',
                        ),
                        'minutes' => ConfigField::optional($nullableInteger),
                        'hours' => ConfigField::optional($nullableInteger),
                        'days' => ConfigField::optional($nullableInteger),
                    ])),
                ]),
                [],
            ),
            'meta' => ConfigField::defaulted(
                ConfigSchema::object([], allowUnknown: true),
                [],
            ),
        ]);
    }

    public function example(): array
    {
        return [
            'title' => 'Follow up',
            'name' => 'Follow up',
            'description' => 'General follow-up task.',
            'responsible_party' => Task::RESPONSIBLE_PARTY_INTERNAL,
            'assigned_to_strategy' => TaskTemplate::ASSIGNED_TO_STRATEGY_UNASSIGNED,
            'due_offset_minutes' => 1440,
            'link_defaults' => [
                [
                    'role' => TaskLink::ROLE_SUBJECT,
                    'source' => TaskTemplate::LINK_SOURCE_CURRENT_CONTACT,
                ],
            ],
            'source_version' => '2026_07_phase_12',
            'is_active' => true,
            'defaults' => [],
            'meta' => [],
        ];
    }
}
