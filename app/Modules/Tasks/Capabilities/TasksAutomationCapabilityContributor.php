<?php

namespace App\Modules\Tasks\Capabilities;

use App\Support\AutomationCapabilities\Contracts\AutomationCapabilityContributor;
use App\Support\AutomationCapabilities\Data\AutomationCapabilityDefinition;

class TasksAutomationCapabilityContributor implements AutomationCapabilityContributor
{
    public function definitions(): iterable
    {
        yield new AutomationCapabilityDefinition(
            key: 'tasks.create_task',
            moduleKey: 'tasks',
            capabilityType: AutomationCapabilityDefinition::TYPE_ACTION,
            pointType: 'create_task',
            handlerKey: 'create_task',
            actionKey: 'tasks.create_task',
            name: 'Create task',
            description: 'Create a Task directly or from a DB-owned TaskTemplate.',
            requiredModules: ['tasks'],
            sourceVersion: '2026_07_phase_6c_3',
        );
    }
}
