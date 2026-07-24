<?php

namespace App\Modules\Campaigns\ConfigContracts;

use App\Modules\Campaigns\Models\Campaign;
use App\Support\ConfigContracts\Contracts\ConfigContract;
use App\Support\ConfigContracts\Data\ConfigField;
use App\Support\ConfigContracts\Data\ConfigSchema;

class CampaignPresetDefinitionConfigContract implements ConfigContract
{
    public function key(): string { return 'campaigns.preset_definition'; }
    public function owner(): string { return 'campaigns'; }
    public function sourcePattern(): string { return 'presets.modules.{contributor}.campaigns.definitions.{campaign_key}'; }

    public function schema(): ConfigSchema
    {
        $version = ConfigSchema::oneOf([ConfigSchema::string(), ConfigSchema::integer(), ConfigSchema::number()], nullable: true);
        $condition = ConfigSchema::object([], allowUnknown: true);
        $timing = ConfigSchema::object([
            'type' => ConfigField::required(ConfigSchema::string(allowedValues: ['immediate', 'delay', 'anchored'])),
            'minutes' => ConfigField::optional(ConfigSchema::integer()),
            'hours' => ConfigField::optional(ConfigSchema::integer()),
            'days' => ConfigField::optional(ConfigSchema::integer()),
        ]);
        $criteria = ConfigSchema::object([
            'timing' => ConfigField::optional($timing),
            'schedule' => ConfigField::optional($timing),
            'conditions' => ConfigField::optional(ConfigSchema::listOf($condition)),
        ], atMostOne: [['timing', 'schedule']]);
        $dependencyStates = ConfigSchema::oneOf([
            ConfigSchema::string(allowedValues: ['scheduled', 'pending', 'sent', 'skipped', 'failed', 'terminal', 'unavailable']),
            ConfigSchema::listOf(ConfigSchema::string(allowedValues: ['scheduled', 'pending', 'sent', 'skipped', 'failed', 'terminal', 'unavailable'])),
        ]);
        $dependencies = ConfigSchema::object([
            'requires_scheduled_variant_keys' => ConfigField::optional(ConfigSchema::listOf(ConfigSchema::string())),
            'requires_variant_states' => ConfigField::optional(ConfigSchema::mapOf($dependencyStates)),
            'requires' => ConfigField::optional(ConfigSchema::listOf(ConfigSchema::object([
                'variant_key' => ConfigField::required(ConfigSchema::string()),
                'states' => ConfigField::required($dependencyStates),
            ]))),
        ]);
        $variant = ConfigSchema::object([
            'key' => ConfigField::required(ConfigSchema::string()),
            'name' => ConfigField::optional(ConfigSchema::string(nullable: true)),
            'sort_order' => ConfigField::defaulted(ConfigSchema::integer(), 0),
            'order' => ConfigField::optional(ConfigSchema::integer())->deprecated(),
            'dispatch_key' => ConfigField::optional(ConfigSchema::string()),
            'channel' => ConfigField::optional(ConfigSchema::string()),
            'purpose' => ConfigField::optional(ConfigSchema::string()),
            'scope' => ConfigField::optional(ConfigSchema::string()),
            'is_active' => ConfigField::defaulted(ConfigSchema::boolean(), true),
            'criteria' => ConfigField::defaulted($criteria, []),
            'dependency_rules' => ConfigField::defaulted($dependencies, []),
            'source_config_path' => ConfigField::optional(ConfigSchema::string(nullable: true)),
            'source_version' => ConfigField::optional($version),
            'meta' => ConfigField::defaulted(ConfigSchema::object([], allowUnknown: true), []),
        ]);
        $step = ConfigSchema::object([
            'step_number' => ConfigField::required(ConfigSchema::integer()),
            'name' => ConfigField::optional(ConfigSchema::string(nullable: true)),
            'dispatch_key' => ConfigField::defaulted(ConfigSchema::string(), 'campaign_step_due'),
            'channel' => ConfigField::optional(ConfigSchema::string(nullable: true)),
            'purpose' => ConfigField::optional(ConfigSchema::string(nullable: true)),
            'scope' => ConfigField::optional(ConfigSchema::string(nullable: true)),
            'variant_strategy' => ConfigField::defaulted(ConfigSchema::string(allowedValues: ['first_available', 'send_all_eligible', 'dependency_aware']), 'first_available'),
            'is_active' => ConfigField::defaulted(ConfigSchema::boolean(), true),
            'criteria' => ConfigField::defaulted($criteria, []),
            'source_version' => ConfigField::optional($version),
            'meta' => ConfigField::defaulted(ConfigSchema::object([], allowUnknown: true), []),
            'variants' => ConfigField::required(ConfigSchema::listOf($variant)),
        ]);

        return ConfigSchema::object([
            'key' => ConfigField::required(ConfigSchema::string()),
            'name' => ConfigField::required(ConfigSchema::string()),
            'description' => ConfigField::optional(ConfigSchema::string(nullable: true)),
            'channel' => ConfigField::required(ConfigSchema::string()),
            'purpose' => ConfigField::required(ConfigSchema::string()),
            'scope' => ConfigField::required(ConfigSchema::string()),
            'status' => ConfigField::defaulted(ConfigSchema::string(allowedValues: [Campaign::STATUS_ACTIVE, Campaign::STATUS_INACTIVE, Campaign::STATUS_ARCHIVED]), Campaign::STATUS_ACTIVE),
            'source_version' => ConfigField::optional($version),
            'steps' => ConfigField::required(ConfigSchema::listOf($step)),
            'meta' => ConfigField::defaulted(ConfigSchema::object([], allowUnknown: true), []),
        ]);
    }

    public function example(): array
    {
        return ['key' => 'follow_up', 'name' => 'Follow Up', 'channel' => 'email', 'purpose' => 'marketing', 'scope' => 'nurture', 'steps' => [[
            'step_number' => 1, 'criteria' => ['timing' => ['type' => 'delay', 'days' => 1]], 'variants' => [[
                'key' => 'email', 'dispatch_key' => 'campaign_step_due', 'channel' => 'email', 'purpose' => 'marketing', 'scope' => 'nurture',
            ]],
        ]]];
    }
}