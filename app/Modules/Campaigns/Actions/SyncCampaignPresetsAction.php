<?php

namespace App\Modules\Campaigns\Actions;

use App\Modules\Campaigns\Data\CampaignPresetDefinition;
use App\Modules\Campaigns\Data\CampaignPresetSyncResult;
use App\Modules\Campaigns\Data\CampaignStepPresetDefinition;
use App\Modules\Campaigns\Data\CampaignStepVariantPresetDefinition;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignStep;
use App\Modules\Campaigns\Models\CampaignStepVariant;
use App\Modules\Campaigns\Services\CampaignMessageChainDefinitionBuilder;
use App\Modules\Messaging\Actions\PublishMessageChainVersionAction;
use App\Modules\Messaging\Models\MessageChain;
use App\Modules\Messaging\Models\MessageChainVersion;
use App\Modules\Messaging\Models\MessageTemplate;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use InvalidArgumentException;
use Throwable;
use App\Support\Presets\Data\ResolvedPresetDomain;
use App\Support\Presets\Enums\PresetDomain;

class SyncCampaignPresetsAction
{
    private const MESSAGE_CHAIN_SOURCE = 'campaign_preset_bridge';

    public function __construct(
        private readonly CampaignMessageChainDefinitionBuilder $messageChainDefinitionBuilder,
        private readonly PublishMessageChainVersionAction $publishMessageChainVersion,
    ) {}

    /**
     * Campaign preset sync intentionally has no force mode.
     *
     * Campaigns, Steps, and Variants may be customized by operators/clients,
     * so normal preset sync preserves customized records and only updates
     * non-customized preset-owned records. Add a force mode only as a
     * deliberate Campaigns feature, not as part of default preset cleanup.
     */
    public function handle(ResolvedPresetDomain $resolved): CampaignPresetSyncResult
    {
        if ($resolved->domain !== PresetDomain::Campaigns) {
            throw new InvalidArgumentException(sprintf(
                'Campaign preset sync requires domain [%s]; received [%s].',
                PresetDomain::Campaigns->value,
                $resolved->domain->value,
            ));
        }

        $result = new CampaignPresetSyncResult();

        foreach ($resolved->definitions as $campaignKey => $definitionData) {
            try {
                $definition = CampaignPresetDefinition::fromArray(
                    data: $definitionData,
                    definitionKey: $campaignKey,
                );
            } catch (Throwable $exception) {
                throw new InvalidArgumentException(
                    sprintf(
                        'Campaign preset definition [%s] is invalid: %s',
                        $campaignKey,
                        $exception->getMessage(),
                    ),
                    previous: $exception,
                );
            }

            DB::transaction(function () use ($definition, $result): void {
                $campaign = $this->syncCampaign($definition, $result);

                $this->syncSteps(
                    campaign: $campaign,
                    definition: $definition,
                    result: $result,
                );

                $this->syncMessageChain(
                    campaign: $campaign->refresh(),
                    result: $result,
                );
            }, 3);
        }

        return $result;
    }

    private function syncCampaign(
        CampaignPresetDefinition $definition,
        CampaignPresetSyncResult $result,
    ): Campaign {
        $campaign = Campaign::query()
            ->where('key', $this->normalizeSegment($definition->key))
            ->first();

        if (! $campaign instanceof Campaign) {
            $campaign = Campaign::query()->create([
                'key' => $this->normalizeSegment($definition->key),
                'name' => $definition->name,
                'description' => $definition->description,
                'channel' => $this->normalizeSegment($definition->channel),
                'purpose' => $this->normalizeSegment($definition->purpose),
                'scope' => $this->normalizeSegment($definition->scope),
                'family_key' => $definition->familyKey,
                'priority' => $definition->priority,
                'eligibility_filter' => $definition->eligibility->criteria,
                'enrollment_mode' => $definition->eligibility->mode,
                'reentry_policy' => $definition->eligibility->reentryPolicy,
                'ineligible_behavior' => $definition->eligibility->whenIneligible,
                'status' => $this->normalizeSegment($definition->status),
                'source_version' => $definition->sourceVersion,
                'is_customized' => false,
                'customized_at' => null,
                'meta' => $definition->meta,
            ]);

            $result->recordCampaignCreated();

            return $campaign;
        }

        if ($campaign->is_customized) {
            $result->recordCampaignSkipped();

            return $campaign;
        }

        $campaign->forceFill([
            'name' => $definition->name,
            'description' => $definition->description,
            'channel' => $this->normalizeSegment($definition->channel),
            'purpose' => $this->normalizeSegment($definition->purpose),
            'scope' => $this->normalizeSegment($definition->scope),
            'family_key' => $definition->familyKey,
            'priority' => $definition->priority,
            'eligibility_filter' => $definition->eligibility->criteria,
            'enrollment_mode' => $definition->eligibility->mode,
            'reentry_policy' => $definition->eligibility->reentryPolicy,
            'ineligible_behavior' => $definition->eligibility->whenIneligible,
            'source_version' => $definition->sourceVersion,
            'meta' => $this->campaignMeta(
                campaign: $campaign,
                presetMeta: $definition->meta,
            ),
        ])->save();

        $result->recordCampaignUpdated();

        return $campaign;
    }

