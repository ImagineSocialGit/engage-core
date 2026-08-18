<?php

namespace App\Modules\Campaigns\Actions;

use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignEnrollment;
use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Actions\CancelMessageChainEnrollmentAction;
use App\Modules\Messaging\Actions\SkipScheduledMessagesAction;
use App\Modules\Messaging\Models\MessageChainEnrollment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class CancelCampaignEnrollmentAction
{
    public function __construct(
        private readonly CancelMessageChainEnrollmentAction $cancelMessageChainEnrollment,
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
            ->with('messageChainEnrollment')
            ->where('contact_id', $contact->getKey())
            ->where('campaign_key', $campaignKey)
            ->where(function ($query): void {
                $query
                    ->whereHas(
                        'messageChainEnrollment',
                        fn ($chainQuery) => $chainQuery->whereIn('status', [
                            MessageChainEnrollment::STATUS_ACTIVE,
                            MessageChainEnrollment::STATUS_PAUSED,
                        ]),
                    )
                    // Transitional cleanup only. F7 removes the unlinked legacy path.
                    ->orWhere(function ($legacyQuery): void {
                        $legacyQuery
                            ->whereNull('message_chain_enrollment_id')
                            ->whereIn('status', [
                                CampaignEnrollment::STATUS_ACTIVE,
                                CampaignEnrollment::STATUS_PAUSED,
                            ]);
                    });
            })
            ->orderByDesc('id')
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
        $enrollmentId = (int) $enrollment->getKey();
        $campaignId = is_numeric($enrollment->campaign_id)
            ? (int) $enrollment->campaign_id
            : null;
        $reason = $this->reason($reason);

        return DB::transaction(function () use (
            $enrollmentId,
            $campaignId,
            $source,
            $reason,
            $skipPendingMessages,
            $meta,
        ): CampaignEnrollment {
            if ($campaignId !== null) {
                Campaign::query()
                    ->whereKey($campaignId)
                    ->lockForUpdate()
                    ->first();
            }

            $lockedEnrollment = CampaignEnrollment::query()
                ->with('messageChainEnrollment')
                ->whereKey($enrollmentId)
                ->lockForUpdate()
                ->firstOrFail();
            $chainEnrollment = $lockedEnrollment->messageChainEnrollment;

            if ($chainEnrollment instanceof MessageChainEnrollment) {
                if ($chainEnrollment->isTerminal()) {
                    return $lockedEnrollment;
                }

                $cancelled = $this->cancelMessageChainEnrollment->handle(
                    enrollment: $chainEnrollment,
                    reason: $reason,
                    skipPendingMessages: $skipPendingMessages,
                );

                if ($cancelled->status !== MessageChainEnrollment::STATUS_CANCELLED) {
                    return $lockedEnrollment;
                }

                $cancelledAt = $cancelled->cancelled_at ?? Carbon::now();

                $this->projectCancellation(
                    enrollment: $lockedEnrollment,
                    source: $source,
                    reason: $reason,
                    skipPendingMessages: $skipPendingMessages,
                    meta: $meta,
                    cancelledAt: $cancelledAt,
                );
                $lockedEnrollment->setRelation('messageChainEnrollment', $cancelled);

                return $lockedEnrollment->refresh()->load('messageChainEnrollment');
            }

            if ($lockedEnrollment->isCancelled() || $lockedEnrollment->isCompleted()) {
                return $lockedEnrollment;
            }

            // Transitional cleanup only. New F5+ enrollments must always be linked.
            // Keeping shutdown safe for an unexpected old row is cheaper and safer than
            // allowing an orphaned nurture to continue while F7 removes legacy runtime.
            $cancelledAt = Carbon::now();

            $this->projectCancellation(
                enrollment: $lockedEnrollment,
                source: $source,
                reason: $reason,
                skipPendingMessages: $skipPendingMessages,
                meta: $meta,
                cancelledAt: $cancelledAt,
            );

            if ($skipPendingMessages) {
                $this->skipScheduledMessages->forMetaValue(
                    key: 'campaign_enrollment_id',
                    value: $lockedEnrollment->getKey(),
                    reason: $reason,
                );
            }

            return $lockedEnrollment->refresh();
        }, 3);
    }

    /**
     * @param array<string, mixed>|null $meta
     */
    private function projectCancellation(
        CampaignEnrollment $enrollment,
        ?Model $source,
        string $reason,
        bool $skipPendingMessages,
        ?array $meta,
        Carbon $cancelledAt,
    ): void {
        $existingMeta = is_array($enrollment->meta) ? $enrollment->meta : [];

        $enrollment->forceFill([
            'status' => CampaignEnrollment::STATUS_CANCELLED,
            'cancelled_at' => $enrollment->cancelled_at ?? $cancelledAt,
            'exited_at' => $enrollment->exited_at ?? $cancelledAt,
            'exit_reason' => $enrollment->exit_reason ?? $reason,
            'meta' => array_replace_recursive($existingMeta, [
                'cancellation' => [
                    'reason' => $reason,
                    'source_type' => $source?->getMorphClass(),
                    'source_id' => $source?->getKey(),
                    'skipped_pending_messages' => $skipPendingMessages,
                    'meta' => $meta ?? [],
                    'cancelled_at' => $cancelledAt->toISOString(),
                ],
            ]),
        ])->save();
    }

    private function reason(?string $reason): string
    {
        $reason = is_string($reason) ? trim($reason) : '';

        return mb_substr(
            $reason !== '' ? $reason : 'campaign_cancelled',
            0,
            96,
        );
    }
}