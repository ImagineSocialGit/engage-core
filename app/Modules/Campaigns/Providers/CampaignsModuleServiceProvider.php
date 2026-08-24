<?php

namespace App\Modules\Campaigns\Providers;

use App\Modules\Campaigns\Automation\CampaignsAutomationPointAuthoringContributor;
use App\Modules\Campaigns\Automation\CampaignsAutomationPointDefinitionContributor;
use App\Modules\Campaigns\Automation\CancelCampaignAutomationActionHandler;
use App\Modules\Campaigns\Automation\CancelCampaignFamilyAutomationActionHandler;
use App\Modules\Campaigns\Automation\EnrollCampaignAutomationActionHandler;
use App\Modules\Campaigns\Automation\PauseCampaignAutomationActionHandler;
use App\Modules\Campaigns\Automation\PauseCampaignFamilyAutomationActionHandler;
use App\Modules\Campaigns\Automation\ResumeCampaignAutomationActionHandler;
use App\Modules\Campaigns\Capabilities\CampaignsAutomationCapabilityContributor;
use App\Modules\Campaigns\ConfigContracts\CampaignPresetConfigContractTargetProvider;
use App\Modules\Campaigns\ConfigContracts\CampaignPresetDefinitionConfigContract;
use App\Modules\Campaigns\Console\Commands\DeactivateCampaignCommand;
use App\Modules\Campaigns\Console\Commands\SyncCampaignPresetsCommand;
use App\Modules\Campaigns\Jobs\ProcessDueCampaignTouchDatesJob;
use App\Modules\Campaigns\Jobs\ReconcileAutomaticCampaignEligibilityJob;
use App\Modules\Campaigns\Listeners\ReconcileCampaignEligibilityFromAutomationEvent;
use App\Modules\Campaigns\Listeners\ReconcileCampaignEligibilityFromContactFilterFactsChanged;
use App\Modules\Campaigns\Services\CampaignMessageChainExecutionContextProvider;
use App\Modules\Campaigns\Services\ContactShow\ContactCampaignsVisibilityDataProvider;
use App\Modules\Campaigns\TokenContracts\CampaignTokenContextProvider;
use App\Modules\Campaigns\TokenContracts\CampaignTokenSourceProvider;
use App\Modules\Campaigns\Validation\CampaignsSetupValidationContributor;
use App\Modules\Core\Events\ContactFilterFactsChanged;
use App\Support\AutomationEvents\Events\AutomationEventRecorded;
use Illuminate\Console\Scheduling\Schedule;
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
        $this->app->tag(CampaignMessageChainExecutionContextProvider::class, 'messaging.message_chain_execution_context_providers');
        $this->app->tag([CampaignsAutomationCapabilityContributor::class], 'automation.capability_contributors');
        $this->app->tag([CampaignsAutomationPointDefinitionContributor::class], 'automation.point_definition_contributors');
        $this->app->tag([CampaignsAutomationPointAuthoringContributor::class], 'automation.point_authoring_contributors');
        $this->app->tag([
            EnrollCampaignAutomationActionHandler::class,
            CancelCampaignAutomationActionHandler::class,
            PauseCampaignAutomationActionHandler::class,
            ResumeCampaignAutomationActionHandler::class,
            PauseCampaignFamilyAutomationActionHandler::class,
            CancelCampaignFamilyAutomationActionHandler::class,
        ], 'automation.action_handlers');
        $this->app->tag([CampaignsSetupValidationContributor::class], 'setup.validation_contributors');
        $this->app->tag([ContactCampaignsVisibilityDataProvider::class], 'core.contact_show_data_providers');
    }

    public function boot(): void
    {
        Event::listen(
            ContactFilterFactsChanged::class,
            ReconcileCampaignEligibilityFromContactFilterFactsChanged::class,
        );

        Event::listen(
            AutomationEventRecorded::class,
            ReconcileCampaignEligibilityFromAutomationEvent::class,
        );

        $this->callAfterResolving(
            Schedule::class,
            function (Schedule $schedule): void {
                $schedule
                    ->job(new ProcessDueCampaignTouchDatesJob())
                    ->everyMinute()
                    ->withoutOverlapping();

                $schedule
                    ->job(new ReconcileAutomaticCampaignEligibilityJob())
                    ->everyFifteenMinutes()
                    ->withoutOverlapping();
            },
        );

        if ($this->app->runningInConsole()) {
            $this->commands([
                DeactivateCampaignCommand::class,
                SyncCampaignPresetsCommand::class,
            ]);
        }
    }
}