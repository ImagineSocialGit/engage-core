<?php

namespace App\Modules\Campaigns\Jobs;

use App\Modules\Campaigns\Actions\ApplyAutomaticCampaignEligibilityAction;
use App\Modules\Campaigns\Actions\EnrollContactInCampaignAllocationAction;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Core\Models\ContactImportBatch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class ReconcileImportedAllocationCampaignJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;
    public int $timeout = 120;

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [5, 15, 30, 60];
    }

    public function __construct(
        public readonly int $batchId,
        public readonly int $campaignId,
        public readonly int $afterContactId = 0,
    ) {
        $queue = config('contacts.queues.ingestion', 'default');
        $this->onQueue(is_string($queue) && trim($queue) !== '' ? trim($queue) : 'default');
    }

    public function handle(
        ApplyAutomaticCampaignEligibilityAction $eligibility,
        EnrollContactInCampaignAllocationAction $enroll,
    ): void
    {
        $batch = ContactImportBatch::query()->find($this->batchId);
        $campaign = Campaign::query()->find($this->campaignId);

        if (! $batch instanceof ContactImportBatch
            || $batch->status !== ContactImportBatch::STATUS_COMPLETED
            || ! $campaign instanceof Campaign
            || ! $campaign->isActive()
            || ! $campaign->usesRecurringAllocation()
            || data_get($batch->meta, 'post_import_config.campaign_launch_timing.campaign_key') !== $campaign->key
        ) {
            return;
        }

        $contacts = $batch->importedContactsQuery()
            ->where('contacts.id', '>', $this->afterContactId)
            ->orderBy('contacts.id')
            ->limit(200)
            ->get();

        foreach ($contacts as $contact) {
            if ($campaign->usesAutomaticEnrollment()) {
                $eligibility->handle(campaign: $campaign, contact: $contact);
            } else {
                $enroll->handle(
                    contact: $contact,
                    campaignKey: (string) $campaign->key,
                    source: $batch,
                    meta: ['contact_import' => ['batch_id' => $this->batchId]],
                    entryKey: 'contact_import_batch:'.$this->batchId,
                );
            }
        }

        if ($contacts->count() === 200) {
            self::dispatch(
                batchId: $this->batchId,
                campaignId: $this->campaignId,
                afterContactId: (int) $contacts->last()->getKey(),
            )->afterCommit();
        }
    }
}