<?php

namespace App\Modules\Campaigns\Jobs;

use App\Modules\Campaigns\Actions\ReconcileAutomaticCampaignEligibilityAction;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class ReconcileAutomaticCampaignEligibilityJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;
    public int $uniqueFor = 840;

    public function __construct()
    {
        $this->onQueue('campaigns');
    }

    public function uniqueId(): string
    {
        return 'campaigns:automatic-eligibility-reconciliation';
    }

    public function handle(
        ReconcileAutomaticCampaignEligibilityAction $reconcile,
    ): void {
        $reconcile->handle();
    }
}