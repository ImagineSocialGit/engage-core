<?php

namespace App\Modules\Campaigns\Actions;

use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Services\CampaignAllocationSettingsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class UpdateCampaignAllocationSettingsAction
{
    public function __construct(
        private readonly CampaignAllocationSettingsService $settings,
    ) {}

    /** @param array<string, mixed>|null $allocationSettings */
    public function handle(
        Campaign $campaign,
        string $executionStrategy,
        ?array $allocationSettings = null,
    ): Campaign {
        $executionStrategy = strtolower(trim($executionStrategy));

        if (! in_array($executionStrategy, Campaign::EXECUTION_STRATEGIES, true)) {
            throw ValidationException::withMessages([
                'execution_strategy' => 'Choose a supported Campaign execution strategy.',
            ]);
        }

        return DB::transaction(function () use (
            $campaign,
            $executionStrategy,
            $allocationSettings,
        ): Campaign {
            $locked = Campaign::query()
                ->whereKey($campaign->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->execution_strategy !== $executionStrategy) {
                throw ValidationException::withMessages([
                    'execution_strategy' => 'Campaign type is fixed after creation. Create a new Campaign to use a different type.',
                ]);
            }

            if (! $locked->usesRecurringAllocation()) {
                return $locked;
            }

            $locked->forceFill([
                'allocation_settings' => $this->settings->forPersistence($allocationSettings ?? []),
                'is_customized' => true,
                'customized_at' => now(),
            ])->save();

            return $locked->refresh();
        }, 3);
    }

}