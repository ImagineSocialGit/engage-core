<?php

namespace App\Modules\Webinars\ConfigContracts;

use App\Support\ConfigContracts\Contracts\ConfigContract;
use App\Support\ConfigContracts\Data\ConfigField;
use App\Support\ConfigContracts\Data\ConfigSchema;

class WebinarMessageAreaConfigContract implements ConfigContract
{
    public function key(): string
    {
        return 'webinars.message_area';
    }

    public function owner(): string
    {
        return 'webinars';
    }

    public function sourcePattern(): string
    {
        return 'webinars.message_areas.{area_key}';
    }

    public function schema(): ConfigSchema
    {
        $consolidationPrimary = ConfigSchema::object([
            'area_key' => ConfigField::required(ConfigSchema::string()),
            'intent_key' => ConfigField::required(ConfigSchema::string()),
            'purpose' => ConfigField::required(ConfigSchema::string()),
            'scope' => ConfigField::required(ConfigSchema::string()),
            'message_type' => ConfigField::required(ConfigSchema::string()),
            'dispatch_key' => ConfigField::required(ConfigSchema::string()),
        ]);

        return ConfigSchema::object([
            'enabled' => ConfigField::defaulted(ConfigSchema::boolean(), true),
            'disableable' => ConfigField::optional(ConfigSchema::boolean()),
            'kind' => ConfigField::defaulted(ConfigSchema::string(allowedValues: [
                'template',
                'consent_acknowledgement',
            ]), 'template'),
            'label' => ConfigField::required(ConfigSchema::string()),
            'description' => ConfigField::required(ConfigSchema::string()),
            'purpose' => ConfigField::required(ConfigSchema::string()),
            'scope' => ConfigField::required(ConfigSchema::string()),
            'surface' => ConfigField::required(ConfigSchema::string()),
            'message_type' => ConfigField::required(ConfigSchema::string()),
            'dispatch_key' => ConfigField::required(ConfigSchema::string()),
            'required' => ConfigField::required(ConfigSchema::oneOf([
                ConfigSchema::boolean(),
                ConfigSchema::string(allowedValues: [
                    'registration_messaging_available',
                    'waitlist_messaging_available',
                ]),
            ])),
            'usage_types' => ConfigField::defaulted(ConfigSchema::listOf(ConfigSchema::string()), []),
            'profile_context_keys' => ConfigField::defaulted(ConfigSchema::listOf(ConfigSchema::string()), []),
            'managed_by_messaging' => ConfigField::optional(ConfigSchema::boolean()),
            'consolidation_policy' => ConfigField::optional(ConfigSchema::string(nullable: true)),
            'consolidation_primary' => ConfigField::optional(ConfigSchema::oneOf([
                $consolidationPrimary,
            ], nullable: true)),
            'sort_order' => ConfigField::optional(ConfigSchema::integer()),
        ]);
    }

    public function example(): array
    {
        return [
            'enabled' => true,
            'kind' => 'template',
            'label' => 'Registration confirmations',
            'description' => 'Sent after someone registers.',
            'purpose' => 'transactional',
            'scope' => 'webinar',
            'surface' => 'webinar_registrations',
            'message_type' => 'confirmation',
            'dispatch_key' => 'registration_created',
            'required' => true,
            'usage_types' => ['webinar_confirmation'],
            'profile_context_keys' => ['confirmation', 'confirmations'],
        ];
    }
}
