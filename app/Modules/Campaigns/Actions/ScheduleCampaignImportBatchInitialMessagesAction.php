<?php

namespace App\Modules\Campaigns\Actions;

use App\Modules\Campaigns\Models\CampaignEnrollment;
use App\Modules\Core\Models\ContactImportBatch;
use App\Modules\Core\Models\ContactImportOccurrence;
use App\Modules\Messaging\Models\MessageChainEnrollment;
use App\Modules\Messaging\Models\ScheduledMessage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

final class ScheduleCampaignImportBatchInitialMessagesAction
{
    /**
     * @return array{
     *     campaign_key: string,
     *     enrollment_count: int,
     *     requested_first_message_at: string,
     *     effective_first_message_at: string
     * }
     */
    public function handle(
        ContactImportBatch $batch,
        string $campaignKey,
        Carbon|string $firstMessageAt,
    ): array {
        $campaignKey = trim($campaignKey);

        if ($campaignKey === '') {
            throw new InvalidArgumentException('Campaign key is required.');
        }

        $requestedAt = ($firstMessageAt instanceof Carbon
            ? $firstMessageAt->copy()
            : Carbon::parse($firstMessageAt)
        )->utc();

        return DB::transaction(function () use (
            $batch,
            $campaignKey,
            $requestedAt,
        ): array {
            $batchContactIds = ContactImportOccurrence::query()
                ->select('contact_id')
                ->where('contact_import_batch_id', $batch->getKey())
                ->whereNotNull('contact_id');

            $campaignEnrollments = CampaignEnrollment::query()
                ->where('campaign_key', $campaignKey)
                ->whereIn('contact_id', $batchContactIds)
                ->when(
                    $batch->imported_at !== null,
                    fn ($query) => $query->where(
                        'started_at',
                        '>=',
                        $batch->imported_at,
                    ),
                )
                ->when(
                    $batch->created_at !== null,
                    fn ($query) => $query->where(
                        'created_at',
                        '>=',
                        $batch->created_at,
                    ),
                )
                ->whereNotNull('message_chain_enrollment_id')
                ->orderBy('id')
                ->lockForUpdate()
                ->get([
                    'id',
                    'message_chain_enrollment_id',
                ]);

            $messageChainEnrollmentIds = $campaignEnrollments
                ->pluck('message_chain_enrollment_id')
                ->filter(fn (mixed $id): bool => is_numeric($id) && (int) $id > 0)
                ->map(fn (mixed $id): int => (int) $id)
                ->unique()
                ->values();

            if ($campaignEnrollments->count() !== $messageChainEnrollmentIds->count()) {
                throw new RuntimeException(
                    "Campaign launch timing for batch [{$batch->getKey()}] found an invalid or duplicate MessageChain enrollment reference.",
                );
            }

            if ($messageChainEnrollmentIds->isEmpty()) {
                return [
                    'campaign_key' => $campaignKey,
                    'enrollment_count' => 0,
                    'requested_first_message_at' => $requestedAt->toISOString(),
                    'effective_first_message_at' => $requestedAt->toISOString(),
                ];
            }

            $chainEnrollments = MessageChainEnrollment::query()
                ->whereKey($messageChainEnrollmentIds->all())
                ->orderBy('id')
                ->lockForUpdate()
                ->get([
                    'id',
                    'status',
                    'current_message_chain_step_id',
                    'next_action_at',
                ]);

            if ($chainEnrollments->count() !== $messageChainEnrollmentIds->count()) {
                throw new RuntimeException(
                    "Campaign launch timing for batch [{$batch->getKey()}] found a missing MessageChain enrollment.",
                );
            }

            $unsafe = $chainEnrollments->first(
                fn (MessageChainEnrollment $enrollment): bool =>
                    $enrollment->status !== MessageChainEnrollment::STATUS_ACTIVE
                    || $enrollment->current_message_chain_step_id === null
                    || $enrollment->next_action_at === null,
            );

            if ($unsafe instanceof MessageChainEnrollment) {
                throw new RuntimeException(
                    "Campaign launch timing cannot retime MessageChain enrollment [{$unsafe->getKey()}] because it is no longer at an active unmaterialized step.",
                );
            }

            if (ScheduledMessage::query()
                ->whereIn(
                    'message_chain_enrollment_id',
                    $messageChainEnrollmentIds->all(),
                )
                ->exists()
            ) {
                throw new RuntimeException(
                    "Campaign launch timing for batch [{$batch->getKey()}] cannot change after a Campaign message has already been materialized.",
                );
            }

            $effectiveAt = $requestedAt->isPast()
                ? Carbon::now()->utc()
                : $requestedAt->copy();

            MessageChainEnrollment::query()
                ->whereKey($messageChainEnrollmentIds->all())
                ->update([
                    'next_action_at' => $effectiveAt,
                ]);

            return [
                'campaign_key' => $campaignKey,
                'enrollment_count' => $messageChainEnrollmentIds->count(),
                'requested_first_message_at' => $requestedAt->toISOString(),
                'effective_first_message_at' => $effectiveAt->toISOString(),
            ];
        }, 3);
    }
}