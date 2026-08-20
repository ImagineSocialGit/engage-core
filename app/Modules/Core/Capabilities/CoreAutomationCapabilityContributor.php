<?php

namespace App\Modules\Core\Capabilities;

use App\Support\AutomationCapabilities\Contracts\AutomationCapabilityContributor;
use App\Support\AutomationCapabilities\Data\AutomationCapabilityDefinition;

class CoreAutomationCapabilityContributor implements AutomationCapabilityContributor
{
    public function definitions(): iterable
    {
        yield new AutomationCapabilityDefinition(
            key: 'core.add_contact_tag',
            moduleKey: 'core',
            capabilityType: AutomationCapabilityDefinition::TYPE_ACTION,
            pointType: 'add_contact_tag',
            handlerKey: 'add_contact_tag',
            actionKey: 'core.add_contact_tag',
            name: 'Add contact tag',
            description: 'Add a tag to the current Contact.',
            requiredModules: ['core'],
            sourceVersion: '2026_08_contact_tag_automation',
        );

        yield new AutomationCapabilityDefinition(
            key: 'core.remove_contact_tag',
            moduleKey: 'core',
            capabilityType: AutomationCapabilityDefinition::TYPE_ACTION,
            pointType: 'remove_contact_tag',
            handlerKey: 'remove_contact_tag',
            actionKey: 'core.remove_contact_tag',
            name: 'Remove contact tag',
            description: 'Remove a tag from the current Contact.',
            requiredModules: ['core'],
            sourceVersion: '2026_08_contact_tag_automation',
        );
    }
}