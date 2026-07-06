<?php

namespace Database\Factories;

use App\Modules\Messaging\Models\MessageTemplatePreset;
use App\Modules\Messaging\Payloads\EmailPayload;
use Illuminate\Database\Eloquent\Factories\Factory;

class MessageTemplatePresetFactory extends Factory
{
    protected $model = MessageTemplatePreset::class;

    public function definition(): array
    {
        return [
            'key' => 'email.transactional.webinar.confirmation.'.uniqid(),
            'name' => 'Webinar confirmation',
            'description' => null,
            'channel' => 'email',
            'purpose' => 'transactional',
            'scope' => 'webinar',
            'message_type' => 'confirmation',
            'payload_class' => EmailPayload::class,
            'queue' => 'emails',
            'dispatch_keys' => ['registration_created'],
            'timing' => 'immediate',
            'schedule' => null,
            'conditions' => [],
            'payload' => [
                'subject' => 'Registered',
                'body' => 'Thanks',
            ],
            'tokens' => [],
            'status' => MessageTemplatePreset::STATUS_ACTIVE,
            'is_active' => true,
            'source' => 'factory',
            'source_config_path' => null,
            'source_version' => null,
            'is_customized' => false,
            'customized_at' => null,
            'last_synced_at' => null,
            'meta' => [],
        ];
    }

    public function customized(): static
    {
        return $this->state(fn () => [
            'is_customized' => true,
            'customized_at' => now(),
        ]);
    }
}
