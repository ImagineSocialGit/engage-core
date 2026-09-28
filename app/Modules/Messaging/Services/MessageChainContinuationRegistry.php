<?php

namespace App\Modules\Messaging\Services;

use App\Modules\Messaging\Contracts\MessageChainContinuationProvider;
use App\Modules\Messaging\Models\MessageChainEnrollment;
use App\Modules\Messaging\Models\MessageChainStep;

final class MessageChainContinuationRegistry
{
    /** @param iterable<int, MessageChainContinuationProvider> $providers */
    public function __construct(private readonly iterable $providers) {}

    public function nextStep(
        MessageChainEnrollment $enrollment,
        MessageChainStep $lastStep,
    ): ?MessageChainStep {
        foreach ($this->providers as $provider) {
            $step = $provider->nextStep($enrollment, $lastStep);

            if ($step instanceof MessageChainStep) {
                return $step;
            }
        }

        return null;
    }
}