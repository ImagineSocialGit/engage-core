<?php

namespace App\Modules\Forms\Services;

use App\Modules\Forms\Data\PublishedForm;
use App\Modules\Forms\Models\FormDefinition;
use App\Modules\Forms\Models\FormVersion;
use DomainException;
use InvalidArgumentException;

final class PublishedFormResolver
{
    public function __construct(
        private readonly FormSchemaNormalizer $schemas,
    ) {}

    public function find(
        string $key,
        bool $publicOnly = false,
    ): ?PublishedForm {
        $key = trim($key);

        if ($key === '' || preg_match(FormSchemaNormalizer::KEY_PATTERN, $key) !== 1) {
            return null;
        }

        $query = FormDefinition::query()
            ->with('currentVersion')
            ->where('key', $key)
            ->where('status', FormDefinition::STATUS_ACTIVE);

        if ($publicOnly) {
            $query->where('is_public', true);
        }

        $definition = $query->first();

        if (! $definition instanceof FormDefinition) {
            return null;
        }

        return $this->published($definition);
    }

    public function require(
        string $key,
        bool $publicOnly = false,
    ): PublishedForm {
        $published = $this->find(
            key: $key,
            publicOnly: $publicOnly,
        );

        if ($published instanceof PublishedForm) {
            return $published;
        }

        throw new DomainException(
            "Published form [{$key}] is unavailable.",
        );
    }

    private function published(FormDefinition $definition): PublishedForm
    {
        $version = $definition->currentVersion;

        if (! $version instanceof FormVersion) {
            throw new DomainException(
                "Active form [{$definition->key}] has no current FormVersion.",
            );
        }

        if ((int) $version->form_definition_id !== (int) $definition->getKey()) {
            throw new DomainException(
                "Active form [{$definition->key}] points at a FormVersion owned by another definition.",
            );
        }

        if ($version->status !== FormVersion::STATUS_PUBLISHED
            || $version->published_at === null
            || $version->archived_at !== null
        ) {
            throw new DomainException(
                "Active form [{$definition->key}] does not point at a current published FormVersion.",
            );
        }

        try {
            $schema = $this->schemas->normalize(
                $version->schema ?? [],
                "Published form [{$definition->key}] schema",
            );
            $fields = $this->schemas->fields(
                $schema,
                "Published form [{$definition->key}] schema",
            );
        } catch (InvalidArgumentException $exception) {
            throw new DomainException(
                "Published form [{$definition->key}] has an invalid schema: {$exception->getMessage()}",
                previous: $exception,
            );
        }

        return new PublishedForm(
            definitionId: (int) $definition->getKey(),
            versionId: (int) $version->getKey(),
            versionNumber: (int) $version->version,
            key: $definition->key,
            name: $version->name,
            description: $version->description,
            category: $definition->category,
            isPublic: (bool) $definition->is_public,
            schema: $schema,
            rules: is_array($version->rules) ? $version->rules : [],
            layout: is_array($version->layout) ? $version->layout : [],
            settings: is_array($version->settings) ? $version->settings : [],
            fields: $fields,
        );
    }
}