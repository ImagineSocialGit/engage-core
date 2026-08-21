<?php

namespace App\Modules\Forms\Validation;

use App\Modules\Forms\Models\FormDefinition;
use App\Modules\Forms\Models\FormVersion;
use App\Modules\Forms\Services\ExternalFormIntakeClientResolver;
use App\Modules\Forms\Services\FormSubmissionConsentIntentResolver;
use App\Modules\Forms\Services\FormSubmissionContactMapper;
use App\Modules\Forms\Services\FormSubmissionValidator;
use App\Modules\Forms\Services\FormSubmissionVerificationPolicy;
use App\Modules\Forms\Services\FormSchemaNormalizer;
use App\Modules\Forms\Services\PublishedFormResolver;
use App\Support\ModuleIntegrations\Forms\FormSubmissionConsentBridge;
use App\Support\SetupValidation\Contracts\SetupValidationContributor;
use App\Support\SetupValidation\Data\SetupValidationFinding;
use DomainException;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

final class FormsSetupValidationContributor implements SetupValidationContributor
{
    private const MODULE = 'forms';
    private const SOURCE = 'forms.runtime';

    public function __construct(
        private readonly FormSchemaNormalizer $schemas,
        private readonly PublishedFormResolver $publishedForms,
        private readonly FormSubmissionValidator $submissions,
        private readonly FormSubmissionContactMapper $contacts,
        private readonly FormSubmissionConsentIntentResolver $consentIntents,
        private readonly FormSubmissionConsentBridge $consentBridge,
        private readonly FormSubmissionVerificationPolicy $verifications,
        private readonly ExternalFormIntakeClientResolver $externalClients,
    ) {}

    public function findings(): iterable
    {
        yield from $this->externalIntakeFindings();

        if (! Schema::hasTable('form_definitions')
            || ! Schema::hasTable('form_versions')
        ) {
            return;
        }

        $definitions = FormDefinition::query()
            ->with('currentVersion')
            ->where('status', FormDefinition::STATUS_ACTIVE)
            ->orderBy('key')
            ->get();

        foreach ($definitions as $definition) {
            $context = [
                'form_definition_id' => (int) $definition->getKey(),
                'form_key' => $definition->key,
                'is_public' => (bool) $definition->is_public,
            ];
            $path = "form_definitions.{$definition->key}";
            $version = $definition->currentVersion;

            if (! $version instanceof FormVersion) {
                yield $this->error(
                    code: 'forms.runtime.current_version_missing',
                    message: "Active form [{$definition->key}] has no current FormVersion.",
                    path: "{$path}.current_form_version_id",
                    context: $context,
                );

                continue;
            }

            $versionContext = $context + [
                'form_version_id' => (int) $version->getKey(),
                'version' => (int) $version->version,
            ];

            if ((int) $version->form_definition_id !== (int) $definition->getKey()) {
                yield $this->error(
                    code: 'forms.runtime.current_version_owner_mismatch',
                    message: "Active form [{$definition->key}] points at a FormVersion owned by another definition.",
                    path: "{$path}.current_form_version_id",
                    context: $versionContext,
                );

                continue;
            }

            if ($version->status !== FormVersion::STATUS_PUBLISHED
                || $version->published_at === null
                || $version->archived_at !== null
            ) {
                yield $this->error(
                    code: 'forms.runtime.current_version_unpublished',
                    message: "Active form [{$definition->key}] must point at a non-archived published FormVersion.",
                    path: "form_versions.{$version->getKey()}",
                    context: $versionContext + [
                        'status' => $version->status,
                        'published_at' => $version->published_at?->toISOString(),
                        'archived_at' => $version->archived_at?->toISOString(),
                    ],
                );

                continue;
            }

            try {
                $this->schemas->normalize(
                    $version->schema ?? [],
                    "Published form [{$definition->key}] schema",
                );
            } catch (InvalidArgumentException $exception) {
                yield $this->error(
                    code: 'forms.runtime.schema_invalid',
                    message: $exception->getMessage(),
                    path: "form_versions.{$version->getKey()}.schema",
                    context: $versionContext,
                );

                continue;
            }

            try {
                $published = $this->publishedForms->require($definition->key);
                $this->submissions->validateConfiguration($published);
            } catch (DomainException $exception) {
                yield $this->error(
                    code: 'forms.runtime.submission_rules_invalid',
                    message: $exception->getMessage(),
                    path: "form_versions.{$version->getKey()}.rules",
                    context: $versionContext,
                );

                continue;
            }

            try {
                $this->contacts->validateConfiguration($published);
            } catch (DomainException $exception) {
                yield $this->error(
                    code: 'forms.runtime.submission_mapping_invalid',
                    message: $exception->getMessage(),
                    path: "form_versions.{$version->getKey()}.settings",
                    context: $versionContext,
                );
            }

            try {
                $consentIntents = $this->consentIntents->resolve($published);
                $this->consentBridge->validateConfiguration(
                    $published,
                    $consentIntents,
                );
            } catch (DomainException $exception) {
                yield $this->error(
                    code: 'forms.runtime.submission_consent_invalid',
                    message: $exception->getMessage(),
                    path: "form_versions.{$version->getKey()}.settings.submission.consents",
                    context: $versionContext,
                );
            }

            try {
                $this->verifications->validateConfiguration($published);
            } catch (DomainException $exception) {
                yield $this->error(
                    code: 'forms.runtime.submission_verification_invalid',
                    message: $exception->getMessage(),
                    path: "form_versions.{$version->getKey()}.settings.submission.verification",
                    context: $versionContext,
                );
            }
        }
    }

