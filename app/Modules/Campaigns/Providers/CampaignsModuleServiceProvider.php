<?php

namespace App\Modules\Campaigns\Providers;

use App\Modules\Campaigns\Automation\CampaignsAutomationPointAuthoringContributor;
use App\Modules\Campaigns\Automation\CampaignsAutomationPointDefinitionContributor;
use App\Modules\Campaigns\Automation\CancelCampaignAutomationActionHandler;
use App\Modules\Campaigns\Automation\EnrollCampaignAutomationActionHandler;
use App\Modules\Campaigns\Capabilities\CampaignsAutomationCapabilityContributor;
use App\Modules\Campaigns\ConfigContracts\CampaignPresetConfigContractTargetProvider;
use App\Modules\Campaigns\ConfigContracts\CampaignPresetDefinitionConfigContract;
use App\Modules\Campaigns\Console\Commands\SyncCampaignPresetsCommand;
use App\Modules\Campaigns\Listeners\ScheduleNextCampaignStepAfterScheduledMessageSent;
use App\Modules\Campaigns\Services\ContactShow\ContactCampaignsVisibilityDataProvider;
use App\Modules\Campaigns\TokenContracts\CampaignTokenContextProvider;
use App\Modules\Campaigns\TokenContracts\CampaignTokenSourceProvider;
use App\Modules\Campaigns\Validation\CampaignsSetupValidationContributor;
use App\Modules\Messaging\Events\ScheduledMessageSent;
use App\Modules\Messaging\Events\ScheduledMessageSkipped;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class CampaignsModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->tag(CampaignPresetDefinitionConfigContract::class, 'config.contracts');
        $this->app->tag(CampaignPresetConfigContractTargetProvider::class, 'config.contract_target_providers');
        $this->app->tag(CampaignTokenSourceProvider::class, 'token.source_providers');
        $this->app->tag(CampaignTokenContextProvider::class, 'token.context_providers');

        $this->app->tag([
            CampaignsAutomationCapabilityContributor::class,
        ], 'automation.capability_contributors');

        $this->app->tag([
            CampaignsAutomationPointDefinitionContributor::class,
        ], 'automation.point_definition_contributors');

        $this->app->tag([
            CampaignsAutomationPointAuthoringContributor::class,
        ], 'automation.point_authoring_contributors');

        $this->app->tag([
            EnrollCampaignAutomationActionHandler::class,
            CancelCampaignAutomationActionHandler::class,
        ], 'automation.action_handlers');

        $this->app->tag([
            CampaignsSetupValidationContributor::class,
        ], 'setup.validation_contributors');

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

        Event::listen(
            ScheduledMessageSkipped::class,
            ScheduleNextCampaignStepAfterScheduledMessageSent::class,
        );

        if ($this->app->runningInConsole()) {
            $this->commands([
                SyncCampaignPresetsCommand::class,
            ]);
        }
    }
}
