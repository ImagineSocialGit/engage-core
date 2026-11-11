<?php

namespace App\Support\Deployment\Credentials;

use App\Support\Deployment\EnvironmentFileRepository;
use App\Support\Environment\Data\EnvironmentVariableDefinition;
use RuntimeException;
use Throwable;

/** Client-scoped, non-secret connection metadata. It never contains signing keys. */
final readonly class CoreInternalCallerRegistry
{
    public const ENVIRONMENT_KEY = 'INTERNAL_PLATFORM_CALLERS';

    /** @param list<array<string, string>> $callers */
    private function __construct(
        public string $clientKey,
        public string $environment,
        private array $callers,
    ) {}

    public static function decode(?string $encoded, string $client, string $environment): self
    {
        try {
            self::validateContext($client, $environment);
            if ($encoded === null || $encoded === '') {
                return new self($client, $environment, []);
            }
            if (strlen($encoded) > 65536 || preg_match('/\A[A-Za-z0-9_-]+\z/D', $encoded) !== 1) {
                throw new RuntimeException();
            }
            $json = base64_decode(strtr($encoded, '-_', '+/').str_repeat('=', (4 - strlen($encoded) % 4) % 4), true);
            if (! is_string($json)) {
                throw new RuntimeException();
            }
            $data = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
            if (! is_array($data) || self::keys($data) !== ['callers', 'client_key', 'environment', 'schema']
                || ($data['schema'] ?? null) !== 1 || ($data['client_key'] ?? null) !== $client
                || ($data['environment'] ?? null) !== $environment
                || ! is_array($data['callers'] ?? null) || ! array_is_list($data['callers'])
                || count($data['callers']) > 128
            ) {
                throw new RuntimeException();
            }
            $result = new self($client, $environment, []);
            foreach ($data['callers'] as $record) {
                if (! is_array($record)
                    || self::keys($record) !== ['caller_id', 'purpose', 'source_client', 'source_origin', 'source_platform']
                    || count(array_filter($record, 'is_string')) !== 5
                    || $record['caller_id'] !== $record['source_client']
                ) {
                    throw new RuntimeException();
                }
                $next = $result->withCaller($record['purpose'], $record['source_platform'], $record['source_client'], $record['source_origin']);
                if (count($next->callers) === count($result->callers)) {
                    throw new RuntimeException();
                }
                $result = $next;
            }
            // Canonical encoding rejects duplicate JSON names, reordered records,
            // hidden extra fields and alternative encodings of the same metadata.
            if (! hash_equals($result->encode(), $encoded)) {
                throw new RuntimeException();
            }

            return $result;
        } catch (Throwable) {
            throw new RuntimeException('internal_caller_registry_invalid');
        }
    }

    /** Read from the selected private file; no bootstrap env() dependency. */
    public static function selected(?EnvironmentFileRepository $files = null): ?self
    {
        if (! app()->bound('env') || app()->environment('testing')) {
            return null;
        }
        try {
            $files ??= new EnvironmentFileRepository();
            $client = $files->inspectScope(EnvironmentVariableDefinition::SCOPE_CLIENT);
            $root = $files->inspectScope(EnvironmentVariableDefinition::SCOPE_ROOT);
            if ($client['issues'] !== [] || $root['issues'] !== []
                || array_key_exists(self::ENVIRONMENT_KEY, $root['values'])
            ) {
                throw new RuntimeException();
            }

            return self::decode($client['values'][self::ENVIRONMENT_KEY] ?? null,
                (string) config('client.key'), app()->environment());
        } catch (Throwable) {
            throw new RuntimeException('internal_caller_registry_unavailable');
        }
    }

    public function withCaller(string $purpose, string $sourcePlatform, string $sourceClient, string $sourceOrigin): self
    {
        if (preg_match('/\A[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)+\z/D', $purpose) !== 1
            || ! in_array($sourcePlatform, ['seo', 'artist', 'label'], true)
            || preg_match('/\A[a-z][a-z0-9_-]{0,127}\z/D', $sourceClient) !== 1
            || ! self::validOrigin($sourceOrigin, $this->environment)
        ) {
            throw new RuntimeException('internal_caller_metadata_invalid');
        }
        $record = ['caller_id' => $sourceClient, 'purpose' => $purpose,
            'source_client' => $sourceClient, 'source_origin' => $sourceOrigin, 'source_platform' => $sourcePlatform];
        $identity = self::identity($record);
        $records = [];
        foreach ($this->callers as $existing) {
            if (self::identity($existing) === $identity) {
                if ($existing !== $record) {
                    throw new RuntimeException('internal_caller_metadata_conflict');
                }
                return $this;
            }
            $records[self::identity($existing)] = $existing;
        }
        if (count($records) >= 128) {
            throw new RuntimeException('internal_caller_registry_full');
        }
        $records[$identity] = $record;
        ksort($records, SORT_STRING);

        return new self($this->clientKey, $this->environment, array_values($records));
    }

    /** @return array<string, array<string, string>> */
    public function forPurpose(string $purpose, string $sourcePlatform): array
    {
        $result = [];
        foreach ($this->callers as $record) {
            if ($record['purpose'] === $purpose && $record['source_platform'] === $sourcePlatform) {
                $result[$record['caller_id']] = $record;
            }
        }

        return $result;
    }

    public function encode(): string
    {
        $json = json_encode(['callers' => $this->callers, 'client_key' => $this->clientKey,
            'environment' => $this->environment, 'schema' => 1], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $encoded = rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
        if (strlen($encoded) > 65536) {
            throw new RuntimeException('internal_caller_registry_full');
        }

        return $encoded;
    }

    /** @param array<string, mixed> $data @return list<string> */
    private static function keys(array $data): array
    {
        $keys = array_keys($data);
        sort($keys, SORT_STRING);

        return $keys;
    }

    /** @param array<string, string> $record */
    private static function identity(array $record): string
    {
        return implode("\0", [$record['purpose'], $record['source_platform'], $record['caller_id']]);
    }

    private static function validateContext(string $client, string $environment): void
    {
        if (preg_match('/\A[a-z][a-z0-9_-]{0,127}\z/D', $client) !== 1
            || ! in_array($environment, ['local', 'staging', 'production'], true)
        ) {
            throw new RuntimeException();
        }
    }

    private static function validOrigin(string $origin, string $environment): bool
    {
        $parts = parse_url($origin);

        return is_array($parts) && trim($origin) === $origin && ! str_contains($origin, '\\')
            && in_array($parts['scheme'] ?? null, $environment === 'local' ? ['http', 'https'] : ['https'], true)
            && is_string($parts['host'] ?? null)
            && filter_var($parts['host'], FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false
            && array_intersect(['user', 'pass', 'port', 'path', 'query', 'fragment'], array_keys($parts)) === [];
    }
}