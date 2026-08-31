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
            name: 'Message',
            description: 'Start a conversation or reply automatically using a reusable message.',
            requiredModules: ['messaging'],
            sourceVersion: '2026_08_unified_message_authoring_v1',
        );
    }
}