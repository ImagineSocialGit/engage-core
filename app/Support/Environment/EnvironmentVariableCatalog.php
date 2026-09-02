<?php

namespace App\Support\Environment;

use App\Support\Environment\Data\EnvironmentVariableDefinition;
use InvalidArgumentException;

final class EnvironmentVariableCatalog
{
    /**
     * Exhaustive Engage-owned environment catalog.
     *
     * This catalog answers ownership/sensitivity only. Whether a specific
     * client deployment actually needs a key is resolved later by deployment
     * plan contributors from the committed client/module configuration.
     *
     * @return array<string, EnvironmentVariableDefinition>
     */
    public static function definitions(): array
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

        return $definitions;
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
            ['CRM_LOGIN_MAX_ATTEMPTS', $root, 'core', false],
            ['CRM_LOGIN_DECAY_SECONDS', $root, 'core', false],

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
            ['CACHE_NEXT_UPCOMING_WEBINAR_EMPTY_SECONDS', $root, 'webinars', false],
            ['CACHE_NEXT_UPCOMING_WEBINAR_MIN_SECONDS', $root, 'webinars', false],
            ['CACHE_ACTIVE_WEBINAR_SERIES_MIN_SECONDS', $root, 'webinars', false],
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
            ['FLOW_ROUTE_CONTINUATION_QUEUE', $root, 'flow_routes', false],
            ['WEBINAR_REGISTRATION_QUEUE', $root, 'webinars', false],
            ['WEBINAR_WEBHOOK_QUEUE', $root, 'webinars', false],
            ['WEBINAR_REMINDER_QUEUE', $root, 'webinars', false],
            ['WEBINAR_CONFIRMATION_MESSAGE_QUEUE', $root, 'webinars', false],
            ['WEBINAR_FOLLOWUP_QUEUE', $root, 'webinars', false],
            ['SMS_QUEUE', $root, 'messaging', false],
            ['MESSAGING_DELIVERY_CLAIM_LEASE_SECONDS', $root, 'messaging', false],
            ['MESSAGING_DELIVERY_RECOVERY_BATCH_SIZE', $root, 'messaging', false],
            ['MESSAGING_PENDING_MESSAGE_OVERDUE_GRACE_SECONDS', $root, 'messaging', false],
            ['MESSAGING_BULK_CHUNK_SIZE', $root, 'messaging', false],
            ['MESSAGING_BULK_RELEASE_INTERVAL_SECONDS', $root, 'messaging', false],
            ['MESSAGING_PROVIDER_RATE_LIMIT_CACHE_STORE', $root, 'messaging', false],
            ['MESSAGING_RESEND_RATE_LIMIT_ENABLED', $root, 'messaging', false],
            ['MESSAGING_RESEND_MAX_REQUESTS_PER_SECOND', $root, 'messaging', false],
            ['MESSAGING_RESEND_RATE_LIMIT_SCOPE', $root, 'messaging', false],
            ['FLOW_ROUTE_IMMEDIATE_EXECUTION_BUDGET', $root, 'flow_routes', false],
            ['REDIS_CLIENT', $root, 'core', false],
            ['REDIS_HOST', $root, 'core', false],
            ['REDIS_PASSWORD', $root, 'core', true],
            ['REDIS_PORT', $root, 'core', false],
            ['REDIS_DB', $root, 'core', false],
            ['REDIS_CACHE_DB', $root, 'core', false],
            ['REDIS_URL', $root, 'core', true],
            ['REDIS_USERNAME', $root, 'core', false],
            ['REDIS_MAX_RETRIES', $root, 'core', false],
            ['REDIS_BACKOFF_ALGORITHM', $root, 'core', false],
            ['REDIS_BACKOFF_BASE', $root, 'core', false],
            ['REDIS_BACKOFF_CAP', $root, 'core', false],
            ['FILESYSTEM_DISK', $root, 'storage', false],
            ['WEBHOOK_INBOX_CLAIM_LEASE_SECONDS', $root, 'core', false],

