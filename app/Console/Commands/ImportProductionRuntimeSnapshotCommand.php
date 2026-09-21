<?php

namespace App\Console\Commands;

use App\Support\TestingTools\ProductionRuntimeSnapshotImporter;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

final class ImportProductionRuntimeSnapshotCommand extends Command
{
    protected $signature = 'dev:production-snapshot:import
        {path : Absolute path or path relative to the application root}
        {--user-email= : Local CRM user that should receive production User references}
        {--dry-run : Validate the snapshot and target database without writing rows}
        {--confirm= : Type IMPORT to apply the snapshot}';

    protected $description = 'One-off local-only loader for definition-aware production runtime JSONL snapshots used to reproduce real client state safely in DEV.';

    public function handle(ProductionRuntimeSnapshotImporter $importer): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('This command is available only in local or testing environments.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');

        if (! $dryRun && $this->option('confirm') !== 'IMPORT') {
            $this->error('Refusing to import. Re-run with --confirm=IMPORT after a successful --dry-run.');

            return self::FAILURE;
        }

        $path = $this->resolvePath((string) $this->argument('path'));
        $userEmail = $this->option('user-email');
        $userEmail = is_string($userEmail) && trim($userEmail) !== ''
            ? trim($userEmail)
            : null;

        try {
            $result = $importer->import(
                path: $path,
                localUserEmail: $userEmail,
                dryRun: $dryRun,
            );
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info($dryRun
            ? 'Production runtime snapshot dry-run passed.'
            : 'Production runtime snapshot imported into DEV.');

        $this->table(
            ['Item', 'Value'],
            [
                ['Source database', $result['source_database'] ?? 'unknown'],
                ['Source generated at', $result['source_generated_at'] ?? 'unknown'],
                ['Source client', $result['source_client_key'] ?? 'not present in snapshot rows'],
                ['Snapshot tables', (string) $result['tables_seen']],
                ['Runtime tables', (string) $result['runtime_tables']],
                ['Runtime rows', number_format((int) $result['runtime_rows'])],
                ['Mapped config/reference rows', number_format((int) $result['mapped_reference_rows'])],
                ['Replayed production definition rows', number_format((int) $result['replay_rows'])],
                ['Message-chain enrollments isolated from background execution', number_format((int) $result['isolated_message_chain_enrollments'])],
                ['Pending/sending ScheduledMessages held', number_format((int) $result['held_scheduled_messages'])],
            ],
        );

        if ($result['warnings'] !== []) {
            $this->newLine();
            $this->warn('Review-only reconstruction warnings:');

            foreach ($result['warnings'] as $warning) {
                $this->line('  - '.$warning);
            }
        }

        if (! $dryRun) {
            $this->newLine();
            $this->info('Imported runtime row counts');

            $this->table(
                ['Table', 'Rows'],
                collect($result['imported_counts'])
                    ->map(fn (int $count, string $table): array => [$table, number_format($count)])
                    ->values()
                    ->all(),
            );

            $this->newLine();
            $this->warn('This is a review snapshot. Do not convert testing:production_snapshot MessageChain enrollments back to normal surfaces or enable real provider credentials in this DEV copy.');
        } else {
            $this->newLine();
            $this->line('Dry-run made no database changes. Apply only after reviewing the warnings:');
            $this->line('  php artisan dev:production-snapshot:import '.$this->displayPath($path).' --confirm=IMPORT'.($userEmail ? ' --user-email='.$userEmail : ''));
        }

        return self::SUCCESS;
    }

    private function resolvePath(string $path): string
    {
        $path = trim($path);

        if ($path === '') {
            throw new RuntimeException('A snapshot path is required.');
        }

        if (str_starts_with($path, DIRECTORY_SEPARATOR)) {
            return $path;
        }

        return base_path($path);
    }

    private function displayPath(string $path): string
    {
        $base = rtrim(base_path(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        return str_starts_with($path, $base)
            ? substr($path, strlen($base))
            : $path;
    }
}