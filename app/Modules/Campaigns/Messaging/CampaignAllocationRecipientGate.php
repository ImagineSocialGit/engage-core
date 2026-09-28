<?php

namespace App\Modules\Campaigns\Messaging;

use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignAllocationAssignment;
use App\Modules\Campaigns\Models\CampaignAllocationEnrollment;
use App\Modules\Campaigns\Models\CampaignAllocationMessageExclusion;
use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Contracts\MessageRecipientGate;
use App\Modules\Messaging\Models\ScheduledMessage;
use Illuminate\Database\Eloquent\Model;

final class CampaignAllocationRecipientGate implements MessageRecipientGate
{
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

        $assignment = $message->context;

        if (! $assignment instanceof CampaignAllocationAssignment) {
            return null;
        }

        if ((int) $assignment->contact_id !== (int) $recipient->getKey()) {
            return 'Campaign allocation assignment does not match this recipient.';
        }

        $campaign = $assignment->campaign;

        if (! $campaign instanceof Campaign
            || ! $campaign->isActive()
            || ! $campaign->usesRecurringAllocation()
        ) {
            return 'Campaign allocation is no longer active.';
        }

        $enrollment = $assignment->enrollment;

        if (! $enrollment instanceof CampaignAllocationEnrollment
            || ! $enrollment->isActive()
        ) {
            return 'Campaign allocation enrollment is no longer active.';
        }

        $excluded = CampaignAllocationMessageExclusion::query()
            ->where('campaign_id', $assignment->campaign_id)
            ->where('contact_id', $recipient->getKey())
            ->where(
                'message_step_key',
                (string) $assignment->message_step_key,
            )
            ->exists();

        return $excluded
            ? 'Excluded from this Campaign allocation message.'
            : null;
    }
}