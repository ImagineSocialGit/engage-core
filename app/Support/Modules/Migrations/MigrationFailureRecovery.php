<?php

namespace App\Support\Modules\Migrations;

use Illuminate\Database\Events\MigrationEnded;
use Illuminate\Database\Events\MigrationStarted;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Migrations\Migrator;
use ReflectionClass;
use RuntimeException;
use Throwable;

final class MigrationFailureRecovery
{
    private bool $tracking = false;

    private ?Migration $candidate = null;

    private ?string $candidateName = null;

    public function __construct(
        private readonly Migrator $migrator,
    ) {}

    public function begin(): void
    {
        $this->tracking = true;
        $this->candidate = null;
        $this->candidateName = null;
    }

    public function migrationStarted(MigrationStarted $event): void
    {
        if (! $this->tracking || $event->method !== 'up') {
            return;
        }

        $this->candidate = $event->migration;
        $this->candidateName = $this->migrationName($event->migration);
    }

    public function migrationEnded(MigrationEnded $event): void
    {
        if (! $this->tracking
            || $event->method !== 'up'
            || ! $this->candidate instanceof Migration
            || $event->migration !== $this->candidate
        ) {
            return;
        }

        $this->candidate = null;
        $this->candidateName = null;
    }

    public function recover(): ?string
    {
        if (! $this->tracking || ! $this->candidate instanceof Migration) {
            return null;
        }

        $migration = $this->candidate;
        $migrationName = $this->candidateName;

        if ($migrationName === null) {
            throw new RuntimeException(
                'Automatic cleanup could not determine the identity of the partially applied migration.',
            );
        }

        if (in_array(
            $migrationName,
            $this->migrator->getRepository()->getRan(),
            true,
        )) {
            return null;
        }

        $connection = $this->migrator->resolveConnection(
            $migration->getConnection(),
        );
        $grammar = $connection->getSchemaGrammar();

        if ($grammar === null) {
            $connection->useDefaultSchemaGrammar();
            $grammar = $connection->getSchemaGrammar();
        }

        if ($grammar === null) {
            throw new RuntimeException(
                "Automatic cleanup could not resolve the schema grammar for partially applied migration [{$migrationName}].",
            );
        }

        if ($grammar->supportsSchemaTransactions()
            && $migration->withinTransaction
        ) {
            return $migrationName;
        }

        if (! method_exists($migration, 'down')) {
            throw new RuntimeException(
                "Partially applied migration [{$migrationName}] does not define a down() method for automatic cleanup.",
            );
        }

        try {
            $this->migrator->usingConnection(
                $migration->getConnection(),
                static function () use ($migration): void {
                    $migration->down();
                },
            );
        } catch (Throwable $exception) {
            throw new RuntimeException(
                "Automatic cleanup failed for partially applied migration [{$migrationName}]: {$exception->getMessage()}",
                previous: $exception,
            );
        }

        return $migrationName;
    }

    public function finish(): void
    {
        $this->tracking = false;
        $this->candidate = null;
        $this->candidateName = null;
    }

    private function migrationName(Migration $migration): ?string
    {
        $filename = (new ReflectionClass($migration))->getFileName();

        if (! is_string($filename) || trim($filename) === '') {
            return null;
        }

        return $this->migrator->getMigrationName($filename);
    }
}