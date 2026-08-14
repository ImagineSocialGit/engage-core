<?php

namespace App\Support\Testing;

use RuntimeException;

final class ArtisanTestDatabaseLock
{
    /**
     * @var resource|null
     */
    private $handle;

    private function __construct(
        private readonly string $database,
        private readonly string $lockFile,
        $handle,
    ) {
        $this->handle = $handle;
    }

    public static function acquire(
        string $database,
        string $projectRoot,
    ): self {
        $database = trim($database);

        if ($database === '') {
            throw new RuntimeException(
                'The test database name cannot be empty.',
            );
        }

        $lockFile = self::lockFileForDatabase($database);
        $handle = @fopen($lockFile, 'c+');

        if ($handle === false) {
            throw new RuntimeException(
                "Unable to open the Engage Core test lock file [{$lockFile}].",
            );
        }

        if (! flock($handle, LOCK_EX | LOCK_NB)) {
            rewind($handle);
            $owner = trim((string) stream_get_contents($handle));
            fclose($handle);

            $message = "Another Engage Core test run is already using [{$database}]. "
                .'Concurrent suites against the same destructive test database are not supported.';

            if ($owner !== '') {
                $message .= PHP_EOL.PHP_EOL.'Current lock owner:'.PHP_EOL.$owner;
            }

            throw new RuntimeException($message);
        }

        ftruncate($handle, 0);
        rewind($handle);

        fwrite(
            $handle,
            sprintf(
                "PID: %d\nDatabase: %s\nStarted: %s\nProject: %s\n",
                getmypid() ?: 0,
                $database,
                gmdate('Y-m-d\TH:i:s\Z'),
                $projectRoot,
            ),
        );
        fflush($handle);

        return new self(
            database: $database,
            lockFile: $lockFile,
            handle: $handle,
        );
    }

    public function database(): string
    {
        return $this->database;
    }

    public function lockFile(): string
    {
        return $this->lockFile;
    }

    public function release(): void
    {
        if (! is_resource($this->handle)) {
            return;
        }

        flock($this->handle, LOCK_UN);
        fclose($this->handle);

        $this->handle = null;
    }

    public function __destruct()
    {
        $this->release();
    }

    private static function lockFileForDatabase(string $database): string
    {
        return rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
            .DIRECTORY_SEPARATOR
            .'engage-core-tests-'
            .substr(hash('sha256', $database), 0, 24)
            .'.lock';
    }
}