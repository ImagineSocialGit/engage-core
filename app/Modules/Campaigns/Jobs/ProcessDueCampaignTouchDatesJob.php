<?php

namespace App\Modules\Campaigns\Jobs;

use App\Modules\Campaigns\Actions\ProcessDueCampaignTouchDatesAction;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessDueCampaignTouchDatesJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $uniqueFor = 55;

    public function uniqueId(): string
    {
        return 'campaigns:due-annual-touch-dates';
    }

    public function handle(): void
    {
        app(ProcessDueCampaignTouchDatesAction::class)->handle();
    }
}