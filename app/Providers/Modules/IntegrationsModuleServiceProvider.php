<?php

namespace App\Providers\Modules;

use App\Support\AutomationEvents\Events\AutomationEventRecorded;
use App\Modules\Messaging\Contracts\ReusableMessageTemplateAuthoringOptionContributor;
use App\Support\ModuleIntegrations\Messaging\FlowRoutes\FlowRouteReusableMessageTemplateAuthoringContributor;
use App\Support\ModuleIntegrations\Scheduling\Automation\AppointmentHostNotificationAutomationCapabilityContributor;
use App\Support\ModuleIntegrations\Scheduling\Automation\AppointmentHostNotificationAutomationPointAuthoringContributor;
use App\Support\ModuleIntegrations\Scheduling\Automation\AppointmentHostNotificationAutomationPointDefinitionContributor;
use App\Support\ModuleIntegrations\Scheduling\Automation\AppointmentTaskAutomationCapabilityContributor;
use App\Support\ModuleIntegrations\Scheduling\Automation\AppointmentTaskAutomationPointAuthoringContributor;
use App\Support\ModuleIntegrations\Scheduling\Automation\AppointmentTaskAutomationPointDefinitionContributor;
use App\Support\ModuleIntegrations\Scheduling\Automation\CreateAppointmentTaskAutomationActionHandler;
use App\Support\ModuleIntegrations\Scheduling\Automation\NotifyAppointmentHostAutomationActionHandler;
use App\Support\ModuleIntegrations\Scheduling\Automation\ReconcileAppointmentHostNotifications;
use App\Support\ModuleIntegrations\Scheduling\Automation\ReconcileAppointmentTasks;
use App\Support\ModuleIntegrations\Scheduling\Simple\ApplySimpleAppointmentAfterBookingActions;
use App\Support\Modules\ModuleManager;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class IntegrationsModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $enabled = $this->app->make(ModuleManager::class)->enabledKeysWithDependencies();

        if ($this->has($enabled, ['flow_routes', 'messaging'])) {
            $this->app->tag(
                FlowRouteReusableMessageTemplateAuthoringContributor::class,
                ReusableMessageTemplateAuthoringOptionContributor::TAG,
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
    }

    private function has(array $enabled, array $required): bool
    {
        return array_diff($required, $enabled) === [];
    }
}