    /**
     * @return iterable<SetupValidationFinding>
     */
    private function externalIntakeFindings(): iterable
    {
        $enabled = config('forms.external_intake.enabled', false);

        if (! is_bool($enabled)) {
            yield $this->error(
                code: 'forms.external_intake.setting_invalid',
                message: 'External Forms intake setting [enabled] must be a boolean.',
                path: 'forms.external_intake.enabled',
            );

            return;
        }

        if (! $enabled) {
            return;
        }

        $integerSettings = [
            'max_body_bytes' => [1024, 10 * 1024 * 1024],
            'max_timestamp_drift_seconds' => [30, 3600],
            'nonce_ttl_seconds' => [60, 7200],
            'unauthenticated_rate_limit_per_minute' => [1, 10000],
            'client_rate_limit_per_minute' => [1, 10000],
        ];
        $invalidIntegerSetting = false;

        foreach ($integerSettings as $key => [$minimum, $maximum]) {
            $value = config("forms.external_intake.{$key}");

            if (! is_int($value) || $value < $minimum || $value > $maximum) {
                $invalidIntegerSetting = true;

                yield $this->error(
                    code: 'forms.external_intake.setting_invalid',
                    message: "External Forms intake setting [{$key}] must be an integer between {$minimum} and {$maximum}.",
                    path: "forms.external_intake.{$key}",
                );
            }
        }

        $drift = config('forms.external_intake.max_timestamp_drift_seconds');
        $nonceTtl = config('forms.external_intake.nonce_ttl_seconds');

        if (! $invalidIntegerSetting
            && is_int($drift)
            && is_int($nonceTtl)
            && $nonceTtl < ($drift * 2)
        ) {
            yield $this->error(
                code: 'forms.external_intake.nonce_ttl_too_short',
                message: 'External Forms intake nonce_ttl_seconds must be at least twice max_timestamp_drift_seconds.',
                path: 'forms.external_intake.nonce_ttl_seconds',
            );
        }

        try {
            $clients = $this->externalClients->all();
        } catch (InvalidArgumentException $exception) {
            yield $this->error(
                code: 'forms.external_intake.client_config_invalid',
                message: $exception->getMessage(),
                path: 'forms.external_intake.clients',
            );

            return;
        }

        if (! Schema::hasTable('form_definitions')
            || ! Schema::hasTable('form_versions')
        ) {
            return;
        }

        foreach ($clients as $client) {
            foreach ($client->allowedForms as $formKey) {
                try {
                    $this->publishedForms->require(
                        key: $formKey,
                        publicOnly: true,
                    );
                } catch (DomainException $exception) {
                    yield $this->error(
                        code: 'forms.external_intake.allowed_form_unavailable',
                        message: "External Forms intake client [{$client->id}] requires unavailable public form [{$formKey}].",
                        path: "forms.external_intake.clients.{$client->id}.allowed_forms",
                        context: [
                            'client_id' => $client->id,
                            'form_key' => $formKey,
                        ],
                    );
                }
            }
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    private function error(
        string $code,
        string $message,
        string $path,
        array $context = [],
    ): SetupValidationFinding {
        return new SetupValidationFinding(
            severity: SetupValidationFinding::SEVERITY_ERROR,
            code: $code,
            message: $message,
            source: self::SOURCE,
            path: $path,
            module: self::MODULE,
            context: $context,
        );
    }
}