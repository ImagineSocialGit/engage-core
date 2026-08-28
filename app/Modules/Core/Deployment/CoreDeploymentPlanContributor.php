<?php

namespace App\Modules\Core\Deployment;

use App\Support\Deployment\Contracts\DeploymentPlanContributor;
use App\Support\Deployment\Data\EnvironmentRequirement;
use Illuminate\Support\Env;

final class CoreDeploymentPlanContributor implements DeploymentPlanContributor
{
    public function owner(): string
    {
        return 'core';
    }

    public function environmentRequirements(): iterable
    {
        // Runtime identity and encryption must always be explicit.
        yield EnvironmentRequirement::required(
            'APP_ENV',
            'The runtime environment must be explicit so development-only and production-only safety rules cannot depend on Laravel defaults.',
        );
        yield EnvironmentRequirement::required(
            'APP_KEY',
            'Laravel encryption and signed runtime state require an application key.',
        );
        yield EnvironmentRequirement::required(
            'CLIENT_KEY',
            'The persisted active client must match the client selected by the current committed build/runtime.',
            expectedValue: (string) config('client.key', ''),
        );

        // Shared machine/process settings have safe Core defaults and should not
        // be copied into real .env files unless an environment intentionally overrides them.
        foreach ([
            'APP_NAME',
            'APP_DEBUG',
            'APP_LOCALE',
            'APP_FALLBACK_LOCALE',
            'APP_FAKER_LOCALE',
            'APP_MAINTENANCE_DRIVER',
            'APP_MAINTENANCE_STORE',
            'APP_PREVIOUS_KEYS',
            'STAGING_USER',
            'STAGING_PASSWORD',
            'CRM_LOGIN_MAX_ATTEMPTS',
            'CRM_LOGIN_DECAY_SECONDS',
            'LOG_CHANNEL',
            'LOG_STACK',
            'LOG_DEPRECATIONS_CHANNEL',
            'LOG_DEPRECATIONS_TRACE',
            'LOG_LEVEL',
            'LOG_DAILY_DAYS',
            'DB_CONNECTION',
            'DB_HOST',
            'DB_PORT',
            'DB_SOCKET',
            'DB_CHARSET',
            'DB_COLLATION',
            'MYSQL_ATTR_SSL_CA',
            'CACHE_STORE',
            'CACHE_EXTERNAL_API_RESPONSE_SECONDS',
            'CACHE_IMAGE_MANIFEST_SECONDS',
            'SESSION_DRIVER',
            'SESSION_LIFETIME',
            'SESSION_ENCRYPT',
            'SESSION_PATH',
            'SESSION_HTTP_ONLY',
            'SESSION_SAME_SITE',
            'SESSION_SECURE_COOKIE',
            'QUEUE_CONNECTION',
            'QUEUE_FAILED_DRIVER',
            'CONTACT_INGESTION_QUEUE',
            'CONTACT_ENRICHMENT_QUEUE',
            'REDIS_CLIENT',
            'REDIS_HOST',
            'REDIS_PASSWORD',
            'REDIS_PORT',
            'REDIS_DB',
            'REDIS_CACHE_DB',
            'REDIS_URL',
            'REDIS_USERNAME',
            'REDIS_MAX_RETRIES',
            'REDIS_BACKOFF_ALGORITHM',
            'REDIS_BACKOFF_BASE',
            'REDIS_BACKOFF_CAP',
            'WEBHOOK_INBOX_CLAIM_LEASE_SECONDS',
            'HORIZON_WAIT_THRESHOLD_DEFAULT',
            'HORIZON_MASTER_MEMORY_LIMIT',
            'HORIZON_MAX_PROCESSES',
            'HORIZON_MEMORY',
            'HORIZON_TRIES',
            'HORIZON_TIMEOUT',
            'HORIZON_PRODUCTION_MAX_PROCESSES',
            'HORIZON_STAGING_MAX_PROCESSES',
            'HORIZON_LOCAL_MAX_PROCESSES',
            'HORIZON_PRODUCTION_BULK_MAX_PROCESSES',
            'HORIZON_STAGING_BULK_MAX_PROCESSES',
            'HORIZON_LOCAL_BULK_MAX_PROCESSES',
            'HORIZON_BALANCE_MAX_SHIFT',
            'HORIZON_BALANCE_COOLDOWN',
            'HORIZON_SUPERVISOR_1_QUEUES',
            'HORIZON_NAME',
            'HORIZON_DOMAIN',
            'HORIZON_PATH',
        ] as $key) {
            yield EnvironmentRequirement::defaulted(
                $key,
                'Core provides a default; persist this key only when the deployment intentionally overrides that default.',
            );
        }

        // Client deployment identity should be explicit because it must remain
        // isolated from other clients sharing the same Core checkout/services.
        foreach ([
            'ROOT_DOMAIN' => 'The client public root domain anchors generated URLs and domain policy.',
            'APP_URL' => 'The client application origin must be explicit for generated links.',
            'CRM_APP_URL' => 'The CRM origin must be explicit; Engage does not assume a crm. hostname.',
            'DB_DATABASE' => 'Each client deployment requires an explicit database identity.',
            'DB_USERNAME' => 'Each client deployment requires an explicit database user.',
            'DB_PASSWORD' => 'Each client deployment requires database credentials.',
            'CACHE_PREFIX' => 'Client cache keys must be isolated when infrastructure is shared.',
            'REDIS_PREFIX' => 'Client Redis keys must be isolated when infrastructure is shared.',
            'HORIZON_PREFIX' => 'Client Horizon keys must be isolated when infrastructure is shared.',
        ] as $key => $reason) {
            yield EnvironmentRequirement::required($key, $reason);
        }

        foreach ([
            'SESSION_DOMAIN',
            'PROJECT_STATE_ADMIN_EMAIL',
        ] as $key) {
            yield EnvironmentRequirement::optional(
                $key,
                'This is a client-owned Core override and is only needed when the corresponding optional behavior is intentionally enabled.',
            );
        }
    }
}