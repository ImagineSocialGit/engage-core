<?php

namespace App\Modules\Reporting\Actions;

use App\Modules\Reporting\Data\ReportingExternalMeasurementData;
use App\Modules\Reporting\Models\ReportingExternalMeasurement;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Database\QueryException;
use InvalidArgumentException;

final class UpsertReportingExternalMeasurementAction
{
    public function handle(ReportingExternalMeasurementData $data): ReportingExternalMeasurement
    {
        $normalized = $this->normalize($data);
        $identityHash = hash('sha256', json_encode(
            $this->identityMaterial($normalized),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));

        $existing = ReportingExternalMeasurement::query()
            ->where('identity_hash', $identityHash)
            ->first();

        if ($existing instanceof ReportingExternalMeasurement) {
            return $this->updateExisting($existing, $normalized, $identityHash);
        }

        try {
            return ReportingExternalMeasurement::query()->create([
                ...$normalized,
                'identity_hash' => $identityHash,
                'imported_at' => now('UTC'),
            ]);
        } catch (QueryException $exception) {
            $existing = ReportingExternalMeasurement::query()
                ->where('identity_hash', $identityHash)
                ->first();

            if (! $existing instanceof ReportingExternalMeasurement) {
                throw $exception;
            }

            return $this->updateExisting($existing, $normalized, $identityHash);
        }
    }

    /** @param array<string, mixed> $normalized */
    private function updateExisting(
        ReportingExternalMeasurement $existing,
        array $normalized,
        string $identityHash,
    ): ReportingExternalMeasurement {
        $meta = array_replace(
            is_array($existing->meta) ? $existing->meta : [],
            is_array($normalized['meta'] ?? null) ? $normalized['meta'] : [],
        );
        unset($normalized['meta']);

        $existing->forceFill([
            ...array_filter(
                $normalized,
                fn (mixed $value): bool => $value !== null,
            ),
            'meta' => $meta !== [] ? $meta : null,
            'identity_hash' => $identityHash,
            'imported_at' => now('UTC'),
        ])->save();

        return $existing->refresh();
    }

    /** @return array<string, mixed> */
    private function normalize(ReportingExternalMeasurementData $data): array
    {
        $platform = str_replace('-', '_', strtolower(trim($data->platform)));

        if ($platform === ''
            || strlen($platform) > 32
            || preg_match('/^[a-z0-9][a-z0-9._]*$/', $platform) !== 1
        ) {
            throw new InvalidArgumentException('Reporting external measurement platform is invalid.');
        }

        $periodStart = CarbonImmutable::instance($data->periodStart)->startOfDay();
        $periodEnd = CarbonImmutable::instance($data->periodEnd)->startOfDay();

        if ($periodEnd->lessThan($periodStart)) {
            throw new InvalidArgumentException('Reporting external measurement period end cannot be before period start.');
        }

        $timezone = $this->nullableString($data->accountTimezone, 64, 'account timezone');

        if ($timezone !== null) {
            try {
                new DateTimeZone($timezone);
            } catch (\Throwable) {
                throw new InvalidArgumentException('Reporting external measurement account timezone must be an IANA timezone.');
            }
        }

        $currency = $this->nullableString($data->currency, 3, 'currency');

        if ($currency !== null) {
            $currency = strtoupper($currency);

            if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
                throw new InvalidArgumentException('Reporting external measurement currency must be a three-letter code.');
            }
        }

        $campaignId = $this->nullableString($data->campaignId, 120, 'campaign ID');
        $groupId = $this->nullableString($data->groupId, 120, 'group ID');
        $creativeId = $this->nullableString($data->creativeId, 120, 'creative ID');
        $campaignName = $this->nullableString($data->campaignName, 255, 'campaign name');
        $groupName = $this->nullableString($data->groupName, 255, 'group name');
        $creativeName = $this->nullableString($data->creativeName, 255, 'creative name');

        $identityQuality = $this->identityQuality(
            campaignId: $campaignId,
            groupId: $groupId,
            creativeId: $creativeId,
            campaignName: $campaignName,
            groupName: $groupName,
            creativeName: $creativeName,
        );

        $sourceFileHash = $this->nullableString($data->sourceFileHash, 64, 'source file hash');

        if ($sourceFileHash !== null && preg_match('/^[a-f0-9]{64}$/', strtolower($sourceFileHash)) !== 1) {
            throw new InvalidArgumentException('Reporting external measurement source file hash must be SHA-256.');
        }

