<?php

namespace App\Modules\Reporting\Services;

use App\Modules\Reporting\Data\NormalizedReportingAttribution;
use InvalidArgumentException;
use LogicException;

final class ReportingAttributionNormalizer
{
    /**
     * @param array<string, mixed> $query
     */
    public function normalize(
        string $path,
        ?string $referrer,
        array $query,
    ): NormalizedReportingAttribution {
        $normalizedPath = $this->normalizePath($path);
        $referrerHost = $this->normalizeReferrerHost($referrer);
        $utm = $this->normalizeUtm($query);
        $external = $this->normalizeExternalAttribution($query);
        $clickIdHashes = $this->normalizeClickIds($query);

        return new NormalizedReportingAttribution(
            path: $normalizedPath,
            referrerHost: $referrerHost,
            utmSource: $utm['utm_source'] ?? null,
            utmMedium: $utm['utm_medium'] ?? null,
            utmCampaign: $utm['utm_campaign'] ?? null,
            utmContent: $utm['utm_content'] ?? null,
            utmTerm: $utm['utm_term'] ?? null,
            externalPlatform: $external['platform'] ?? null,
            externalCampaignId: $external['campaign_id'] ?? null,
            externalGroupId: $external['group_id'] ?? null,
            externalCreativeId: $external['creative_id'] ?? null,
            externalPlacement: $external['placement'] ?? null,
            clickIdHashes: $clickIdHashes,
        );
    }

    public function normalizeHost(string $host): string
    {
        $host = rtrim(strtolower(trim($host)), '.');

        if ($host === ''
            || strlen($host) > $this->hostMaxLength()
            || preg_match('/[\x00-\x20\x7F]/', $host) === 1
            || str_contains($host, '\\')
            || str_contains($host, '/')
            || str_contains($host, '..')
            || preg_match('/^[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?$/', $host) !== 1
        ) {
            throw new InvalidArgumentException('Reporting host is invalid.');
        }

        return $host;
    }

    private function normalizePath(string $value): string
    {
        $value = trim($value);

        if ($value === ''
            || preg_match('/[\x00-\x1F\x7F]/', $value) === 1
            || str_contains($value, '\\')
        ) {
            throw new InvalidArgumentException('Reporting path is invalid.');
        }

        $path = parse_url($value, PHP_URL_PATH);

        if (! is_string($path) || $path === '') {
            $path = '/';
        }

        if (! str_starts_with($path, '/')) {
            $path = '/'.$path;
        }

        if (strlen($path) > $this->pathMaxLength()) {
            throw new InvalidArgumentException('Reporting path exceeds the configured maximum length.');
        }

        return $path;
    }

    private function normalizeReferrerHost(?string $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $value = trim($value);
        $host = parse_url($value, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            $candidate = strtolower($value);

            if (str_contains($candidate, '/')
                || str_contains($candidate, '?')
                || str_contains($candidate, '#')
            ) {
                return null;
            }

            $host = $candidate;
        }

        try {
            return $this->normalizeHost($host);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, string>
     */
    private function normalizeUtm(array $query): array
    {
        $keys = config('reporting.attribution.utm_keys', []);

        if (! is_array($keys)) {
            return [];
        }

        $normalized = [];
        $canonical = [
            'utm_source' => 'utm_source',
            'utm_medium' => 'utm_medium',
            'utm_campaign' => 'utm_campaign',
            'utm_content' => 'utm_content',
            'utm_term' => 'utm_term',
        ];

        foreach ($canonical as $dimension => $queryKey) {
            if (($keys[$dimension] ?? null) !== $queryKey) {
                continue;
            }

            $value = $this->boundedScalar($query[$queryKey] ?? null);

            if ($value !== null) {
                $normalized[$dimension] = $value;
            }
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, string>
     */
    private function normalizeExternalAttribution(array $query): array
    {
        $keys = config('reporting.attribution.external_keys', []);

        if (! is_array($keys)) {
            return [];
        }

        $canonical = [
            'platform',
            'campaign_id',
            'group_id',
            'creative_id',
            'placement',
        ];
        $normalized = [];

        foreach ($canonical as $dimension) {
            $queryKey = $keys[$dimension] ?? null;

            if (! is_string($queryKey) || trim($queryKey) === '') {
                continue;
            }

            $value = $this->boundedScalar($query[$queryKey] ?? null);

            if ($value === null) {
                continue;
            }

            if ($dimension === 'platform') {
                $value = str_replace('-', '_', strtolower($value));

                if (preg_match('/^[a-z0-9][a-z0-9._]*$/', $value) !== 1) {
                    throw new InvalidArgumentException('Reporting external attribution platform is invalid.');
                }
            }

            $normalized[$dimension] = $value;
        }

        $hasExternalIdentity = array_intersect_key(
            $normalized,
            array_flip(['campaign_id', 'group_id', 'creative_id', 'placement']),
        ) !== [];

        if ($hasExternalIdentity && ! isset($normalized['platform'])) {
            throw new InvalidArgumentException(
                'Reporting external attribution requires platform when an external campaign, group, creative, or placement value is present.',
            );
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, string>
     */
    private function normalizeClickIds(array $query): array
    {
        $keys = config('reporting.attribution.click_id_keys', []);

        if (! is_array($keys) || $keys === []) {
            return [];
        }

        $normalized = [];
        $hashKey = config('reporting.attribution.click_id_hash_key');

        foreach ($keys as $queryKey => $canonicalKey) {
            if (! is_string($queryKey)
                || ! is_string($canonicalKey)
                || trim($canonicalKey) === ''
            ) {
                continue;
            }

            $value = $this->boundedScalar($query[$queryKey] ?? null);

            if ($value === null) {
                continue;
            }

            if (! is_string($hashKey) || strlen($hashKey) < 32) {
                throw new LogicException(
                    'Reporting approved click identifiers require a dedicated hash key of at least 32 characters.',
                );
            }

            $canonicalKey = strtolower(trim($canonicalKey));
            $normalized[$canonicalKey] = hash_hmac(
                'sha256',
                $canonicalKey."\0".$value,
                $hashKey,
            );
        }

        ksort($normalized);

        return $normalized;
    }

    private function boundedScalar(mixed $value): ?string
    {
        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            return null;
        }

        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new InvalidArgumentException('Reporting attribution value contains control characters.');
        }

        if (strlen($value) > $this->valueMaxLength()) {
            throw new InvalidArgumentException('Reporting attribution value exceeds the configured maximum length.');
        }

        return $value;
    }

    private function pathMaxLength(): int
    {
        return min(512, max(1, (int) config('reporting.attribution.path_max_length', 512)));
    }

    private function hostMaxLength(): int
    {
        return min(255, max(1, (int) config('reporting.attribution.host_max_length', 255)));
    }

    private function valueMaxLength(): int
    {
        return min(120, max(1, (int) config('reporting.attribution.value_max_length', 120)));
    }
}