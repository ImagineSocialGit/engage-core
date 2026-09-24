<?php

namespace App\Modules\Events\Data;

final readonly class EventGateDecision
{
    /**
     * @param array<int, string> $blockers
     */
    public function __construct(
        public array $blockers = [],
    ) {}

    public function allowed(): bool
    {
        return $this->blockers === [];
    }

    public function blockedBy(string $code): bool
    {
        return in_array($code, $this->blockers, true);
    }
}