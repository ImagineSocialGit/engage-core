<?php

namespace App\Console\Commands;

use App\Support\Modules\Migrations\ModuleMigrationExecutionResult;
use App\Support\Modules\Migrations\ModuleMigrationExecutor;
use App\Support\Modules\Migrations\ModuleMigrationPlan;
use App\Support\Modules\Migrations\ModuleMigrationPlanner;
use App\Support\Modules\Migrations\ModuleMigrationRegistry;
use App\Support\Modules\ModuleManager;
use App\Support\Users\CrmUserManager;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

final class EngageInstallCommand extends Command
{
    use ConfirmableTrait;

    protected $signature = 'engage:install
        {--modules= : Comma-separated module keys; defaults to configured enabled schema-owning modules}
        {--preset= : Optional preset package key passed to presets:sync}
        {--create-user : Create the first CRM user after successful installation}
        {--no-create-user : Skip the post-install CRM user prompt}
        {--force : Force the operation to run in production}';

    protected $description = 'Install platform schema, selected module schema, presets, setup validation, and optional CRM user onboarding for a client.';

    public function handle(
        ModuleManager $modules,
        ModuleMigrationRegistry $registry,
        ModuleMigrationPlanner $planner,
        ModuleMigrationExecutor $executor,
        CrmUserManager $users,
    ): int {
        try {
            $this->assertUserOptionsAreValid();

            $requestedModuleKeys = $this->requestedModuleKeys(
                modules: $modules,
                registry: $registry,
            );
            $plan = $planner->forModules($requestedModuleKeys);

            $this->assertSelectionCoversConfiguredSchema(
                plan: $plan,
                modules: $modules,
                registry: $registry,
            );
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if (! $this->confirmToProceed()) {
            return self::FAILURE;
        }

        $this->line('Requested modules: '.implode(
            ', ',
            $plan->requestedModuleKeys,
        ));
        $this->line('Resolved modules: '.implode(
            ', ',
            $plan->dependencyOrderedModuleKeys,
        ));

        $this->newLine();
        $this->info('[1/4] Platform migrations');

        try {
            $exitCode = $this->call('migrate', [
                '--path' => [$registry->platform()->path],
                '--force' => true,
            ]);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return $this->stageFailure('platform migrations');
        }

        if ($exitCode !== self::SUCCESS) {
            return $this->stageFailure('platform migrations');
        }

        $this->newLine();
        $this->info('[2/4] Module installation');

        try {
            $result = $executor->execute($plan);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return $this->stageFailure('module installation');
        }

        $this->renderModuleResult($result);

        $this->newLine();
        $this->info('[3/4] Preset synchronization');

        try {
            $exitCode = $this->call(
                'presets:sync',
                $this->presetArguments(),
            );
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return $this->stageFailure('preset synchronization');
        }

        if ($exitCode !== self::SUCCESS) {
            return $this->stageFailure('preset synchronization');
        }

        $this->newLine();
        $this->info('[4/4] Setup validation');

        try {
            $exitCode = $this->call('setup:validate');
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return $this->stageFailure('setup validation');
        }

        if ($exitCode !== self::SUCCESS) {
            return $this->stageFailure('setup validation');
        }

        if ($this->shouldCreateUser()) {
            $exitCode = $this->createInitialUser($users);

            if ($exitCode !== self::SUCCESS) {
                return $exitCode;
            }
        } else {
            $this->newLine();
            $this->line(
                'CRM user creation skipped. Run [php artisan engage:user:add] when ready.',
            );
        }

        $this->newLine();
        $this->info('Engage installation completed successfully.');

        return self::SUCCESS;
    }

    /**
     * @return array<int, string>
     */
    private function requestedModuleKeys(
        ModuleManager $modules,
        ModuleMigrationRegistry $registry,
    ): array {
        $option = $this->option('modules');

        if (is_string($option) && trim($option) !== '') {
            $requested = $this->parseModuleOption($option);
        } else {
            $requested = array_values(array_filter(
                $modules->enabledKeysWithDependencies(),
                static fn (string $moduleKey): bool => $registry->hasModule($moduleKey),
            ));
        }

        return array_values(array_unique([
            'core',
            ...$requested,
        ]));
    }

    /**
     * @return array<int, string>
     */
    private function parseModuleOption(string $option): array
    {
        $parts = preg_split('/,/', trim($option));

        if (! is_array($parts) || $parts === []) {
            throw new InvalidArgumentException(
                'Installer --modules must contain at least one module key.',
            );
        }

        $moduleKeys = [];

        foreach ($parts as $index => $part) {
            $moduleKey = trim($part);

            if ($moduleKey === '') {
                throw new InvalidArgumentException(
                    "Installer --modules contains an empty module key at position [{$index}].",
                );
            }

            if (! in_array($moduleKey, $moduleKeys, true)) {
                $moduleKeys[] = $moduleKey;
            }
        }

        return $moduleKeys;
    }

    private function assertSelectionCoversConfiguredSchema(
        ModuleMigrationPlan $plan,
        ModuleManager $modules,
        ModuleMigrationRegistry $registry,
    ): void {
        $configuredSchemaModuleKeys = array_values(array_filter(
            $modules->enabledKeysWithDependencies(),
            static fn (string $moduleKey): bool => $registry->hasModule($moduleKey),
        ));
        $missingModuleKeys = array_values(array_diff(
            $configuredSchemaModuleKeys,
            $plan->migrationModuleKeys(),
        ));

        if ($missingModuleKeys === []) {
            return;
        }

        throw new InvalidArgumentException(sprintf(
            'Installer module selection does not cover configured enabled schema scopes: [%s]. Include them in --modules or update client module configuration before installation.',
            implode(', ', $missingModuleKeys),
        ));
    }

    private function assertUserOptionsAreValid(): void
    {
        $createUser = (bool) $this->option('create-user');
        $noCreateUser = (bool) $this->option('no-create-user');

        if ($createUser && $noCreateUser) {
            throw new InvalidArgumentException(
                'Installer options --create-user and --no-create-user are mutually exclusive.',
            );
        }

        if ($createUser && ! $this->input->isInteractive()) {
            throw new InvalidArgumentException(
                'Installer --create-user requires interactive input for the password. Run interactively or use --no-create-user and create the user later with engage:user:add.',
            );
        }
    }

    private function shouldCreateUser(): bool
    {
        if ((bool) $this->option('create-user')) {
            return true;
        }

        if ((bool) $this->option('no-create-user')) {
            return false;
        }

        if (! $this->input->isInteractive()) {
            return false;
        }

        $this->newLine();

        return $this->confirm(
            'Create a CRM user now?',
            true,
        );
    }

    private function createInitialUser(CrmUserManager $users): int
    {
        $this->newLine();
        $this->info('CRM user creation');

        $name = trim((string) $this->ask('Name'));
        $email = trim((string) $this->ask('Email'));
        $password = (string) $this->secret('Password');
        $confirmation = (string) $this->secret('Confirm password');

        if ($password !== $confirmation) {
            $this->error('Password confirmation does not match.');

            return $this->userCreationFailure();
        }

        try {
            $user = $users->create(
                name: $name,
                email: $email,
                password: $password,
            );
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $messages) {
                foreach ($messages as $message) {
                    $this->error($message);
                }
            }

            return $this->userCreationFailure();
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return $this->userCreationFailure();
        }

        $this->info("CRM user [{$user->email}] created.");

        return self::SUCCESS;
    }

