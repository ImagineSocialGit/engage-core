<?php

namespace App\Modules\Messaging\Contracts;

use App\Modules\Messaging\Models\MessageChainEnrollment;
use App\Modules\Messaging\Models\MessageChainStep;

interface MessageChainStepBypass
{
    public function reason(MessageChainEnrollment $enrollment, MessageChainStep $step): ?string;
}