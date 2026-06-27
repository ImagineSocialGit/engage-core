<?php

namespace App\Modules\Campaigns\Actions;

use App\Modules\Campaigns\Models\CampaignEnrollment;
use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Actions\SkipScheduledMessagesAction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class CancelCampaignEnrollmentAction
{
    public function __construct(
        private readonly SkipScheduledMessagesAction $skipScheduledMessages,
    ) {}

    /**
     * @param array<string, mixed>|null $meta
     */
    public function handle(
        Contact $contact,
        string $campaignKey,
        ?Model $source = null,
        ?string $reason = null,
        bool $skipPendingMessages = true,
        ?array $meta = null,
    ): ?CampaignEnrollment {
        $enrollment = CampaignEnrollment::query()
            ->where('contact_id', $contact->id)
            ->where('campaign_key', $campaignKey)
            ->whereIn('status', [
                CampaignEnrollment::STATUS_ACTIVE,
                CampaignEnrollment::STATUS_PAUSED,
            ])
            ->latest('id')
            ->first();

        if (! $enrollment instanceof CampaignEnrollment) {
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

    /**
     * @param array<string, mixed>|null $meta
     */
    public function cancelEnrollment(
        CampaignEnrollment $enrollment,
        ?Model $source = null,
        ?string $reason = null,
        bool $skipPendingMessages = true,
        ?array $meta = null,
    ): CampaignEnrollment {
        if ($enrollment->isCancelled() || $enrollment->isCompleted()) {
            return $enrollment;
        }

        $now = Carbon::now();
        $reason = $this->reason($reason);

        $existingMeta = is_array($enrollment->meta) ? $enrollment->meta : [];

        $enrollment->forceFill([
            'status' => CampaignEnrollment::STATUS_CANCELLED,
            'cancelled_at' => $enrollment->cancelled_at ?? $now,
            'exited_at' => $enrollment->exited_at ?? $now,
            'exit_reason' => $enrollment->exit_reason ?? $reason,
            'meta' => array_replace_recursive($existingMeta, [
                'cancellation' => [
                    'reason' => $reason,
                    'source_type' => $source?->getMorphClass(),
                    'source_id' => $source?->getKey(),
                    'skipped_pending_messages' => $skipPendingMessages,
                    'meta' => $meta ?? [],
                    'cancelled_at' => $now->toISOString(),
                ],
            ]),
        ])->save();

        if ($skipPendingMessages) {
            $this->skipScheduledMessages->forMetaValue(
                key: 'campaign_enrollment_id',
                value: $enrollment->id,
                reason: $reason,
            );
        }

        return $enrollment->refresh();
    }

    private function reason(?string $reason): string
    {
        $reason = is_string($reason) ? trim($reason) : '';

        return $reason !== '' ? $reason : 'campaign_cancelled';
    }
}