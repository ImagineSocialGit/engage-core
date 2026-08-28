<?php

namespace App\Modules\Broadcasts\Services;

use App\Modules\Broadcasts\Models\Broadcast;
use App\Modules\Messaging\Services\MessageTemplateTokenValidator;
use InvalidArgumentException;

class BroadcastMessageTokenValidator
{
    public function __construct(
        private readonly MessageTemplateTokenValidator $messageTemplateTokenValidator,
    ) {}

    /**
     * @param array<string, mixed> $payload
     * @return array<int, array{level: string, path: string, message: string}>
     */
    public function issues(
        array $payload,
        string $channel,
        string $dispatchKey = Broadcast::DEFAULT_DISPATCH_KEY,
    ): array {
        return $this->messageTemplateTokenValidator->validatePayload(
            payload: $payload,
            dispatchKeys: [$dispatchKey],
            channel: $channel,
            purpose: 'marketing',
            scope: 'broadcast',
            surface: 'broadcasts',
            path: 'payload',
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function assertValid(
        array $payload,
        string $channel,
        string $dispatchKey = Broadcast::DEFAULT_DISPATCH_KEY,
    ): void {
        $error = collect($this->issues(
            payload: $payload,
            channel: $channel,
            dispatchKey: $dispatchKey,
        ))->firstWhere('level', 'error');

        if (! is_array($error)) {
            return;
        }

        $message = $error['message'] ?? null;

        throw new InvalidArgumentException(
            is_string($message) && trim($message) !== ''
                ? trim($message)
                : 'Broadcast message contains an invalid dynamic field.',
        );
    }

    public function assertBroadcastValid(Broadcast $broadcast): void
    {
        if (! $broadcast->isRegularBroadcast()) {
            return;
        }

        $this->assertValid(
            payload: is_array($broadcast->payload) ? $broadcast->payload : [],
            channel: (string) $broadcast->channel,
            dispatchKey: is_string($broadcast->dispatch_key) && trim($broadcast->dispatch_key) !== ''
                ? trim($broadcast->dispatch_key)
                : Broadcast::DEFAULT_DISPATCH_KEY,
        );
    }
}