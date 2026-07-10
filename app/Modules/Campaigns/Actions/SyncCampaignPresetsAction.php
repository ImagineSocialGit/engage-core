<?php

namespace App\Modules\Campaigns\Actions;

use App\Modules\Campaigns\Data\CampaignPresetDefinition;
use App\Modules\Campaigns\Data\CampaignPresetSyncResult;
use App\Modules\Campaigns\Data\CampaignStepPresetDefinition;
use App\Modules\Campaigns\Data\CampaignStepVariantPresetDefinition;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignStep;
use App\Modules\Campaigns\Models\CampaignStepVariant;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;
use App\Support\Presets\Data\ResolvedPresetDomain;
use App\Support\Presets\Enums\PresetDomain;

class SyncCampaignPresetsAction
{
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

        $definitions = [];

        foreach ($resolved->definitions as $campaignKey => $definition) {
            $definitions[] = array_replace(['key' => $campaignKey], $definition);
        }

        foreach ($definitions as $definitionData) {
            try {
                $definition = CampaignPresetDefinition::fromArray($definitionData);
            } catch (Throwable $exception) {
                $result->campaignSkipped(
                    $definitionData['key'] ?? 'unknown',
                    $exception->getMessage(),
                );

                continue;
            }

            $campaign = $this->syncCampaign($definition, $result);

            $this->syncSteps(
                campaign: $campaign,
                definition: $definition,
                result: $result,
            );
        }

        return $result;
    }

    /**
     * @return array<int, CampaignPresetDefinition>
     */

    /**
     * @return array<string, mixed>
     */

    /**
     * @return array<int, string>
     */

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
                'status' => $this->normalizeSegment($definition->status),
                'is_active' => $definition->isActive,
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
            'status' => $this->normalizeSegment($definition->status),
            'is_active' => $definition->isActive,
            'source_version' => $definition->sourceVersion,
            'meta' => $definition->meta,
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

        $message = $this->messageReference(
            campaign: $campaign,
            definition: $definition,
        );

        if (! $step instanceof CampaignStep) {
            $step = CampaignStep::query()->create([
                'campaign_id' => $campaign->id,
                'step_number' => $definition->stepNumber,
                'name' => $definition->name,
                'dispatch_key' => $this->normalizeSegment($definition->dispatchKey),
                'channel' => $message['channel'],
                'purpose' => $message['purpose'],
                'scope' => $message['scope'],
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
            'channel' => $message['channel'],
            'purpose' => $message['purpose'],
            'scope' => $message['scope'],
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
            'source_config_path' => $definition->sourceConfigPath,
            'source_version' => $definition->sourceVersion,
            'meta' => $definition->meta,
        ];
    }

    /**
     * @return array{channel: string, purpose: string, scope: string}
     */
    private function messageReference(
        Campaign $campaign,
        CampaignStepPresetDefinition $definition,
    ): array {
        return [
            'channel' => $this->normalizeSegment($definition->channel ?? $campaign->channel),
            'purpose' => $this->normalizeSegment($definition->purpose ?? $campaign->purpose),
            'scope' => $this->normalizeSegment($definition->scope ?? $campaign->scope),
        ];
    }

    private function normalizeSegment(string $value): string
    {
        return str_replace('-', '_', strtolower(trim($value)));
    }
}
