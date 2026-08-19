<?php

namespace App\Modules\Campaigns\Actions;

use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignEnrollment;
use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Actions\CancelMessageChainEnrollmentAction;
use App\Modules\Messaging\Models\MessageChainEnrollment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class CancelCampaignEnrollmentAction
{
    public function __construct(
        private readonly CancelMessageChainEnrollmentAction $cancelMessageChainEnrollment,
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
            ->whereNotNull('message_chain_enrollment_id')
            ->whereHas(
                'messageChainEnrollment',
                fn ($query) => $query->whereIn('status', [
                    MessageChainEnrollment::STATUS_ACTIVE,
                    MessageChainEnrollment::STATUS_PAUSED,
                ]),
            )
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

            if (! $chainEnrollment instanceof MessageChainEnrollment) {
                throw new RuntimeException(sprintf(
                    'CampaignEnrollment [%d] cannot be cancelled because it has no linked MessageChainEnrollment.',
                    (int) $lockedEnrollment->getKey(),
                ));
            }

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

            $existingMeta = is_array($lockedEnrollment->meta) ? $lockedEnrollment->meta : [];

            $lockedEnrollment->forceFill([
                'meta' => array_replace_recursive($existingMeta, [
                    'lifecycle' => [
                        'last_cancellation' => [
                            'reason' => $reason,
                            'source_type' => $source?->getMorphClass(),
                            'source_id' => $source?->getKey(),
                            'skipped_pending_messages' => $skipPendingMessages,
                            'meta' => $meta ?? [],
                            'cancelled_at' => $cancelled->cancelled_at?->toISOString(),
                        ],
                    ],
                ]),
            ])->save();
            $lockedEnrollment->setRelation('messageChainEnrollment', $cancelled);

            return $lockedEnrollment->refresh()->load('messageChainEnrollment');
        }, 3);
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