<?php

namespace Tests\Feature\Campaigns;

use App\Http\Middleware\ForceStagingAccess;
use App\Models\User;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Actions\PublishMessageChainVersionAction;
use App\Modules\Messaging\Actions\PublishMessageTemplateVersionAction;
use App\Modules\Messaging\Models\MessageChain;
use App\Modules\Messaging\Models\MessageChainEnrollment;
use App\Modules\Messaging\Models\MessageChainStep;
use App\Modules\Messaging\Models\MessageChainVersion;
use App\Modules\Messaging\Models\MessageTemplate;
use App\Modules\Messaging\Models\MessageTemplateCatalogEntry;
use App\Modules\Messaging\Models\MessageTemplatePreset;
use App\Modules\Messaging\Models\MessageTemplateVersion;
use App\Modules\Messaging\Payloads\EmailPayload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CampaignMessageChainAuthoringTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('modules.enabled', ['campaigns', 'messaging']);
        $this->withoutMiddleware(ForceStagingAccess::class);
    }

    public function test_setup_reads_the_current_pinned_chain_version_and_exposes_versioned_edit_actions(): void
    {
        $user = User::factory()->create();
        [$campaign, $version, $firstPreset] = $this->campaignWithPublishedChain();
        $firstVariant = $version->steps->firstOrFail()->variants->firstOrFail();

        $this->actingAs($user)
            ->get(route('crm.campaigns.edit', [
                'campaign' => $campaign,
                'panel' => 'messages',
            ]))
            ->assertOk()
            ->assertViewHas('scheduleAuthoring', fn (array $schedule): bool =>
                $schedule['editable'] === true
                && $schedule['message_chain_version_id'] === (int) $version->getKey()
                && count($schedule['steps']) === 2
            )
            ->assertSee('data-campaign-schedule-form', false)
            ->assertSee('name="message_chain_version_id"', false)
            ->assertSee('name="new_step[message_template_preset_id]"', false)
            ->assertSee(route('crm.campaigns.schedule.update', $campaign), false)
            ->assertSee(route('crm.campaigns.messages.update', [
                'campaign' => $campaign,
                'messageChainStepVariant' => $firstVariant,
                'messageTemplatePreset' => $firstPreset,
            ]), false)
            ->assertSee('chain-variant:'.$firstVariant->getKey(), false);
    }

    public function test_schedule_changes_publish_a_new_version_without_moving_existing_enrollments(): void
    {
        $user = User::factory()->create();
        [$campaign, $firstVersion, , $secondPreset] = $this->campaignWithPublishedChain();
        $enrollment = $this->enrollmentPinnedTo($campaign, $firstVersion);
        $steps = $firstVersion->steps->values();

        $this->actingAs($user)
            ->from(route('crm.campaigns.edit', [
                'campaign' => $campaign,
                'panel' => 'schedule',
            ]))
            ->patch(route('crm.campaigns.schedule.update', $campaign), [
                'campaign_editor' => 'schedule',
                'message_chain_version_id' => $firstVersion->getKey(),
                'steps' => [
                    [
                        'key' => $steps[0]->key,
                        'name' => $steps[0]->name,
                        'position' => 2,
                        'timing_type' => MessageChainStep::TIMING_IMMEDIATE,
                        'remove' => true,
                    ],
                    [
                        'key' => $steps[1]->key,
                        'name' => 'Moved follow-up',
                        'position' => 1,
                        'timing_type' => MessageChainStep::TIMING_DELAY,
                        'delay_value' => 3,
                        'delay_unit' => 'days',
                    ],
                ],
                'new_step' => [
                    'add' => true,
                    'message_template_preset_id' => $secondPreset->getKey(),
                    'name' => 'New final message',
                    'position' => 2,
                    'timing_type' => MessageChainStep::TIMING_DELAY,
                    'delay_value' => 2,
                    'delay_unit' => 'hours',
                ],
            ])
            ->assertRedirect(route('crm.campaigns.edit', [
                'campaign' => $campaign,
                'panel' => 'schedule',
            ]));

        $chain = $campaign->messageChain()->firstOrFail();
        $secondVersion = $chain->requireCurrentVersion();

        $this->assertNotSame($firstVersion->getKey(), $secondVersion->getKey());
        $this->assertSame(2, $chain->versions()->count());
        $this->assertSame($firstVersion->getKey(), $enrollment->fresh()->message_chain_version_id);
        $this->assertEquals(['step_2', 'step_3'], $secondVersion->steps->pluck('key')->all());
        $this->assertEquals(['Moved follow-up', 'New final message'], $secondVersion->steps->pluck('name')->all());
        $this->assertEquals([259200, 7200], $secondVersion->steps->pluck('offset_seconds')->all());
        $this->assertEquals(['step_1', 'step_2'], $firstVersion->fresh()->steps->pluck('key')->all());
        $this->assertEquals([0, 86400], $firstVersion->fresh()->steps->pluck('offset_seconds')->all());
        $this->assertTrue($campaign->fresh()->is_customized);
        $this->assertTrue($chain->fresh()->is_customized);
    }

    public function test_message_edit_publishes_template_and_chain_versions_while_preserving_existing_pins(): void
    {
        $user = User::factory()->create();
        [$campaign, $firstChainVersion, $preset] = $this->campaignWithPublishedChain();
        $enrollment = $this->enrollmentPinnedTo($campaign, $firstChainVersion);
        $firstVariant = $firstChainVersion->steps->firstOrFail()->variants->firstOrFail();
        $firstTemplateVersionId = (int) $firstVariant->message_template_version_id;

        $this->actingAs($user)
            ->from(route('crm.campaigns.edit', [
                'campaign' => $campaign,
                'panel' => 'messages',
            ]))
            ->patch(route('crm.campaigns.messages.update', [
                'campaign' => $campaign,
                'messageChainStepVariant' => $firstVariant,
                'messageTemplatePreset' => $preset,
            ]), [
                'campaign_editor' => 'messages',
                '_editing_message_id' => 'chain-variant:'.$firstVariant->getKey(),
                'message_chain_version_id' => $firstChainVersion->getKey(),
                'payload' => [
                    'subject' => 'Updated Campaign subject',
                    'body' => 'Updated Campaign body.',
                ],
            ])
            ->assertRedirect(route('crm.campaigns.edit', [
                'campaign' => $campaign,
                'panel' => 'messages',
            ]));

        $chain = $campaign->messageChain()->firstOrFail();
        $secondChainVersion = $chain->requireCurrentVersion();
        $replacementVariant = $secondChainVersion->steps->firstOrFail()->variants->firstOrFail();
        $template = MessageTemplate::query()->where('key', $preset->key)->firstOrFail();
        $secondTemplateVersion = $template->requireCurrentVersion();

        $this->assertNotSame($firstChainVersion->getKey(), $secondChainVersion->getKey());
        $this->assertNotSame($firstTemplateVersionId, $secondTemplateVersion->getKey());
        $this->assertSame($secondTemplateVersion->getKey(), $replacementVariant->message_template_version_id);
        $this->assertSame($firstTemplateVersionId, $firstVariant->fresh()->message_template_version_id);
        $this->assertSame($firstChainVersion->getKey(), $enrollment->fresh()->message_chain_version_id);
        $this->assertSame('Updated Campaign subject', $secondTemplateVersion->payload()['subject']);
        $this->assertSame('Updated Campaign body.', $secondTemplateVersion->payload()['body']);
    }

    public function test_stale_schedule_submission_is_rejected_without_publishing_another_version(): void
    {
        $user = User::factory()->create();
        [$campaign, $firstVersion] = $this->campaignWithPublishedChain();
        $chain = $campaign->messageChain()->firstOrFail();
        $definition = $firstVersion->definition();

        app(PublishMessageChainVersionAction::class)->handle(
            messageChain: $chain,
            steps: array_map(function (array $step): array {
                $step['name'] = ($step['name'] ?? 'Message').' revised';

                return $step;
            }, $definition['steps']),
            exitConditions: $definition['exit_conditions'] ?? [],
        );

        $versionCount = $chain->versions()->count();

        $this->actingAs($user)
            ->from(route('crm.campaigns.edit', [
                'campaign' => $campaign,
                'panel' => 'schedule',
            ]))
            ->patch(route('crm.campaigns.schedule.update', $campaign), [
                'campaign_editor' => 'schedule',
                'message_chain_version_id' => $firstVersion->getKey(),
                'steps' => collect($firstVersion->steps)->values()->map(
                    fn (MessageChainStep $step, int $index): array => [
                        'key' => $step->key,
                        'name' => $step->name,
                        'position' => $index + 1,
                        'timing_type' => $step->timing_type,
                        'delay_value' => $step->timing_type === MessageChainStep::TIMING_DELAY ? 1 : null,
                        'delay_unit' => $step->timing_type === MessageChainStep::TIMING_DELAY ? 'days' : null,
                    ],
                )->all(),
            ])
            ->assertSessionHasErrors(['message_chain_version_id']);

        $this->assertSame($versionCount, $chain->versions()->count());
    }

    public function test_stale_message_submission_rolls_back_template_publication(): void
    {
        $user = User::factory()->create();
        [$campaign, $firstVersion, $preset] = $this->campaignWithPublishedChain();
        $chain = $campaign->messageChain()->firstOrFail();
        $target = $firstVersion->steps->firstOrFail()->variants->firstOrFail();
        $template = MessageTemplate::query()->where('key', $preset->key)->firstOrFail();
        $templateVersionCount = $template->versions()->count();
        $definition = $firstVersion->definition();
        $definition['steps'][0]['name'] = 'Published in another tab';

        app(PublishMessageChainVersionAction::class)->handle(
            messageChain: $chain,
            steps: $definition['steps'],
            exitConditions: $definition['exit_conditions'] ?? [],
        );

        $chainVersionCount = $chain->versions()->count();

        $this->actingAs($user)
            ->from(route('crm.campaigns.edit', [
                'campaign' => $campaign,
                'panel' => 'messages',
            ]))
            ->patch(route('crm.campaigns.messages.update', [
                'campaign' => $campaign,
                'messageChainStepVariant' => $target,
                'messageTemplatePreset' => $preset,
            ]), [
                'campaign_editor' => 'messages',
                '_editing_message_id' => 'chain-variant:'.$target->getKey(),
                'message_chain_version_id' => $firstVersion->getKey(),
                'payload' => [
                    'subject' => 'Stale subject',
                    'body' => 'This must roll back.',
                ],
            ])
            ->assertSessionHasErrors(['message_chain_version_id']);

        $this->assertSame($templateVersionCount, $template->versions()->count());
        $this->assertSame($chainVersionCount, $chain->versions()->count());
        $this->assertSame('First subject', $template->fresh()->currentPayload()['subject']);
    }

    /**
     * @return array{0: Campaign, 1: MessageChainVersion, 2: MessageTemplatePreset, 3: MessageTemplatePreset}
     */
    private function campaignWithPublishedChain(): array
    {
        [$firstPreset, $firstTemplateVersion] = $this->publishedPreset(
            key: 'fixture.campaign.first.email',
            name: 'First Campaign email',
            subject: 'First subject',
        );
        [$secondPreset, $secondTemplateVersion] = $this->publishedPreset(
            key: 'fixture.campaign.second.email',
            name: 'Second Campaign email',
            subject: 'Second subject',
        );
        $chain = MessageChain::query()->create([
            'key' => 'campaign.authoring_fixture',
            'name' => 'Authoring fixture',
            'status' => MessageChain::STATUS_ACTIVE,
            'source' => 'test',
            'is_customized' => false,
        ]);
        $version = app(PublishMessageChainVersionAction::class)->handle(
            messageChain: $chain,
            steps: [
                $this->stepDefinition(
                    key: 'step_1',
                    name: 'First message',
                    sortOrder: 10,
                    timingType: MessageChainStep::TIMING_IMMEDIATE,
                    offsetSeconds: 0,
                    templateVersion: $firstTemplateVersion,
                ),
                $this->stepDefinition(
                    key: 'step_2',
                    name: 'Second message',
                    sortOrder: 20,
                    timingType: MessageChainStep::TIMING_DELAY,
                    offsetSeconds: 86400,
                    templateVersion: $secondTemplateVersion,
                ),
            ],
        );
        $campaign = Campaign::factory()->create([
            'key' => 'authoring_fixture',
            'name' => 'Authoring fixture',
            'message_chain_id' => $chain->getKey(),
            'status' => Campaign::STATUS_ACTIVE,
        ]);

        return [$campaign->refresh(), $version->fresh('steps.variants'), $firstPreset, $secondPreset];
    }

    /** @return array{0: MessageTemplatePreset, 1: MessageTemplateVersion} */
    private function publishedPreset(
        string $key,
        string $name,
        string $subject,
    ): array {
        $preset = MessageTemplatePreset::factory()->create([
            'key' => $key,
            'name' => $name,
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'fixture_nurture',
            'message_type' => str_replace('.', '_', $key),
            'payload_class' => EmailPayload::class,
            'queue' => 'marketing',
            'dispatch_keys' => ['campaign_step_due'],
            'payload' => [
                'subject' => $subject,
                'body' => $name.' body.',
            ],
        ]);
        MessageTemplateCatalogEntry::factory()
            ->forPreset($preset)
            ->create([
                'channel' => 'email',
                'purpose' => 'marketing',
                'scope' => 'fixture_nurture',
                'module_key' => 'campaigns',
                'module_label' => 'Campaigns',
                'surface' => 'campaigns',
                'group_key' => 'campaign:authoring_fixture',
                'group_label' => 'Authoring fixture',
                'item_key' => $key,
                'item_label' => $name,
                'usage_type' => 'campaign_step',
                'is_active' => true,
            ]);
        $template = MessageTemplate::query()->create([
            'key' => $key,
            'name' => $name,
            'channel' => 'email',
            'status' => MessageTemplate::STATUS_ACTIVE,
            'source' => 'test',
            'is_customized' => false,
        ]);
        $version = app(PublishMessageTemplateVersionAction::class)->handle(
            messageTemplate: $template,
            payload: $preset->payload,
        );

        return [$preset->refresh(), $version];
    }

    /** @return array<string, mixed> */
    private function stepDefinition(
        string $key,
        string $name,
        int $sortOrder,
        string $timingType,
        int $offsetSeconds,
        MessageTemplateVersion $templateVersion,
    ): array {
        return [
            'key' => $key,
            'name' => $name,
            'sort_order' => $sortOrder,
            'timing_type' => $timingType,
            'offset_seconds' => $offsetSeconds,
            'variant_strategy' => MessageChainStep::VARIANT_STRATEGY_FIRST_AVAILABLE,
            'advance_policy' => MessageChainStep::ADVANCE_ALL_TERMINAL,
            'conditions' => [],
            'is_active' => true,
            'variants' => [[
                'key' => 'email',
                'sort_order' => 10,
                'message_template_version_id' => $templateVersion->getKey(),
                'channel' => 'email',
                'purpose' => 'marketing',
                'scope' => 'fixture_nurture',
                'message_type' => $key.'_email',
                'queue' => 'marketing',
                'dependency_policy' => [],
                'conditions' => [],
                'is_active' => true,
            ]],
        ];
    }

    private function enrollmentPinnedTo(
        Campaign $campaign,
        MessageChainVersion $version,
    ): MessageChainEnrollment {
        $contact = Contact::factory()->create();

        return MessageChainEnrollment::query()->create([
            'message_chain_version_id' => $version->getKey(),
            'recipient_type' => $contact->getMorphClass(),
            'recipient_id' => $contact->getKey(),
            'context_type' => null,
            'context_id' => null,
            'origin_type' => $campaign->getMorphClass(),
            'origin_id' => $campaign->getKey(),
            'surface' => 'campaigns',
            'current_message_chain_step_id' => $version->steps->firstOrFail()->getKey(),
            'next_action_at' => now()->addHour(),
            'status' => MessageChainEnrollment::STATUS_ACTIVE,
            'dedupe_key' => 'campaign-authoring-'.uniqid(),
            'started_at' => now(),
        ]);
    }
}