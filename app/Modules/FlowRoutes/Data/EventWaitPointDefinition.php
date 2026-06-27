<?php

namespace App\Modules\FlowRoutes\Data;

class EventWaitPointDefinition
{
    /**
     * @param array<string, mixed> $correlation
     * @param array<string, mixed> $meta
     */
    public function __construct(
        public readonly ?string $expectedEvent,
        public readonly array $correlation = [],
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

        $event = $source['expected_event']
            ?? $source['event']
            ?? $source['name']
            ?? null;

        $event = is_string($event) ? trim($event) : null;

        if ($event === null || $event === '') {
            return new self(
                expectedEvent: null,
                invalidReason: 'event_wait_missing_expected_event',
                meta: self::meta($source),
            );
        }

        $correlation = $source['correlation'] ?? [];

        if (! is_array($correlation)) {
            return new self(
                expectedEvent: $event,
                invalidReason: 'event_wait_invalid_correlation',
                meta: self::meta($source),
            );
        }

        return new self(
            expectedEvent: $event,
            correlation: $correlation,
            meta: self::meta($source),
        );
    }

    public function isValid(): bool
    {
        return $this->invalidReason === null
            && is_string($this->expectedEvent)
            && trim($this->expectedEvent) !== '';
    }

    /**
     * @return array<string, mixed>
     */
    public function toMetaPayload(): array
    {
        return [
            'expected_event' => $this->expectedEvent,
            'correlation' => $this->correlation,
            'meta' => $this->meta,
        ];
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