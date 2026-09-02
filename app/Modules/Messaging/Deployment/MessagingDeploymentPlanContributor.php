<?php

namespace App\Modules\Messaging\Deployment;

use App\Support\Deployment\Contracts\DeploymentPlanContributor;
use App\Support\Deployment\Data\EnvironmentRequirement;
use App\Support\Modules\ModuleManager;
use Illuminate\Support\Env;

final class MessagingDeploymentPlanContributor implements DeploymentPlanContributor
{
    public function __construct(
        private readonly ModuleManager $modules,
    ) {}

    public function owner(): string
    {
        return 'messaging';
    }

    public function environmentRequirements(): iterable
    {
        yield from $this->generalRuntimeRequirements();
        yield from $this->emailRequirements();
        yield from $this->smsRequirements();
    }

    /** @return iterable<int, EnvironmentRequirement> */
    private function generalRuntimeRequirements(): iterable
    {
        foreach ([
            'MESSAGING_DELIVERY_CLAIM_LEASE_SECONDS',
            'MESSAGING_DELIVERY_RECOVERY_BATCH_SIZE',
            'MESSAGING_PENDING_MESSAGE_OVERDUE_GRACE_SECONDS',
            'MESSAGING_BULK_CHUNK_SIZE',
            'MESSAGING_BULK_RELEASE_INTERVAL_SECONDS',
            'MESSAGING_PROVIDER_RATE_LIMIT_CACHE_STORE',
            'EMAIL_UNSUBSCRIBE_SIGNED_URL_EXPIRATION_DAYS',
            'EMAIL_TRANSACTIONAL_OPT_OUT_SIGNED_URL_EXPIRATION_DAYS',
        ] as $key) {
            yield EnvironmentRequirement::defaulted(
                $key,
                'Messaging provides a process default; persist this key only when the deployment deliberately overrides that default.',
            );
        }

        yield EnvironmentRequirement::optional(
            'PERMISSION_INVITATION_PUBLIC_URL',
            'Set this only when permission-invitation links should use a public base URL different from APP_URL.',
        );
    }

    /** @return iterable<int, EnvironmentRequirement> */
    private function emailRequirements(): iterable
    {
        $providers = $this->configuredProviderKeys('messaging.email.providers');

        yield EnvironmentRequirement::required(
            'EMAIL_PROVIDER',
            'Messaging email delivery requires an explicit supported provider selection.',
            allowedValues: $providers,
        );

        $provider = $this->selectedString('EMAIL_PROVIDER');

        if ($provider === null || ! in_array($provider, $providers, true)) {
            return;
        }

        if ($provider !== 'resend') {
            return;
        }

        $externalTransport = $this->usesExternalTransport();

        if ($externalTransport) {
            yield EnvironmentRequirement::required(
                'MAIL_MAILER',
                'Live Resend delivery must use the configured Resend Laravel mail transport rather than the local/log fallback.',
                expectedValue: 'resend',
            );
        } else {
            yield EnvironmentRequirement::optional(
                'MAIL_MAILER',
                'Local/testing deployment planning does not require the live Laravel mail transport; local runtime delivery uses the Messaging dev sink.',
            );
        }

        if ($externalTransport && ! $this->emailPurposesHaveAddresses()) {
            yield EnvironmentRequirement::required(
                'MAIL_FROM_ADDRESS',
                'Live email delivery requires a sender address. MAIL_FROM_ADDRESS is the shared fallback when purpose-specific sender overrides do not already cover both transactional and marketing email.',
            );
        } else {
            yield EnvironmentRequirement::optional(
                'MAIL_FROM_ADDRESS',
                'Shared email sender fallback; optional when purpose-specific sender addresses already resolve or live transport is not used.',
            );
        }

        foreach ([
            'MAIL_FROM_NAME',
            'FROM_EMAIL_TRANSACTIONAL',
            'FROM_NAME_TRANSACTIONAL',
            'FROM_EMAIL_MARKETING',
            'FROM_NAME_MARKETING',
            'RESEND_FROM_EMAIL_TRANSACTIONAL',
            'RESEND_FROM_NAME_TRANSACTIONAL',
            'RESEND_FROM_EMAIL_MARKETING',
            'RESEND_FROM_NAME_MARKETING',
        ] as $key) {
            yield EnvironmentRequirement::optional(
                $key,
                'Optional Messaging email sender override; the configured fallback chain remains authoritative when omitted.',
            );
        }

        yield $externalTransport
            ? EnvironmentRequirement::required(
                'RESEND_API_KEY',
                'Live Resend email delivery requires the client Resend API key.',
            )
            : EnvironmentRequirement::optional(
                'RESEND_API_KEY',
                'Local/testing deployment planning keeps the Resend API key optional; local runtime delivery uses the Messaging dev sink.',
            );

        foreach ([
            'MESSAGING_RESEND_RATE_LIMIT_ENABLED',
            'MESSAGING_RESEND_MAX_REQUESTS_PER_SECOND',
            'MESSAGING_RESEND_RATE_LIMIT_SCOPE',
        ] as $key) {
            yield EnvironmentRequirement::defaulted(
                $key,
                'Messaging provides a Resend submission-rate default; persist only a deliberate provider-limit override.',
            );
        }

        yield $externalTransport
            ? EnvironmentRequirement::required(
                'RESEND_WEBHOOK_SECRET',
                'Live Resend delivery feedback must be signature-verified so bounce, complaint, failed-destination, and provider-suppression evidence can protect future sends.',
            )
            : EnvironmentRequirement::optional(
                'RESEND_WEBHOOK_SECRET',
                'Local/testing deployment planning keeps the live Resend webhook secret optional; local runtime delivery uses the Messaging dev sink.',
            );

        yield EnvironmentRequirement::defaulted(
            'RESEND_WEBHOOK_TIMESTAMP_DRIFT_SECONDS',
            'Resend webhook verification provides a default timestamp-drift tolerance.',
        );
    }

