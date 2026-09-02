<?php

namespace Tests\Feature\Campaigns;

use App\Modules\Campaigns\Actions\SyncCampaignPresetsAction;
use App\Modules\Campaigns\Data\CampaignPresetDefinition;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignStep;
use App\Modules\Campaigns\Models\CampaignStepVariant;
use App\Support\Presets\Enums\PresetDomain;
use App\Support\Presets\PresetCompositionResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use InvalidArgumentException;
use Tests\TestCase;

class CompactCampaignPresetAuthoringTest extends TestCase
{
    use RefreshDatabase;

    public function test_compact_definition_derives_runtime_identity_order_and_inherited_fields(): void
    {
        $definition = CampaignPresetDefinition::fromArray(
            data: $this->compactDefinition(),
            definitionKey: 'Homebuyer-Nurture',
        );

        $this->assertSame('homebuyer_nurture', $definition->key);
        $this->assertSame('multi', $definition->channel);
        $this->assertSame('marketing', $definition->purpose);
        $this->assertSame('webinar_nurture', $definition->scope);
        $this->assertSame('consumer_nurture', $definition->familyKey);
        $this->assertSame(15, $definition->priority);
        $this->assertSame(Campaign::ENROLLMENT_MODE_AUTOMATIC, $definition->eligibility->mode);
        $this->assertEquals([
            'status' => ['prospect_nurture'],
            'tag' => ['VA'],
        ], $definition->eligibility->criteria);
        $this->assertSame(Campaign::REENTRY_NEVER, $definition->eligibility->reentryPolicy);
        $this->assertSame(Campaign::INELIGIBLE_CANCEL, $definition->eligibility->whenIneligible);
        $this->assertSame('campaign_step_due', $definition->dispatchKey);
        $this->assertSame('send_all_eligible', $definition->variantStrategy);
        $this->assertSame('7', $definition->sourceVersion);
        $this->assertCount(2, $definition->steps);

        [$firstStep, $secondStep] = $definition->steps;

        $this->assertSame(1, $firstStep->stepNumber);
        $this->assertSame('multi', $firstStep->channel);
        $this->assertSame('send_all_eligible', $firstStep->variantStrategy);
        $this->assertSame('7', $firstStep->sourceVersion);
        $this->assertSame(['sms', 'email'], array_map(
            fn ($variant): string => $variant->key,
            $firstStep->variants,
        ));
        $this->assertSame([10, 20], array_map(
            fn ($variant): int => $variant->sortOrder,
            $firstStep->variants,
        ));

        foreach ($firstStep->variants as $variant) {
            $this->assertSame('campaign_step_due', $variant->dispatchKey);
            $this->assertSame('marketing', $variant->purpose);
            $this->assertSame('webinar_nurture', $variant->scope);
            $this->assertSame('7', $variant->sourceVersion);
        }

        $this->assertSame(2, $secondStep->stepNumber);
        $this->assertSame('email', $secondStep->channel);
        $this->assertSame('first_available', $secondStep->variantStrategy);
    }

    public function test_compact_definition_accepts_a_configured_scope_without_a_core_scope_registry(): void
    {
        $data = $this->compactDefinition();
        $data['scope'] = 'Client-Specific-Nurture';

        $definition = CampaignPresetDefinition::fromArray(
            data: $data,
            definitionKey: 'client_specific_campaign',
        );

        $this->assertSame('client_specific_nurture', $definition->scope);

        foreach ($definition->steps as $step) {
            foreach ($step->variants as $variant) {
                $this->assertSame('client_specific_nurture', $variant->scope);
            }
        }
    }

    public function test_compact_definition_syncs_derived_values_to_explicit_runtime_rows(): void
    {
        Config::set('presets.packages.test_client.groups.campaigns', ['default']);
        Config::set('presets.modules.webinars.campaigns.groups.default', [
            'homebuyer_nurture',
        ]);
        Config::set(
            'presets.modules.webinars.campaigns.definitions.homebuyer_nurture',
            $this->compactDefinition(),
        );

        app(SyncCampaignPresetsAction::class)->handle(
            app(PresetCompositionResolver::class)->resolve(
                'test_client',
                PresetDomain::Campaigns,
            ),
        );

        $campaign = Campaign::query()
            ->where('key', 'homebuyer_nurture')
            ->firstOrFail();

        $this->assertSame('multi', $campaign->channel);
        $this->assertSame('marketing', $campaign->purpose);
        $this->assertSame('webinar_nurture', $campaign->scope);
        $this->assertSame('consumer_nurture', $campaign->family_key);
        $this->assertSame(15, $campaign->priority);
        $this->assertSame(Campaign::ENROLLMENT_MODE_AUTOMATIC, $campaign->enrollment_mode);
        $this->assertEquals([
            'status' => ['prospect_nurture'],
            'tag' => ['VA'],
        ], $campaign->eligibility_filter);
        $this->assertSame(Campaign::REENTRY_NEVER, $campaign->reentry_policy);
        $this->assertSame(Campaign::INELIGIBLE_CANCEL, $campaign->ineligible_behavior);

        $steps = CampaignStep::query()
            ->where('campaign_id', $campaign->id)
            ->orderBy('step_number')
            ->get();

        $this->assertSame([1, 2], $steps->pluck('step_number')->all());
        $this->assertSame(['multi', 'email'], $steps->pluck('channel')->all());
        $this->assertSame(
            ['send_all_eligible', 'first_available'],
            $steps->pluck('variant_strategy')->all(),
        );

        $firstStepVariants = CampaignStepVariant::query()
            ->where('campaign_step_id', $steps[0]->id)
            ->orderBy('sort_order')
            ->get();

        $this->assertSame(['sms', 'email'], $firstStepVariants->pluck('key')->all());
        $this->assertSame([10, 20], $firstStepVariants->pluck('sort_order')->all());
        $this->assertSame(
            ['campaign_step_due'],
            $firstStepVariants->pluck('dispatch_key')->unique()->values()->all(),
        );
        $this->assertSame(
            [null],
            $firstStepVariants->pluck('source_config_path')->unique()->values()->all(),
        );
    }

