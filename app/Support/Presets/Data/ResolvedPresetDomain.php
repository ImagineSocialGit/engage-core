<?php

namespace App\Support\Presets\Data;

use App\Support\Presets\Enums\PresetDomain;

final class ResolvedPresetDomain
{
    /**
     * @param array<int, string> $selectedGroups
     * @param array<int, string> $selectedContributors
     * @param array<int, string> $definitionKeys
     * @param array<string, array<string, mixed>> $definitions
     * @param array<string, array{contributor: string, source: string}> $provenance
     * @param array<string, array<int, string>> $definitionGroups
     */
    public function __construct(
        public readonly string $presetKey,
        public readonly PresetDomain $domain,
        public readonly array $selectedGroups,
        public readonly array $selectedContributors,
        public readonly array $definitionKeys,
        public readonly array $definitions,
        public readonly array $provenance,
        public readonly array $definitionGroups,
    ) {}
}
