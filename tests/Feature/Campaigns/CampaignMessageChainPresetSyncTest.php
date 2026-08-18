<?php

namespace Tests\Feature\Campaigns;

use App\Modules\Campaigns\Actions\SyncCampaignPresetsAction;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Messaging\Services\MessageDefinitionResolver;
use App\Modules\Messaging\Models\MessageChain;
use App\Modules\Messaging\Models\MessageChainStep;
use App\Modules\Messaging\Models\MessageTemplate;
use App\Modules\Messaging\Models\MessageTemplateVersion;
use App\Support\Presets\Data\ResolvedPresetDomain;
use App\Support\Presets\Enums\PresetDomain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class CampaignMessageChainPresetSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_preset_sync_publishes_and_binds_an_immutable_message_chain(): void
    {
        $smsVersion = $this->templateVersion('fixture.campaign.sms', 'sms', 'a');
        $emailVersion = $this->templateVersion('fixture.campaign.email', 'email', 'b');

        $resolver = Mockery::mock(MessageDefinitionResolver::class);
        $resolver->shouldReceive('resolveCampaignStep')
            ->andReturnUsing(function (
                $channel,
                $purpose,
                $scope,
                $campaignKey,
                $stepNumber,
                $dispatchKey,
                $variantKey = null,
                $context = null,
            ) use ($smsVersion, $emailVersion): array {
                return [
                    'message_template_version_id' => $channel === 'sms'
                        ? $smsVersion->getKey()
                        : $emailVersion->getKey(),
                    'message_type' => $campaignKey.'_step_'.$stepNumber,
                    'queue' => 'marketing',
                ];
            });
        $this->app->instance(MessageDefinitionResolver::class, $resolver);

        $resolved = $this->resolvedDomain($this->campaignDefinition());
        $result = app(SyncCampaignPresetsAction::class)->handle($resolved);

        $campaign = Campaign::query()
            ->where('key', 'homebuyer_nurture')
            ->firstOrFail();
        $chain = MessageChain::query()
            ->with('currentVersion.steps.variants')
            ->where('key', 'campaign.homebuyer_nurture')
            ->firstOrFail();

        $this->assertSame((int) $chain->getKey(), (int) $campaign->message_chain_id);
        $this->assertSame('campaign_preset_bridge', $chain->source);
        $this->assertSame('7', $chain->source_version);
        $this->assertSame(MessageChain::STATUS_INACTIVE, $chain->status);
        $this->assertNotNull($chain->currentVersion);
        $this->assertTrue($chain->currentVersion->isPublished());
        $this->assertSame(1, $result->messageChainsCreated);
        $this->assertSame(1, $result->messageChainVersionsPublished);

        $steps = $chain->currentVersion->steps;

        $this->assertSame(['step_1', 'step_2'], $steps->pluck('key')->all());
        $this->assertSame(
            [MessageChainStep::TIMING_DELAY, MessageChainStep::TIMING_DELAY],
            $steps->pluck('timing_type')->all(),
        );
        $this->assertSame([7200, 86400], $steps->pluck('offset_seconds')->all());
        $this->assertSame(
            [
                MessageChainStep::VARIANT_STRATEGY_DEPENDENCY_AWARE,
                MessageChainStep::VARIANT_STRATEGY_FIRST_AVAILABLE,
            ],
            $steps->pluck('variant_strategy')->all(),
        );

        $firstVariants = $steps[0]->variants;

        $this->assertSame(['sms', 'email'], $firstVariants->pluck('key')->all());
        $this->assertSame(
            $smsVersion->getKey(),
            $firstVariants[0]->message_template_version_id,
        );
        $this->assertSame(
            $emailVersion->getKey(),
            $firstVariants[1]->message_template_version_id,
        );
        $this->assertEquals(
            [
                'requires_variant_states' => [
                    'sms' => ['sent', 'unavailable'],
                ],
            ],
            $firstVariants[1]->dependency_policy,
        );
        $this->assertSame('marketing', $firstVariants[0]->queue);

        $secondResult = app(SyncCampaignPresetsAction::class)->handle($resolved);

        $this->assertSame(1, $chain->versions()->count());
        $this->assertSame(1, $secondResult->messageChainVersionsReused);
    }

    public function test_changed_campaign_sequence_publishes_a_new_version_without_rewriting_the_old_one(): void
    {
        $emailVersion = $this->templateVersion('fixture.campaign.versioning.email', 'email', 'c');

        $resolver = Mockery::mock(MessageDefinitionResolver::class);
        $resolver->shouldReceive('resolveCampaignStep')
            ->andReturn([
                'message_template_version_id' => $emailVersion->getKey(),
                'message_type' => 'versioning_campaign_step',
                'queue' => 'marketing',
            ]);
        $this->app->instance(MessageDefinitionResolver::class, $resolver);

        $definition = [
            'name' => 'Versioned Campaign',
            'description' => 'Fixture Campaign.',
            'purpose' => 'marketing',
            'scope' => 'fixture_nurture',
            'status' => 'inactive',
            'source_version' => 1,
            'steps' => [[
                'name' => 'Follow-up',
                'criteria' => [
                    'timing' => [
                        'type' => 'delay',
                        'hours' => 2,
                    ],
                ],
                'variants' => [
                    'email' => [
                        'channel' => 'email',
                    ],
                ],
            ]],
        ];

        app(SyncCampaignPresetsAction::class)->handle(
            $this->resolvedDomain($definition, 'versioned_campaign'),
        );

        $chain = MessageChain::query()
            ->where('key', 'campaign.versioned_campaign')
            ->firstOrFail();
        $firstVersion = $chain->requireCurrentVersion();

        $definition['source_version'] = 2;
        $definition['steps'][0]['criteria']['timing']['hours'] = 3;

        $result = app(SyncCampaignPresetsAction::class)->handle(
            $this->resolvedDomain($definition, 'versioned_campaign'),
        );

        $chain->refresh();
        $secondVersion = $chain->requireCurrentVersion();

        $this->assertNotSame($firstVersion->getKey(), $secondVersion->getKey());
        $this->assertSame(2, $chain->versions()->count());
        $this->assertSame(1, $result->messageChainVersionsPublished);
        $this->assertSame(7200, $firstVersion->steps()->firstOrFail()->offset_seconds);
        $this->assertSame(10800, $secondVersion->steps()->firstOrFail()->offset_seconds);
        $this->assertNotNull($firstVersion->published_at);
        $this->assertNotNull($secondVersion->published_at);
    }

    /**
     * @return array<string, mixed>
     */
    private function campaignDefinition(): array
    {
        return [
            'name' => 'Homebuyer Nurture',
            'description' => 'Fixture compact Campaign definition.',
            'purpose' => 'marketing',
            'scope' => 'webinar_nurture',
            'status' => 'inactive',
            'variant_strategy' => 'dependency_aware',
            'source_version' => 7,
            'steps' => [
                [
                    'name' => 'Initial follow-up',
                    'criteria' => [
                        'timing' => [
                            'type' => 'delay',
                            'hours' => 2,
                        ],
                    ],
                    'variants' => [
                        'sms' => [
                            'channel' => 'sms',
                        ],
                        'email' => [
                            'channel' => 'email',
                            'dependency_rules' => [
                                'requires_variant_states' => [
                                    'sms' => ['sent', 'unavailable'],
                                ],
                            ],
                        ],
                    ],
                ],
                [
                    'name' => 'Second follow-up',
                    'variant_strategy' => 'first_available',
                    'criteria' => [
                        'timing' => [
                            'type' => 'delay',
                            'days' => 1,
                        ],
                    ],
                    'variants' => [
                        'email' => [
                            'channel' => 'email',
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $definition
     */
    private function resolvedDomain(
        array $definition,
        string $key = 'homebuyer_nurture',
    ): ResolvedPresetDomain {
        return new ResolvedPresetDomain(
            presetKey: 'fixture',
            domain: PresetDomain::Campaigns,
            selectedGroups: ['fixture'],
            selectedContributors: ['fixture'],
            definitionKeys: [$key],
            definitions: [$key => $definition],
            provenance: [
                $key => [
                    'contributor' => 'fixture',
                    'source' => 'tests.fixture.campaigns',
                ],
            ],
            definitionGroups: [$key => ['fixture']],
        );
    }

    private function templateVersion(
        string $key,
        string $channel,
        string $hashSeed,
    ): MessageTemplateVersion {
        $template = MessageTemplate::query()->create([
            'key' => $key,
            'name' => $key,
            'channel' => $channel,
            'status' => MessageTemplate::STATUS_ACTIVE,
            'source' => 'test',
            'is_customized' => false,
        ]);

        $version = MessageTemplateVersion::query()->create([
            'message_template_id' => $template->getKey(),
            'version' => 1,
            'subject' => $channel === 'email' ? 'Fixture subject' : null,
            'content' => $channel === 'email'
                ? ['body' => 'Fixture body']
                : ['message' => 'Fixture SMS'],
            'renderer_key' => 'fixture',
            'renderer_version' => '1',
            'content_hash' => hash('sha256', $hashSeed),
        ]);

        $template->forceFill([
            'current_version_id' => $version->getKey(),
        ])->save();

        return $version;
    }
}