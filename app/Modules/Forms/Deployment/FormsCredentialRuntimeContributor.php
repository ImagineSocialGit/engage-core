<?php

namespace App\Modules\Forms\Deployment;

use App\Modules\Forms\Services\ExternalFormIntakeSigningCredentialResolver;
use App\Support\Deployment\Credentials\CoreCredentialCallerContributor;
use App\Support\Deployment\Credentials\CoreCredentialRuntimeBinding;
use RuntimeException;

/** Forms owns caller admission and the credential consumed by signed intake. */
final class FormsCredentialRuntimeContributor implements CoreCredentialCallerContributor
{
    public function __construct(private readonly ExternalFormIntakeSigningCredentialResolver $credentials) {}

    public function owner(): string
    {
        return 'forms';
    }

    public function callerCapabilities(): iterable
    {
        yield ['purpose' => 'forms.external_intake', 'source_platform' => 'seo'];
        yield ['purpose' => 'forms.external_intake', 'source_platform' => 'artist'];
    }

    public function validateCaller(string $purpose, string $sourcePlatform, string $sourceClient, string $sourceOrigin): void
    {
        if ($purpose !== 'forms.external_intake' || ! in_array($sourcePlatform, ['seo', 'artist'], true)) {
            throw new RuntimeException('forms_caller_route_unsupported');
        }
        FormsCallerConfiguration::authorized(
            config('forms.external_intake.authorized_callers', []),
            $sourcePlatform, $sourceClient, $sourceOrigin,
        );
    }

    public function runtimeBindings(): iterable
    {
        if (config('forms.external_intake.enabled', false) === false) {
            return;
        }
        $clients = config('forms.external_intake.clients');
        if (config('forms.external_intake.enabled') !== true || ! is_array($clients) || $clients === []) {
            throw new RuntimeException('forms_runtime_caller_configuration_invalid');
        }
        ksort($clients, SORT_STRING);
        foreach ($clients as $callerId => $settings) {
            if (! is_string($callerId) || ! $this->credentials->hasValidBinding($callerId, $settings)
                || ! is_string($settings['source_origin'] ?? null)
                || ! is_string($settings['source_platform'] ?? null)
            ) {
                throw new RuntimeException('forms_runtime_caller_configuration_invalid');
            }
            yield new CoreCredentialRuntimeBinding(
                owner: 'forms', purpose: 'forms.external_intake',
                sourcePlatform: $settings['source_platform'], callerId: $callerId,
                sourceOrigin: $settings['source_origin'],
                secretEnvironmentKey: $settings['secret_env_key'],
                consumer: fn (): ?string => $this->credentials->resolve($callerId, $settings),
            );
        }
    }
}
