<?php

namespace App\Modules\FlowRoutes\Data\Points;

class SendMessagePointDefinition
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
        public readonly ?string $onNoMessages = null,
        public readonly ?string $invalidReason = null,
        public readonly array $meta = [],
    ) {}

    /**
     * @param array<string, mixed> $definition
     * @param array<string, mixed> $settings
     */
    public static function from(array $definition, array $settings = []): self
    {
        $source = array_replace_recursive($definition, $settings);

        $channel = self::string($source, 'channel');

        if ($channel === null) {
            return self::invalid($source, 'send_message_missing_channel');
        }

        $purpose = self::string($source, 'purpose');

        if ($purpose === null) {
            return self::invalid($source, 'send_message_missing_purpose');
        }

        $scope = self::string($source, 'scope');

        if ($scope === null) {
            return self::invalid($source, 'send_message_missing_scope');
        }

        $dispatchKeys = self::dispatchKeys($source);

        if ($dispatchKeys === []) {
            return self::invalid($source, 'send_message_missing_dispatch_keys');
        }

        $payload = $source['payload'] ?? [];
        $criteria = $source['criteria'] ?? [];

        return new self(
            channel: $channel,
            purpose: $purpose,
            scope: $scope,
            dispatchKeys: $dispatchKeys,
            payload: is_array($payload) ? $payload : [],
            criteria: is_array($criteria) ? $criteria : [],
            anchor: $source['anchor'] ?? null,
            onNoMessages: self::string($source, 'on_no_messages') ?? 'skipped',
            meta: self::meta($source),
        );
    }

    public function isValid(): bool
    {
        return $this->invalidReason === null
            && $this->channel !== null
            && $this->purpose !== null
            && $this->scope !== null
            && $this->dispatchKeys !== [];
    }

    /**
     * @return array<string, mixed>
     */
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

    /**
     * @param array<string, mixed> $source
     */
    private static function invalid(array $source, string $reason): self
    {
        return new self(
            channel: self::string($source, 'channel'),
            purpose: self::string($source, 'purpose'),
            scope: self::string($source, 'scope'),
            dispatchKeys: self::dispatchKeys($source),
            payload: is_array($source['payload'] ?? null) ? $source['payload'] : [],
            criteria: is_array($source['criteria'] ?? null) ? $source['criteria'] : [],
            anchor: $source['anchor'] ?? null,
            onNoMessages: self::string($source, 'on_no_messages') ?? 'skipped',
            invalidReason: $reason,
            meta: self::meta($source),
        );
    }

    /**
     * @param array<string, mixed> $source
     */
    private static function string(array $source, string $key): ?string
    {
        $value = $source[$key] ?? null;

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }

    /**
     * @param array<string, mixed> $source
     * @return array<int, string>
     */
    private static function dispatchKeys(array $source): array
    {
        $dispatchKeys = $source['dispatch_keys']
            ?? $source['dispatch_key']
            ?? [];

        if (is_string($dispatchKeys)) {
            $dispatchKeys = [$dispatchKeys];
        }

        if (! is_array($dispatchKeys)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            fn (mixed $dispatchKey): ?string => is_string($dispatchKey) && trim($dispatchKey) !== ''
                ? trim($dispatchKey)
                : null,
            $dispatchKeys,
        ))));
    }

    /**
     * @param array<string, mixed> $source
     * @return array<string, mixed>
     */
    private static function meta(array $source): array
    {
        $meta = $source['meta'] ?? [];

        return is_array($meta) ? $meta : [];
    }
}