<?php

namespace App\Providers\Modules;

use App\Events\Messaging\ScheduledMessageSent;
use App\Listeners\Campaigns\ScheduleNextCampaignStepAfterScheduledMessageSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class CampaignsModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Event::listen(
            ScheduledMessageSent::class,
            ScheduleNextCampaignStepAfterScheduledMessageSent::class,
        );
    }
}