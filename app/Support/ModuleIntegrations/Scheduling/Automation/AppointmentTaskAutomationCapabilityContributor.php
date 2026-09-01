<?php

namespace App\Support\ModuleIntegrations\Scheduling\Automation;

use App\Support\AutomationCapabilities\Contracts\AutomationCapabilityContributor;
use App\Support\AutomationCapabilities\Data\AutomationCapabilityDefinition;

class AppointmentTaskAutomationCapabilityContributor implements AutomationCapabilityContributor
{
    public function definitions(): iterable
    {
        yield new AutomationCapabilityDefinition(
            key: 'scheduling.create_appointment_task',
            moduleKey: 'scheduling',
            capabilityType: AutomationCapabilityDefinition::TYPE_ACTION,
            pointType: 'create_appointment_task',
            handlerKey: 'create_appointment_task',
            actionKey: 'scheduling.create_appointment_task',
            name: 'Create appointment task',
            description: 'Create a Task due relative to an Appointment and optionally assign it to the Appointment host.',
            requiredModules: ['flow_routes', 'scheduling', 'tasks'],
            sourceVersion: '2026_09_appointment_relative_automation',
        );
    }
}