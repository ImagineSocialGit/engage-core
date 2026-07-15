<?php

namespace App\Modules\Messaging\Data\Automation;

class SendMessageAutomationDefinition
{
    /**
     * @param array<int, string> $dispatchKeys
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $criteria
     * @param array<string, mixed> $meta
     */
    public function __construct(
        public readonly ?string $channel,
        public readonly ?string $purpose,
        public readonly ?string $scope,
        public readonly array $dispatchKeys = [],
        public readonly array $payload = [],
        public readonly array $criteria = [],
        public readonly mixed $anchor = null,
        public readonly string $onNoMessages = 'skipped',
        public readonly ?string $invalidReason = null,
        public readonly array $meta = [],
    ) {}

    /** @param array<string, mixed> $input */
    public static function from(array $input): self
    {
        $channel = self::string($input, 'channel');
        $purpose = self::string($input, 'purpose');
        $scope = self::string($input, 'scope');
        $dispatchKeys = self::dispatchKeys($input);

        $invalidReason = match (true) {
            $channel === null => 'send_message_missing_channel',
            $purpose === null => 'send_message_missing_purpose',
            $scope === null => 'send_message_missing_scope',
            $dispatchKeys === [] => 'send_message_missing_dispatch_keys',
            default => null,
        };

        return new self(
            channel: $channel,
            purpose: $purpose,
            scope: $scope,
            dispatchKeys: $dispatchKeys,
            payload: is_array($input['payload'] ?? null) ? $input['payload'] : [],
            criteria: is_array($input['criteria'] ?? null) ? $input['criteria'] : [],
            anchor: $input['anchor'] ?? null,
            onNoMessages: self::string($input, 'on_no_messages') ?? 'skipped',
            invalidReason: $invalidReason,
            meta: is_array($input['meta'] ?? null) ? $input['meta'] : [],
        );
    }

    public function isValid(): bool
    {
        return $this->invalidReason === null;
    }

    /** @return array<string, mixed> */
    public function toMetaPayload(): array
    {
        return [
            'channel' => $this->channel,
            'purpose' => $this->purpose,
            'scope' => $this->scope,
            'dispatch_keys' => $this->dispatchKeys,
            'payload' => $this->payload,
            'criteria' => $this->criteria,
            'anchor' => $this->anchor,
            'on_no_messages' => $this->onNoMessages,
            'meta' => $this->meta,
        ];
    }

    /** @param array<string, mixed> $input */
    private static function string(array $input, string $key): ?string
    {
        $value = $input[$key] ?? null;

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<int, string>
     */
    private static function dispatchKeys(array $input): array
    {
        $dispatchKeys = $input['dispatch_keys'] ?? $input['dispatch_key'] ?? [];

        if (is_string($dispatchKeys)) {
            $dispatchKeys = [$dispatchKeys];
        }

        if (! is_array($dispatchKeys)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $dispatchKey): ?string => is_string($dispatchKey) && trim($dispatchKey) !== ''
                ? trim($dispatchKey)
                : null,
            $dispatchKeys,
        ))));
    }
}