    /** @return iterable<int, EnvironmentRequirement> */
    private function smsRequirements(): iterable
    {
        $externalTransport = $this->usesExternalTransport();
        $inboundMessagingEnabled = $this->moduleEnabled('inbound_messaging');
        $smsAllowedValues = $externalTransport && ! $inboundMessagingEnabled
            ? ['false']
            : ['true', 'false'];

        yield EnvironmentRequirement::required(
            'SMS_ENABLED',
            $externalTransport && ! $inboundMessagingEnabled
                ? 'Live SMS is not safe without Inbound Messaging because the current provider webhook path owns STOP/HELP handling. Enable Inbound Messaging in development before deploying live SMS.'
                : 'Messaging must explicitly decide whether SMS delivery is enabled; Core does not infer this from provider credentials.',
            allowedValues: $smsAllowedValues,
        );

        if ($this->selectedBoolean('SMS_ENABLED') !== true
            || ($externalTransport && ! $inboundMessagingEnabled)
        ) {
            return;
        }

        foreach ([
            'SMS_QUEUE',
            'SMS_RATE_LIMIT_PER_IP_PER_HOUR',
            'SMS_RATE_LIMIT_PER_PHONE_PER_DAY',
            'SMS_DUPLICATE_WINDOW_MINUTES',
            'SMS_DAILY_ALERT_THRESHOLD',
            'SMS_DAILY_HARD_LIMIT',
        ] as $key) {
            yield EnvironmentRequirement::defaulted(
                $key,
                'SMS is enabled and Messaging provides a safe operational default; persist only deliberate overrides.',
            );
        }

        foreach ([
            'SMS_FROM',
            'SMS_FROM_TRANSACTIONAL',
            'SMS_FROM_MARKETING',
        ] as $key) {
            yield EnvironmentRequirement::optional(
                $key,
                'Optional provider-neutral SMS sender fallback.',
            );
        }

        $configuredProviders = $this->configuredProviderKeys('sms.providers');
        $providers = $externalTransport
            ? array_values(array_intersect($configuredProviders, ['telnyx']))
            : $configuredProviders;

        yield EnvironmentRequirement::required(
            'SMS_PROVIDER',
            $externalTransport
                ? 'Live SMS requires a provider with a currently exposed inbound webhook path for STOP/HELP and provider events; the current live-safe provider is Telnyx.'
                : 'SMS is enabled, so Messaging requires an explicit configured SMS provider selection.',
            allowedValues: $providers,
        );

        $provider = $this->selectedString('SMS_PROVIDER');

        if ($provider === null || ! in_array($provider, $providers, true)) {
            return;
        }

        if ($provider === 'telnyx') {
            yield from $this->telnyxRequirements();

            return;
        }

        if ($provider === 'twilio') {
            yield from $this->twilioRequirements();
        }
    }

