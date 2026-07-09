<?php

namespace App\Modules\FlowRoutes\Capabilities;

use App\Support\AutomationCapabilities\Contracts\AutomationCapabilityContributor;
use App\Support\AutomationCapabilities\Data\AutomationCapabilityDefinition;

class FlowRoutesAutomationCapabilityContributor implements AutomationCapabilityContributor
{
    public function definitions(): iterable
    {
        yield new AutomationCapabilityDefinition(
            key: 'flow_routes.noop',
            moduleKey: 'flow_routes',
            capabilityType: AutomationCapabilityDefinition::TYPE_ACTION,
            pointType: 'noop',
            handlerKey: 'noop',
            name: 'No operation',
            description: 'Complete a route point without producing a side effect.',
            requiredModules: ['flow_routes'],
            sourceVersion: '2026_07_phase_6c_3',
        );

        yield new AutomationCapabilityDefinition(
            key: 'flow_routes.wait',
            moduleKey: 'flow_routes',
            capabilityType: AutomationCapabilityDefinition::TYPE_WAIT,
            pointType: 'wait',
            handlerKey: 'wait',
            name: 'Wait until time',
            description: 'Pause route execution until a configured time is reached.',
            requiredModules: ['flow_routes'],
            sourceVersion: '2026_07_phase_6c_3',
        );

        yield new AutomationCapabilityDefinition(
            key: 'flow_routes.event_wait',
            moduleKey: 'flow_routes',
            capabilityType: AutomationCapabilityDefinition::TYPE_WAIT,
            pointType: 'event_wait',
            handlerKey: 'event_wait',
            name: 'Wait for event',
            description: 'Pause route execution until a matching automation event is recorded.',
            requiredModules: ['flow_routes'],
            sourceVersion: '2026_07_phase_6c_3',
        );

        yield new AutomationCapabilityDefinition(
            key: 'flow_routes.condition',
            moduleKey: 'flow_routes',
            capabilityType: AutomationCapabilityDefinition::TYPE_CONDITION,
            pointType: 'condition',
            handlerKey: 'condition',
            name: 'Evaluate condition',
            description: 'Evaluate generic route data conditions.',
            requiredModules: ['flow_routes'],
            sourceVersion: '2026_07_phase_6c_3',
        );

        yield new AutomationCapabilityDefinition(
            key: 'flow_routes.branch_evaluate',
            moduleKey: 'flow_routes',
            capabilityType: AutomationCapabilityDefinition::TYPE_CONDITION,
            pointType: 'branch_evaluate',
            handlerKey: 'branch_evaluate',
            name: 'Evaluate branch',
            description: 'Evaluate route conditions and select the next branch.',
            requiredModules: ['flow_routes'],
            sourceVersion: '2026_07_phase_6c_3',
        );

        yield new AutomationCapabilityDefinition(
            key: 'flow_routes.change_status',
            moduleKey: 'flow_routes',
            capabilityType: AutomationCapabilityDefinition::TYPE_ACTION,
            pointType: 'change_status',
            handlerKey: 'change_status',
            actionKey: 'contacts.change_status',
            name: 'Change contact status',
            description: 'Move a contact to another DB-owned ContactStatus through the public status-update seam.',
            requiredModules: ['flow_routes', 'workflow'],
            sourceVersion: '2026_07_phase_6c_3',
        );
    }
}
