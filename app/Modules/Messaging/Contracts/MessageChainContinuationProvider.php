<?php

namespace App\Modules\Messaging\Contracts;

use App\Modules\Messaging\Models\MessageChainEnrollment;
use App\Modules\Messaging\Models\MessageChainStep;

interface MessageChainContinuationProvider
{
    public function nextStep(
        MessageChainEnrollment $enrollment,
        MessageChainStep $lastStep,
    ): ?MessageChainStep;
}