<?php

namespace App\Modules\Campaigns\Services;

use App\Modules\Campaigns\Models\Campaign;
use Illuminate\Database\Eloquent\Collection;

final class CampaignEligibilityDependencyResolver
{
    /**
     * @param array<int, mixed> $criterionKeys
     * @return Collection<int, Campaign>
     */
    public function forCriterionKeys(array $criterionKeys): Collection
    {
        $criterionKeys = $this->criterionKeys($criterionKeys);

        if ($criterionKeys === []) {
            return new Collection();
        }

        return Campaign::query()
            ->active()
            ->where('enrollment_mode', Campaign::ENROLLMENT_MODE_AUTOMATIC)
            ->orderBy('id')
            ->get()
            ->filter(function (Campaign $campaign) use ($criterionKeys): bool {
                $eligibility = is_array($campaign->eligibility_filter)
                    ? $campaign->eligibility_filter
                    : [];

                return array_intersect(
                    $criterionKeys,
                    array_keys($eligibility),
                ) !== [];
            })
            ->values();
    }

    /** @param array<int, mixed> $criterionKeys @return array<int, string> */
    private function criterionKeys(array $criterionKeys): array
    {
        $normalized = [];

        foreach ($criterionKeys as $criterionKey) {
            if (! is_string($criterionKey)) {
                continue;
            }

            $criterionKey = trim($criterionKey);

            if ($criterionKey === '' || in_array($criterionKey, $normalized, true)) {
                continue;
            }

            $normalized[] = $criterionKey;
        }

        return $normalized;
    }
}