<?php

namespace App\Support\Clients;

use App\Support\Environment\EnvironmentVariableCatalog;
use Dotenv\Dotenv;
use Illuminate\Support\Env;
use RuntimeException;

final class ClientEnvironmentLoader
{
    public function load(string $basePath, bool $deferUnknownKeys = false): void
    {
        $clientKey = $this->clientKey();

        if ($clientKey === null) {
            return;
        }

        if (! preg_match('/^[a-z0-9][a-z0-9_-]*$/', $clientKey)) {
            throw new RuntimeException(
                "CLIENT_KEY [{$clientKey}] contains invalid characters."
            );
        }

        /*
         * Clear every legal client-owned key before looking for the selected
         * client's environment file. This prevents stale root/process values or
         * values from a previously selected client from leaking through even
         * when the newly selected client does not have a .env file yet.
         */
        foreach (self::clientOwnedKeys() as $key) {
            $this->clearEnvironmentValue($key);
        }

        $clientDirectory = rtrim($basePath, DIRECTORY_SEPARATOR)
            .DIRECTORY_SEPARATOR.'client'
            .DIRECTORY_SEPARATOR.$clientKey;

        $environmentPath = $clientDirectory.DIRECTORY_SEPARATOR.'.env';

        if (! is_file($environmentPath)) {
            return;
        }

        if (! is_readable($environmentPath)) {
            throw new RuntimeException(sprintf(
                'Selected client environment [%s] is not readable by the current process.',
                $environmentPath,
            ));
        }

        $values = Dotenv::createArrayBacked(
            $clientDirectory,
            '.env',
        )->safeLoad();

        $allowed = self::clientOwnedKeys();
        $unsupportedKeys = array_values(array_diff(array_keys($values), $allowed));

        // During early bootstrap only statically owned values can be injected.
        // Root-owned keys are always invalid, even in the deferred pass. Unknown
        // values remain private in this local map until strict post-registration
        // ownership validation; they never become process environment inputs.
        if ($deferUnknownKeys) {
            $rootOwned = EnvironmentVariableCatalog::rootOwnedKeys();
            $rejected = array_values(array_intersect($unsupportedKeys, $rootOwned));
        } else {
            $rejected = $unsupportedKeys;
        }

        sort($rejected);

        if ($rejected !== []) {
            throw new RuntimeException(sprintf(
                'Client environment [%s] contains root-owned or unsupported key(s): %s.',
                $environmentPath,
                implode(', ', $rejected),
            ));
        }

        foreach ($values as $key => $value) {
            if (in_array($key, $allowed, true)) {
                $this->setEnvironmentValue($key, $value);
            } else {
                // A stale inherited unknown value must not act as a fallback.
                $this->clearEnvironmentValue($key);
            }
        }
    }

    /**
     * Bootstrap-safe client ownership authority.
     *
     * @return array<int, string>
     */
    public static function clientOwnedKeys(): array
    {
        return EnvironmentVariableCatalog::clientOwnedKeys();
    }

    private function clientKey(): ?string
    {
        $clientKey = Env::get('CLIENT_KEY');

        if (! is_string($clientKey)) {
            return null;
        }

        $clientKey = trim($clientKey);

        return $clientKey !== ''
            ? $clientKey
            : null;
    }

    private function clearEnvironmentValue(string $key): void
    {
        putenv($key);

        unset($_ENV[$key], $_SERVER[$key]);
    }

    private function setEnvironmentValue(string $key, string $value): void
    {
        putenv("{$key}={$value}");

        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
}