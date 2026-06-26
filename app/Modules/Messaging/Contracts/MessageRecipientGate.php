<?php

namespace App\Modules\Messaging\Contracts;

use Illuminate\Database\Eloquent\Model;

interface MessageRecipientGate
{
    public function supports(Model $recipient): bool;

    /**
     * @param array<string, mixed> $context
     */
    public function allows(
        Model $recipient,
        string $channel,
        ?string $type = null,
        array $context = [],
    ): bool;

    /**
     * @param array<string, mixed> $context
     */
    public function denialReason(
        Model $recipient,
        string $channel,
        ?string $type = null,
        array $context = [],
    ): ?string;
}