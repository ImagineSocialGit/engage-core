<?php

namespace App\Support\Environment;

use App\Support\Clients\ClientPackageRuntime;
use App\Support\Deployment\Contracts\DeploymentEnvironmentDefinitionContributor;
use App\Support\Environment\Data\EnvironmentVariableDefinition;
use InvalidArgumentException;

final class EnvironmentVariableCatalog
{
    /**
     * Effective Engage-owned environment catalog for the selected client.
     *
     * This catalog answers ownership/sensitivity only. Whether a specific
     * client deployment actually needs a key is resolved later by deployment
     * plan contributors from the committed client/module configuration.
     *
     * @return array<string, EnvironmentVariableDefinition>
     */
    public static function definitions(): array
    {
        $definitions = self::templateDefinitions();

        // Dynamic selected-client keys (for example, one signing key per
        // authorized external caller) belong to the enabled module that
        // consumes them. Reuse the existing deployment contributor lifecycle:
        // an installed but unselected package never registers its contributor.
        // Neither an environment value nor a requested key can register itself.
        foreach (app()->tagged('deployment.plan_contributors') as $contributor) {
            if (! $contributor instanceof DeploymentEnvironmentDefinitionContributor) {
                continue;
            }

            $owner = $contributor->owner();
            if ($owner === '' || trim($owner) !== $owner) {
                throw new InvalidArgumentException(
                    'Dynamic environment contributor returned an invalid owner.',
                );
            }

            foreach ($contributor->environmentDefinitions() as $definition) {
                if (! $definition instanceof EnvironmentVariableDefinition) {
                    throw new InvalidArgumentException(
                        "Dynamic environment contributor [{$owner}] returned an invalid definition.",
                    );
                }

                if ($definition->owner !== $owner) {
                    throw new InvalidArgumentException(
                        "Dynamic environment key [{$definition->key}] cannot be claimed by an unrelated owner.",
                    );
                }

                if (array_key_exists($definition->key, $definitions)) {
                    throw new InvalidArgumentException(
                        "Dynamic environment key [{$definition->key}] conflicts with a registered environment key.",
                    );
                }

                $definitions[$definition->key] = $definition;
            }
        }

        return $definitions;
    }

    /**
     * Static environment catalog used by reference .env templates.
     * Caller-specific keys are intentionally NOT included here: their
     * identities exist only for selected, activated capability instances.
     *
     * @return array<string, EnvironmentVariableDefinition>
     */
    public static function templateDefinitions(): array
    {
        $definitions = self::builtInDefinitions();

        foreach (
            ClientPackageRuntime::manifest()->environmentDefinitions()
            as $key => $definition
        ) {
            if (array_key_exists($key, $definitions)) {
                throw new InvalidArgumentException(
                    "Client package environment variable [{$key}] conflicts with an Engage-owned environment variable.",
                );
            }

            $definitions[$key] = $definition;
        }

        return $definitions;
    }

    /**
     * @return array<string, EnvironmentVariableDefinition>
     */
    public static function builtInDefinitions(): array
    {
        $definitions = [];

        foreach (self::specifications() as [$key, $scope, $owner, $secret]) {
            $definitions[$key] = new EnvironmentVariableDefinition(
                key: $key,
                scope: $scope,
                owner: $owner,
                secret: $secret,
            );
        }

        // Static definitions live with their Core modules, not in this central
        // catalog. Discover them without booting any module or reading env().
        foreach (ModuleEnvironmentDefinitionLoader::definitions() as $key => $definition) {
            if (isset($definitions[$key])) {
                throw new InvalidArgumentException(
                    "Module environment key [{$key}] conflicts with a registered environment key.",
                );
            }

            $definitions[$key] = $definition;
        }

        return $definitions;
    }

    /** @return array<int, string> */
    public static function builtInKeys(): array
    {
        return array_keys(self::builtInDefinitions());
    }

    public static function definition(string $key): EnvironmentVariableDefinition
    {
        $definition = self::definitions()[$key] ?? null;

        if (! $definition instanceof EnvironmentVariableDefinition) {
            throw new InvalidArgumentException(
                "Environment variable [{$key}] is not registered in the Engage environment catalog.",
            );
        }

        return $definition;
    }

    public static function has(string $key): bool
    {
        return array_key_exists($key, self::definitions());
    }

