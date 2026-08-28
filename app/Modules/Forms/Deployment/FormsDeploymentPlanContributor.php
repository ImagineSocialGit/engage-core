<?php

namespace App\Modules\Forms\Deployment;

use App\Support\Deployment\Contracts\DeploymentPlanContributor;
use App\Support\Deployment\Data\EnvironmentRequirement;
use App\Support\Presets\Enums\PresetDomain;
use App\Support\Presets\PresetCompositionResolver;
use App\Support\Presets\PresetPackageResolver;

final class FormsDeploymentPlanContributor implements DeploymentPlanContributor
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