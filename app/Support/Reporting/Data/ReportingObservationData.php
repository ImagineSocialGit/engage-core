<?php

namespace App\Support\Reporting\Data;

use DateTimeInterface;

final readonly class ReportingObservationData
{
    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $properties
     * @param array<int, string> $classificationReasons
     */
    public function __construct(
        public string $eventId,
        public string $eventKey,
        public int $eventVersion,
        public string $source,
        public DateTimeInterface $occurredAt,
        public string $host,
        public string $surface,
        public string $path,
        public array $properties = [],
        public ?string $sessionToken = null,
        public ?string $referrer = null,
        public array $query = [],
        public string $trafficClass = 'unknown',
        public ?string $classifierKey = null,
        public ?int $classifierVersion = null,
        public array $classificationReasons = [],
        public ?string $deviceClass = null,
        public ?string $browserFamily = null,
        public ?string $osFamily = null,
    ) {}
}