<?php

namespace App\Modules\Messaging\Services;

use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Data\MessageData;
use App\Modules\Messaging\Enums\MessageChannel;
use DateTimeInterface;
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

        $mergedPayload = $this->withCanonicalTokens(
            payload: array_replace_recursive(
                $definitionPayload,
                $payload,
            ),
            recipient: $recipient,
        );

        $destination = $this->explicitDestination($mergedPayload, $channel)
            ?? $this->destinationForChannel($recipient, $channel);

        if (! is_string($destination) || trim($destination) === '') {
            return null;
        }

        return $this->sanitizeSendPayload(array_replace_recursive(
            $mergedPayload,
            ['to' => trim($destination)],
        ));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function conditionContext(Model $recipient, ?Model $context, array $payload): array
    {
        $conditionContext = [
            $this->contextKey($recipient) => $this->modelContext($recipient),
        ];

        if ($context) {
            $conditionContext[$this->contextKey($context)] = $this->modelContext($context);
        }

        return array_replace_recursive(
            is_array($payload['tokens'] ?? null) ? $payload['tokens'] : [],
            $conditionContext,
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
    private function withCanonicalTokens(array $payload, Model $recipient): array
    {
        $tokens = array_replace_recursive(
            $this->recipientTokens($recipient),
            is_array($payload['tokens'] ?? null)
                ? $payload['tokens']
                : [],
        );

        unset($payload['tokens']);

        if ($recipient instanceof Contact) {
            $payload['contact_id'] = $recipient->getKey();
        }

        if ($tokens !== []) {
            $payload['tokens'] = $tokens;
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function recipientTokens(Model $recipient): array
    {
        if (! $recipient instanceof Contact) {
            return [];
        }

        return $this->compactContactTokens((new MessageData($recipient))->toArray());
    }

    /**
     * @param array<string, mixed> $tokens
     * @return array<string, mixed>
     */
    private function compactContactTokens(array $tokens): array
    {
        $compact = [];

        foreach (['contact_id', 'first_name', 'last_name', 'name', 'email', 'phone', 'source', 'subsource', 'status'] as $key) {
            if (array_key_exists($key, $tokens)) {
                $compact[$key] = $this->sanitizeScalarValue($tokens[$key]);
            }
        }

        $compact['contact'] = [];

        if (is_array($tokens['contact'] ?? null)) {
            foreach (['id', 'first_name', 'last_name', 'name', 'email', 'phone', 'source', 'subsource', 'status'] as $key) {
                if (array_key_exists($key, $tokens['contact'])) {
                    $compact['contact'][$key] = $this->sanitizeScalarValue($tokens['contact'][$key]);
                }
            }
        }

        foreach ([
            'id' => 'contact_id',
            'first_name' => 'first_name',
            'last_name' => 'last_name',
            'name' => 'name',
            'email' => 'email',
            'phone' => 'phone',
            'source' => 'source',
            'subsource' => 'subsource',
            'status' => 'status',
        ] as $contactKey => $tokenKey) {
            if (! array_key_exists($contactKey, $compact['contact']) && array_key_exists($tokenKey, $compact)) {
                $compact['contact'][$contactKey] = $compact[$tokenKey];
            }
        }

        return array_filter($compact, fn (mixed $value): bool => $value !== null && $value !== []);
    }

    /**
     * @return array<string, mixed>
     */
    private function modelContext(Model $model): array
    {
        return $model->toArray();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function explicitDestination(array $payload, string $channel): ?string
    {
        $keys = match ($channel) {
            MessageChannel::Email->value => ['to', 'email', 'contact_email'],
            MessageChannel::Sms->value => ['to', 'phone', 'contact_phone'],
            default => ['to'],
        };

        foreach ($keys as $key) {
            $value = $payload[$key] ?? null;

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function sanitizeSendPayload(array $payload): array
    {
        $sanitized = [];

        foreach ($payload as $key => $value) {
            if (! is_string($key) || trim($key) === '') {
                continue;
            }

            $value = $this->sanitizePayloadValue($value);

            if ($value !== null) {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }

    private function sanitizePayloadValue(mixed $value): mixed
    {
        if ($value instanceof Model) {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format(DateTimeInterface::ATOM);
        }

        if (is_scalar($value) || $value === null) {
            return $value;
        }

        if (! is_array($value)) {
            return null;
        }

        $sanitized = [];

        foreach ($value as $key => $item) {
            if (! is_int($key) && ! is_string($key)) {
                continue;
            }

            $item = $this->sanitizePayloadValue($item);

            if ($item !== null) {
                $sanitized[$key] = $item;
            }
        }

        return $sanitized;
    }

    private function sanitizeScalarValue(mixed $value): mixed
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format(DateTimeInterface::ATOM);
        }

        return is_scalar($value) || $value === null ? $value : null;
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