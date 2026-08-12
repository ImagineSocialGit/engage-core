<?php

namespace App\Modules\Forms\Validation;

use App\Modules\Forms\Models\FormDefinition;
use App\Modules\Forms\Models\FormVersion;
use App\Modules\Forms\Services\FormSchemaNormalizer;
use App\Support\SetupValidation\Contracts\SetupValidationContributor;
use App\Support\SetupValidation\Data\SetupValidationFinding;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

final class FormsSetupValidationContributor implements SetupValidationContributor
{
    private const MODULE = 'forms';
    private const SOURCE = 'forms.runtime';

    public function __construct(
        private readonly FormSchemaNormalizer $schemas,
    ) {}

    public function findings(): iterable
    {
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