    /** @return array<int, string> */
    public static function keys(): array
    {
        return array_keys(self::definitions());
    }

    /** @return array<int, string> */
    public static function rootOwnedKeys(): array
    {
        return self::keysForScope(EnvironmentVariableDefinition::SCOPE_ROOT);
    }

    /** @return array<int, string> */
    public static function clientOwnedKeys(): array
    {
        return self::keysForScope(EnvironmentVariableDefinition::SCOPE_CLIENT);
    }

    /**
     * @return array<int, string>
     */
    private static function keysForScope(string $scope): array
    {
        return array_values(array_map(
            static fn (EnvironmentVariableDefinition $definition): string => $definition->key,
            array_filter(
                self::definitions(),
                static fn (EnvironmentVariableDefinition $definition): bool => $definition->scope === $scope,
            ),
        ));
    }

    /**
     * @return array<int, array{0:string,1:string,2:string,3:bool}>
     */
    private static function specifications(): array
    {
        $root = EnvironmentVariableDefinition::SCOPE_ROOT;
        $client = EnvironmentVariableDefinition::SCOPE_CLIENT;

        return [
            // Application/process foundation.
            ['APP_NAME', $root, 'core', false],
            ['APP_ENV', $root, 'core', false],
            ['APP_KEY', $root, 'core', true],
            ['APP_DEBUG', $root, 'core', false],
            ['CLIENT_KEY', $root, 'core', false],
            ['APP_LOCALE', $root, 'core', false],
            ['APP_FALLBACK_LOCALE', $root, 'core', false],
            ['APP_FAKER_LOCALE', $root, 'core', false],
            ['APP_MAINTENANCE_DRIVER', $root, 'core', false],
            ['APP_MAINTENANCE_STORE', $root, 'core', false],
            ['APP_PREVIOUS_KEYS', $root, 'core', true],
            ['STAGING_USER', $root, 'core', false],
            ['STAGING_PASSWORD', $root, 'core', true],
            ['STAGING_PASSWORD_HASH', $root, 'core', true],
            ['CRM_LOGIN_MAX_ATTEMPTS', $root, 'core', false],
            ['CRM_LOGIN_DECAY_SECONDS', $root, 'core', false],
            ['PUBLIC_HUMAN_VERIFICATION_PROVIDER', $root, 'core', false],
            ['PUBLIC_HUMAN_VERIFICATION_GRANT_TTL_SECONDS', $root, 'core', false],
            ['TURNSTILE_TIMEOUT_SECONDS', $root, 'core', false],
            ['TURNSTILE_CONNECT_TIMEOUT_SECONDS', $root, 'core', false],
            ['TURNSTILE_RETRY_ATTEMPTS', $root, 'core', false],
            ['TURNSTILE_RETRY_SLEEP_MILLISECONDS', $root, 'core', false],

            // Logging.
            ['LOG_CHANNEL', $root, 'core', false],
            ['LOG_STACK', $root, 'core', false],
            ['LOG_DEPRECATIONS_CHANNEL', $root, 'core', false],
            ['LOG_DEPRECATIONS_TRACE', $root, 'core', false],
            ['LOG_LEVEL', $root, 'core', false],
            ['LOG_DAILY_DAYS', $root, 'core', false],

            // Database/network infrastructure. Database identity remains client-owned.
            ['DB_CONNECTION', $root, 'core', false],
            ['DB_HOST', $root, 'core', false],
            ['DB_PORT', $root, 'core', false],
            ['DB_SOCKET', $root, 'core', false],
            ['DB_CHARSET', $root, 'core', false],
            ['DB_COLLATION', $root, 'core', false],
            ['MYSQL_ATTR_SSL_CA', $root, 'core', false],

            // Cache/session/queue/Redis process infrastructure.
            ['CACHE_STORE', $root, 'core', false],
            ['CACHE_EXTERNAL_API_RESPONSE_SECONDS', $root, 'core', false],
            ['CACHE_IMAGE_MANIFEST_SECONDS', $root, 'core', false],
            ['SESSION_DRIVER', $root, 'core', false],
            ['SESSION_LIFETIME', $root, 'core', false],
            ['SESSION_ENCRYPT', $root, 'core', false],
            ['SESSION_PATH', $root, 'core', false],
            ['SESSION_HTTP_ONLY', $root, 'core', false],
            ['SESSION_SAME_SITE', $root, 'core', false],
            ['SESSION_SECURE_COOKIE', $root, 'core', false],
            ['QUEUE_CONNECTION', $root, 'core', false],
            ['QUEUE_FAILED_DRIVER', $root, 'core', false],
            ['CONTACT_INGESTION_QUEUE', $root, 'core', false],
            ['CONTACT_ENRICHMENT_QUEUE', $root, 'core', false],
            ['REDIS_CLIENT', $root, 'core', false],
            ['REDIS_HOST', $root, 'core', false],
            ['REDIS_SCHEME', $root, 'core', false],
            ['REDIS_TLS_CA', $root, 'core', false],
            ['REDIS_PASSWORD', $root, 'core', true],
            ['REDIS_PORT', $root, 'core', false],
            ['REDIS_DB', $root, 'core', false],
            ['REDIS_CACHE_DB', $root, 'core', false],
            ['REDIS_USERNAME', $root, 'core', false],
            ['REDIS_MAX_RETRIES', $root, 'core', false],
            ['REDIS_BACKOFF_ALGORITHM', $root, 'core', false],
            ['REDIS_BACKOFF_BASE', $root, 'core', false],
            ['REDIS_BACKOFF_CAP', $root, 'core', false],
            ['FILESYSTEM_DISK', $root, 'storage', false],
            ['WEBHOOK_INBOX_CLAIM_LEASE_SECONDS', $root, 'core', false],

            // Horizon/worker process tuning.
            ['HORIZON_WAIT_THRESHOLD_DEFAULT', $root, 'core', false],
            ['HORIZON_MASTER_MEMORY_LIMIT', $root, 'core', false],
            ['HORIZON_MAX_PROCESSES', $root, 'core', false],
            ['HORIZON_MEMORY', $root, 'core', false],
            ['HORIZON_TRIES', $root, 'core', false],
            ['HORIZON_TIMEOUT', $root, 'core', false],
            ['HORIZON_PRODUCTION_MAX_PROCESSES', $root, 'core', false],
            ['HORIZON_STAGING_MAX_PROCESSES', $root, 'core', false],
            ['HORIZON_LOCAL_MAX_PROCESSES', $root, 'core', false],
            ['HORIZON_PRODUCTION_BULK_MAX_PROCESSES', $root, 'core', false],
            ['HORIZON_STAGING_BULK_MAX_PROCESSES', $root, 'core', false],
            ['HORIZON_LOCAL_BULK_MAX_PROCESSES', $root, 'core', false],
            ['HORIZON_BALANCE_MAX_SHIFT', $root, 'core', false],
            ['HORIZON_BALANCE_COOLDOWN', $root, 'core', false],
            ['HORIZON_SUPERVISOR_1_QUEUES', $root, 'core', false],
            ['HORIZON_NAME', $root, 'core', false],
            ['HORIZON_DOMAIN', $root, 'core', false],
            ['HORIZON_PATH', $root, 'core', false],

            // Selected-client URLs and database identity.
            ['APP_URL', $client, 'core', false],
            ['ROOT_DOMAIN', $client, 'core', false],
            ['CRM_APP_URL', $client, 'core', false],
            ['DB_DATABASE', $client, 'core', false],
            ['DB_USERNAME', $client, 'core', false],
            ['DB_PASSWORD', $client, 'core', true],
            ['CACHE_PREFIX', $client, 'core', false],
            ['REDIS_PREFIX', $client, 'core', false],
            ['HORIZON_PREFIX', $client, 'core', false],
            ['SESSION_DOMAIN', $client, 'core', false],
            ['PUBLIC_HUMAN_VERIFICATION_ENABLED', $client, 'core', false],
            ['TURNSTILE_SITE_KEY', $client, 'core', false],
            ['TURNSTILE_SECRET_KEY', $client, 'core', true],

            // Client storage transport/identity.
            ['DO_SPACES_KEY', $client, 'storage', true],
            ['DO_SPACES_SECRET', $client, 'storage', true],
            ['DO_SPACES_ENDPOINT', $client, 'storage', false],
            ['DO_SPACES_REGION', $client, 'storage', false],
            ['DO_SPACES_BUCKET', $client, 'storage', false],
            ['CDN_BASE_URL', $client, 'storage', false],

            // Remaining Core-owned client metadata.
            ['PROJECT_STATE_ADMIN_EMAIL', $client, 'core', false],
        ];
    }
}
