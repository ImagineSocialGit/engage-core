<?php

namespace App\Modules\Messaging\Capabilities;

use App\Support\AutomationCapabilities\Contracts\AutomationCapabilityContributor;
use App\Support\AutomationCapabilities\Data\AutomationCapabilityDefinition;

class MessagingAutomationCapabilityContributor implements AutomationCapabilityContributor
{
    public function definitions(): iterable
    {
        yield new AutomationCapabilityDefinition(
            key: 'messaging.send_message',
            moduleKey: 'messaging',
            capabilityType: AutomationCapabilityDefinition::TYPE_ACTION,
            pointType: 'send_message',
            handlerKey: 'send_message',
            actionKey: 'messaging.dispatch_message',
            name: 'Send message',
            description: 'Dispatch a message through Messaging using a reusable message definition.',
            requiredModules: ['messaging'],
            sourceVersion: '2026_07_phase_6c_3',
        );
    }
}
