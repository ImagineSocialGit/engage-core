<?php

namespace Tests\Feature\Messaging;

use App\Modules\Messaging\Actions\PublishMessageChainVersionAction;
use App\Modules\Messaging\Actions\PublishMessageTemplateVersionAction;
use App\Modules\Messaging\Models\MessageChain;
use App\Modules\Messaging\Models\MessageChainStep;
use App\Modules\Messaging\Models\MessageTemplate;
use App\Modules\Messaging\Services\MessageChainPresentationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MessageChainPresentationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_projects_current_chain_copy_into_channel_first_message_carousels(): void
    {
        $emailTemplate = MessageTemplate::query()->create([
            'key' => 'test.chain.email',
            'name' => 'Email Fixture',
            'channel' => 'email',
            'status' => MessageTemplate::STATUS_ACTIVE,
            'source' => 'test',
        ]);
        $emailVersion = app(PublishMessageTemplateVersionAction::class)->handle(
            messageTemplate: $emailTemplate,
            payload: [
                'subject' => 'Welcome {first_name}',
                'body' => 'Email body.',
            ],
        );

        $smsTemplate = MessageTemplate::query()->create([
            'key' => 'test.chain.sms',
            'name' => 'SMS Fixture',
            'channel' => 'sms',
            'status' => MessageTemplate::STATUS_ACTIVE,
            'source' => 'test',
        ]);
        $smsVersion = app(PublishMessageTemplateVersionAction::class)->handle(
            messageTemplate: $smsTemplate,
            payload: [
                'message' => 'Reminder text.',
            ],
        );

        $chain = MessageChain::query()->create([
            'key' => 'test.chain.presentation',
            'name' => 'Presentation Fixture',
            'status' => MessageChain::STATUS_ACTIVE,
            'source' => 'test',
        ]);

        app(PublishMessageChainVersionAction::class)->handle(
            messageChain: $chain,
            steps: [
                [
                    'key' => 'welcome_email',
                    'name' => 'Welcome Email',
                    'sort_order' => 10,
                    'timing_type' => MessageChainStep::TIMING_DELAY,
                    'offset_seconds' => 900,
                    'variants' => [[
                        'key' => 'email',
                        'message_template_version_id' => $emailVersion->getKey(),
                        'channel' => 'email',
                        'purpose' => 'transactional',
                        'scope' => 'test',
                        'message_type' => 'welcome',
                        'queue' => 'default',
                    ]],
                ],
                [
                    'key' => 'reminder_sms',
                    'name' => 'Reminder SMS',
                    'sort_order' => 20,
                    'timing_type' => MessageChainStep::TIMING_ANCHORED,
                    'anchor_key' => 'webinar.starts_at',
                    'offset_seconds' => -3600,
                    'variants' => [[
                        'key' => 'sms',
                        'message_template_version_id' => $smsVersion->getKey(),
                        'channel' => 'sms',
                        'purpose' => 'transactional',
                        'scope' => 'test',
                        'message_type' => 'reminder',
                        'queue' => 'default',
                    ]],
                ],
            ],
        );

        $presentation = app(MessageChainPresentationService::class)->present(
            messageChain: $chain->fresh(),
            anchorLabels: ['webinar.starts_at' => 'webinar start'],
        );

        $this->assertSame(2, $presentation['message_count']);
        $this->assertEquals(['email', 'sms'], array_keys($presentation['channels']));
        $this->assertSame('welcome_email', $presentation['channels']['email']['messages'][0]['step_key']);
        $this->assertSame('email', $presentation['channels']['email']['messages'][0]['variant_key']);
        $this->assertSame('email', $presentation['channels']['email']['messages'][0]['channel']);
        $this->assertSame('reminder_sms', $presentation['channels']['sms']['messages'][0]['step_key']);
        $this->assertSame('sms', $presentation['channels']['sms']['messages'][0]['variant_key']);
        $this->assertSame('sms', $presentation['channels']['sms']['messages'][0]['channel']);
        $this->assertSame(
            'Welcome {first_name}',
            $presentation['channels']['email']['messages'][0]['payload']['subject'],
        );
        $this->assertSame(
            'Reminder text.',
            $presentation['channels']['sms']['messages'][0]['payload']['message'],
        );
    }
}