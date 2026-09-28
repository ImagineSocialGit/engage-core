<?php

namespace App\Modules\Campaigns\Messaging;

use App\Modules\Campaigns\Actions\RecordPriorCampaignMessageReceiptAction;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignEnrollment;
use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Contracts\MessageChainStepBypass;
use App\Modules\Messaging\Models\MessageChainEnrollment;
use App\Modules\Messaging\Models\MessageChainStep;

final class CampaignPriorReceiptStepBypass implements MessageChainStepBypass
{
    public function __construct(
        private readonly RecordPriorCampaignMessageReceiptAction $receipts,
    ) {}

    public function reason(MessageChainEnrollment $enrollment, MessageChainStep $step): ?string
    {
        if ($enrollment->surface !== 'campaigns'
            || (int) $step->message_chain_version_id !== (int) $enrollment->message_chain_version_id
            || ! $enrollment->recipient instanceof Contact
            || ! $enrollment->context instanceof CampaignEnrollment
            || ! $enrollment->origin instanceof Campaign
            || (int) $enrollment->context->contact_id !== (int) $enrollment->recipient->getKey()
            || (int) $enrollment->context->campaign_id !== (int) $enrollment->origin->getKey()) {
            return null;
        }

        return $this->receipts->recorded(
            $enrollment->recipient,
            $enrollment->origin,
            (string) $step->key,
        ) ? 'previously_received' : null;
    }
}