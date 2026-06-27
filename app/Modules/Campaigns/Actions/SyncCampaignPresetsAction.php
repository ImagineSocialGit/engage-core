<?php

namespace App\Modules\Campaigns\Actions;

use App\Modules\Campaigns\Data\CampaignPresetDefinition;
use App\Modules\Campaigns\Data\CampaignPresetSyncResult;
use App\Modules\Campaigns\Data\CampaignStepPresetDefinition;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignStep;
use Illuminate\Support\Facades\DB;

class SyncCampaignPresetsAction
{
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
        $presetKey = config('presets.default');

        return is_string($presetKey) && trim($presetKey) !== ''
            ? trim($presetKey)
            : null;
    }

    /**
     * @return array<int, CampaignPresetDefinition>
     */
    private function campaignDefinitions(?string $presetKey): array
    {
        if ($presetKey === null) {
            return [];
        }

        $campaigns = config('presets.presets.'.$presetKey.'.campaigns', []);

        if (! is_array($campaigns)) {
            return [];
        }

        $definitions = [];

        foreach ($campaigns as $campaign) {
            if (! is_array($campaign)) {
                continue;
            }

            $definitions[] = CampaignPresetDefinition::fromArray(
                data: $campaign,
                presetKey: $presetKey,
            );
        }

        return $definitions;
    }

    private function syncCampaign(
        CampaignPresetDefinition $definition,
        CampaignPresetSyncResult $result,
    ): Campaign {
        $campaign = Campaign::query()
            ->where('key', $definition->key)
            ->first();

        if (! $campaign instanceof Campaign) {
            $campaign = Campaign::create([
                'key' => $definition->key,
                'name' => $definition->name,
                'description' => $definition->description,
                'channel' => $definition->channel,
                'purpose' => $definition->purpose,
                'scope' => $definition->scope,
                'status' => $definition->status,
                'is_active' => $definition->isActive,
                'preset_key' => $definition->presetKey,
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
            'channel' => $definition->channel,
            'purpose' => $definition->purpose,
            'scope' => $definition->scope,
            'status' => $definition->status,
            'is_active' => $definition->isActive,
            'preset_key' => $definition->presetKey,
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
        foreach ($definition->steps as $stepDefinition) {
            $this->syncStep(
                campaign: $campaign,
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
            $step = CampaignStep::create([
                'campaign_id' => $campaign->id,
                'step_number' => $definition->stepNumber,
                'name' => $definition->name,
                'dispatch_key' => $definition->dispatchKey,
                'is_active' => $definition->isActive,
                'criteria' => $definition->criteria,
                'payload' => $definition->payload,
                'meta' => $definition->meta,
            ]);

            $result->recordStepCreated();

            return $step;
        }

        if ($campaign->is_customized) {
            $result->recordStepSkipped();

            return $step;
        }

        $step->forceFill([
            'name' => $definition->name,
            'dispatch_key' => $definition->dispatchKey,
            'is_active' => $definition->isActive,
            'criteria' => $definition->criteria,
            'payload' => $definition->payload,
            'meta' => $definition->meta,
        ])->save();

        $result->recordStepUpdated();

        return $step;
    }
}