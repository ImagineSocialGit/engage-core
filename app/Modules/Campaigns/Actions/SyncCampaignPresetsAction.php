<?php

namespace App\Modules\Campaigns\Actions;

use App\Modules\Campaigns\Data\CampaignPresetDefinition;
use App\Modules\Campaigns\Data\CampaignPresetSyncResult;
use App\Modules\Campaigns\Data\CampaignStepPresetDefinition;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignStep;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

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
                ? trim($value)
                : null,
            $values,
        ))));
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
                'channel' => $this->normalizeSegment($definition->channel),
                'purpose' => $this->normalizeSegment($definition->purpose),
                'scope' => $this->normalizeSegment($definition->scope),
                'status' => $definition->status,
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
            'status' => $definition->status,
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
                $this->syncStep(
                    campaign: $campaign,
                    definition: $stepDefinition,
                    result: $result,
                );
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
            ->delete();

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

        $message = $this->messageReference(
            campaign: $campaign,
            definition: $definition,
        );

        if (! $step instanceof CampaignStep) {
            $step = CampaignStep::create([
                'campaign_id' => $campaign->id,
                'step_number' => $definition->stepNumber,
                'name' => $definition->name,
                'dispatch_key' => $this->normalizeSegment($definition->dispatchKey),
                'channel' => $message['channel'],
                'purpose' => $message['purpose'],
                'scope' => $message['scope'],
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

        if ($step->is_customized || $campaign->is_customized) {
            $result->recordStepSkipped();

            return $step;
        }

        $step->forceFill([
            'name' => $definition->name,
            'dispatch_key' => $this->normalizeSegment($definition->dispatchKey),
            'channel' => $message['channel'],
            'purpose' => $message['purpose'],
            'scope' => $message['scope'],
            'is_active' => $definition->isActive,
            'criteria' => $definition->criteria,
            'source_version' => $definition->sourceVersion,
            'meta' => $definition->meta,
        ])->save();

        $result->recordStepUpdated();

        return $step;
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
