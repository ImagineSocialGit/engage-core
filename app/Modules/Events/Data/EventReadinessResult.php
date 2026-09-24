<?php

namespace App\Modules\Events\Data;

final readonly class EventReadinessResult
{
    /**
     * @param array<int, EventReadinessFinding> $findings
     */
    public function __construct(
        public string $capability,
        public array $findings,
    ) {}

    public function ready(): bool
    {
        return $this->findings === [];
    }

    /**
     * @return array<int, string>
     */
    public function codes(): array
    {
        return array_values(array_unique(array_map(
            static fn (EventReadinessFinding $finding): string => $finding->code,
            $this->findings,
        )));
    }

    public function has(string $code): bool
    {
        return in_array($code, $this->codes(), true);
    }
}