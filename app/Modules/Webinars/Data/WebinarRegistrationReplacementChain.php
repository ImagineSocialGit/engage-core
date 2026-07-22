<?php

namespace App\Modules\Webinars\Data;

use App\Modules\Webinars\Models\WebinarRegistration;

final readonly class WebinarRegistrationReplacementChain
{
    /**
     * @param array<int, int> $traversedRegistrationIds
     */
    public function __construct(
        public WebinarRegistration $original,
        public WebinarRegistration $canonical,
        public array $traversedRegistrationIds,
        public bool $unresolvedReplacement = false,
        public bool $cancelled = false,
        public bool $cycleDetected = false,
        public bool $seriesBoundaryViolated = false,
        public bool $contactBoundaryViolated = false,
        public bool $occurrenceBoundaryViolated = false,
    ) {}

    public function safeForPublicLifecycle(): bool
    {
        return ! $this->cycleDetected
            && ! $this->seriesBoundaryViolated
            && ! $this->contactBoundaryViolated
            && ! $this->occurrenceBoundaryViolated;
    }
}