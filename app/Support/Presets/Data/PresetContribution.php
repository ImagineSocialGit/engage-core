<?php

namespace App\Support\Presets\Data;

use App\Support\Presets\Enums\PresetDomain;

final class PresetContribution
{
    /**
     * @param array<string, array<int, string>> $groups
     * @param array<string, array<string, mixed>> $definitions
     */
    public function __construct(
        public readonly string $contributor,
        public readonly PresetDomain $domain,
        public readonly array $groups,
        public readonly array $definitions,
        public readonly string $source,
    ) {}
}
