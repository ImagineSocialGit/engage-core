<?php

namespace App\Modules\Relationships\Capabilities;

use App\Support\AutomationCapabilities\Contracts\AutomationCapabilityContributor;
use App\Support\AutomationCapabilities\Data\AutomationCapabilityDefinition;

class RelationshipsAutomationCapabilityContributor implements AutomationCapabilityContributor
{
    public function definitions(): iterable
    {
        yield new AutomationCapabilityDefinition(
            key: 'relationships.change_stage',
            moduleKey: 'relationships',
            capabilityType: AutomationCapabilityDefinition::TYPE_ACTION,
            pointType: 'change_relationship_stage',
            handlerKey: 'change_relationship_stage',
            actionKey: 'relationships.change_stage',
            name: 'Change relationship stage',
            description: 'Move an existing active Contact relationship to another configured active stage.',
            requiredModules: ['relationships'],
            sourceVersion: '2026_08_reply_outcomes_c2',
        );
    }
}