<?php

namespace App\Modules\Messaging\ConfigContracts;

use App\Modules\Messaging\Enums\MessagePurpose;
use App\Modules\Messaging\Services\MessageTokenFallbackResolver;
use App\Modules\Messaging\Support\MessageMediaPayload;
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
            'reply_profile_key' => ConfigField::optional(ConfigSchema::string(nullable: true)),
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

    public static function tokenFallbacks(): ConfigSchema
    {
        return ConfigSchema::listOf(ConfigSchema::object([
            'token' => ConfigField::required(ConfigSchema::string()),
            'missing_behavior' => ConfigField::required(ConfigSchema::string(
                allowedValues: MessageTokenFallbackResolver::BEHAVIORS,
            )),
            'fallback' => ConfigField::optional(ConfigSchema::mixed()),
            'segment' => ConfigField::optional(ConfigSchema::string(nullable: true)),
        ]));
    }

    public static function link(): ConfigSchema
    {
        return ConfigSchema::object([
            'tracking_key' => ConfigField::optional(ConfigSchema::string()),
            'label' => ConfigField::required(ConfigSchema::string()),
            'url' => ConfigField::required(ConfigSchema::string()),
        ]);
    }

    public static function media(): ConfigSchema
    {
        return ConfigSchema::object([
            'asset_uuid' => ConfigField::required(ConfigSchema::string()),
            'kind' => ConfigField::required(ConfigSchema::string(
                allowedValues: MessageMediaPayload::KINDS,
            )),
            'title' => ConfigField::required(ConfigSchema::string()),
            'url' => ConfigField::required(ConfigSchema::string()),
            'mime_type' => ConfigField::optional(ConfigSchema::string(nullable: true)),
            'poster_asset_uuid' => ConfigField::optional(ConfigSchema::string(nullable: true)),
            'poster_url' => ConfigField::optional(ConfigSchema::string(nullable: true)),
            'tracking_key' => ConfigField::optional(ConfigSchema::string()),
        ]);
    }
}