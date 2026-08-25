<?php

namespace App\Support\ProcessHighway\Contracts;

interface ProcessHighwayEntryRampContributor
{
    public function criterionKey(): string;

    /**
     * @param array<string, mixed> $node
     * @return array{
     *     contact_count: int,
     *     application_sources: array<int, array{key: string, label: string, detail: string, url?: string}>
     * }
     */
    public function inspect(string $value, array $node): array;
}