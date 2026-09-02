<?php

namespace App\Modules\Webinars\Deployment;

use App\Support\Deployment\Contracts\DeploymentPlanContributor;
use App\Support\Deployment\Data\EnvironmentRequirement;

final class WebinarsDeploymentPlanContributor implements DeploymentPlanContributor
{
    public function owner(): string
    {
        return 'webinars';
    }

    public function environmentRequirements(): iterable
    {
        yield from $this->generalRuntimeRequirements();

        $providers = $this->configuredProviderKeys();

        yield EnvironmentRequirement::defaulted(
            'WEBINAR_PROVIDER',
            'Webinars defaults to Zoom; persist this selector only when the client deliberately overrides the configured provider.',
            allowedValues: $providers,
        );

        $provider = $this->selectedProvider();

        if ($provider === null || ! in_array($provider, $providers, true)) {
            return;
        }

        if ($provider === 'zoom') {
            yield from $this->zoomRequirements();
        }
    }

    /** @return iterable<int, EnvironmentRequirement> */
    private function generalRuntimeRequirements(): iterable
    {
        foreach ([
            'CACHE_NEXT_UPCOMING_WEBINAR_EMPTY_SECONDS',
            'CACHE_NEXT_UPCOMING_WEBINAR_MIN_SECONDS',
            'CACHE_ACTIVE_WEBINAR_SERIES_MIN_SECONDS',
            'WEBINAR_REGISTRATION_QUEUE',
            'WEBINAR_WEBHOOK_QUEUE',
            'WEBINAR_REMINDER_QUEUE',
            'WEBINAR_CONFIRMATION_MESSAGE_QUEUE',
            'WEBINAR_FOLLOWUP_QUEUE',
        ] as $key) {
            yield EnvironmentRequirement::defaulted(
                $key,
                'Webinars provides a process default; persist this key only when the deployment deliberately overrides that default.',
            );
        }

        yield EnvironmentRequirement::defaulted(
            'WEBINAR_APP_URL',
            'The public Webinar origin is derived from APP_URL and ROOT_DOMAIN as webinar.[ROOT_DOMAIN]; persist WEBINAR_APP_URL only for a deliberate hostname override.',
        );
    }

    /** @return iterable<int, EnvironmentRequirement> */
    private function zoomRequirements(): iterable
    {
        foreach ([
            'ZOOM_BASE_URL',
            'ZOOM_OAUTH_URL',
            'ZOOM_OAUTH_TOKEN_TTL_SECONDS',
        ] as $key) {
            yield EnvironmentRequirement::defaulted(
                $key,
                'Zoom integration provides a safe process default; persist this key only for a deliberate provider override.',
            );
        }

        $externalProvider = $this->usesExternalProvider();

        foreach ([
            'ZOOM_ACCOUNT_ID',
            'ZOOM_CLIENT_ID',
            'ZOOM_CLIENT_SECRET',
        ] as $key) {
            yield $externalProvider
                ? EnvironmentRequirement::required(
                    $key,
                    'Staging/production Zoom Server-to-Server OAuth requires the selected client credential.',
                )
                : EnvironmentRequirement::optional(
                    $key,
                    'Local/testing deployment planning keeps live Zoom Server-to-Server OAuth credentials optional.',
                );
        }

        if ($this->zoomWebhookCapabilitiesEnabled()) {
            yield $externalProvider
                ? EnvironmentRequirement::required(
                    'ZOOM_WEBHOOK_SECRET',
                    'Enabled Webinar post-event capabilities require signature verification for live Zoom webhook callbacks.',
                )
                : EnvironmentRequirement::optional(
                    'ZOOM_WEBHOOK_SECRET',
                    'Local/testing deployment planning keeps the live Zoom webhook signing secret optional.',
                );
        } else {
            yield EnvironmentRequirement::optional(
                'ZOOM_WEBHOOK_SECRET',
                'No Webinar post-event capability currently requires Zoom webhook processing; retain a secret only for a deliberate provider callback configuration.',
            );
        }

        yield EnvironmentRequirement::defaulted(
            'ZOOM_WEBHOOK_MAX_TIMESTAMP_DRIFT_SECONDS',
            'Zoom webhook verification provides a default timestamp-drift tolerance.',
        );
    }

    /** @return array<int, string> */
    private function configuredProviderKeys(): array
    {
        $providers = config('webinars.providers', []);

        if (! is_array($providers)) {
            return [];
        }

        $keys = array_values(array_filter(
            array_keys($providers),
            static fn (mixed $key): bool => is_string($key) && trim($key) !== '',
        ));

        $keys = array_map(
            static fn (string $key): string => strtolower(trim($key)),
            $keys,
        );

        sort($keys);

        return array_values(array_unique($keys));
    }

    private function selectedProvider(): ?string
    {
        $provider = config('webinars.provider');

        if (! is_string($provider)) {
            return null;
        }

        $provider = strtolower(trim($provider));

        return $provider !== '' ? $provider : null;
    }

    private function zoomWebhookCapabilitiesEnabled(): bool
    {
        return config('webinars.post_event.attendance.enabled', true) === true
            || config('webinars.post_event.recordings.enabled', false) === true;
    }

    private function usesExternalProvider(): bool
    {
        return ! app()->environment(['local', 'testing']);
    }
}