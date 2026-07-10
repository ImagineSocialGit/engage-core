<?php

namespace Tests\Feature\Messaging;

use App\Modules\Messaging\Payloads\EmailPayload;
use App\Modules\Messaging\Services\MessageConfigValidator;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class MessageTemplateBehaviorOwnershipValidationTest extends TestCase
{
    public function test_reusable_message_template_rejects_timing_schedule_and_conditions(): void
    {
        Config::set('messaging.email.transactional.webinar', [
            'confirmation' => [
                'dispatch_key' => 'registration_created',
                'payload_class' => EmailPayload::class,
                'queue' => 'notifications',
                'timing' => 'scheduled',
                'schedule' => ['type' => 'delay', 'minutes' => 10],
                'conditions' => [['field' => 'webinar.starts_at', 'operator' => 'filled']],
                'payload' => [
                    'subject' => 'Registered',
                    'body' => 'Thanks',
                ],
            ],
        ]);

        $issues = app(MessageConfigValidator::class)->validateRoute(
            channel: 'email',
            purpose: 'transactional',
            scope: 'webinar',
        );

        $messages = collect($issues)->pluck('message')->all();

        $this->assertTrue(collect($messages)->contains(fn (string $message): bool => str_contains($message, '[timing]')));
        $this->assertTrue(collect($messages)->contains(fn (string $message): bool => str_contains($message, '[schedule]')));
        $this->assertTrue(collect($messages)->contains(fn (string $message): bool => str_contains($message, '[conditions]')));
    }

    public function test_content_only_reusable_message_template_is_valid(): void
    {
        Config::set('messaging.email.transactional.webinar', [
            'confirmation' => [
                'dispatch_key' => 'registration_created',
                'payload_class' => EmailPayload::class,
                'queue' => 'notifications',
                'payload' => [
                    'subject' => 'Registered',
                    'body' => 'Thanks',
                ],
            ],
        ]);

        $issues = app(MessageConfigValidator::class)->validateRoute(
            channel: 'email',
            purpose: 'transactional',
            scope: 'webinar',
        );

        $this->assertSame([], $issues);
    }
}
