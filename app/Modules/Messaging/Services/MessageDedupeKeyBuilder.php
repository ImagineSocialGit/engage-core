<?php

namespace App\Modules\Messaging\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class MessageDedupeKeyBuilder
{
    /**
     * @param array<string, mixed> $definition
     */
    public function build(
        Model $recipient,
        array $definition,
        ?Model $context,
        Carbon $sendAt,
    ): string {
        return implode(':', array_filter([
            'message',
            $recipient->getMorphClass(),
            $recipient->getKey(),
            $definition['channel'],
            $definition['purpose'],
            $definition['scope'],
            $definition['message_type'],

            $definition['campaign_key'] ?? null,
            $definition['step'] ?? null,

            $definition['timing'] ?? null,
            $definition['schedule']['type'] ?? null,
            $definition['schedule']['minutes'] ?? null,
            $sendAt->toISOString(),

            $context?->getMorphClass(),
            $context?->getKey(),
        ], fn (mixed $value): bool => $value !== null && $value !== ''));
    }
}