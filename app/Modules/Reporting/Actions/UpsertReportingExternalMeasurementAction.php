<?php

namespace App\Modules\Reporting\Actions;

use App\Modules\Reporting\Data\ReportingExternalMeasurementData;
use App\Modules\Reporting\Models\ReportingExternalMeasurement;
use Carbon\CarbonImmutable;
use DateTimeZone;
use InvalidArgumentException;

final class UpsertReportingExternalMeasurementAction
{
    public function handle(ReportingExternalMeasurementData $data): ReportingExternalMeasurement
    {
        $normalized = $this->normalize($data);
        $identityHash = hash('sha256', json_encode([
            'measurement_date' => $normalized['measurement_date'],
            'platform' => $normalized['platform'],
            'account_id' => $normalized['account_id'],
            'campaign_id' => $normalized['campaign_id'],
            'group_id' => $normalized['group_id'],
            'creative_id' => $normalized['creative_id'],
            'placement' => $normalized['placement'],
            'result_type' => $normalized['result_type'],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return ReportingExternalMeasurement::query()->updateOrCreate(
            ['identity_hash' => $identityHash],
            [
                ...$normalized,
                'identity_hash' => $identityHash,
                'imported_at' => now('UTC'),
            ],
        );
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

        return [
            'measurement_date' => CarbonImmutable::instance($data->measurementDate)->toDateString(),
            'platform' => $platform,
            'account_id' => $this->nullableString($data->accountId, 120, 'account ID'),
            'account_timezone' => $timezone,
            'campaign_id' => $this->requiredString($data->campaignId, 120, 'campaign ID'),
            'group_id' => $this->nullableString($data->groupId, 120, 'group ID'),
            'creative_id' => $this->nullableString($data->creativeId, 120, 'creative ID'),
            'campaign_name' => $this->nullableString($data->campaignName, 255, 'campaign name'),
            'group_name' => $this->nullableString($data->groupName, 255, 'group name'),
            'creative_name' => $this->nullableString($data->creativeName, 255, 'creative name'),
            'placement' => $this->nullableString($data->placement, 120, 'placement'),
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
        ];
    }

    private function requiredString(string $value, int $max, string $label): string
    {
        $normalized = $this->nullableString($value, $max, $label);

        if ($normalized === null) {
            throw new InvalidArgumentException("Reporting external measurement {$label} is required.");
        }

        return $normalized;
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