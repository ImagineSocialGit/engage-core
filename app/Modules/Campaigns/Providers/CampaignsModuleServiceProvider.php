<?php

namespace App\Modules\Campaigns\Providers;

use App\Modules\Campaigns\Console\Commands\SyncCampaignPresetsCommand;
use App\Modules\Campaigns\Listeners\ScheduleNextCampaignStepAfterScheduledMessageSent;
use App\Modules\Campaigns\Services\ContactShow\ContactCampaignsVisibilityDataProvider;
use App\Modules\Messaging\Events\ScheduledMessageSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class CampaignsModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->tag([
            ContactCampaignsVisibilityDataProvider::class,
        ], 'core.contact_show_data_providers');
    }

    public function boot(): void
    {
        Event::listen(
            ScheduledMessageSent::class,
            ScheduleNextCampaignStepAfterScheduledMessageSent::class,
        );

        if ($this->app->runningInConsole()) {
            $this->commands([
                SyncCampaignPresetsCommand::class,
            ]);
        }
    }
}