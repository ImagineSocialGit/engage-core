<?php

namespace App\Modules\Messaging\ConfigContracts;

use App\Modules\Messaging\Payloads\EmailPayload;
use App\Support\ConfigContracts\Contracts\ConfigContract;
use App\Support\ConfigContracts\Data\ConfigField;
use App\Support\ConfigContracts\Data\ConfigSchema;

class EmailMessageDefinitionConfigContract implements ConfigContract
{
    public function key(): string
    {
        return 'messaging.email_definition';
    }

    public function owner(): string
    {
        return 'messaging';
    }

    public function sourcePattern(): string
    {
        return 'messaging.email.definitions.{purpose}.{scope}.{message_type_or_campaign_variant}';
    }

    public function schema(): ConfigSchema
    {
        return MessageDefinitionSchema::forChannel('email', ConfigSchema::object([
            'subject' => ConfigField::required(ConfigSchema::string()),
            'body' => ConfigField::required(ConfigSchema::string()),
            'view' => ConfigField::optional(ConfigSchema::string(nullable: true)),
            'cta' => ConfigField::optional(MessageDefinitionSchema::link()),
            'ctas' => ConfigField::optional(ConfigSchema::listOf(MessageDefinitionSchema::link())),
            'secondary_link' => ConfigField::optional(MessageDefinitionSchema::link()),
            'footer' => ConfigField::optional(ConfigSchema::string(nullable: true)),
        ]));
    }

    public function example(): array
    {
        return [
            'dispatch_key' => 'registration_created',
            'payload_class' => EmailPayload::class,
            'queue' => 'confirmation_messages',
            'payload' => [
                'subject' => 'Registration confirmed',
                'body' => 'Hi {first_name}, your registration is confirmed.',
            ],
        ];
    }
}