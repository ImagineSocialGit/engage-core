<?php

namespace App\Modules\Documents\Services;

use App\Support\ModuleIntegrations\Documents\Contracts\DocumentRequirementDefinitionContributor;
use App\Support\ModuleIntegrations\Documents\Data\DocumentRequirementDefinitionContribution;
use InvalidArgumentException;

final class DocumentRequirementDefinitionRegistry
{
    public function __construct(
        private readonly iterable $contributors = [],
    ) {}

    /**
     * @return array<string, array{
     *     contributor: string,
     *     definition: DocumentRequirementDefinitionContribution
     * }>
     */
    public function definitions(): array
    {
        $definitions = [];
        $contributorKeys = [];

        foreach ($this->contributors as $contributor) {
            if (! $contributor instanceof DocumentRequirementDefinitionContributor) {
                throw new InvalidArgumentException(sprintf(
                    'Document requirement registry received invalid contributor [%s].',
                    get_debug_type($contributor),
                ));
            }

            $contributorKey = trim($contributor->contributorKey());

            if ($contributorKey === ''
                || mb_strlen($contributorKey) > 100
                || preg_match('/\A[a-z0-9][a-z0-9_-]*\z/', $contributorKey) !== 1
            ) {
                throw new InvalidArgumentException(sprintf(
                    'Document requirement contributor [%s] returned an invalid contributor key.',
                    $contributor::class,
                ));
            }

            if (isset($contributorKeys[$contributorKey])
                && $contributorKeys[$contributorKey] !== $contributor::class
            ) {
                throw new InvalidArgumentException(sprintf(
                    'Document requirement contributor key [%s] is claimed by both [%s] and [%s].',
                    $contributorKey,
                    $contributorKeys[$contributorKey],
                    $contributor::class,
                ));
            }

            $contributorKeys[$contributorKey] = $contributor::class;

            foreach ($contributor->definitions() as $definition) {
                if (! $definition instanceof DocumentRequirementDefinitionContribution) {
                    throw new InvalidArgumentException(sprintf(
                        'Document requirement contributor [%s] returned invalid definition [%s].',
                        $contributor::class,
                        get_debug_type($definition),
                    ));
                }

                if (isset($definitions[$definition->key])) {
                    throw new InvalidArgumentException(sprintf(
                        'Document requirement key [%s] is defined by multiple contributors [%s] and [%s].',
                        $definition->key,
                        $definitions[$definition->key]['contributor'],
                        $contributorKey,
                    ));
                }

                $definitions[$definition->key] = [
                    'contributor' => $contributorKey,
                    'definition' => $definition,
                ];
            }
        }

        ksort($definitions);

        return $definitions;
    }
}