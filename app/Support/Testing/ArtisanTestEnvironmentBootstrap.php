<?php

namespace App\Support\Testing;

use DOMDocument;
use DOMXPath;
use RuntimeException;

final class ArtisanTestEnvironmentBootstrap
{
    public static function prepare(
        ?string $command,
        ?string $basePath = null,
    ): ?ArtisanTestDatabaseLock {
        if ($command !== 'test') {
            return null;
        }

        self::setEnvironmentValue('APP_ENV', 'testing');

        $basePath ??= dirname(__DIR__, 3);

        return ArtisanTestDatabaseLock::acquire(
            self::resolveTestDatabase($basePath),
            $basePath,
        );
    }

    private static function resolveTestDatabase(string $basePath): string
    {
        $phpUnitDatabase = self::phpUnitDatabaseConfiguration($basePath);
        $environmentDatabase = getenv('DB_DATABASE');

        if (
            $phpUnitDatabase !== null
            && $phpUnitDatabase['force']
        ) {
            return $phpUnitDatabase['value'];
        }

        if (
            is_string($environmentDatabase)
            && trim($environmentDatabase) !== ''
        ) {
            return trim($environmentDatabase);
        }

        if ($phpUnitDatabase !== null) {
            return $phpUnitDatabase['value'];
        }

        throw new RuntimeException(
            'Unable to determine the test database before Laravel bootstrap. '
            .'Define DB_DATABASE in phpunit.xml/phpunit.xml.dist or export DB_DATABASE before running php artisan test.',
        );
    }

    /**
     * @return array{value: string, force: bool}|null
     */
    private static function phpUnitDatabaseConfiguration(
        string $basePath,
    ): ?array {
        foreach (['phpunit.xml', 'phpunit.xml.dist'] as $filename) {
            $path = rtrim($basePath, DIRECTORY_SEPARATOR)
                .DIRECTORY_SEPARATOR
                .$filename;

            if (! is_file($path)) {
                continue;
            }

            $document = new DOMDocument();

            $previous = libxml_use_internal_errors(true);

            try {
                $loaded = $document->load(
                    $path,
                    LIBXML_NONET,
                );
            } finally {
                libxml_clear_errors();
                libxml_use_internal_errors($previous);
            }

            if (! $loaded) {
                throw new RuntimeException(
                    "Unable to parse PHPUnit configuration [{$path}] while preparing the test database lock.",
                );
            }

            $xpath = new DOMXPath($document);
            $nodes = $xpath->query(
                '/phpunit/php/env[@name="DB_DATABASE"]',
            );

            if ($nodes === false || $nodes->length === 0) {
                continue;
            }

            $node = $nodes->item(0);

            if ($node === null) {
                continue;
            }

            $value = trim($node->attributes?->getNamedItem('value')?->nodeValue ?? '');

            if ($value === '') {
                throw new RuntimeException(
                    "PHPUnit configuration [{$path}] defines an empty DB_DATABASE value.",
                );
            }

            $force = strtolower(
                trim($node->attributes?->getNamedItem('force')?->nodeValue ?? ''),
            );

            return [
                'value' => $value,
                'force' => in_array($force, ['1', 'true', 'yes'], true),
            ];
        }

        return null;
    }

    private static function setEnvironmentValue(
        string $key,
        string $value,
    ): void {
        putenv("{$key}={$value}");

        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
}