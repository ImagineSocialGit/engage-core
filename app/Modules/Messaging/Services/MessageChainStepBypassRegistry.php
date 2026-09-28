<?php

namespace App\Modules\Messaging\Services;

use App\Modules\Messaging\Contracts\MessageChainStepBypass;
use App\Modules\Messaging\Models\MessageChainEnrollment;
use App\Modules\Messaging\Models\MessageChainStep;

final class MessageChainStepBypassRegistry
{
    /** @param iterable<int, MessageChainStepBypass> $bypasses */
    public function __construct(private readonly iterable $bypasses) {}

    public function reason(MessageChainEnrollment $enrollment, MessageChainStep $step): ?string
    {
        foreach ($this->bypasses as $bypass) {
            $reason = $bypass->reason($enrollment, $step);

            if ($reason !== null) {
                return $reason;
            }
        }

        return null;
    }
}