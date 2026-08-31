<?php

namespace App\Modules\InboundMessaging\Capabilities;

use App\Support\AutomationCapabilities\Contracts\AutomationCapabilityContributor;
use App\Support\AutomationCapabilities\Data\AutomationCapabilityDefinition;

class InboundMessagingAutomationCapabilityContributor implements AutomationCapabilityContributor
{
    public function definitions(): iterable
    {
        yield new AutomationCapabilityDefinition(
            key: 'inbound_messaging.automatic_message',
            moduleKey: 'inbound_messaging',
            capabilityType: AutomationCapabilityDefinition::TYPE_ACTION,
            pointType: 'automatic_message',
            handlerKey: 'automatic_message',
            actionKey: 'inbound_messaging.automatic_message',
            name: 'Legacy automatic message',
            description: 'Compatibility runtime for Routes created before reply handling moved into the Message point.',
            requiredModules: ['inbound_messaging', 'messaging'],
            isActive: false,
            sourceVersion: '2026_08_inbound_automatic_message_legacy_v3',
        );
    }
}