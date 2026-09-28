<?php

namespace App\Modules\Campaigns\Services;

use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Messaging\Models\MessageChain;
use App\Modules\Messaging\Models\MessageChainStep;
use App\Modules\Messaging\Models\MessageChainVersion;
use Illuminate\Support\Collection;
use InvalidArgumentException;

final class CampaignMessageStepResolver
{
    public function currentVersion(Campaign $campaign): MessageChainVersion
    {
        if (! is_numeric($campaign->message_chain_id)
            || (int) $campaign->message_chain_id < 1
        ) {
            throw new InvalidArgumentException(sprintf(
                'Campaign [%s] has no selected MessageChain.',
                (string) $campaign->key,
            ));
        }

        $chain = MessageChain::query()
            ->with('currentVersion.steps.variants')
            ->whereKey((int) $campaign->message_chain_id)
            ->first();

        if (! $chain instanceof MessageChain) {
            throw new InvalidArgumentException(sprintf(
                'Campaign [%s] references a missing MessageChain.',
                (string) $campaign->key,
            ));
        }

        $version = $chain->requireCurrentVersion();

        if (! $version->isPublished()) {
            throw new InvalidArgumentException(sprintf(
                'Campaign [%s] does not have a published current message schedule.',
                (string) $campaign->key,
            ));
        }

        return $version;
    }

    /** @return Collection<int, MessageChainStep> */
    public function activeSteps(Campaign $campaign): Collection
    {
        return $this->currentVersion($campaign)
            ->steps
            ->filter(fn (MessageChainStep $step): bool => (bool) $step->is_active)
            ->sort(function (MessageChainStep $left, MessageChainStep $right): int {
                return ((int) $left->sort_order <=> (int) $right->sort_order)
                    ?: ((int) $left->getKey() <=> (int) $right->getKey());
            })
            ->values();
    }

    public function activeStep(Campaign $campaign, string $stepKey): MessageChainStep
    {
        $stepKey = trim($stepKey);

        if ($stepKey === '') {
            throw new InvalidArgumentException('Campaign message step key cannot be empty.');
        }

        $step = $this->activeSteps($campaign)->first(
            fn (MessageChainStep $candidate): bool =>
                (string) $candidate->key === $stepKey,
        );

        if (! $step instanceof MessageChainStep) {
            throw new InvalidArgumentException(sprintf(
                'Campaign [%s] has no active current message [%s].',
                (string) $campaign->key,
                $stepKey,
            ));
        }

        return $step;
    }
}