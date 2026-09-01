<?php

namespace App\Support\ProcessHighway;

final class ProcessHighwayExitLinkBuilder
{
    /** @param array<string, mixed> $node */
    public function factTarget(array $node): ?array
    {
        $criterionKey = $this->queryKey($node['attributes']['criterion_key'] ?? null);
        $value = $this->queryValue($node['attributes']['value'] ?? null);

        if ($criterionKey === null || $value === null) {
            return null;
        }

        if ($criterionKey === 'tag' && ($node['attributes']['present'] ?? true) !== true) {
            return null;
        }

        return [
            'criterion_key' => $criterionKey,
            'value' => $value,
            'label' => (string) ($node['attributes']['value_label'] ?? $node['label'] ?? $value),
            'url' => route('crm.process-highway.index', [
                $criterionKey => $value,
            ]),
        ];
    }

    /**
     * @param array<string, mixed> $highway
     * @param array<string, mixed> $edge
     */
    public function highwayTarget(array $highway, array $edge): ?array
    {
        $highwayKey = $this->string($highway['key'] ?? null);
        $edgeKey = $this->string($edge['key'] ?? null);

        if ($highwayKey === null || $edgeKey === null) {
            return null;
        }

        $anchor = $this->anchor($highwayKey, $edgeKey);

        return array_filter([
            'highway_key' => $highwayKey,
            'highway_name' => $this->string($highway['name'] ?? null) ?? 'Process Highway',
            'edge_key' => $edgeKey,
            'anchor' => $anchor,
            'url' => route('crm.process-highway.index', [
                'highway' => $highwayKey,
            ]).'#'.$anchor,
            'entry_selection' => $this->entrySelection($highway),
            'lane_scope' => $this->string($highway['lane_scope'] ?? null),
            'relationship_key' => $this->string($highway['relationship_key'] ?? null),
        ], fn (mixed $value): bool => $value !== null);
    }

    public function anchor(string $highwayKey, string $edgeKey): string
    {
        return 'process-highway-exit-'.substr(
            sha1($highwayKey.'|'.$edgeKey),
            0,
            16,
        );
    }

    /**
     * @param array<string, mixed> $highway
     * @return array<string, string>
     */
    private function entrySelection(array $highway): array
    {
        $selection = [];

        foreach ($highway['entry_requirements'] ?? [] as $requirement) {
            if (! is_array($requirement)) {
                continue;
            }

            $criterionKey = $this->queryKey($requirement['criterion_key'] ?? null);
            $value = collect($requirement['values'] ?? [])
                ->map(fn (mixed $candidate): ?string => is_array($candidate)
                    ? $this->queryValue($candidate['value'] ?? null)
                    : null)
                ->first(fn (?string $candidate): bool => $candidate !== null);

            if ($criterionKey !== null && is_string($value)) {
                $selection[$criterionKey] = $value;
            }
        }

        ksort($selection);

        return $selection;
    }

    private function queryKey(mixed $value): ?string
    {
        $value = $this->string($value);

        if ($value === null
            || mb_strlen($value) > 64
            || preg_match('/\A[a-z][a-z0-9_]*\z/', $value) !== 1
        ) {
            return null;
        }

        return $value;
    }

    private function queryValue(mixed $value): ?string
    {
        $value = $this->string($value);

        if ($value === null
            || mb_strlen($value) > 191
            || preg_match('/[\x00-\x1F\x7F]/', $value) === 1
        ) {
            return null;
        }

        return $value;
    }

    private function string(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }
}