<?php

namespace App\Modules\Campaigns\Actions;

use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignAllocationEnrollment;
use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Actions\SkipScheduledMessagesAction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

final class CancelCampaignAllocationEnrollmentAction
{
    public function __construct(
        private readonly SkipScheduledMessagesAction $skipScheduledMessages,
    ) {}

    /** @param array<string, mixed>|null $meta */
    public function handle(
        Contact $contact,
        string $campaignKey,
        ?Model $source = null,
        ?string $reason = null,
        bool $skipPendingMessages = true,
        ?array $meta = null,
    ): ?CampaignAllocationEnrollment {
        $enrollment = CampaignAllocationEnrollment::query()
            ->whereHas(
                'campaign',
                fn ($query) => $query->where('key', trim($campaignKey)),
            )
            ->where('contact_id', $contact->getKey())
            ->where('status', CampaignAllocationEnrollment::STATUS_ACTIVE)
            ->orderByDesc('id')
            ->first();

        if (! $enrollment instanceof CampaignAllocationEnrollment) {
            return null;
        }

        return $this->cancelEnrollment(
            enrollment: $enrollment,
            source: $source,
            reason: $reason,
            skipPendingMessages: $skipPendingMessages,
            meta: $meta,
        );
    }

    /** @param array<string, mixed>|null $meta */
    public function cancelEnrollment(
        CampaignAllocationEnrollment $enrollment,
        ?Model $source = null,
        ?string $reason = null,
        bool $skipPendingMessages = true,
        ?array $meta = null,
    ): CampaignAllocationEnrollment {
        $enrollmentId = (int) $enrollment->getKey();
        $campaignId = (int) $enrollment->campaign_id;
        $reason = $this->reason($reason);

        return DB::transaction(function () use (
            $enrollmentId,
            $campaignId,
            $source,
            $reason,
            $skipPendingMessages,
            $meta,
        ): CampaignAllocationEnrollment {
            Campaign::query()
                ->whereKey($campaignId)
                ->lockForUpdate()
                ->first();

            $locked = CampaignAllocationEnrollment::query()
                ->with('assignments')
                ->whereKey($enrollmentId)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $locked->isActive()) {
                return $locked;
            }

            $skipped = 0;

            if ($skipPendingMessages) {
                foreach ($locked->assignments as $assignment) {
                    $skipped += $this->skipScheduledMessages->forContext(
                        context: $assignment,
                        reason: $reason,
                    );
                }
            }

            $cancelledAt = now();
            $existingMeta = is_array($locked->meta) ? $locked->meta : [];

            $locked->forceFill([
                'status' => CampaignAllocationEnrollment::STATUS_CANCELLED,
                'cancelled_at' => $cancelledAt,
                'meta' => array_replace_recursive($existingMeta, [
                    'lifecycle' => [
                        'last_cancellation' => [
                            'reason' => $reason,
                            'source_type' => $source?->getMorphClass(),
                            'source_id' => $source?->getKey(),
                            'skipped_pending_messages' => $skipped,
                            'meta' => $meta ?? [],
                            'cancelled_at' => $cancelledAt->toISOString(),
                        ],
                    ],
                ]),
            ])->save();

            return $locked->refresh();
        }, 3);
    }

    private function reason(?string $reason): string
    {
        $reason = is_string($reason) ? trim($reason) : '';

        return mb_substr(
            $reason !== '' ? $reason : 'campaign_allocation_cancelled',
            0,
            96,
        );
    }
}