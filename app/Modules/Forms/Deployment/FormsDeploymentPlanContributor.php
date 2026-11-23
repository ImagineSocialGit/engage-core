<?php

namespace App\Modules\Forms\Deployment;

use App\Support\Deployment\Contracts\DeploymentEnvironmentDefinitionContributor;
use App\Support\Deployment\Credentials\CoreCredentialCallerKey;
use App\Support\Deployment\Contracts\DeploymentSetupContributor;
use App\Support\Deployment\Data\DeploymentSetupStep;
use App\Support\Deployment\Data\EnvironmentRequirement;
use App\Support\Environment\Data\EnvironmentVariableDefinition;
use App\Support\Presets\Enums\PresetDomain;
use App\Support\Presets\PresetCompositionResolver;
use App\Support\Presets\PresetPackageResolver;

final class FormsDeploymentPlanContributor implements DeploymentEnvironmentDefinitionContributor, DeploymentSetupContributor
{
    public function __construct(
        private readonly PresetPackageResolver $packages,
        private readonly PresetCompositionResolver $composition,
    ) {}

    public function owner(): string
    {
        return 'forms';
    }

    public function environmentDefinitions(): iterable
    {
        foreach ($this->activeCallerSecretKeys() as $key) {
            yield new EnvironmentVariableDefinition(
                key: $key,
                scope: EnvironmentVariableDefinition::SCOPE_CLIENT,
                owner: 'forms', secret: true,
            );
        }
    }

    public function environmentRequirements(): iterable
    {
        foreach ([
            'FORMS_EXTERNAL_INTAKE_MAX_BODY_BYTES',
            'FORMS_EXTERNAL_INTAKE_MAX_TIMESTAMP_DRIFT_SECONDS',
            'FORMS_EXTERNAL_INTAKE_NONCE_TTL_SECONDS',
            'FORMS_EXTERNAL_INTAKE_UNAUTHENTICATED_RATE_LIMIT_PER_MINUTE',
            'FORMS_EXTERNAL_INTAKE_CLIENT_RATE_LIMIT_PER_MINUTE',
        ] as $key) {
            yield EnvironmentRequirement::defaulted(
                $key,
                'Forms provides a safe process default; persist only deliberate operational overrides.',
            );
        }

        $publicForms = $this->selectedPublicFormKeys();
        $hostedPublicForms = $this->selectedHostedPublicFormKeys();
        $liveRuntime = in_array(app()->environment(), ['staging', 'production'], true);

        if ($liveRuntime && $hostedPublicForms !== []) {
            yield EnvironmentRequirement::required(
                'FORMS_APP_URL',
                sprintf(
                    'The committed preset selects hosted public Form%s [%s]; staging/production requires an explicit Core-owned public Forms origin.',
                    count($hostedPublicForms) === 1 ? '' : 's',
                    implode(', ', $hostedPublicForms),
                ),
                valueRule: EnvironmentRequirement::VALUE_RULE_HTTP_ORIGIN,
            );
        } else {
            yield EnvironmentRequirement::optional(
                'FORMS_APP_URL',
                $hostedPublicForms !== []
                    ? 'Local/testing may omit the hosted Forms origin and use the forms.[ROOT_DOMAIN] fallback; configure an explicit origin only when exercising deployment-specific host behavior.'
                    : 'Configure a public Forms origin only when this client exposes Core-hosted Forms.',
                valueRule: EnvironmentRequirement::VALUE_RULE_HTTP_ORIGIN,
            );
        }

        if ($publicForms !== []) {
            yield EnvironmentRequirement::required(
                'FORMS_EXTERNAL_INTAKE_ENABLED',
                sprintf(
                    'The committed preset selects public Form%s [%s]; explicitly decide whether trusted external intake is enabled for this deployment.',
                    count($publicForms) === 1 ? '' : 's',
                    implode(', ', $publicForms),
                ),
            );
        } else {
            yield EnvironmentRequirement::optional(
                'FORMS_EXTERNAL_INTAKE_ENABLED',
                'Set this only when trusted external callers should access published Forms.',
            );
        }

        if (! (bool) config('forms.external_intake.enabled', false)) {
            return;
        }

        foreach ($this->activeCallerSecretKeys() as $key) {
            yield EnvironmentRequirement::required(
                $key,
                'An approved external Forms caller requires its own selected-client signing credential.',
            );
        }
    }

