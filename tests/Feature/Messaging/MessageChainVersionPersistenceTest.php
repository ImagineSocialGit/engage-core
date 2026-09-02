<?php

namespace Tests\Feature\Messaging;

use App\Modules\Messaging\Actions\PublishMessageChainVersionAction;
use App\Modules\Messaging\Actions\PublishMessageTemplateVersionAction;
use App\Modules\Messaging\Models\MessageChain;
use App\Modules\Messaging\Models\MessageChainStep;
use App\Modules\Messaging\Models\MessageChainStepVariant;
use App\Modules\Messaging\Models\MessageChainVersion;
use App\Modules\Messaging\Models\MessageTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class MessageChainVersionPersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_only_distinct_immutable_versions_and_can_reselect_an_existing_version(): void
    {
        $templateVersion = $this->templateVersion(
            key: 'email.transactional.fixture.confirmation',
            channel: 'email',
            payload: [
                'subject' => 'Fixture subject',
                'body' => 'Fixture body.',
            ],
        );

        $chain = MessageChain::query()->create([
            'key' => 'fixture.registration',
            'name' => 'Fixture Registration',
            'status' => MessageChain::STATUS_ACTIVE,
            'source' => 'test',
        ]);

        $publisher = app(PublishMessageChainVersionAction::class);

        $first = $publisher->handle(
            messageChain: $chain,
            exitConditions: [
                'status' => [
                    'cancelled',
                    'completed',
                ],
            ],
            steps: [[
                'key' => 'confirmation',
                'sort_order' => 10,
                'timing_type' => MessageChainStep::TIMING_IMMEDIATE,
                'conditions' => [
                    'all' => [
                        ['field' => 'fixture.ready', 'operator' => 'equals', 'value' => true],
                    ],
                ],
                'variants' => [[
                    'key' => 'email',
                    'sort_order' => 10,
                    'message_template_version_id' => $templateVersion->getKey(),
                    'channel' => 'email',
                    'purpose' => 'transactional',
                    'scope' => 'fixture',
                    'message_type' => 'fixture_confirmation',
                    'queue' => 'confirmation_messages',
                    'conditions' => [
                        'channel_enabled' => true,
                        'destination_present' => true,
                    ],
                ]],
            ]],
        );

        $same = $publisher->handle(
            messageChain: $chain,
            steps: [[
                'variants' => [[
                    'conditions' => [
                        'destination_present' => true,
                        'channel_enabled' => true,
                    ],
                    'queue' => 'confirmation_messages',
                    'message_type' => 'fixture_confirmation',
                    'scope' => 'fixture',
                    'purpose' => 'transactional',
                    'channel' => 'email',
                    'message_template_version_id' => $templateVersion->getKey(),
                    'sort_order' => 10,
                    'key' => 'email',
                ]],
                'conditions' => [
                    'all' => [
                        ['value' => true, 'operator' => 'equals', 'field' => 'fixture.ready'],
                    ],
                ],
                'timing_type' => MessageChainStep::TIMING_IMMEDIATE,
                'sort_order' => 10,
                'key' => 'confirmation',
            ]],
            exitConditions: [
                'status' => [
                    'cancelled',
                    'completed',
                ],
            ],
        );

        $second = $publisher->handle(
            messageChain: $chain,
            exitConditions: [
                'status' => [
                    'cancelled',
                    'completed',
                ],
            ],
            steps: [[
                'key' => 'confirmation',
                'sort_order' => 10,
                'timing_type' => MessageChainStep::TIMING_DELAY,
                'offset_seconds' => 300,
                'conditions' => [
                    'all' => [
                        ['field' => 'fixture.ready', 'operator' => 'equals', 'value' => true],
                    ],
                ],
                'variants' => [[
                    'key' => 'email',
                    'sort_order' => 10,
                    'message_template_version_id' => $templateVersion->getKey(),
                    'channel' => 'email',
                    'purpose' => 'transactional',
                    'scope' => 'fixture',
                    'message_type' => 'fixture_confirmation',
                    'queue' => 'confirmation_messages',
                    'conditions' => [
                        'channel_enabled' => true,
                        'destination_present' => true,
                    ],
                ]],
            ]],
        );

        $reselected = $publisher->handle(
            messageChain: $chain,
            exitConditions: [
                'status' => [
                    'cancelled',
                    'completed',
                ],
            ],
            steps: [[
                'key' => 'confirmation',
                'sort_order' => 10,
                'timing_type' => MessageChainStep::TIMING_IMMEDIATE,
                'conditions' => [
                    'all' => [
                        ['field' => 'fixture.ready', 'operator' => 'equals', 'value' => true],
                    ],
                ],
                'variants' => [[
                    'key' => 'email',
                    'sort_order' => 10,
                    'message_template_version_id' => $templateVersion->getKey(),
                    'channel' => 'email',
                    'purpose' => 'transactional',
                    'scope' => 'fixture',
                    'message_type' => 'fixture_confirmation',
                    'queue' => 'confirmation_messages',
                    'conditions' => [
                        'channel_enabled' => true,
                        'destination_present' => true,
                    ],
                ]],
            ]],
        );

        $this->assertSame($first->getKey(), $same->getKey());
        $this->assertSame(1, $first->version);
        $this->assertSame(2, $second->version);
        $this->assertSame($first->getKey(), $reselected->getKey());
        $this->assertSame(2, MessageChainVersion::query()->count());
        $this->assertSame($first->getKey(), $chain->refresh()->current_version_id);
        $this->assertEquals([
            'exit_conditions' => [
                'status' => [
                    'cancelled',
                    'completed',
                ],
            ],
            'steps' => [[
                'key' => 'confirmation',
                'name' => null,
                'sort_order' => 10,
                'timing_type' => MessageChainStep::TIMING_IMMEDIATE,
                'anchor_key' => null,
                'offset_seconds' => 0,
                'day_offset' => 0,
                'local_time' => null,
                'variant_strategy' => MessageChainStep::VARIANT_STRATEGY_FIRST_AVAILABLE,
                'advance_policy' => MessageChainStep::ADVANCE_ALL_TERMINAL,
                'conditions' => [
                    'all' => [
                        ['field' => 'fixture.ready', 'operator' => 'equals', 'value' => true],
                    ],
                ],
                'is_active' => true,
                'variants' => [[
                    'key' => 'email',
                    'sort_order' => 10,
                    'message_template_version_id' => $templateVersion->getKey(),
                    'channel' => 'email',
                    'purpose' => 'transactional',
                    'scope' => 'fixture',
                    'message_type' => 'fixture_confirmation',
                    'queue' => 'confirmation_messages',
                    'dependency_policy' => null,
                    'conditions' => [
                        'channel_enabled' => true,
                        'destination_present' => true,
                    ],
                    'is_active' => true,
                ]],
            ]],
        ], $first->definition());
    }

    public function test_published_chain_versions_steps_and_variants_are_immutable(): void
    {
        $templateVersion = $this->templateVersion(
            key: 'sms.transactional.fixture.reminder',
            channel: 'sms',
            payload: [
                'message' => 'Fixture reminder.',
            ],
        );

        $chain = MessageChain::query()->create([
            'key' => 'fixture.reminder',
            'name' => 'Fixture Reminder',
            'status' => MessageChain::STATUS_ACTIVE,
        ]);

        $version = app(PublishMessageChainVersionAction::class)->handle(
            messageChain: $chain,
            steps: [[
                'key' => 'reminder',
                'timing_type' => MessageChainStep::TIMING_ANCHORED,
                'anchor_key' => 'fixture.starts_at',
                'offset_seconds' => -600,
                'variants' => [[
                    'key' => 'sms',
                    'message_template_version_id' => $templateVersion->getKey(),
                    'channel' => 'sms',
                    'purpose' => 'transactional',
                    'scope' => 'fixture',
                    'message_type' => 'fixture_reminder',
                ]],
            ]],
        );

        $step = $version->steps->firstOrFail();
        $variant = $step->variants->firstOrFail();

        foreach ([
            fn () => $version->forceFill(['exit_conditions' => ['changed' => true]])->save(),
            fn () => $step->forceFill(['name' => 'Changed'])->save(),
            fn () => $variant->forceFill(['message_type' => 'changed'])->save(),
        ] as $mutation) {
            try {
                $mutation();
                $this->fail('Published message-chain records should be immutable.');
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->expectException(LogicException::class);

        $version->delete();
    }

    private function templateVersion(
        string $key,
        string $channel,
        array $payload,
    ) {
        $template = MessageTemplate::query()->create([
            'key' => $key,
            'name' => 'Fixture Template',
            'channel' => $channel,
            'status' => MessageTemplate::STATUS_ACTIVE,
            'source' => 'test',
        ]);

        return app(PublishMessageTemplateVersionAction::class)->handle(
            $template,
            $payload,
        );
    }
}