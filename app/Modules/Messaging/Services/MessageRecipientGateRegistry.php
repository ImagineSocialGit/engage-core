<?php

namespace App\Modules\Messaging\Services;

use App\Modules\Messaging\Contracts\MessageRecipientGate;
use Illuminate\Database\Eloquent\Model;

class MessageRecipientGateRegistry
{
    /**
     * @param iterable<int, MessageRecipientGate> $gates
     */
    public function __construct(
        private readonly iterable $gates,
    ) {}

    /**
     * @param array<string, mixed> $context
     */
    public function allows(
        Model $recipient,
        string $channel,
        ?string $type = null,
        array $context = [],
    ): bool {
        return $this->denialReason(
            recipient: $recipient,
            channel: $channel,
            type: $type,
            context: $context,
        ) === null;
    }

    /**
     * @param array<string, mixed> $context
     */
    public function denialReason(
        Model $recipient,
        string $channel,
        ?string $type = null,
        array $context = [],
    ): ?string {
        $hasSupportingGate = false;

        foreach ($this->gates as $gate) {
            if (! $gate->supports($recipient)) {
                continue;
            }

            $hasSupportingGate = true;

            $reason = $gate->denialReason(
                recipient: $recipient,
                channel: $channel,
                type: $type,
                context: $context,
            );

            if ($reason !== null) {
                return $reason;
            }
        }

        return null;
    }
}