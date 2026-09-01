<?php

namespace App\Support\ModuleIntegrations\Scheduling\Automation;

use App\Support\AutomationCapabilities\Contracts\AutomationCapabilityContributor;
use App\Support\AutomationCapabilities\Data\AutomationCapabilityDefinition;

class AppointmentHostNotificationAutomationCapabilityContributor implements AutomationCapabilityContributor
{
    public function definitions(): iterable
    {
        yield new AutomationCapabilityDefinition(
            key: 'scheduling.notify_appointment_host',
            moduleKey: 'scheduling',
            capabilityType: AutomationCapabilityDefinition::TYPE_ACTION,
            pointType: 'notify_appointment_host',
            handlerKey: 'notify_appointment_host',
            actionKey: 'scheduling.notify_appointment_host',
            name: 'Notify appointment host',
            description: 'Send an internal reminder to the Appointment host relative to the Appointment start.',
            requiredModules: ['flow_routes', 'scheduling', 'internal_notifications', 'messaging'],
            sourceVersion: '2026_09_appointment_relative_automation',
        );
    }
}