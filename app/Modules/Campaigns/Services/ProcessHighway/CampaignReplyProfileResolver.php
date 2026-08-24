<?php

namespace App\Modules\Campaigns\Services\ProcessHighway;

use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Messaging\Models\MessageChain;
use App\Modules\Messaging\Models\MessageChainStep;
use App\Modules\Messaging\Models\MessageChainStepVariant;
use App\Modules\Messaging\Models\MessageChainVersion;
use App\Modules\Messaging\Models\MessageTemplatePresetAssignment;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

final class CampaignReplyProfileResolver
{
    /** @return array<int, string> */
    public function resolve(Campaign $campaign): array
    {
        $campaign->loadMissing('messageChain.currentVersion.steps.variants');

        $chain = $campaign->messageChain;
        $version = $chain instanceof MessageChain ? $chain->currentVersion : null;

        if ($version instanceof MessageChainVersion && $version->isPublished()) {
            return $this->fromChainVersion($version);
        }

        return $this->fromLegacyProjection($campaign);
    }

    /** @return array<int, string> */
    private function fromChainVersion(MessageChainVersion $version): array
    {
        return $this->normalize(
            $version->steps
                ->filter(fn (MessageChainStep $step): bool => (bool) $step->is_active)
                ->flatMap(fn (MessageChainStep $step): Collection => $step->variants)
                ->filter(fn (MessageChainStepVariant $variant): bool => (bool) $variant->is_active)
                ->pluck('reply_profile_key'),
        );
    }

    /** @return array<int, string> */
    private function fromLegacyProjection(Campaign $campaign): array
    {
        if (! Schema::hasTable('message_template_preset_assignments')) {
            return [];
        }

        return $this->normalize(
            MessageTemplatePresetAssignment::query()
                ->active()
                ->where('surface', 'campaigns')
                ->where('campaign_key', $campaign->key)
                ->whereNull('context_type')
                ->whereNull('context_id')
                ->pluck('reply_profile_key'),
        );
    }

    /**
     * @param Collection<int, mixed> $values
     * @return array<int, string>
     */
    private function normalize(Collection $values): array
    {
        return $values
            ->filter(fn (mixed $value): bool => is_string($value) && trim($value) !== '')
            ->map(fn (string $value): string => trim($value))
            ->unique()
            ->sort()
            ->values()
            ->all();
    }
}