<?php

namespace App\Support\Modules\Migrations;

final readonly class ModuleMigrationBaselineBootstrapAssessment
{
    /**
     * @param array<string, string> $migrationChecksums
     */
    private function __construct(
        public bool $ready,
        public array $migrationChecksums,
        public ?string $blocker,
    ) {}

    /**
     * @param array<string, string> $migrationChecksums
     */
    public static function ready(array $migrationChecksums): self
    {
        return new self(
            ready: true,
            migrationChecksums: $migrationChecksums,
            blocker: null,
        );
    }

    public static function blocked(string $blocker): self
    {
        return new self(
            ready: false,
            migrationChecksums: [],
            blocker: $blocker,
        );
    }
}