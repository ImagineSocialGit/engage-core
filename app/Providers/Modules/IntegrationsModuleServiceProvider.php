<?php

namespace App\Providers\Modules;

use App\Support\AutomationEvents\Events\AutomationEventRecorded;
use App\Modules\Messaging\Contracts\MessageTemplateDeletionReferenceContributor;
use App\Modules\Messaging\Contracts\ReusableMessageTemplateAuthoringOptionContributor;
use App\Support\ModuleIntegrations\Messaging\Broadcasts\BroadcastMessageTemplateDeletionReferenceContributor;
use App\Support\ModuleIntegrations\Messaging\Campaigns\CampaignTouchMessageTemplateDeletionReferenceContributor;
use App\Support\ModuleIntegrations\Messaging\FlowRoutes\FlowRouteMessageTemplateDeletionReferenceContributor;
use App\Support\ModuleIntegrations\Messaging\FlowRoutes\FlowRouteReusableMessageTemplateAuthoringContributor;
use App\Support\ModuleIntegrations\Scheduling\Automation\AppointmentHostNotificationAutomationCapabilityContributor;
use App\Support\ModuleIntegrations\Messaging\Tasks\ScheduledMessageTaskLinkPresenter;
use App\Support\ModuleIntegrations\Scheduling\Automation\AppointmentHostNotificationAutomationPointAuthoringContributor;
use App\Support\ModuleIntegrations\Scheduling\Automation\AppointmentHostNotificationAutomationPointDefinitionContributor;
use App\Support\ModuleIntegrations\Scheduling\Automation\AppointmentTaskAutomationCapabilityContributor;
use App\Support\ModuleIntegrations\Scheduling\Automation\AppointmentTaskAutomationPointAuthoringContributor;
use App\Support\ModuleIntegrations\Scheduling\Automation\AppointmentTaskAutomationPointDefinitionContributor;
use App\Support\ModuleIntegrations\Scheduling\Automation\CreateAppointmentTaskAutomationActionHandler;
use App\Support\ModuleIntegrations\Scheduling\Automation\NotifyAppointmentHostAutomationActionHandler;
use App\Support\ModuleIntegrations\Scheduling\Automation\ReconcileAppointmentHostNotifications;
use App\Support\ModuleIntegrations\Scheduling\Automation\ReconcileAppointmentTasks;
use App\Support\ModuleIntegrations\Scheduling\Core\ContactTagBookingOfferRewardActionHandler;
use App\Support\ModuleIntegrations\Scheduling\Simple\ApplySimpleAppointmentAfterBookingActions;
use App\Support\ModuleIntegrations\Scheduling\Webinars\WebinarRegistrantBookingEligibilityProvider;
use App\Modules\Reporting\Actions\ProcessDueScheduledReportsAction;
use App\Modules\Reporting\Contracts\ScheduledReportDeliveryDriver;
use App\Modules\Reporting\Contracts\ScheduledReportProvider;
use App\Modules\Reporting\Contracts\ScheduledReportRecipientOptionProvider;
use App\Support\ModuleIntegrations\Reporting\DailyFollowUp\DailyFollowUpScheduledReportProvider;
use App\Support\ModuleIntegrations\Reporting\InternalNotifications\InternalNotificationScheduledReportDeliveryDriver;
use App\Support\ModuleIntegrations\Reporting\InternalNotifications\TeamMemberScheduledReportRecipientProvider;
use App\Modules\Scheduling\Services\BookingEligibilityProviderRegistry;
use App\Modules\Scheduling\Services\BookingOfferRewardActionHandlerRegistry;
use App\Support\Modules\ModuleManager;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class IntegrationsModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $enabled = $this->app->make(ModuleManager::class)->enabledKeysWithDependencies();

        $this->app->tag([
            BroadcastMessageTemplateDeletionReferenceContributor::class,
            CampaignTouchMessageTemplateDeletionReferenceContributor::class,
            FlowRouteMessageTemplateDeletionReferenceContributor::class,
        ], MessageTemplateDeletionReferenceContributor::TAG);

        if ($this->has($enabled, ['flow_routes', 'messaging'])) {
            $this->app->tag(
                FlowRouteReusableMessageTemplateAuthoringContributor::class,
                ReusableMessageTemplateAuthoringOptionContributor::TAG,
            );
        }

        if ($this->has($enabled, ['messaging', 'tasks'])) {
            $this->app->tag(
                ScheduledMessageTaskLinkPresenter::class,
                'tasks.link_presenters',
            );
        }

        if ($this->has($enabled, ['scheduling', 'core'])) {
            $this->app->tag(
                ContactTagBookingOfferRewardActionHandler::class,
                BookingOfferRewardActionHandlerRegistry::TAG,
            );
        }

        if ($this->has($enabled, ['scheduling', 'webinars'])) {
            $this->app->tag(
                WebinarRegistrantBookingEligibilityProvider::class,
                BookingEligibilityProviderRegistry::TAG,
            );
        }

        if ($this->has($enabled, ['flow_routes', 'scheduling', 'tasks'])) {
            $this->app->tag(AppointmentTaskAutomationCapabilityContributor::class, 'automation.capability_contributors');
            $this->app->tag(AppointmentTaskAutomationPointDefinitionContributor::class, 'automation.point_definition_contributors');
            $this->app->tag(AppointmentTaskAutomationPointAuthoringContributor::class, 'automation.point_authoring_contributors');
            $this->app->tag(CreateAppointmentTaskAutomationActionHandler::class, 'automation.action_handlers');
        }

        if ($this->has($enabled, ['flow_routes', 'scheduling', 'internal_notifications', 'messaging'])) {
            $this->app->tag(AppointmentHostNotificationAutomationCapabilityContributor::class, 'automation.capability_contributors');
            $this->app->tag(AppointmentHostNotificationAutomationPointDefinitionContributor::class, 'automation.point_definition_contributors');
            $this->app->tag(AppointmentHostNotificationAutomationPointAuthoringContributor::class, 'automation.point_authoring_contributors');
            $this->app->tag(NotifyAppointmentHostAutomationActionHandler::class, 'automation.action_handlers');
        }

        if ($this->has($enabled, ['reporting', 'internal_notifications', 'messaging'])) {
            $this->app->tag(
                DailyFollowUpScheduledReportProvider::class,
                ScheduledReportProvider::TAG,
            );
            $this->app->tag(
                TeamMemberScheduledReportRecipientProvider::class,
                ScheduledReportRecipientOptionProvider::TAG,
            );
            $this->app->tag(
                InternalNotificationScheduledReportDeliveryDriver::class,
                ScheduledReportDeliveryDriver::TAG,
            );
        }
    }

    public function boot(): void
    {
        $enabled = $this->app->make(ModuleManager::class)->enabledKeysWithDependencies();

        if ($this->has($enabled, ['scheduling'])
            && ! in_array('flow_routes', $enabled, true)
        ) {
            Event::listen(
                AutomationEventRecorded::class,
                ApplySimpleAppointmentAfterBookingActions::class,
            );
        }

        if ($this->has($enabled, ['scheduling', 'tasks'])) {
            Event::listen(AutomationEventRecorded::class, ReconcileAppointmentTasks::class);
        }

        if ($this->has($enabled, ['scheduling', 'internal_notifications', 'messaging'])) {
            Event::listen(AutomationEventRecorded::class, ReconcileAppointmentHostNotifications::class);
        }

        if ($this->has($enabled, ['reporting', 'internal_notifications', 'messaging'])) {
            $this->callAfterResolving(
                Schedule::class,
                function (Schedule $schedule): void {
                    $schedule
                        ->call(fn (): int => app(
                            ProcessDueScheduledReportsAction::class,
                        )->handle())
                        ->name('reporting-scheduled-reports')
                        ->everyMinute()
                        ->withoutOverlapping(10);
                },
            );
        }
    }

    private function has(array $enabled, array $required): bool
    {
        return array_diff($required, $enabled) === [];
    }
}