        return [
            'period_start' => $periodStart->toDateString(),
            'period_end' => $periodEnd->toDateString(),
            'platform' => $platform,
            'account_id' => $this->nullableString($data->accountId, 120, 'account ID'),
            'account_timezone' => $timezone,
            'campaign_id' => $campaignId,
            'group_id' => $groupId,
            'creative_id' => $creativeId,
            'campaign_name' => $campaignName,
            'group_name' => $groupName,
            'creative_name' => $creativeName,
            'placement' => $this->nullableString($data->placement, 120, 'placement'),
            'identity_quality' => $identityQuality,
            'currency' => $currency,
            'impressions' => $this->nullableCount($data->impressions, 'impressions'),
            'reach' => $this->nullableCount($data->reach, 'reach'),
            'link_clicks' => $this->nullableCount($data->linkClicks, 'link clicks'),
            'outbound_clicks' => $this->nullableCount($data->outboundClicks, 'outbound clicks'),
            'landing_page_views' => $this->nullableCount($data->landingPageViews, 'landing page views'),
            'spend' => $this->nullableDecimal($data->spend, 4, 'spend'),
            'result_type' => $this->nullableString($data->resultType, 80, 'result type'),
            'results' => $this->nullableDecimal($data->results, 6, 'results'),
            'source' => $this->identifier($data->source, 32, 'source'),
            'source_file_hash' => $sourceFileHash !== null ? strtolower($sourceFileHash) : null,
            'meta' => $this->normalizedMeta($data->meta),
        ];
    }

    /**
     * @param array<string, mixed> $normalized
     * @return array<string, mixed>
     */
    private function identityMaterial(array $normalized): array
    {
        $base = [
            'period_start' => $normalized['period_start'],
            'period_end' => $normalized['period_end'],
            'platform' => $normalized['platform'],
            'account_id' => $normalized['account_id'],
            'placement' => $normalized['placement'],
            'source_file_hash' => $normalized['source_file_hash'],
        ];

        if ($normalized['creative_id'] !== null) {
            return $base + [
                'grain' => 'creative',
                'campaign_id' => $normalized['campaign_id'],
                'group_id' => $normalized['group_id'],
                'creative_id' => $normalized['creative_id'],
            ];
        }

        if ($normalized['creative_name'] !== null) {
            return $base + [
                'grain' => 'creative_name_fallback',
                'campaign' => $normalized['campaign_id'] ?? $normalized['campaign_name'],
                'group' => $normalized['group_id'] ?? $normalized['group_name'],
                'creative_name' => $normalized['creative_name'],
            ];
        }

        if ($normalized['group_id'] !== null) {
            return $base + [
                'grain' => 'group',
                'campaign_id' => $normalized['campaign_id'],
                'group_id' => $normalized['group_id'],
            ];
        }

        if ($normalized['group_name'] !== null) {
            return $base + [
                'grain' => 'group_name_fallback',
                'campaign' => $normalized['campaign_id'] ?? $normalized['campaign_name'],
                'group_name' => $normalized['group_name'],
            ];
        }

        if ($normalized['campaign_id'] !== null) {
            return $base + [
                'grain' => 'campaign',
                'campaign_id' => $normalized['campaign_id'],
            ];
        }

        return $base + [
            'grain' => 'campaign_name_fallback',
            'campaign_name' => $normalized['campaign_name'],
        ];
    }

    private function identityQuality(
        ?string $campaignId,
        ?string $groupId,
        ?string $creativeId,
        ?string $campaignName,
        ?string $groupName,
        ?string $creativeName,
    ): string {
        if ($creativeId !== null) {
            return ReportingExternalMeasurement::IDENTITY_STABLE_IDS;
        }

        if ($creativeName !== null) {
            return ReportingExternalMeasurement::IDENTITY_NAME_FALLBACK;
        }

        if ($groupId !== null) {
            return ReportingExternalMeasurement::IDENTITY_STABLE_IDS;
        }

        if ($groupName !== null) {
            return ReportingExternalMeasurement::IDENTITY_NAME_FALLBACK;
        }

        if ($campaignId !== null) {
            return ReportingExternalMeasurement::IDENTITY_STABLE_IDS;
        }

        if ($campaignName !== null) {
            return ReportingExternalMeasurement::IDENTITY_NAME_FALLBACK;
        }

        throw new InvalidArgumentException(
            'Reporting external measurement requires at least one campaign, group, or creative ID/name.',
        );
    }

    /** @param array<string, mixed> $meta */
    private function normalizedMeta(array $meta): array
    {
        $allowed = [];

        foreach (['delivery_status', 'attribution_setting'] as $key) {
            $value = $meta[$key] ?? null;

            if (! is_string($value) || trim($value) === '') {
                continue;
            }

            $allowed[$key] = $this->nullableString($value, 255, "meta {$key}");
        }

        return array_filter($allowed, fn (mixed $value): bool => $value !== null);
    }

    private function nullableString(?string $value, int $max, string $label): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        if (strlen($value) > $max || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new InvalidArgumentException("Reporting external measurement {$label} is invalid or too long.");
        }

        return $value;
    }

    private function identifier(string $value, int $max, string $label): string
    {
        $value = str_replace('-', '_', strtolower(trim($value)));

        if ($value === ''
            || strlen($value) > $max
            || preg_match('/^[a-z0-9][a-z0-9._]*$/', $value) !== 1
        ) {
            throw new InvalidArgumentException("Reporting external measurement {$label} is invalid.");
        }

        return $value;
    }

    private function nullableCount(?int $value, string $label): ?int
    {
        if ($value === null) {
            return null;
        }

        if ($value < 0) {
            throw new InvalidArgumentException("Reporting external measurement {$label} cannot be negative.");
        }

        return $value;
    }

    private function nullableDecimal(int|float|string|null $value, int $scale, string $label): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $value = trim((string) $value);

        if (preg_match('/^\d+(?:\.\d+)?$/', $value) !== 1) {
            throw new InvalidArgumentException("Reporting external measurement {$label} must be a non-negative decimal value.");
        }

        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');

        if (strlen($fraction) > $scale) {
            throw new InvalidArgumentException("Reporting external measurement {$label} exceeds the supported decimal precision.");
        }

        $fraction = str_pad($fraction, $scale, '0');

        if (strlen($whole) > 12) {
            throw new InvalidArgumentException("Reporting external measurement {$label} is too large.");
        }

        return ltrim($whole, '0') === ''
            ? '0.'.($fraction !== '' ? $fraction : str_repeat('0', $scale))
            : ltrim($whole, '0').'.'.$fraction;
    }
}