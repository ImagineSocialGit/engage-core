<?php

namespace App\Support\Modules\Migrations;

final readonly class ModuleMigrationPreflightResult
{
    /**
     * @param array<int, ModuleMigrationStatus> $statuses
     * @param array<int, string> $blockers
     * @param array<int, string> $warnings
     */
    public function __construct(
        public array $statuses,
        public array $blockers,
        public array $warnings,
    ) {}

    public function safe(): bool
    {
        return $this->blockers === [];
    }

    public function summary(): string
    {
        return $this->safe()
            ? 'Module migration preflight passed.'
            : implode(' ', $this->blockers);
    }
}