    public function setupSteps(): iterable
    {
        if (! in_array(app()->environment(), ['staging', 'production'], true)
            || ! (bool) config('forms.external_intake.enabled', false)
        ) {
            return;
        }

        $rootDomain = $this->rootDomain();
        $publicForms = $this->selectedPublicFormKeys();

        yield new DeploymentSetupStep(
            key: 'forms.external_intake',
            title: 'Trusted external Forms intake',
            reason: 'This client allows an approved first-party site application to read and submit Core-backed Forms through the signed server-to-server boundary.',
            instructions: [
                'Confirm the approved caller identity, source/provider labels, and exact allowed public Form keys for this environment.',
                'Register each approved caller through the protected Infrastructure lifecycle, then issue and deliver the selected per-caller credential.',
                "Give the caller the signed endpoints on https://webhooks.{$rootDomain}/forms/{form_key} and https://webhooks.{$rootDomain}/forms/{form_key}/submissions.",
                'Do not reuse a staging signing credential in production unless that sharing is deliberate and documented.',
            ],
            environmentKeys: array_merge(
                ['FORMS_EXTERNAL_INTAKE_ENABLED'],
                $this->activeCallerSecretKeys(),
            ),
            verification: [
                'Run php artisan setup:validate after both sides have the matching credential.',
                'Verify an unsigned GET to one configured Form endpoint reaches Core and returns 401 authentication_failed.',
                ...($publicForms !== []
                    ? ['Run the approved external caller probe for: '.implode(', ', $publicForms).'.']
                    : []),
            ],
            priority: 70,
        );
    }

    /** @return list<string> */
    private function activeCallerSecretKeys(): array
    {
        if (config('forms.external_intake.enabled') !== true) {
            return [];
        }
        $clients = config('forms.external_intake.clients', []);
        if (! is_array($clients)) {
            return [];
        }
        $keys = [];
        foreach ($clients as $callerId => $settings) {
            if (! is_string($callerId) || ! is_array($settings)
                || ! is_string($settings['source_platform'] ?? null)
            ) {
                continue;
            }
            $key = CoreCredentialCallerKey::forCaller(
                'forms.external_intake', $settings['source_platform'], $callerId,
            );
            if ($key !== null) {
                $keys[] = $key;
            }
        }
        sort($keys, SORT_STRING);

        return array_values(array_unique($keys));
    }

    private function rootDomain(): string
    {
        $domain = config('app.root_domain');

        return is_string($domain) && trim($domain) !== ''
            ? trim($domain)
            : '[ROOT_DOMAIN]';
    }

    /** @return array<int, string> */
    private function selectedPublicFormKeys(): array
    {
        return array_keys($this->selectedPublicForms());
    }

    /** @return array<int, string> */
    private function selectedHostedPublicFormKeys(): array
    {
        $keys = [];

        foreach ($this->selectedPublicForms() as $key => $definition) {
            $settings = $definition['settings'] ?? null;
            $public = is_array($settings) ? ($settings['public'] ?? null) : null;
            $hosted = is_array($public) ? ($public['hosted'] ?? null) : null;

            if (is_array($hosted) && ($hosted['enabled'] ?? false) === true) {
                $keys[] = $key;
            }
        }

        sort($keys);

        return array_values(array_unique($keys));
    }

    /** @return array<string, array<string, mixed>> */
    private function selectedPublicForms(): array
    {
        $presetKey = $this->packages->resolvePresetKey(null);

        if ($presetKey === null
            || $this->packages->selectedGroups($presetKey, PresetDomain::Forms) === []
        ) {
            return [];
        }

        $resolved = $this->composition->resolve($presetKey, PresetDomain::Forms);
        $definitions = [];

        foreach ($resolved->definitions as $definitionKey => $definition) {
            if (! is_array($definition) || ($definition['is_public'] ?? false) !== true) {
                continue;
            }

            $key = is_string($definition['key'] ?? null)
                ? trim($definition['key'])
                : (is_string($definitionKey) ? trim($definitionKey) : '');

            if ($key !== '') {
                $definitions[$key] = $definition;
            }
        }

        ksort($definitions);

        return $definitions;
    }
}