            // Forms abuse controls are process-owned; client identity/secret is client-owned below.
            ['FORMS_EXTERNAL_INTAKE_MAX_BODY_BYTES', $root, 'forms', false],
            ['FORMS_EXTERNAL_INTAKE_MAX_TIMESTAMP_DRIFT_SECONDS', $root, 'forms', false],
            ['FORMS_EXTERNAL_INTAKE_NONCE_TTL_SECONDS', $root, 'forms', false],
            ['FORMS_EXTERNAL_INTAKE_UNAUTHENTICATED_RATE_LIMIT_PER_MINUTE', $root, 'forms', false],
            ['FORMS_EXTERNAL_INTAKE_CLIENT_RATE_LIMIT_PER_MINUTE', $root, 'forms', false],

            // Messaging operational controls.
            ['RESEND_WEBHOOK_TIMESTAMP_DRIFT_SECONDS', $root, 'messaging', false],
            ['EMAIL_UNSUBSCRIBE_SIGNED_URL_EXPIRATION_DAYS', $root, 'messaging', false],
            ['EMAIL_TRANSACTIONAL_OPT_OUT_SIGNED_URL_EXPIRATION_DAYS', $root, 'messaging', false],
            ['TELNYX_WEBHOOK_MAX_TIMESTAMP_DRIFT_SECONDS', $root, 'messaging', false],
            ['SMS_RATE_LIMIT_PER_IP_PER_HOUR', $root, 'messaging', false],
            ['SMS_RATE_LIMIT_PER_PHONE_PER_DAY', $root, 'messaging', false],
            ['SMS_DUPLICATE_WINDOW_MINUTES', $root, 'messaging', false],
            ['SMS_DAILY_ALERT_THRESHOLD', $root, 'messaging', false],
            ['SMS_DAILY_HARD_LIMIT', $root, 'messaging', false],

            // Webinar/Zoom process controls.
            ['ZOOM_BASE_URL', $root, 'webinars', false],
            ['ZOOM_OAUTH_URL', $root, 'webinars', false],
            ['ZOOM_OAUTH_TOKEN_TTL_SECONDS', $root, 'webinars', false],
            ['ZOOM_WEBHOOK_MAX_TIMESTAMP_DRIFT_SECONDS', $root, 'webinars', false],

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
            ['WEBINAR_APP_URL', $client, 'webinars', false],
            ['CRM_APP_URL', $client, 'core', false],
            ['SCHEDULING_APP_URL', $client, 'scheduling', false],
            ['DB_DATABASE', $client, 'core', false],
            ['DB_USERNAME', $client, 'core', false],
            ['DB_PASSWORD', $client, 'core', true],
            ['CACHE_PREFIX', $client, 'core', false],
            ['REDIS_PREFIX', $client, 'core', false],
            ['HORIZON_PREFIX', $client, 'core', false],
            ['SESSION_DOMAIN', $client, 'core', false],

            // Client storage transport/identity.
            ['DO_SPACES_KEY', $client, 'storage', true],
            ['DO_SPACES_SECRET', $client, 'storage', true],
            ['DO_SPACES_ENDPOINT', $client, 'storage', false],
            ['DO_SPACES_REGION', $client, 'storage', false],
            ['DO_SPACES_BUCKET', $client, 'storage', false],
            ['CDN_BASE_URL', $client, 'storage', false],

