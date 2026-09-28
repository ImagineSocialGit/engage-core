<?php

namespace App\Modules\Campaigns\Messaging;

use App\Modules\Campaigns\Actions\RecordPriorCampaignMessageReceiptAction;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignEnrollment;
use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Contracts\MessageRecipientGate;
use App\Modules\Messaging\Models\MessageChainEnrollment;
use App\Modules\Messaging\Models\MessageChainStepVariant;
use App\Modules\Messaging\Models\ScheduledMessage;
use Illuminate\Database\Eloquent\Model;

final class CampaignPriorReceiptRecipientGate implements MessageRecipientGate
{
    public function __construct(
        private readonly RecordPriorCampaignMessageReceiptAction $receipts,
    ) {}

    public function supports(Model $recipient): bool
    {
        return $recipient instanceof Contact;
    }

    public function allows(
        Model $recipient,
        string $channel,
        ?string $type = null,
        array $context = [],
    ): bool {
        return $this->denialReason($recipient, $channel, $type, $context) === null;
    }

    public function denialReason(
        Model $recipient,
        string $channel,
        ?string $type = null,
        array $context = [],
    ): ?string {
        $message = $context['scheduled_message'] ?? null;

        if (! $recipient instanceof Contact || ! $message instanceof ScheduledMessage) {
            return null;
        }

        $enrollment = $message->messageChainEnrollment;
        $variant = $message->messageChainStepVariant;

        if (! $enrollment instanceof MessageChainEnrollment
            || $enrollment->surface !== 'campaigns'
            || ! $variant instanceof MessageChainStepVariant) {
            return null;
        }

        $campaignEnrollment = $enrollment->context;
        $campaign = $enrollment->origin;
        $step = $variant->messageChainStep;

        if (! $campaignEnrollment instanceof CampaignEnrollment
            || ! $campaign instanceof Campaign
            || $step === null
            || (int) $campaignEnrollment->contact_id !== (int) $recipient->getKey()
            || (int) $step->message_chain_version_id !== (int) $enrollment->message_chain_version_id
            || (int) $campaignEnrollment->campaign_id !== (int) $campaign->getKey()) {
            return null;
        }

        return $this->receipts->recorded($recipient, $campaign, (string) $step->key)
            ? 'Previously received outside this system.'
            : null;
    }
}