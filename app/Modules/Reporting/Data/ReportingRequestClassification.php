<?php

namespace App\Modules\Reporting\Data;

final readonly class ReportingRequestClassification
{
    /**
     * @param array<int, string> $reasons
     */
    public function __construct(
        public string $trafficClass,
        public string $classifierKey,
        public int $classifierVersion,
        public array $reasons = [],
        public ?string $deviceClass = null,
        public ?string $browserFamily = null,
        public ?string $osFamily = null,
    ) {}
}