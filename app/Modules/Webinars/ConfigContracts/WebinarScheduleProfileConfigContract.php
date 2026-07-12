<?php

namespace App\Modules\Webinars\ConfigContracts;

use App\Support\ConfigContracts\Contracts\ConfigContract;
use App\Support\ConfigContracts\Data\ConfigField;
use App\Support\ConfigContracts\Data\ConfigSchema;

class WebinarScheduleProfileConfigContract implements ConfigContract
{
    public function key(): string { return 'webinars.schedule_profile'; }
    public function owner(): string { return 'webinars'; }
    public function sourcePattern(): string { return 'webinars.schedule_profiles.{profile_key}'; }

    public function schema(): ConfigSchema
    {
        $version = ConfigSchema::integer(nullable: true);
        $condition = ConfigSchema::object([], allowUnknown: true);
        $schedule = ConfigSchema::object([
            'type' => ConfigField::required(ConfigSchema::string(allowedValues: ['delay', 'anchored'])),
            'minutes' => ConfigField::required(ConfigSchema::integer()),
        ]);
        $item = ConfigSchema::object([
            'key' => ConfigField::required(ConfigSchema::string()),
            'label' => ConfigField::optional(ConfigSchema::string(nullable: true)),
            'context_key' => ConfigField::required(ConfigSchema::string()),
            'channel' => ConfigField::required(ConfigSchema::string(allowedValues: ['email', 'sms'])),
            'purpose' => ConfigField::required(ConfigSchema::string()),
            'scope' => ConfigField::required(ConfigSchema::string()),
            'surface' => ConfigField::optional(ConfigSchema::string(nullable: true)),
            'message_type' => ConfigField::required(ConfigSchema::string()),
            'dispatch_key' => ConfigField::required(ConfigSchema::string()),
            'message_template_key' => ConfigField::required(ConfigSchema::string()),
            'source_config_path' => ConfigField::optional(ConfigSchema::string(nullable: true)),
            'timing' => ConfigField::defaulted(ConfigSchema::string(allowedValues: ['immediate', 'scheduled']), 'immediate'),
            'schedule' => ConfigField::optional(ConfigSchema::oneOf([$schedule], nullable: true)),
            'conditions' => ConfigField::defaulted(ConfigSchema::listOf($condition), []),
            'is_enabled' => ConfigField::defaulted(ConfigSchema::boolean(), true),
            'is_active' => ConfigField::defaulted(ConfigSchema::boolean(), true),
            'sort_order' => ConfigField::optional(ConfigSchema::integer()),
            'meta' => ConfigField::defaulted(ConfigSchema::object([
                'skip_when_join_clicked' => ConfigField::optional(ConfigSchema::boolean()),
            ], allowUnknown: true), []),
        ]);
        return ConfigSchema::object([
            'name' => ConfigField::required(ConfigSchema::string()),
            'description' => ConfigField::optional(ConfigSchema::string(nullable: true)),
            'status' => ConfigField::defaulted(ConfigSchema::string(allowedValues: ['active', 'inactive', 'archived']), 'active'),
            'is_default' => ConfigField::defaulted(ConfigSchema::boolean(), false),
            'is_active' => ConfigField::defaulted(ConfigSchema::boolean(), true),
            'source_version' => ConfigField::optional($version),
            'items' => ConfigField::required(ConfigSchema::listOf($item)),
            'meta' => ConfigField::defaulted(ConfigSchema::object([], allowUnknown: true), []),
        ]);
    }

    public function example(): array
    {
        return ['name' => 'Default', 'is_default' => true, 'items' => [[
            'key' => 'email_confirmation', 'context_key' => 'confirmations', 'channel' => 'email', 'purpose' => 'transactional', 'scope' => 'webinar', 'message_type' => 'confirmation', 'dispatch_key' => 'registration_created', 'message_template_key' => 'confirmation', 'timing' => 'immediate', 'schedule' => null,
        ]]];
    }
}
