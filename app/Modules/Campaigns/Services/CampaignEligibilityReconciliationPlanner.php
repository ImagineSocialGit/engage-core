<?php

namespace App\Modules\Campaigns\Services;

use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignEnrollment;
use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Models\MessageChainEnrollment;
use Illuminate\Database\Eloquent\Collection;

final class CampaignEligibilityReconciliationPlanner
{
    /**
     * Order automatic Campaign reconciliation so currently-open enrollments
     * settle before new candidates in the same pass. This prevents a stale
     * family incumbent from temporarily blocking the newly eligible Campaign.
     *
     * @param Collection<int, Campaign> $campaigns
     * @return Collection<int, Campaign>
     */
    public function orderForContact(
        Contact $contact,
        Collection $campaigns,
    ): Collection {
        if ($campaigns->isEmpty()) {
            return $campaigns;
        }

        $openCampaignIds = $this->openCampaignIds(
            contact: $contact,
            campaignIds: array_map(
                static fn (mixed $id): int => (int) $id,
                $campaigns->modelKeys(),
            ),
        );

        return $campaigns
            ->sort(function (Campaign $left, Campaign $right) use ($openCampaignIds): int {
                $leftOpen = isset($openCampaignIds[(int) $left->getKey()]);
                $rightOpen = isset($openCampaignIds[(int) $right->getKey()]);

                if ($leftOpen !== $rightOpen) {
                    return $leftOpen ? -1 : 1;
                }

                $priority = (int) $right->priority <=> (int) $left->priority;

                return $priority !== 0
                    ? $priority
                    : ((int) $left->getKey() <=> (int) $right->getKey());
            })
            ->values();
    }

    /**
     * Return the target automatic Campaign plus any currently-open automatic
     * siblings from its family. Open siblings come first so import-time launch
     * can settle an old family enrollment before trying to open the target.
     *
     * @return Collection<int, Campaign>
     */
    public function targetWithOpenFamilyCampaigns(
        Contact $contact,
        Campaign $target,
    ): Collection {
        $campaigns = Campaign::query()
            ->active()
            ->where('enrollment_mode', Campaign::ENROLLMENT_MODE_AUTOMATIC)
            ->when(
                is_string($target->family_key) && trim($target->family_key) !== '',
                fn ($query) => $query->where('family_key', $target->family_key),
                fn ($query) => $query->whereKey($target->getKey()),
            )
            ->orderBy('id')
            ->get();

        $openCampaignIds = $this->openCampaignIds(
            contact: $contact,
            campaignIds: array_map(
                static fn (mixed $id): int => (int) $id,
                $campaigns->modelKeys(),
            ),
        );

        return $this->orderForContact($contact, $campaigns)
            ->filter(fn (Campaign $campaign): bool =>
                (int) $campaign->getKey() === (int) $target->getKey()
                || isset($openCampaignIds[(int) $campaign->getKey()])
            )
            ->values();
    }

    /**
     * @param array<int, int> $campaignIds
     * @return array<int, true>
     */
    private function openCampaignIds(
        Contact $contact,
        array $campaignIds,
    ): array {
        if ($campaignIds === []) {
            return [];
        }

        return CampaignEnrollment::query()
            ->where('contact_id', $contact->getKey())
            ->whereIn('campaign_id', $campaignIds)
            ->whereNotNull('message_chain_enrollment_id')
            ->whereHas(
                'messageChainEnrollment',
                fn ($query) => $query->whereIn('status', [
                    MessageChainEnrollment::STATUS_ACTIVE,
                    MessageChainEnrollment::STATUS_PAUSED,
                ]),
            )
            ->pluck('campaign_id')
            ->filter(fn (mixed $id): bool => is_numeric($id))
            ->mapWithKeys(fn (mixed $id): array => [(int) $id => true])
            ->all();
    }
}