    private function userCreationFailure(): int
    {
        $this->newLine();
        $this->error(
            'Installation stages completed, but CRM user creation failed.',
        );
        $this->line(
            'Correct the reported user input and run [php artisan engage:user:add]. Do not rerun destructive setup solely to recreate a login.',
        );

        return self::FAILURE;
    }

    /**
     * @return array<string, string>
     */
    private function presetArguments(): array
    {
        $preset = $this->option('preset');

        if (! is_string($preset) || trim($preset) === '') {
            return [];
        }

        return [
            'preset' => trim($preset),
        ];
    }

    private function renderModuleResult(
        ModuleMigrationExecutionResult $result,
    ): void {
        if ($result->scopeResults === []) {
            $this->line('The resolved module closure owns no registered schema.');

            return;
        }

        $this->table(
            ['Module', 'Result', 'Migrations run'],
            array_map(
                fn (string $moduleKey): array => [
                    $moduleKey,
                    $result->outcome($moduleKey),
                    $result->ranMigrationCount($moduleKey),
                ],
                $result->moduleKeys(),
            ),
        );

        $this->line(sprintf(
            'Module installation stage completed. %d migration(s) ran.',
            $result->totalRanMigrationCount(),
        ));
    }

    private function stageFailure(string $stage): int
    {
        $this->newLine();
        $this->error("Engage installation failed during [{$stage}].");
        $this->line(
            'Correct the reported problem and rerun the same command; completed installation stages are designed to be idempotent.',
        );

        return self::FAILURE;
    }
}