    private function syncSteps(
        Campaign $campaign,
        CampaignPresetDefinition $definition,
        CampaignPresetSyncResult $result,
    ): void {
        if ($campaign->is_customized) {
            foreach ($definition->steps as $stepDefinition) {
                $result->recordStepSkipped();

                foreach ($stepDefinition->variants as $variantDefinition) {
                    $result->recordVariantSkipped();
                }
            }

            return;
        }

        $activeStepNumbers = array_values(array_unique(array_map(
            fn (CampaignStepPresetDefinition $stepDefinition): int => $stepDefinition->stepNumber,
            $definition->steps,
        )));

        CampaignStep::query()
            ->where('campaign_id', $campaign->id)
            ->when(
                $activeStepNumbers !== [],
                fn ($query) => $query->whereNotIn('step_number', $activeStepNumbers),
            )
            ->where('is_customized', false)
            ->delete();

        foreach ($definition->steps as $stepDefinition) {
            $step = $this->syncStep(
                campaign: $campaign,
                definition: $stepDefinition,
                result: $result,
            );

            $this->syncVariants(
                campaign: $campaign,
                step: $step,
                definition: $stepDefinition,
                result: $result,
            );
        }
    }

    private function syncStep(
        Campaign $campaign,
        CampaignStepPresetDefinition $definition,
        CampaignPresetSyncResult $result,
    ): CampaignStep {
        $step = CampaignStep::query()
            ->where('campaign_id', $campaign->id)
            ->where('step_number', $definition->stepNumber)
            ->first();

        if (! $step instanceof CampaignStep) {
            $step = CampaignStep::query()->create([
                'campaign_id' => $campaign->id,
                'step_number' => $definition->stepNumber,
                'name' => $definition->name,
                'dispatch_key' => $this->normalizeSegment($definition->dispatchKey),
                'channel' => $this->normalizeSegment($definition->channel),
                'purpose' => $this->normalizeSegment($definition->purpose),
                'scope' => $this->normalizeSegment($definition->scope),
                'variant_strategy' => $this->normalizeSegment($definition->variantStrategy),
                'is_active' => $definition->isActive,
                'criteria' => $definition->criteria,
                'source_version' => $definition->sourceVersion,
                'is_customized' => false,
                'customized_at' => null,
                'meta' => $definition->meta,
            ]);

            $result->recordStepCreated();

            return $step;
        }

        if ($step->is_customized) {
            $result->recordStepSkipped();

            return $step;
        }

        $step->forceFill([
            'name' => $definition->name,
            'dispatch_key' => $this->normalizeSegment($definition->dispatchKey),
            'channel' => $this->normalizeSegment($definition->channel),
            'purpose' => $this->normalizeSegment($definition->purpose),
            'scope' => $this->normalizeSegment($definition->scope),
            'variant_strategy' => $this->normalizeSegment($definition->variantStrategy),
            'is_active' => $definition->isActive,
            'criteria' => $definition->criteria,
            'source_version' => $definition->sourceVersion,
            'meta' => $definition->meta,
        ])->save();

        $result->recordStepUpdated();

        return $step;
    }

    private function syncVariants(
        Campaign $campaign,
        CampaignStep $step,
        CampaignStepPresetDefinition $definition,
        CampaignPresetSyncResult $result,
    ): void {
        if ($step->is_customized) {
            foreach ($definition->variants as $variantDefinition) {
                $result->recordVariantSkipped();
            }

            return;
        }

        $activeVariantKeys = array_values(array_unique(array_map(
            fn (CampaignStepVariantPresetDefinition $variantDefinition): string => $this->normalizeSegment($variantDefinition->key),
            $definition->variants,
        )));

        CampaignStepVariant::query()
            ->where('campaign_step_id', $step->id)
            ->when(
                $activeVariantKeys !== [],
                fn ($query) => $query->whereNotIn('key', $activeVariantKeys),
            )
            ->where('is_customized', false)
            ->delete();

        foreach ($definition->variants as $variantDefinition) {
            $this->syncVariant(
                step: $step,
                definition: $variantDefinition,
                result: $result,
            );
        }
    }

    private function syncVariant(
        CampaignStep $step,
        CampaignStepVariantPresetDefinition $definition,
        CampaignPresetSyncResult $result,
    ): CampaignStepVariant {
        $variant = CampaignStepVariant::query()
            ->where('campaign_step_id', $step->id)
            ->where('key', $this->normalizeSegment($definition->key))
            ->first();

        if (! $variant instanceof CampaignStepVariant) {
            $variant = CampaignStepVariant::query()->create($this->variantAttributes(
                step: $step,
                definition: $definition,
            ) + [
                'is_customized' => false,
                'customized_at' => null,
            ]);

            $result->recordVariantCreated();

            return $variant;
        }

        if ($variant->is_customized) {
            $result->recordVariantSkipped();

            return $variant;
        }

        $variant->forceFill($this->variantAttributes(
            step: $step,
            definition: $definition,
        ))->save();

        $result->recordVariantUpdated();

        return $variant;
    }

