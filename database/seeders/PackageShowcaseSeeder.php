<?php

namespace Database\Seeders;

use App\Support\Modules\ModuleManager;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Discovers development-only seeders contributed by enabled package modules.
 * No imports or assumptions about individual vertical packages belong here.
 */
final class PackageShowcaseSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local', 'testing')) {
            throw new RuntimeException('Package showcase data may only be seeded in local/testing environments.');
        }

        $modules = app(ModuleManager::class);
        $seeded = [];

        foreach ($modules->enabledDefinitions() as $key => $definition) {
            if (! is_array($definition)) {
                continue;
            }

            $class = $definition['showcase_seeder'] ?? null;
            if ($class === null) {
                continue;
            }

            if (! is_string($class) || ! is_subclass_of($class, Seeder::class)) {
                throw new RuntimeException("Module [{$key}] declares an invalid showcase seeder class.");
            }

            $this->call($class);
            $seeded[] = $key;
        }

        $this->command?->info($seeded === []
            ? 'No enabled packages declare showcase seeders.'
            : 'Package showcase seeders completed: '.implode(', ', $seeded));
    }
}