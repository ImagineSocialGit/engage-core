<?php

namespace App\Modules\Campaigns\ConfigContracts;

use App\Modules\Campaigns\Models\Campaign;
use App\Support\ConfigContracts\Contracts\ConfigContract;
use App\Support\ConfigContracts\Data\ConfigField;
use App\Support\ConfigContracts\Data\ConfigSchema;

class CampaignPresetDefinitionConfigContract implements ConfigContract
{
    public function key(): string
    {
        return 'campaigns.preset_definition';
    }

    public function owner(): string
    {
        return 'campaigns';
    }

    public function sourcePattern(): string
    {
        return 'presets.modules.{contributor}.campaigns.definitions.{campaign_key}';
    }

    public function schema(): ConfigSchema
    {
        $version = ConfigSchema::oneOf([
            ConfigSchema::string(),
            ConfigSchema::integer(),
            ConfigSchema::number(),
        ], nullable: true);
        $condition = ConfigSchema::object([], allowUnknown: true);
        $timing = ConfigSchema::object([
            'type' => ConfigField::required(
                ConfigSchema::string(
                    allowedValues: ['immediate', 'delay', 'anchored'],
                ),
            ),
            'minutes' => ConfigField::optional(ConfigSchema::integer()),
            'hours' => ConfigField::optional(ConfigSchema::integer()),
            'days' => ConfigField::optional(ConfigSchema::integer()),
        ]);
        $criteria = ConfigSchema::object([
            'timing' => ConfigField::optional($timing),
            'conditions' => ConfigField::optional(
                ConfigSchema::listOf($condition),
            ),
        ]);
        $dependencyStates = ConfigSchema::oneOf([
            ConfigSchema::string(
                allowedValues: [
                    'scheduled',
                    'pending',
                    'sent',
                    'skipped',
                    'failed',
                    'terminal',
                    'unavailable',
                ],
            ),
            ConfigSchema::listOf(
                ConfigSchema::string(
                    allowedValues: [
                        'scheduled',
                        'pending',
                        'sent',
                        'skipped',
                        'failed',
                        'terminal',
                        'unavailable',
                    ],
                ),
            ),
        ]);
        $dependencies = ConfigSchema::object([
            'requires_variant_states' => ConfigField::required(
                ConfigSchema::mapOf($dependencyStates),
            ),
        ]);
        $variant = ConfigSchema::object([
            'name' => ConfigField::optional(
                ConfigSchema::string(nullable: true),
            ),
            'channel' => ConfigField::required(ConfigSchema::string()),
            'is_active' => ConfigField::defaulted(
                ConfigSchema::boolean(),
                true,
            ),
            'criteria' => ConfigField::defaulted($criteria, []),
            'dependency_rules' => ConfigField::defaulted($dependencies, []),
            'source_version' => ConfigField::optional($version),
            'meta' => ConfigField::defaulted(
                ConfigSchema::object([], allowUnknown: true),
                [],
            ),
        ]);
        $step = ConfigSchema::object([
            'name' => ConfigField::optional(
                ConfigSchema::string(nullable: true),
            ),
            'variant_strategy' => ConfigField::optional(
                ConfigSchema::string(
                    allowedValues: [
                        'first_available',
                        'send_all_eligible',
                        'dependency_aware',
                    ],
                ),
            ),
            'is_active' => ConfigField::defaulted(
                ConfigSchema::boolean(),
                true,
            ),
            'criteria' => ConfigField::defaulted($criteria, []),
            'source_version' => ConfigField::optional($version),
            'meta' => ConfigField::defaulted(
                ConfigSchema::object([], allowUnknown: true),
                [],
            ),
            'variants' => ConfigField::required(
                ConfigSchema::mapOf($variant),
            ),
        ]);

        return ConfigSchema::object([
            'name' => ConfigField::required(ConfigSchema::string()),
            'description' => ConfigField::optional(
                ConfigSchema::string(nullable: true),
            ),
            'purpose' => ConfigField::required(ConfigSchema::string()),
            'scope' => ConfigField::required(ConfigSchema::string()),
            'status' => ConfigField::defaulted(
                ConfigSchema::string(
                    allowedValues: [
                        Campaign::STATUS_ACTIVE,
                        Campaign::STATUS_INACTIVE,
                        Campaign::STATUS_ARCHIVED,
                    ],
                ),
                Campaign::STATUS_ACTIVE,
            ),
            'variant_strategy' => ConfigField::defaulted(
                ConfigSchema::string(
                    allowedValues: [
                        'first_available',
                        'send_all_eligible',
                        'dependency_aware',
                    ],
                ),
                'first_available',
            ),
            'source_version' => ConfigField::optional($version),
            'steps' => ConfigField::required(
                ConfigSchema::listOf($step),
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
            'name' => 'Follow Up',
            'purpose' => 'marketing',
            'scope' => 'nurture',
            'steps' => [
                [
                    'criteria' => [
                        'timing' => [
                            'type' => 'delay',
                            'days' => 1,
                        ],
                    ],
                    'variants' => [
                        'email' => [
                            'channel' => 'email',
                        ],
                    ],
                ],
            ],
        ];
    }
}