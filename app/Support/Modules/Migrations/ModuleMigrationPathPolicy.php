<?php

namespace App\Support\Modules\Migrations;

final class ModuleMigrationPathPolicy
{
    public function __construct(
        private readonly ModuleMigrationRegistry $registry,
    ) {}

    /**
     * Migration paths registered during normal application bootstrap.
     *
     * @return array<int, string>
     */
    public function runtimeStartupPaths(): array
    {
        return [
            $this->registry->platform()->path,
        ];
    }

    /**
     * Complete non-vertical module schema used only by the test bootstrap.
     *
     * @return array<int, string>
     */
    public function completeTestModulePaths(): array
    {
        $paths = [];

        foreach ($this->registry->modules() as $scope) {
            if (! str_starts_with(
                $scope->path,
                'database/migrations/modules/',
            )) {
                continue;
            }

            $paths[] = $scope->path;
        }

        return array_values(array_unique($paths));
    }
}