            // Messaging/provider/sender identity.
            ['MAIL_MAILER', $client, 'messaging', false],
            ['MAIL_FROM_ADDRESS', $client, 'messaging', false],
            ['MAIL_FROM_NAME', $client, 'messaging', false],
            ['EMAIL_PROVIDER', $client, 'messaging', false],
            ['FROM_EMAIL_TRANSACTIONAL', $client, 'messaging', false],
            ['FROM_NAME_TRANSACTIONAL', $client, 'messaging', false],
            ['FROM_EMAIL_MARKETING', $client, 'messaging', false],
            ['FROM_NAME_MARKETING', $client, 'messaging', false],
            ['RESEND_API_KEY', $client, 'messaging', true],
            ['RESEND_WEBHOOK_SECRET', $client, 'messaging', true],
            ['INBOUND_EMAIL_DOMAIN', $client, 'inbound_messaging', false],
            ['RESEND_FROM_EMAIL_TRANSACTIONAL', $client, 'messaging', false],
            ['RESEND_FROM_NAME_TRANSACTIONAL', $client, 'messaging', false],
            ['RESEND_FROM_EMAIL_MARKETING', $client, 'messaging', false],
            ['RESEND_FROM_NAME_MARKETING', $client, 'messaging', false],
            ['PERMISSION_INVITATION_PUBLIC_URL', $client, 'messaging', false],
            ['INTERNAL_NOTIFICATION_FROM_ADDRESS', $client, 'internal_notifications', false],
            ['INTERNAL_NOTIFICATION_FROM_NAME', $client, 'internal_notifications', false],
            ['INBOUND_REPLY_DEFAULT_TEAM_MEMBER_EMAIL', $client, 'inbound_messaging', false],
            ['PROJECT_STATE_ADMIN_EMAIL', $client, 'core', false],

            // External Forms intake.
            ['FORMS_EXTERNAL_INTAKE_ENABLED', $client, 'forms', false],
            ['FORMS_EXTERNAL_INTAKE_CLIENT_ID', $client, 'forms', false],
            ['FORMS_EXTERNAL_INTAKE_CLIENT_SECRET', $client, 'forms', true],
            ['FORMS_EXTERNAL_INTAKE_SOURCE', $client, 'forms', false],
            ['FORMS_EXTERNAL_INTAKE_PROVIDER', $client, 'forms', false],
            ['FORMS_EXTERNAL_INTAKE_ALLOWED_FORMS', $client, 'forms', false],
            ['FORMS_EXTERNAL_INTAKE_DOMAINS', $client, 'forms', false],

            // SMS provider selection and credentials.
            ['SMS_ENABLED', $client, 'messaging', false],
            ['SMS_PROVIDER', $client, 'messaging', false],
            ['SMS_FROM', $client, 'messaging', false],
            ['SMS_FROM_TRANSACTIONAL', $client, 'messaging', false],
            ['SMS_FROM_MARKETING', $client, 'messaging', false],
            ['TELNYX_API_KEY', $client, 'messaging', true],
            ['TELNYX_FROM', $client, 'messaging', false],
            ['TELNYX_FROM_TRANSACTIONAL', $client, 'messaging', false],
            ['TELNYX_FROM_MARKETING', $client, 'messaging', false],
            ['TELNYX_FROM_NOTIFICATIONS', $client, 'messaging', false],
            ['TELNYX_WEBHOOK_PUBLIC_KEY', $client, 'messaging', false],
            ['MESSAGING_SMS_MARKETING_PROFILE_ID', $client, 'messaging', false],
            ['MESSAGING_SMS_TRANSACTIONAL_PROFILE_ID', $client, 'messaging', false],
            ['TWILIO_SID', $client, 'messaging', false],
            ['TWILIO_AUTH_TOKEN', $client, 'messaging', true],
            ['TWILIO_FROM', $client, 'messaging', false],
            ['TWILIO_FROM_TRANSACTIONAL', $client, 'messaging', false],
            ['TWILIO_FROM_MARKETING', $client, 'messaging', false],
            ['TWILIO_VIRTUAL_PHONE', $client, 'messaging', false],

            // Webinar provider selection and credentials.
            ['WEBINAR_PROVIDER', $client, 'webinars', false],
            ['ZOOM_ACCOUNT_ID', $client, 'webinars', false],
            ['ZOOM_CLIENT_ID', $client, 'webinars', false],
            ['ZOOM_CLIENT_SECRET', $client, 'webinars', true],
            ['ZOOM_WEBHOOK_SECRET', $client, 'webinars', true],
        ];
    }
}