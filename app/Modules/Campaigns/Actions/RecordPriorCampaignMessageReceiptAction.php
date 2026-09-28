<?php

namespace App\Modules\Campaigns\Actions;

use App\Models\User;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignPriorMessageReceipt;
use App\Modules\Core\Models\Contact;
use App\Modules\Core\Models\ContactImportOccurrence;
use App\Modules\Messaging\Models\MessageChain;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

final class RecordPriorCampaignMessageReceiptAction
{
    public function handle(
        Contact $contact,
        Campaign $campaign,
        string $messageStepKey,
        string $evidenceSource,
        ?Model $source = null,
        ?User $attestedBy = null,
        ?DateTimeInterface $receivedAt = null,
    ): CampaignPriorMessageReceipt {
        $messageStepKey = trim($messageStepKey);

        if (! in_array($evidenceSource, [
            CampaignPriorMessageReceipt::SOURCE_CONTACT_IMPORT,
            CampaignPriorMessageReceipt::SOURCE_OPERATOR_ATTESTATION,
        ], true)) {
            throw new InvalidArgumentException('Unsupported prior Campaign message evidence source.');
        }

        if ($source !== null && ! $source->exists) {
            throw new InvalidArgumentException('Prior Campaign message evidence requires a persisted source.');
        }

        if ($evidenceSource === CampaignPriorMessageReceipt::SOURCE_CONTACT_IMPORT
            && (! $source instanceof ContactImportOccurrence
                || (int) $source->contact_id !== (int) $contact->getKey())) {
            throw new InvalidArgumentException(
                'Imported prior Campaign message evidence requires an occurrence for this Contact.',
            );
        }

        if ($evidenceSource === CampaignPriorMessageReceipt::SOURCE_OPERATOR_ATTESTATION
            && (! $attestedBy instanceof User || ! $attestedBy->exists)) {
            throw new InvalidArgumentException(
                'Manual prior Campaign message evidence requires a persisted operator.',
            );
        }

        $chain = $campaign->messageChain()->first();

        if (! $chain instanceof MessageChain
            || $messageStepKey === ''
            || ! $chain->requireCurrentVersion()->steps()
                ->where('key', $messageStepKey)->exists()) {
            throw new InvalidArgumentException(
                'The selected message is not in the current Campaign schedule.',
            );
        }

        return CampaignPriorMessageReceipt::query()->firstOrCreate(
            [
                'contact_id' => $contact->getKey(),
                'campaign_id' => $campaign->getKey(),
                'message_step_key' => $messageStepKey,
            ],
            [
                'evidence_source' => $evidenceSource,
                'source_type' => $source?->getMorphClass(),
                'source_id' => $source?->getKey(),
                'attested_by' => $attestedBy?->getKey(),
                'received_at' => $receivedAt,
            ],
        );
    }

    public function recorded(Contact $contact, Campaign $campaign, string $messageStepKey): bool
    {
        return CampaignPriorMessageReceipt::query()
            ->where('contact_id', $contact->getKey())
            ->where('campaign_id', $campaign->getKey())
            ->where('message_step_key', trim($messageStepKey))
            ->exists();
    }
}