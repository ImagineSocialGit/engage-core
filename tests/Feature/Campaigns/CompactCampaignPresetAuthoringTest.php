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
    }

    public function test_removed_verbose_fields_and_dependency_aliases_are_rejected(): void
    {
        $cases = [
            'campaign key' => [
                fn (array $definition): array => array_replace($definition, [
                    'key' => 'homebuyer_nurture',
                ]),
                'removed field [key]',
            ],
            'campaign channel' => [
                fn (array $definition): array => array_replace($definition, [
                    'channel' => 'email',
                ]),
                'removed field [channel]',
            ],
            'campaign dispatch key' => [
                fn (array $definition): array => array_replace($definition, [
                    'dispatch_key' => 'campaign_step_due',
                ]),
                'removed field [dispatch_key]',
            ],
            'step number' => [
                function (array $definition): array {
                    $definition['steps'][0]['step_number'] = 1;

                    return $definition;
                },
                'removed field [step_number]',
            ],
            'step dispatch key' => [
                function (array $definition): array {
                    $definition['steps'][0]['dispatch_key'] = 'campaign_step_due';

                    return $definition;
                },
                'removed field [dispatch_key]',
            ],
            'variant list' => [
                function (array $definition): array {
                    $definition['steps'][0]['variants'] = array_values(
                        $definition['steps'][0]['variants'],
                    );

                    return $definition;
                },
                'variants must be a non-empty map',
            ],
            'variant key' => [
                function (array $definition): array {
                    $definition['steps'][0]['variants']['sms']['key'] = 'sms';

                    return $definition;
                },
                'removed field [key]',
            ],
            'variant sort order' => [
                function (array $definition): array {
                    $definition['steps'][0]['variants']['sms']['sort_order'] = 10;

                    return $definition;
                },
                'removed field [sort_order]',
            ],
            'variant purpose' => [
                function (array $definition): array {
                    $definition['steps'][0]['variants']['sms']['purpose'] = 'marketing';

                    return $definition;
                },
                'removed field [purpose]',
            ],
            'dependency alias' => [
                function (array $definition): array {
                    $definition['steps'][0]['variants']['email']['dependency_rules'] = [
                        'requires_states' => [
                            'sms' => ['sent'],
                        ],
                    ];

                    return $definition;
                },
                'unsupported field(s): [requires_states]',
            ],
        ];

        foreach ($cases as $label => [$mutator, $messageFragment]) {
            $this->assertDefinitionInvalid(
                definition: $mutator($this->compactDefinition()),
                messageFragment: $messageFragment,
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
        string $messageFragment,
        string $label,
    ): void {
        try {
            CampaignPresetDefinition::fromArray(
                data: $definition,
                definitionKey: 'homebuyer_nurture',
            );
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString(
                $messageFragment,
                $exception->getMessage(),
                $label,
            );

            return;
        }

        $this->fail("Expected compact Campaign authoring case [{$label}] to be rejected.");
    }
}