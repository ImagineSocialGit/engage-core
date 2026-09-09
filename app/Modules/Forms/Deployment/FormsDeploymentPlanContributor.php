<?php

namespace App\Modules\Forms\Deployment;

use App\Support\Deployment\Contracts\DeploymentPlanContributor;
use App\Support\Deployment\Contracts\DeploymentSetupContributor;
use App\Support\Deployment\Data\DeploymentSetupStep;
use App\Support\Deployment\Data\EnvironmentRequirement;
use App\Support\Presets\Enums\PresetDomain;
use App\Support\Presets\PresetCompositionResolver;
use App\Support\Presets\PresetPackageResolver;

final class FormsDeploymentPlanContributor implements DeploymentPlanContributor, DeploymentSetupContributor
{
    public function __construct(
        private readonly PresetPackageResolver $packages,
        private readonly PresetCompositionResolver $composition,
    ) {}

    public function owner(): string
    {
        return 'forms';
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

        foreach ([
            'FORMS_EXTERNAL_INTAKE_CLIENT_ID' => 'External Forms intake requires a stable caller identity.',
            'FORMS_EXTERNAL_INTAKE_CLIENT_SECRET' => 'External Forms intake requires a shared signing secret.',
            'FORMS_EXTERNAL_INTAKE_SOURCE' => 'External Forms intake records the configured source identity.',
            'FORMS_EXTERNAL_INTAKE_PROVIDER' => 'External Forms intake records the configured provider identity.',
            'FORMS_EXTERNAL_INTAKE_ALLOWED_FORMS' => 'External Forms intake must explicitly allow the published form keys callable by this client.',
        ] as $key => $reason) {
            yield EnvironmentRequirement::required($key, $reason);
        }

        yield EnvironmentRequirement::optional(
            'FORMS_EXTERNAL_INTAKE_DOMAINS',
            'Optional bare-domain overrides are only needed when Forms should advertise domains beyond ROOT_DOMAIN.',
        );
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
                'If the shared signing credential has not been issued yet, run php artisan forms:external-intake:issue-secret [client] and install the matching Core/caller credential blocks in their matching environments.',
                "Give the caller the signed endpoints on https://webhooks.{$rootDomain}/forms/{form_key} and https://webhooks.{$rootDomain}/forms/{form_key}/submissions.",
                'Do not reuse a staging signing credential in production unless that sharing is deliberate and documented.',
            ],
            environmentKeys: [
                'FORMS_EXTERNAL_INTAKE_CLIENT_ID',
                'FORMS_EXTERNAL_INTAKE_CLIENT_SECRET',
                'FORMS_EXTERNAL_INTAKE_SOURCE',
                'FORMS_EXTERNAL_INTAKE_PROVIDER',
                'FORMS_EXTERNAL_INTAKE_ALLOWED_FORMS',
                'FORMS_EXTERNAL_INTAKE_DOMAINS',
            ],
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
        $presetKey = $this->packages->resolvePresetKey(null);

        if ($presetKey === null
            || $this->packages->selectedGroups($presetKey, PresetDomain::Forms) === []
        ) {
            return [];
        }

        $resolved = $this->composition->resolve($presetKey, PresetDomain::Forms);
        $keys = [];

        foreach ($resolved->definitions as $definitionKey => $definition) {
            if (! is_array($definition) || ($definition['is_public'] ?? false) !== true) {
                continue;
            }

            $key = is_string($definition['key'] ?? null)
                ? trim($definition['key'])
                : (is_string($definitionKey) ? trim($definitionKey) : '');

            if ($key !== '') {
                $keys[] = $key;
            }
        }

        sort($keys);

        return array_values(array_unique($keys));
    }
}