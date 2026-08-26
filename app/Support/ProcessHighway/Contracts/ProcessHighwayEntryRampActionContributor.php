<?php

namespace App\Support\ProcessHighway\Contracts;

interface ProcessHighwayEntryRampActionContributor
{
    public function criterionKey(): string;

    /**
     * @param array<string, mixed> $node
     * @return array<int, array<string, mixed>>
     */
    public function actions(string $value, array $node): array;
}