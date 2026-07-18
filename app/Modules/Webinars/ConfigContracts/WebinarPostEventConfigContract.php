<?php

namespace App\Modules\Webinars\ConfigContracts;

use App\Support\ConfigContracts\Contracts\ConfigContract;
use App\Support\ConfigContracts\Data\ConfigField;
use App\Support\ConfigContracts\Data\ConfigSchema;

class WebinarPostEventConfigContract implements ConfigContract
{
    public function key(): string
    {
        return 'webinars.post_event';
    }

    public function owner(): string
    {
        return 'webinars';
    }

    public function sourcePattern(): string
    {
        return 'webinars.post_event';
    }

    public function schema(): ConfigSchema
    {
        $event = ConfigSchema::object([
            'event_key' => ConfigField::required(ConfigSchema::string()),
        ]);

        return ConfigSchema::object([
            'events' => ConfigField::required(ConfigSchema::mapOf(ConfigSchema::listOf(ConfigSchema::string()))),
            'retry_seconds' => ConfigField::required(ConfigSchema::integer()),
            'attendance' => ConfigField::required(ConfigSchema::object([
                'enabled' => ConfigField::required(ConfigSchema::boolean()),
            ])),
            'recordings' => ConfigField::required(ConfigSchema::object([
                'enabled' => ConfigField::required(ConfigSchema::boolean()),
            ])),
            'outcome_messages' => ConfigField::required(ConfigSchema::object([
                // Legacy fields remain accepted during client-config migration,
                // but message-area enablement and identity now live under
                // webinars.message_areas.
                'enabled' => ConfigField::optional(ConfigSchema::boolean()),
                'dispatch_key' => ConfigField::optional(ConfigSchema::string()),
                'purpose' => ConfigField::optional(ConfigSchema::string()),
                'scope' => ConfigField::optional(ConfigSchema::string()),
                'channels' => ConfigField::required(ConfigSchema::listOf(ConfigSchema::string(allowedValues: ['email', 'sms']))),
                'conditions' => ConfigField::defaulted(ConfigSchema::listOf(ConfigSchema::object([], allowUnknown: true)), []),
            ])),
            'automation_events' => ConfigField::required(ConfigSchema::object([
                'enabled' => ConfigField::required(ConfigSchema::boolean()),
                'webinar_ended' => ConfigField::required($event),
                'attended' => ConfigField::required($event),
                'missed' => ConfigField::required($event),
                'replay_available' => ConfigField::optional($event),
            ])),
        ]);
    }

    public function example(): array
    {
        return config('webinars.post_event', []);
    }
}
