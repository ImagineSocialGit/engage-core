<?php

namespace App\Modules\Campaigns\Services;

use App\Modules\Campaigns\Actions\CancelCampaignEnrollmentAction;
use App\Modules\Campaigns\Data\CampaignEnrollmentArbitrationResult;
use App\Modules\Campaigns\Exceptions\CampaignUnavailableForEnrollmentException;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignEnrollment;
use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Models\MessageChainEnrollment;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

final class CampaignEnrollmentArbitrator
{
    public const SUPERSEDED_REASON = 'campaign_family_superseded';

    public function __construct(
        private readonly CancelCampaignEnrollmentAction $cancelCampaignEnrollment,
    ) {}

    public function handle(
        Contact $contact,
        Campaign $candidate,
        ?Model $source = null,
    ): CampaignEnrollmentArbitrationResult {
        $campaigns = $this->lockCampaignSet($candidate);
        $lockedCandidate = $campaigns->first(
            fn (Campaign $campaign): bool => (int) $campaign->getKey() === (int) $candidate->getKey(),
        );

        if (
            ! $lockedCandidate instanceof Campaign
            || $this->familyKey($lockedCandidate) !== $this->familyKey($candidate)
        ) {
            throw new RuntimeException(
                "Campaign [{$candidate->key}] changed family while enrollment arbitration was starting. Retry the enrollment.",
            );
        }

        if (! $lockedCandidate->isActive()) {
            throw CampaignUnavailableForEnrollmentException::inactive(
                campaignKey: $lockedCandidate->key,
                campaignStatus: $lockedCandidate->status,
            );
        }

        $openEnrollments = $this->openEnrollments(
            contact: $contact,
            campaignIds: $campaigns->modelKeys(),
        );

        $existing = $openEnrollments->first(
            fn (CampaignEnrollment $enrollment): bool => (int) $enrollment->campaign_id === (int) $lockedCandidate->getKey(),
        );

        if ($existing instanceof CampaignEnrollment) {
            return new CampaignEnrollmentArbitrationResult(
                campaign: $lockedCandidate,
                existingEnrollment: $existing,
            );
        }

        if ($lockedCandidate->family_key === null) {
            return new CampaignEnrollmentArbitrationResult(
                campaign: $lockedCandidate,
            );
        }

        $campaignsById = $campaigns->keyBy(
            fn (Campaign $campaign): int => (int) $campaign->getKey(),
        );

        $incumbents = $openEnrollments
            ->filter(
                fn (CampaignEnrollment $enrollment): bool => (int) $enrollment->campaign_id !== (int) $lockedCandidate->getKey(),
            )
            ->values();

        $blocker = $incumbents
            ->sort(function (CampaignEnrollment $left, CampaignEnrollment $right) use ($campaignsById): int {
                $leftCampaign = $campaignsById->get((int) $left->campaign_id);
                $rightCampaign = $campaignsById->get((int) $right->campaign_id);
                $leftPriority = $leftCampaign instanceof Campaign ? (int) $leftCampaign->priority : 0;
                $rightPriority = $rightCampaign instanceof Campaign ? (int) $rightCampaign->priority : 0;

                return ($rightPriority <=> $leftPriority)
                    ?: ((int) $left->getKey() <=> (int) $right->getKey());
            })
            ->first();

        if ($blocker instanceof CampaignEnrollment) {
            $blockingCampaign = $campaignsById->get((int) $blocker->campaign_id);

            if (
                $blockingCampaign instanceof Campaign
                && (int) $blockingCampaign->priority >= (int) $lockedCandidate->priority
            ) {
                throw CampaignUnavailableForEnrollmentException::familyBlocked(
                    campaignKey: $lockedCandidate->key,
                    familyKey: $lockedCandidate->family_key,
                    campaignPriority: (int) $lockedCandidate->priority,
                    blockingCampaignKey: $blockingCampaign->key,
                    blockingPriority: (int) $blockingCampaign->priority,
                    blockingEnrollmentId: (int) $blocker->getKey(),
                );
            }
        }

        $superseded = [];

        foreach ($incumbents->sortBy('id') as $incumbent) {
            $incumbentCampaign = $campaignsById->get((int) $incumbent->campaign_id);

            if (! $incumbentCampaign instanceof Campaign) {
                continue;
            }

            $cancelled = $this->cancelCampaignEnrollment->cancelEnrollment(
                enrollment: $incumbent,
                source: $source,
                reason: self::SUPERSEDED_REASON,
                skipPendingMessages: true,
                meta: [
                    'campaign_family' => [
                        'family_key' => $lockedCandidate->family_key,
                        'superseded_by_campaign_key' => $lockedCandidate->key,
                        'superseded_by_priority' => (int) $lockedCandidate->priority,
                    ],
                ],
            );

            if ($cancelled->runtimeStatus() !== MessageChainEnrollment::STATUS_CANCELLED) {
                continue;
            }

            $superseded[] = [
                'campaign_enrollment_id' => (int) $cancelled->getKey(),
                'campaign_id' => (int) $incumbentCampaign->getKey(),
                'campaign_key' => $incumbentCampaign->key,
                'priority' => (int) $incumbentCampaign->priority,
            ];
        }

        return new CampaignEnrollmentArbitrationResult(
            campaign: $lockedCandidate,
            superseded: $superseded,
        );
    }

    /**
     * @return Collection<int, Campaign>
     */
    private function lockCampaignSet(Campaign $candidate): Collection
    {
        $familyKey = $this->familyKey($candidate);

        return Campaign::query()
            ->when(
                $familyKey !== null,
                fn ($query) => $query->where('family_key', $familyKey),
                fn ($query) => $query->whereKey($candidate->getKey()),
            )
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    /**
     * @param array<int, int|string> $campaignIds
     * @return Collection<int, CampaignEnrollment>
     */
    private function openEnrollments(Contact $contact, array $campaignIds): Collection
    {
        if ($campaignIds === []) {
            return new Collection();
        }

        $enrollments = CampaignEnrollment::query()
            ->where('contact_id', $contact->getKey())
            ->whereIn('campaign_id', $campaignIds)
            ->whereNotNull('message_chain_enrollment_id')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $chainEnrollmentIds = $enrollments
            ->pluck('message_chain_enrollment_id')
            ->filter(fn (mixed $id): bool => is_numeric($id) && (int) $id > 0)
            ->map(fn (mixed $id): int => (int) $id)
            ->values()
            ->all();

        if ($chainEnrollmentIds === []) {
            return new Collection();
        }

        $openChainEnrollments = MessageChainEnrollment::query()
            ->whereIn('id', $chainEnrollmentIds)
            ->whereIn('status', [
                MessageChainEnrollment::STATUS_ACTIVE,
                MessageChainEnrollment::STATUS_PAUSED,
            ])
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy(fn (MessageChainEnrollment $enrollment): int => (int) $enrollment->getKey());

        return $enrollments
            ->filter(function (CampaignEnrollment $enrollment) use ($openChainEnrollments): bool {
                $chainEnrollment = $openChainEnrollments->get((int) $enrollment->message_chain_enrollment_id);

                if (! $chainEnrollment instanceof MessageChainEnrollment) {
                    return false;
                }

                $enrollment->setRelation('messageChainEnrollment', $chainEnrollment);

                return true;
            })
            ->values();
    }

    private function familyKey(Campaign $campaign): ?string
    {
        if (! is_string($campaign->family_key)) {
            return null;
        }

        $familyKey = trim($campaign->family_key);

        return $familyKey !== '' ? $familyKey : null;
    }
}