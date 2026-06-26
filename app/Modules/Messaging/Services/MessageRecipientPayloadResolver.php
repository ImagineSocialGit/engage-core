<?php

namespace App\Modules\Messaging\Services;

use App\Modules\Messaging\Enums\MessageChannel;
use App\Modules\Core\Models\Contact;
use App\Modules\InternalNotifications\Models\TeamMember;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class MessageRecipientPayloadResolver
{
    /**
     * @param  array<string, mixed>  $definitionPayload
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    public function resolve(
        Model $recipient,
        MessageChannel|string $channel,
        string $purpose,
        string $scope,
        string $messageType,
        array $definitionPayload = [],
        array $payload = [],
    ): ?array {
        $channel = $this->normalizeChannel($channel);

        $mergedPayload = array_replace_recursive(
            $definitionPayload,
            $payload,
        );

        $destination = $this->explicitDestination($mergedPayload)
            ?? $this->destinationForChannel($recipient, $channel);

        if (! is_string($destination) || trim($destination) === '') {
            return null;
        }

        $resolvedPayload = array_replace_recursive(
            $mergedPayload,
            [
                'to' => trim($destination),
                'recipient_type' => $recipient->getMorphClass(),
                'recipient_id' => $recipient->getKey(),
                'channel' => $channel,
                'purpose' => $purpose,
                'scope' => $scope,
                'message_type' => $messageType,
            ],
        );

        return $resolvedPayload;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function conditionContext(Model $recipient, ?Model $context, array $payload): array
    {
        $conditionContext = [
            $this->contextKey($recipient) => $recipient->toArray(),
        ];

        if ($context) {
            $conditionContext[$this->contextKey($context)] = $context->toArray();
        }

        return array_replace_recursive(
            $conditionContext,
            is_array($payload['runtime_context'] ?? null) ? $payload['runtime_context'] : [],
            is_array($payload['context'] ?? null) ? $payload['context'] : [],
            is_array($payload['tokens'] ?? null) ? $payload['tokens'] : [],
        );
    }

    public function destinationForChannel(Model $recipient, MessageChannel|string $channel): ?string
    {
        $channel = $this->normalizeChannel($channel);

        return match (true) {
            $recipient instanceof Contact && $channel === MessageChannel::Email->value => $recipient->email,
            $recipient instanceof Contact && $channel === MessageChannel::Sms->value => $recipient->phone,

            $recipient instanceof TeamMember && $channel === MessageChannel::Email->value => $recipient->email,
            $recipient instanceof TeamMember && $channel === MessageChannel::Sms->value => $recipient->phone,

            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function explicitDestination(array $payload): ?string
    {
        foreach (['to', 'email', 'phone', 'contact_email', 'contact_phone'] as $key) {
            $value = $payload[$key] ?? null;

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    private function contextKey(Model $model): string
    {
        return Str::snake(class_basename($model));
    }

    private function normalizeChannel(MessageChannel|string $channel): string
    {
        return $channel instanceof MessageChannel
            ? $channel->value
            : strtolower(trim($channel));
    }
}