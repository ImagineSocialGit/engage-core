<?php

namespace App\Modules\Messaging\Services;

use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Enums\MessageChannel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class MessageRecipientPayloadResolver
{
    public function __construct(
        private readonly MessageRecipientPayloadProviderRegistry $payloadProviderRegistry,
    ) {}

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

        $mergedPayload = $this->withRecipientTokens(
            payload: array_replace_recursive(
                $definitionPayload,
                $payload,
            ),
            recipient: $recipient,
        );

        $destination = $this->explicitDestination($mergedPayload)
            ?? $this->destinationForChannel($recipient, $channel);

        if (! is_string($destination) || trim($destination) === '') {
            return null;
        }

        return array_replace_recursive(
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
            default => $this->payloadProviderRegistry->destinationForChannel($recipient, $channel),
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function withRecipientTokens(array $payload, Model $recipient): array
    {
        $tokens = $this->recipientTokens($recipient);

        if ($tokens === []) {
            return $payload;
        }

        return array_replace_recursive(
            [
                'tokens' => $tokens,
                'context' => [
                    $this->contextKey($recipient) => $tokens[$this->contextKey($recipient)] ?? $recipient->toArray(),
                ],
            ],
            $payload,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function recipientTokens(Model $recipient): array
    {
        if (! $recipient instanceof Contact) {
            return [];
        }

        $firstName = $this->nullableString($recipient->getAttribute('first_name'));
        $lastName = $this->nullableString($recipient->getAttribute('last_name'));
        $name = $this->nullableString($recipient->getAttribute('name'));
        $email = $this->nullableString($recipient->getAttribute('email'));
        $phone = $this->nullableString($recipient->getAttribute('phone'));
        $status = $this->nullableString($recipient->getAttribute('status'));

        $fullName = trim(implode(' ', array_filter([
            $firstName,
            $lastName,
        ])));

        if ($name === null && $fullName !== '') {
            $name = $fullName;
        }

        $contact = [
            'id' => $recipient->getKey(),
            'first_name' => $firstName,
            'last_name' => $lastName,
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
            'status' => $status,
        ];

        return [
            'first_name' => $firstName,
            'last_name' => $lastName,
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
            'contact' => $contact,
        ];
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== ''
            ? trim($value)
            : null;
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