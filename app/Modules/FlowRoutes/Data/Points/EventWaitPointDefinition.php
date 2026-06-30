<?php

namespace App\Modules\FlowRoutes\Data\Points;

class EventWaitPointDefinition
{
    /**
     * @param array<string, mixed> $correlation
     * @param array<string, mixed> $meta
     */
    public function __construct(
        public readonly ?string $eventKey,
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

        $eventKey = self::string($source, 'event_key');

        if ($eventKey === null) {
            return new self(
                eventKey: null,
                invalidReason: 'event_wait_missing_event_key',
                meta: self::meta($source),
            );
        }

        $correlation = $source['correlation'] ?? [];

        if (! is_array($correlation)) {
            return new self(
                eventKey: $eventKey,
                invalidReason: 'event_wait_invalid_correlation',
                meta: self::meta($source),
            );
        }

        return new self(
            eventKey: $eventKey,
            correlation: $correlation,
            meta: self::meta($source),
        );
    }

    public function isValid(): bool
    {
        return $this->invalidReason === null
            && is_string($this->eventKey)
            && trim($this->eventKey) !== '';
    }

    /**
     * @return array<string, mixed>
     */
    public function toMetaPayload(): array
    {
        return [
            'event_key' => $this->eventKey,
            'correlation' => $this->correlation,
            'meta' => $this->meta,
        ];
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
     * @return array<string, mixed>
     */
    private static function meta(array $source): array
    {
        $meta = $source['meta'] ?? [];

        return is_array($meta) ? $meta : [];
    }
}