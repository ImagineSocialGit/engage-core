<?php

namespace Tests\Feature\Messaging;

use App\Modules\Messaging\Actions\DuplicateMessageChainAction;
use App\Modules\Messaging\Actions\PublishMessageChainVersionAction;
use App\Modules\Messaging\Actions\PublishMessageTemplateVersionAction;
use App\Modules\Messaging\Models\MessageChain;
use App\Modules\Messaging\Models\MessageChainStep;
use App\Modules\Messaging\Models\MessageChainStepVariant;
use App\Modules\Messaging\Models\MessageChainVersion;
use App\Modules\Messaging\Models\MessageTemplate;
use App\Modules\Messaging\Models\MessageTemplateVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MessageChainDuplicationTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_duplicates_chain_structure_while_reusing_immutable_template_versions_until_copy_on_write_customization(): void
    {
        $emailTemplate = MessageTemplate::query()->create([
            'key' => 'email.transactional.fixture.registration',
            'name' => 'Fixture Registration Email',
            'channel' => 'email',
            'status' => MessageTemplate::STATUS_ACTIVE,
            'source' => 'test',
        ]);
        $smsTemplate = MessageTemplate::query()->create([
            'key' => 'sms.transactional.fixture.reminder',
            'name' => 'Fixture Reminder SMS',
            'channel' => 'sms',
            'status' => MessageTemplate::STATUS_ACTIVE,
            'source' => 'test',
        ]);

        $templatePublisher = app(PublishMessageTemplateVersionAction::class);
        $emailVersion = $templatePublisher->handle($emailTemplate, [
            'subject' => 'Fixture registration',
            'body' => 'Fixture registration body.',
        ]);
        $smsVersion = $templatePublisher->handle($smsTemplate, [
            'message' => 'Fixture reminder.',
        ]);

        $source = MessageChain::query()->create([
            'key' => 'fixture.series.primary',
            'name' => 'Fixture Primary Series',
            'description' => 'Fixture chain used as a duplication source.',
            'status' => MessageChain::STATUS_ACTIVE,
            'source' => 'test',
        ]);

        $chainPublisher = app(PublishMessageChainVersionAction::class);
        $sourceVersion = $chainPublisher->handle(
            messageChain: $source,
            exitConditions: [
                'any' => [
                    ['field' => 'fixture.cancelled_at', 'operator' => 'present'],
                ],
            ],
            steps: [
                [
                    'key' => 'registration',
                    'sort_order' => 10,
                    'timing_type' => MessageChainStep::TIMING_IMMEDIATE,
                    'variants' => [[
                        'key' => 'email',
                        'message_template_version_id' => $emailVersion->getKey(),
                        'channel' => 'email',
                        'purpose' => 'transactional',
                        'scope' => 'fixture',
                        'message_type' => 'fixture_registration',
                        'queue' => 'confirmation_messages',
                    ]],
                ],
                [
                    'key' => 'reminder',
                    'sort_order' => 20,
                    'timing_type' => MessageChainStep::TIMING_ANCHORED,
                    'anchor_key' => 'fixture.starts_at',
                    'offset_seconds' => -3600,
                    'variants' => [[
                        'key' => 'sms',
                        'message_template_version_id' => $smsVersion->getKey(),
                        'channel' => 'sms',
                        'purpose' => 'transactional',
                        'scope' => 'fixture',
                        'message_type' => 'fixture_reminder',
                        'queue' => 'reminders',
                    ]],
                ],
            ],
        );

        $duplicate = app(DuplicateMessageChainAction::class)->handle(
            source: $source,
            key: 'fixture.series.secondary',
            name: 'Fixture Secondary Series',
        );

        $duplicateVersion = $duplicate->requireCurrentVersion();

        $this->assertNotSame($source->getKey(), $duplicate->getKey());
        $this->assertNotSame(
            $sourceVersion->getKey(),
            $duplicateVersion->getKey(),
        );
        $this->assertSame('duplicate', $duplicate->source);
        $this->assertTrue($duplicate->is_customized);
        $this->assertEquals(
            $sourceVersion->definition(),
            $duplicateVersion->definition(),
        );
        $this->assertSame(2, MessageChain::query()->count());
        $this->assertSame(2, MessageChainVersion::query()->count());
        $this->assertSame(4, MessageChainStep::query()->count());
        $this->assertSame(4, MessageChainStepVariant::query()->count());
        $this->assertSame(2, MessageTemplateVersion::query()->count());

        $customEmailVersion = $templatePublisher->handle($emailTemplate, [
            'subject' => 'Customized fixture registration',
            'body' => 'Customized fixture registration body.',
        ]);

        $customDefinition = $duplicateVersion->definition();
        $customDefinition['steps'][0]['variants'][0]['message_template_version_id'] =
            $customEmailVersion->getKey();

        $customDuplicateVersion = $chainPublisher->handle(
            messageChain: $duplicate,
            steps: $customDefinition['steps'],
            exitConditions: $customDefinition['exit_conditions'],
        );

        $source->refresh()->load('currentVersion.steps.variants');
        $duplicate->refresh()->load('currentVersion.steps.variants');

        $this->assertSame(
            $sourceVersion->getKey(),
            $source->current_version_id,
        );
        $this->assertSame(
            $emailVersion->getKey(),
            $source->requireCurrentVersion()
                ->steps
                ->firstOrFail()
                ->variants
                ->firstOrFail()
                ->message_template_version_id,
        );
        $this->assertSame(
            $customDuplicateVersion->getKey(),
            $duplicate->current_version_id,
        );
        $this->assertSame(
            $customEmailVersion->getKey(),
            $duplicate->requireCurrentVersion()
                ->steps
                ->firstOrFail()
                ->variants
                ->firstOrFail()
                ->message_template_version_id,
        );
        $this->assertSame(3, MessageChainVersion::query()->count());
        $this->assertSame(3, MessageTemplateVersion::query()->count());
    }
}