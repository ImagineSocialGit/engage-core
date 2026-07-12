<?php

namespace App\Modules\Messaging\ConfigContracts;

use App\Modules\Messaging\Enums\MessagePurpose;
use App\Support\ConfigContracts\Data\ConfigField;
use App\Support\ConfigContracts\Data\ConfigSchema;

class MessageDefinitionSchema
{
    public static function forChannel(string $channel, ConfigSchema $payload): ConfigSchema
    {
        $version = ConfigSchema::oneOf([
            ConfigSchema::string(),
            ConfigSchema::integer(),
            ConfigSchema::number(),
        ], nullable: true);

        $fields = [
            'key' => ConfigField::optional(ConfigSchema::string()),
            'enabled' => ConfigField::defaulted(ConfigSchema::boolean(), true),
            'message_type' => ConfigField::optional(ConfigSchema::string()),
            'channel' => ConfigField::optional(ConfigSchema::string(allowedValues: [$channel])),
            'purpose' => ConfigField::optional(ConfigSchema::string(allowedValues: MessagePurpose::values())),
            'scope' => ConfigField::optional(ConfigSchema::string()),
            'payload_class' => ConfigField::required(ConfigSchema::string()),
            'queue' => ConfigField::required(ConfigSchema::string()),
            'payload' => ConfigField::required($payload),
            'description' => ConfigField::optional(ConfigSchema::string(nullable: true)),
            'notification_type' => ConfigField::optional(ConfigSchema::string(nullable: true)),
            'source_version' => ConfigField::optional($version),
            'meta' => ConfigField::defaulted(ConfigSchema::object([], allowUnknown: true), []),
        ];

        return ConfigSchema::object([
            ...$fields,
            'dispatch_key' => ConfigField::optional(ConfigSchema::string()),
            'dispatch_keys' => ConfigField::optional(ConfigSchema::listOf(ConfigSchema::string())),
        ], atLeastOne: [['dispatch_key', 'dispatch_keys']]);
    }

    public static function link(): ConfigSchema
    {
        return ConfigSchema::object([
            'label' => ConfigField::required(ConfigSchema::string()),
            'url' => ConfigField::required(ConfigSchema::string()),
        ]);
    }
}
