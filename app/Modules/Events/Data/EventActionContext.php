<?php

namespace App\Modules\Events\Data;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;

final readonly class EventActionContext
{
    /**
     * @param array<string, mixed> $meta
     */
    public function __construct(
        public string $source,
        public ?string $reason = null,
        public ?CarbonInterface $occurredAt = null,
        public array $meta = [],
    ) {
        if (trim($source) === '') {
            throw new InvalidArgumentException('Event action source is required.');
        }

        if ($reason !== null && trim($reason) === '') {
            throw new InvalidArgumentException('Event action reason must be null or non-empty.');
        }

        if (array_is_list($meta) && $meta !== []) {
            throw new InvalidArgumentException('Event action metadata must be a map.');
        }
    }

    public function sourceKey(): string
    {
        return trim($this->source);
    }

    public function reasonValue(): ?string
    {
        return $this->reason !== null ? trim($this->reason) : null;
    }

    public function occurredAtValue(): CarbonImmutable
    {
        if ($this->occurredAt === null) {
            return CarbonImmutable::now('UTC');
        }

        return CarbonImmutable::instance($this->occurredAt);
    }
}