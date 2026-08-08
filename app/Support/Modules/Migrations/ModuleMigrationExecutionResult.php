<?php

namespace App\Support\Modules\Migrations;

use InvalidArgumentException;

final readonly class ModuleMigrationExecutionResult
{
    public const OUTCOME_CURRENT = 'current';

    public const OUTCOME_TRACKED = 'tracked';

    public const OUTCOME_MIGRATED = 'migrated';

    public const OUTCOME_UPDATED = 'updated';

    public const OUTCOME_RECONCILED = 'reconciled';

    /**
     * @param array<string, array{outcome: string, ran_migrations: int}> $scopeResults
     */
    public function __construct(
        public ModuleMigrationPlan $plan,
        public array $scopeResults,
    ) {}

    /**
     * @return array<int, string>
     */
    public function moduleKeys(): array
    {
        return array_keys($this->scopeResults);
    }

    public function outcome(string $moduleKey): string
    {
        return $this->scopeResult($moduleKey)['outcome'];
    }

    public function ranMigrationCount(string $moduleKey): int
    {
        return $this->scopeResult($moduleKey)['ran_migrations'];
    }

    public function totalRanMigrationCount(): int
    {
        return array_sum(array_map(
            static fn (array $result): int => $result['ran_migrations'],
            $this->scopeResults,
        ));
    }

    public function countOutcome(string $outcome): int
    {
        return count(array_filter(
            $this->scopeResults,
            static fn (array $result): bool => $result['outcome'] === $outcome,
        ));
    }

    /**
     * @return array{outcome: string, ran_migrations: int}
     */
    private function scopeResult(string $moduleKey): array
    {
        $result = $this->scopeResults[$moduleKey] ?? null;

        if (! is_array($result)) {
            throw new InvalidArgumentException(
                "Module migration result does not contain scope [{$moduleKey}].",
            );
        }

        return $result;
    }
}