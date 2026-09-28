<?php

namespace App\Modules\Campaigns\Jobs;

use App\Modules\Campaigns\Actions\CheckCampaignAudienceCompletionAction;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class CheckCampaignAudienceCompletionJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;
    public int $uniqueFor = 840;

    public function __construct()
    {
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return 'campaigns:audience-completion';
    }

    public function handle(CheckCampaignAudienceCompletionAction $check): void
    {
        $check->handle();
    }
}