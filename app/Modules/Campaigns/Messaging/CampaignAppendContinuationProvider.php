<?php

namespace App\Modules\Campaigns\Messaging;

use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignEnrollment;
use App\Modules\Campaigns\Models\CampaignMessageChainAppend;
use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Contracts\MessageChainContinuationProvider;
use App\Modules\Messaging\Models\MessageChainEnrollment;
use App\Modules\Messaging\Models\MessageChainStep;
use App\Modules\Messaging\Models\MessageChainVersion;

final class CampaignAppendContinuationProvider implements MessageChainContinuationProvider
{
    public function nextStep(
        MessageChainEnrollment $enrollment,
        MessageChainStep $lastStep,
    ): ?MessageChainStep {
        $enrollment->loadMissing(['recipient', 'context', 'origin', 'messageChainVersion']);
        $contact = $enrollment->recipient;
        $wrapper = $enrollment->context;
        $campaign = $enrollment->origin;
        $version = $enrollment->messageChainVersion;

        if ($enrollment->surface !== 'campaigns'
            || ! $contact instanceof Contact
            || ! $wrapper instanceof CampaignEnrollment
            || ! $campaign instanceof Campaign
            || ! $version instanceof MessageChainVersion
            || ! $campaign->isActive()
            || (int) $wrapper->getKey() !== (int) $enrollment->context_id
            || (int) $wrapper->message_chain_enrollment_id !== (int) $enrollment->getKey()
            || (int) $wrapper->contact_id !== (int) $contact->getKey()
            || (int) $wrapper->campaign_id !== (int) $campaign->getKey()
            || (int) $campaign->message_chain_id !== (int) $version->message_chain_id
            || (int) $lastStep->message_chain_version_id !== (int) $version->getKey()) {
            return null;
        }

        $append = CampaignMessageChainAppend::query()
            ->where('campaign_id', $campaign->getKey())
            ->where('from_message_chain_version_id', $version->getKey())
            ->first();

        if (! $append instanceof CampaignMessageChainAppend) {
            return null;
        }

        $target = MessageChainVersion::query()
            ->whereKey($append->to_message_chain_version_id)
            ->where('message_chain_id', $version->message_chain_id)
            ->whereNotNull('published_at')
            ->first();

        if (! $target instanceof MessageChainVersion) {
            return null;
        }

        return $target->steps()
            ->where('key', $append->appended_step_key)
            ->where('is_active', true)
            ->first();
    }
}