    public function test_campaign_priority_must_be_an_integer(): void
    {
        $definition = $this->compactDefinition();
        $definition['priority'] = '15';

        $this->assertDefinitionInvalid(
            definition: $definition,
            label: 'priority string',
        );
    }

    public function test_eligibility_defaults_to_manual_and_fail_closed_policy(): void
    {
        $data = $this->compactDefinition();
        unset($data['eligibility']);

        $definition = CampaignPresetDefinition::fromArray(
            data: $data,
            definitionKey: 'manual_campaign',
        );

        $this->assertSame(Campaign::ENROLLMENT_MODE_MANUAL, $definition->eligibility->mode);
        $this->assertEquals([], $definition->eligibility->criteria);
        $this->assertSame(Campaign::REENTRY_NEVER, $definition->eligibility->reentryPolicy);
        $this->assertSame(Campaign::INELIGIBLE_CONTINUE, $definition->eligibility->whenIneligible);
    }

    public function test_automatic_eligibility_requires_at_least_one_criterion(): void
    {
        $data = $this->compactDefinition();
        $data['eligibility'] = [
            'mode' => 'automatic',
            'criteria' => [],
        ];

        $this->assertDefinitionInvalid(
            definition: $data,
            label: 'automatic eligibility without criteria',
        );
    }

    public function test_removed_verbose_fields_and_dependency_aliases_are_rejected(): void
    {
        $cases = [
            'campaign key' => fn (array $definition): array => array_replace($definition, [
                'key' => 'homebuyer_nurture',
            ]),
            'campaign channel' => fn (array $definition): array => array_replace($definition, [
                'channel' => 'email',
            ]),
            'campaign dispatch key' => fn (array $definition): array => array_replace($definition, [
                'dispatch_key' => 'campaign_step_due',
            ]),
            'step number' => function (array $definition): array {
                $definition['steps'][0]['step_number'] = 1;

                return $definition;
            },
            'step dispatch key' => function (array $definition): array {
                $definition['steps'][0]['dispatch_key'] = 'campaign_step_due';

                return $definition;
            },
            'variant list' => function (array $definition): array {
                $definition['steps'][0]['variants'] = array_values(
                    $definition['steps'][0]['variants'],
                );

                return $definition;
            },
            'variant key' => function (array $definition): array {
                $definition['steps'][0]['variants']['sms']['key'] = 'sms';

                return $definition;
            },
            'variant sort order' => function (array $definition): array {
                $definition['steps'][0]['variants']['sms']['sort_order'] = 10;

                return $definition;
            },
            'variant source config path' => function (array $definition): array {
                $definition['steps'][0]['variants']['sms']['source_config_path'] =
                    'messaging.sms.definitions.marketing.webinar_nurture.campaigns.homebuyer_nurture.steps.1.variants.sms';

                return $definition;
            },
            'variant purpose' => function (array $definition): array {
                $definition['steps'][0]['variants']['sms']['purpose'] = 'marketing';

                return $definition;
            },
            'dependency alias' => function (array $definition): array {
                $definition['steps'][0]['variants']['email']['dependency_rules'] = [
                    'requires_states' => [
                        'sms' => ['sent'],
                    ],
                ];

                return $definition;
            },
        ];

        foreach ($cases as $label => $mutator) {
            $this->assertDefinitionInvalid(
                definition: $mutator($this->compactDefinition()),
                label: $label,
            );
        }
    }

    /** @return array<string, mixed> */
    private function compactDefinition(): array
    {
        return [
            'name' => 'Homebuyer Nurture',
            'description' => 'Generic compact Campaign definition.',
            'purpose' => 'marketing',
            'scope' => 'webinar_nurture',
            'family_key' => 'Consumer-Nurture',
            'priority' => 15,
            'eligibility' => [
                'mode' => 'automatic',
                'criteria' => [
                    'status' => ['prospect_nurture'],
                    'tag' => ['VA'],
                ],
                'reentry' => 'never',
                'when_ineligible' => 'cancel',
            ],
            'variant_strategy' => 'send_all_eligible',
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
                            'name' => 'SMS follow-up',
                            'channel' => 'sms',
                        ],
                        'email' => [
                            'name' => 'Email follow-up',
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

    /** @param array<string, mixed> $definition */
    private function assertDefinitionInvalid(
        array $definition,
        string $label,
    ): void {
        try {
            CampaignPresetDefinition::fromArray(
                data: $definition,
                definitionKey: 'homebuyer_nurture',
            );
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);

            return;
        }

        $this->fail("Expected compact Campaign authoring case [{$label}] to be rejected.");
    }
}