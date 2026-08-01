<?php

namespace App\Modules\Messaging\Contracts;

use App\Modules\Messaging\Models\MessageChainEnrollment;

interface MessageChainExecutionContextProvider
{
    public function supports(MessageChainEnrollment $enrollment): bool;

    /**
     * @return array<string, mixed>
     */
    public function values(MessageChainEnrollment $enrollment): array;
}