    /**
     * @return array<string, mixed>
     */
    private function variantAttributes(
        CampaignStep $step,
        CampaignStepVariantPresetDefinition $definition,
    ): array {
        return [
            'campaign_step_id' => $step->id,
            'key' => $this->normalizeSegment($definition->key),
            'name' => $definition->name,
            'sort_order' => $definition->sortOrder,
            'dispatch_key' => $this->normalizeSegment($definition->dispatchKey),
            'channel' => $this->normalizeSegment($definition->channel),
            'purpose' => $this->normalizeSegment($definition->purpose),
            'scope' => $this->normalizeSegment($definition->scope),
            'is_active' => $definition->isActive,
            'criteria' => $definition->criteria,
            'dependency_rules' => $definition->dependencyRules,
            'source_config_path' => null,
            'source_version' => $definition->sourceVersion,
            'meta' => $definition->meta,
        ];
    }

    private function syncMessageChain(
        Campaign $campaign,
        CampaignPresetSyncResult $result,
    ): void {
        if ($campaign->is_customized) {
            $result->recordMessageChainSkipped();

            return;
        }

        if (MessageTemplate::query()->doesntExist()) {
            $result->recordMessageChainDeferred();

            return;
        }

        $key = $this->messageChainKey($campaign);
        $chain = MessageChain::query()
            ->where('key', $key)
            ->lockForUpdate()
            ->first();
        $attributes = [
            'key' => $key,
            'name' => $campaign->name,
            'description' => $campaign->description,
            'status' => $this->messageChainStatus($campaign),
            'source' => self::MESSAGE_CHAIN_SOURCE,
            'source_version' => $campaign->source_version !== null
                ? (string) $campaign->source_version
                : null,
        ];

        if (! $chain instanceof MessageChain) {
            $chain = MessageChain::query()->create([
                ...$attributes,
                'is_customized' => false,
                'customized_at' => null,
            ]);
            $result->recordMessageChainCreated();
        } elseif ($chain->is_customized) {
            if (! $chain->current_version_id) {
                throw new RuntimeException(
                    "Customized Campaign MessageChain [{$chain->key}] has no current version.",
                );
            }

            $result->recordMessageChainSkipped();
            $this->bindCampaignToMessageChain($campaign, $chain);

            return;
        } else {
            $chain->forceFill([
                ...$attributes,
                'is_customized' => false,
                'customized_at' => null,
            ])->save();
            $result->recordMessageChainUpdated();
        }

        $steps = $this->messageChainDefinitionBuilder->build($campaign);
        $versionCountBefore = MessageChainVersion::query()
            ->where('message_chain_id', $chain->getKey())
            ->count();

        $this->publishMessageChainVersion->handle(
            messageChain: $chain,
            steps: $steps,
            exitConditions: [],
        );

        $versionCountAfter = MessageChainVersion::query()
            ->where('message_chain_id', $chain->getKey())
            ->count();

        if ($versionCountAfter > $versionCountBefore) {
            $result->recordMessageChainVersionPublished();
        } else {
            $result->recordMessageChainVersionReused();
        }

        $this->bindCampaignToMessageChain($campaign, $chain);
    }

    private function bindCampaignToMessageChain(
        Campaign $campaign,
        MessageChain $chain,
    ): void {
        if ((int) $campaign->message_chain_id === (int) $chain->getKey()) {
            return;
        }

        $campaign->forceFill([
            'message_chain_id' => $chain->getKey(),
        ])->save();
        $campaign->setRelation('messageChain', $chain);
    }

    private function messageChainKey(Campaign $campaign): string
    {
        $key = 'campaign.'.$this->normalizeSegment($campaign->key);

        if (mb_strlen($key) <= 191) {
            return $key;
        }

        return mb_substr($key, 0, 126).'.'.hash('sha256', $key);
    }

    private function messageChainStatus(Campaign $campaign): string
    {
        return match ($campaign->status) {
            Campaign::STATUS_ACTIVE => MessageChain::STATUS_ACTIVE,
            Campaign::STATUS_ARCHIVED => MessageChain::STATUS_ARCHIVED,
            default => MessageChain::STATUS_INACTIVE,
        };
    }

    /**
     * Preset status is an installation default. Existing Campaign status is
     * operational state and must not be overwritten by routine preset sync.
     *
     * @param array<string, mixed> $presetMeta
     * @return array<string, mixed>
     */
    private function campaignMeta(Campaign $campaign, array $presetMeta): array
    {
        $lifecycle = data_get($campaign->meta, 'lifecycle');

        if (is_array($lifecycle) && $lifecycle !== []) {
            $presetMeta['lifecycle'] = $lifecycle;
        }

        return $presetMeta;
    }

    private function normalizeSegment(string $value): string
    {
        return str_replace('-', '_', strtolower(trim($value)));
    }
}