<?php

namespace App\Modules\Messaging\ConfigContracts;

use App\Modules\Messaging\Payloads\SmsPayload;
use App\Support\ConfigContracts\Contracts\ConfigContract;
use App\Support\ConfigContracts\Data\ConfigField;
use App\Support\ConfigContracts\Data\ConfigSchema;

class SmsMessageDefinitionConfigContract implements ConfigContract
{
    public function key(): string
    {
        return 'messaging.sms_definition';
    }

    public function owner(): string
    {
        return 'messaging';
    }

    public function sourcePattern(): string
    {
        return 'messaging.sms.{purpose}.{scope}.{message_type_or_campaign_variant}';
    }

    public function schema(): ConfigSchema
    {
        return MessageDefinitionSchema::forChannel('sms', ConfigSchema::object([
            'message' => ConfigField::required(ConfigSchema::string()),
        ]));
    }

    public function example(): array
    {
        return [
            'dispatch_key' => 'registration_created',
            'payload_class' => SmsPayload::class,
            'queue' => 'confirmation_messages',
            'payload' => [
                'message' => 'Hi {first_name}, your registration is confirmed.',
            ],
        ];
    }
}
