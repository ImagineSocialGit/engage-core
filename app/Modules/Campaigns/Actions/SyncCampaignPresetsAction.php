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
    public function handle(?string $presetKey = null): CampaignPresetSyncResult
    {
        $presetKey ??= $this->defaultPresetKey();

        $campaignDefinitions = $this->campaignDefinitions($presetKey);

        return DB::transaction(function () use ($campaignDefinitions) {
            $result = new CampaignPresetSyncResult();

            foreach ($campaignDefinitions as $definition) {
                $campaign = $this->syncCampaign($definition, $result);

                $this->syncSteps(
                    campaign: $campaign,
                    definition: $definition,
                    result: $result,
                );
            }

            return $result;
        });
    }

    private function defaultPresetKey(): ?string
    {
        $presetKey = config('client.preset');

        if (is_string($presetKey) && trim($presetKey) !== '') {
            return trim($presetKey);
        }

        $presetKey = config('presets.default_package');

        if (is_string($presetKey) && trim($presetKey) !== '') {
            return trim($presetKey);
        }

        $presetKeys = array_keys(config('presets.packages', []));

        foreach ($presetKeys as $key) {
            if (is_string($key) && trim($key) !== '') {
                return trim($key);
            }
        }

        return null;
    }

    /**
     * @return array<int, CampaignPresetDefinition>
     */
    private function campaignDefinitions(?string $presetKey): array
    {
        if ($presetKey === null) {
            return [];
        }

        $groupKeys = config("presets.packages.{$presetKey}.groups.campaigns", []);

        if (! is_array($groupKeys) || $groupKeys === []) {
            return [];
        }

        $definitions = [];

        foreach ($this->normalizeStringList($groupKeys) as $groupKey) {
            $campaignKeys = config('presets.campaigns.groups.'.$groupKey);

            if (! is_array($campaignKeys)) {
                throw new InvalidArgumentException('Campaign preset group ['.$groupKey.'] does not exist.');
            }

            foreach ($this->normalizeStringList($campaignKeys) as $campaignKey) {
                $definitions[] = CampaignPresetDefinition::fromArray(
                    data: $this->campaignDefinition($campaignKey),
                    definitionKey: $campaignKey,
                );
            }
        }

        return $definitions;
    }

    /**
     * @return array<string, mixed>
     */
    private function campaignDefinition(string $campaignKey): array
    {
        $definition = config('presets.campaigns.definitions.'.$campaignKey);

        if (! is_array($definition)) {
            throw new InvalidArgumentException('Campaign preset definition ['.$campaignKey.'] does not exist.');
        }

        return $definition;
    }

    /**
     * @return array<int, string>
     */
    private function normalizeStringList(mixed $values): array
    {
        if (is_string($values)) {
            $values = [$values];
        }

        if (! is_array($values)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            fn (mixed $value): ?string => is_string($value) && trim($value) !== ''
                ? $this->normalizeSegment($value)
                : null,
            $values,
        ))));
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
