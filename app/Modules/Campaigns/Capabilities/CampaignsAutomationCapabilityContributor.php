<?php

namespace App\Modules\Campaigns\Capabilities;

use App\Support\AutomationCapabilities\Contracts\AutomationCapabilityContributor;
use App\Support\AutomationCapabilities\Data\AutomationCapabilityDefinition;

class CampaignsAutomationCapabilityContributor implements AutomationCapabilityContributor
{
    public function definitions(): iterable
    {
        yield new AutomationCapabilityDefinition(
            key: 'campaigns.enroll_contact',
            moduleKey: 'campaigns',
            capabilityType: AutomationCapabilityDefinition::TYPE_ACTION,
            pointType: 'enroll_campaign',
            handlerKey: 'enroll_campaign',
            actionKey: 'campaigns.enroll_contact',
            name: 'Enroll contact in campaign',
            description: 'Enroll a contact in a DB-owned Campaign.',
            requiredModules: ['campaigns'],
            sourceVersion: '2026_07_phase_6c_3',
        );

        yield new AutomationCapabilityDefinition(
            key: 'campaigns.cancel_enrollment',
            moduleKey: 'campaigns',
            capabilityType: AutomationCapabilityDefinition::TYPE_ACTION,
            pointType: 'cancel_campaign',
            handlerKey: 'cancel_campaign',
            actionKey: 'campaigns.cancel_enrollment',
            name: 'Cancel campaign enrollment',
            description: 'Cancel a contact campaign enrollment through the public Campaigns action seam.',
            requiredModules: ['campaigns'],
            sourceVersion: '2026_07_phase_6c_3',
        );

        yield new AutomationCapabilityDefinition(
            key: 'campaigns.pause_enrollment',
            moduleKey: 'campaigns',
            capabilityType: AutomationCapabilityDefinition::TYPE_ACTION,
            pointType: 'pause_campaign',
            handlerKey: 'pause_campaign',
            actionKey: 'campaigns.pause_enrollment',
            name: 'Pause campaign enrollment',
            description: 'Pause an existing Campaign enrollment and optionally skip its pending messages.',
            requiredModules: ['campaigns'],
            sourceVersion: '2026_08_reply_outcomes_c2',
        );

        yield new AutomationCapabilityDefinition(
            key: 'campaigns.resume_enrollment',
            moduleKey: 'campaigns',
            capabilityType: AutomationCapabilityDefinition::TYPE_ACTION,
            pointType: 'resume_campaign',
            handlerKey: 'resume_campaign',
            actionKey: 'campaigns.resume_enrollment',
            name: 'Resume campaign enrollment',
            description: 'Resume an existing paused Campaign enrollment through the public Campaigns lifecycle seam.',
            requiredModules: ['campaigns'],
            sourceVersion: '2026_08_reply_outcomes_c2',
        );
    }
}