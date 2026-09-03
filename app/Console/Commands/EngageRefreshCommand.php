<?php

namespace App\Console\Commands;

use App\Console\Concerns\InteractsWithDeploymentPlan;
use App\Support\Deployment\DeploymentPlanResolver;
use Illuminate\Console\Command;
use Throwable;

final class EngageRefreshCommand extends Command
{
    use InteractsWithDeploymentPlan;
    protected $signature = 'engage:refresh
        {--modules= : Comma-separated module keys passed to engage:install}
        {--preset= : Optional preset package key passed to engage:install}
        {--force : Skip the destructive confirmation prompt; non-disposable environments are still refused}';

    protected $description = 'Destroy and rebuild a disposable Engage Core database from the current create migrations and client configuration.';

    public function handle(DeploymentPlanResolver $deploymentPlanResolver): int
    {
        $environment = $this->laravel->environment();

        if (! in_array($environment, ['local', 'testing'], true)) {
            $this->error(
                "Refusing database refresh in environment [{$environment}]. "
                .'engage:refresh is allowed only in local or testing environments.',
            );

            return self::FAILURE;
        }

        if (! $this->deploymentEnvironmentIsReady(
            resolver: $deploymentPlanResolver,
            operation: 'Database refresh',
        )) {
            return self::FAILURE;
        }

        $connection = trim((string) config('database.default'));
        $database = $connection === ''
            ? ''
            : trim((string) config("database.connections.{$connection}.database"));
        $clientKey = trim((string) config('client.key'));

        $this->info('Engage Core database refresh');
        $this->line('Environment: '.($environment !== '' ? $environment : '[empty]'));
        $this->line('Client:      '.($clientKey !== '' ? $clientKey : '[none]'));
        $this->line('Connection:  '.($connection !== '' ? $connection : '[empty]'));
        $this->line('Database:    '.($database !== '' ? $database : '[empty]'));

        $this->newLine();
        $this->warn(
            'This drops every table, view, and Laravel migration-history row '
            .'in the selected database.',
        );
        $this->line(
            'It then rebuilds the platform schema, configured module schema, '
            .'presets, and setup validation.',
        );
        $this->line(
            'CRM users are not recreated automatically. Redis is not cleared.',
        );

        if (! $this->confirmed()) {
            $this->line('Database refresh cancelled.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('[1/2] Database wipe');

        try {
            $exitCode = $this->call('db:wipe', [
                '--force' => true,
            ]);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return $this->stageFailure('database wipe');
        }

        if ($exitCode !== self::SUCCESS) {
            return $this->stageFailure('database wipe');
        }

        $this->newLine();
        $this->info('[2/2] Engage installation');

        try {
            $exitCode = $this->call(
                'engage:install',
                $this->installArguments(),
            );
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return $this->stageFailure('Engage installation');
        }

        if ($exitCode !== self::SUCCESS) {
            return $this->stageFailure('Engage installation');
        }

        $this->newLine();
        $this->info('Engage database refresh completed successfully.');
        $this->line(
            'Run [php artisan engage:user:add] if this environment needs a CRM login.',
        );

        return self::SUCCESS;
    }

    private function confirmed(): bool
    {
        if ((bool) $this->option('force')) {
            return true;
        }

        $confirmation = trim((string) $this->ask(
            'Type REFRESH DATABASE to continue',
        ));

        return $confirmation === 'REFRESH DATABASE';
    }

    /**
     * @return array<string, string|bool>
     */
    private function installArguments(): array
    {
        $arguments = [
            '--force' => true,
            '--no-create-user' => true,
        ];

        $modules = $this->option('modules');

        if (is_string($modules) && trim($modules) !== '') {
            $arguments['--modules'] = trim($modules);
        }

        $preset = $this->option('preset');

        if (is_string($preset) && trim($preset) !== '') {
            $arguments['--preset'] = trim($preset);
        }

        return $arguments;
    }

    private function stageFailure(string $stage): int
    {
        $this->newLine();
        $this->error("Engage database refresh failed during [{$stage}].");

        return self::FAILURE;
    }
}