    /** @return iterable<int, EnvironmentRequirement> */
    private function telnyxRequirements(): iterable
    {
        $externalTransport = $this->usesExternalTransport();

        yield $externalTransport
            ? EnvironmentRequirement::required(
                'TELNYX_API_KEY',
                'Live Telnyx SMS delivery requires the client Telnyx API key.',
            )
            : EnvironmentRequirement::optional(
                'TELNYX_API_KEY',
                'Local/testing deployment planning keeps the Telnyx API key optional; local runtime SMS uses the Messaging dev sink.',
            );

        yield EnvironmentRequirement::optional(
            'TELNYX_FROM',
            'Optional generic Telnyx sender fallback.',
        );

        yield $externalTransport && ! $this->hasConfiguredSmsSender('telnyx', 'transactional')
            ? EnvironmentRequirement::required(
                'TELNYX_FROM_TRANSACTIONAL',
                'Live Telnyx transactional SMS requires a resolved sender number.',
            )
            : EnvironmentRequirement::optional(
                'TELNYX_FROM_TRANSACTIONAL',
                'Optional Telnyx transactional sender override.',
            );

        yield $externalTransport && ! $this->hasConfiguredSmsSender('telnyx', 'marketing')
            ? EnvironmentRequirement::required(
                'TELNYX_FROM_MARKETING',
                'Live Telnyx marketing SMS requires a resolved sender number.',
            )
            : EnvironmentRequirement::optional(
                'TELNYX_FROM_MARKETING',
                'Optional Telnyx marketing sender override.',
            );

        if ($this->moduleEnabled('internal_notifications')) {
            yield EnvironmentRequirement::optional(
                'TELNYX_FROM_NOTIFICATIONS',
                'Optional Telnyx sender reserved for Internal Notifications configuration.',
            );
        }

        yield $externalTransport
            ? EnvironmentRequirement::required(
                'TELNYX_WEBHOOK_PUBLIC_KEY',
                'Live Telnyx SMS requires signature verification for both the Messaging-owned delivery-event callback and the Inbound Messaging STOP/HELP/re-opt-in path.',
            )
            : EnvironmentRequirement::optional(
                'TELNYX_WEBHOOK_PUBLIC_KEY',
                'Local/testing deployment planning keeps the live Telnyx webhook public key optional; local runtime SMS uses the Messaging dev sink.',
            );

        yield EnvironmentRequirement::defaulted(
            'TELNYX_WEBHOOK_MAX_TIMESTAMP_DRIFT_SECONDS',
            'Telnyx webhook verification provides a default timestamp-drift tolerance.',
        );
    }

    /** @return iterable<int, EnvironmentRequirement> */
    private function twilioRequirements(): iterable
    {
        $externalTransport = $this->usesExternalTransport();

        foreach (['TWILIO_SID', 'TWILIO_AUTH_TOKEN'] as $key) {
            yield $externalTransport
                ? EnvironmentRequirement::required(
                    $key,
                    'Live Twilio SMS delivery requires the selected client Twilio credentials.',
                )
                : EnvironmentRequirement::optional(
                    $key,
                    'Local/testing deployment planning keeps Twilio credentials optional; local runtime SMS uses the Messaging dev sink.',
                );
        }

        yield EnvironmentRequirement::optional(
            'TWILIO_FROM',
            'Optional generic Twilio sender fallback.',
        );

        yield $externalTransport && ! $this->hasConfiguredSmsSender('twilio', 'transactional')
            ? EnvironmentRequirement::required(
                'TWILIO_FROM_TRANSACTIONAL',
                'Live Twilio transactional SMS requires a resolved sender number.',
            )
            : EnvironmentRequirement::optional(
                'TWILIO_FROM_TRANSACTIONAL',
                'Optional Twilio transactional sender override.',
            );

        yield $externalTransport && ! $this->hasConfiguredSmsSender('twilio', 'marketing')
            ? EnvironmentRequirement::required(
                'TWILIO_FROM_MARKETING',
                'Live Twilio marketing SMS requires a resolved sender number.',
            )
            : EnvironmentRequirement::optional(
                'TWILIO_FROM_MARKETING',
                'Optional Twilio marketing sender override.',
            );
    }

    /** @return array<int, string> */
    private function configuredProviderKeys(string $configPath): array
    {
        $providers = config($configPath, []);

        if (! is_array($providers)) {
            return [];
        }

        $keys = array_values(array_filter(
            array_keys($providers),
            static fn (mixed $key): bool => is_string($key) && trim($key) !== '',
        ));

        sort($keys);

        return $keys;
    }

    private function emailPurposesHaveAddresses(): bool
    {
        foreach (['transactional', 'marketing'] as $purpose) {
            $address = config("messaging.email.providers.resend.from.{$purpose}.address")
                ?: config("messaging.email.from.{$purpose}.address");

            if (! is_string($address) || trim($address) === '') {
                return false;
            }
        }

        return true;
    }

    private function hasConfiguredSmsSender(string $provider, string $purpose): bool
    {
        $sender = config("sms.providers.{$provider}.from.{$purpose}")
            ?: config("sms.from.{$purpose}");

        return is_string($sender) && trim($sender) !== '';
    }

    private function selectedString(string $key): ?string
    {
        $value = Env::get($key);

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }

    private function selectedBoolean(string $key): ?bool
    {
        $value = Env::get($key);

        if (is_bool($value)) {
            return $value;
        }

        if (! is_string($value)) {
            return null;
        }

        return match (strtolower(trim($value))) {
            'true' => true,
            'false' => false,
            default => null,
        };
    }

    private function usesExternalTransport(): bool
    {
        return ! app()->environment(['local', 'testing']);
    }

    private function moduleEnabled(string $module): bool
    {
        return in_array(
            $module,
            $this->modules->enabledKeysWithDependencies(),
            true,
        );
    }
}