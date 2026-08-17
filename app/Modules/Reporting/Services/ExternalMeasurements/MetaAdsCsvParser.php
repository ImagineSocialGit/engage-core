<?php

namespace App\Modules\Reporting\Services\ExternalMeasurements;

use App\Modules\Reporting\Data\ReportingExternalMeasurementData;
use Carbon\CarbonImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Throwable;

final class MetaAdsCsvParser
{
    private const MAX_ROWS = 5000;

    /**
     * @return array{
     *     measurements: array<int, ReportingExternalMeasurementData>,
     *     preview_rows: array<int, array<string, mixed>>,
     *     headers: array<int, string>,
     *     row_count: int,
     *     valid_count: int,
     *     skipped_count: int,
     *     identity_counts: array{stable_ids: int, name_fallback: int},
     *     currencies: array<int, string>,
     *     periods: array<int, string>,
     *     warnings: array<int, string>,
     *     errors: array<int, string>
     * }
     */
    public function parse(
        string $path,
        ?string $accountId = null,
        ?string $accountTimezone = null,
        ?string $sourceFileHash = null,
    ): array {
        $handle = fopen($path, 'r');

        if ($handle === false) {
            throw new InvalidArgumentException('The Meta Ads CSV could not be opened.');
        }

        try {
            $headers = fgetcsv($handle);

            if (! is_array($headers) || $headers === []) {
                throw new InvalidArgumentException('The Meta Ads CSV does not contain a valid header row.');
            }

            $headers = array_values(array_map(
                fn (mixed $header): string => $this->cleanHeader((string) $header),
                $headers,
            ));

            $index = $this->headerIndex($headers);
            $periodStartHeader = $this->firstHeader($index, ['reporting starts', 'day', 'date']);
            $periodEndHeader = $this->firstHeader($index, ['reporting ends']) ?? $periodStartHeader;

            if ($periodStartHeader === null) {
                throw new InvalidArgumentException(
                    'The Meta Ads CSV must include Reporting starts, Day, or Date.',
                );
            }

            $spendHeader = $this->spendHeader($headers);
            $currencyFromHeader = $spendHeader !== null
                ? $this->currencyFromSpendHeader($spendHeader)
                : null;
            $accountTimezone = $this->normalizeTimezoneAlias($accountTimezone);
            $measurements = [];
            $previewRows = [];
            $errors = [];
            $rowCount = 0;
            $stableCount = 0;
            $fallbackCount = 0;
            $currencies = [];
            $periods = [];

            while (($values = fgetcsv($handle)) !== false) {
                if ($this->blankCsvRow($values)) {
                    continue;
                }

                $rowCount++;

                if ($rowCount > self::MAX_ROWS) {
                    throw new InvalidArgumentException(
                        'The Meta Ads CSV contains more than '.number_format(self::MAX_ROWS).' data rows.',
                    );
                }

                $values = array_pad($values, count($headers), null);
                $row = array_combine(
                    $headers,
                    array_slice($values, 0, count($headers)),
                );

                if (! is_array($row)) {
                    $errors[] = "Row {$rowCount}: could not be read.";
                    continue;
                }

                try {
                    $measurement = $this->measurement(
                        row: $row,
                        index: $index,
                        periodStartHeader: $periodStartHeader,
                        periodEndHeader: $periodEndHeader,
                        spendHeader: $spendHeader,
                        currencyFromHeader: $currencyFromHeader,
                        accountIdOverride: $accountId,
                        accountTimezone: $accountTimezone,
                        sourceFileHash: $sourceFileHash,
                    );
                } catch (InvalidArgumentException $exception) {
                    if (count($errors) < 20) {
                        $errors[] = "Row {$rowCount}: {$exception->getMessage()}";
                    }

                    continue;
                }

                $measurements[] = $measurement;
                $identityQuality = $this->identityQuality($measurement);

                if ($identityQuality === 'stable_ids') {
                    $stableCount++;
                } else {
                    $fallbackCount++;
                }

                if ($measurement->currency !== null) {
                    $currencies[$measurement->currency] = true;
                }

                $periodLabel = $measurement->periodStart->format('Y-m-d')
                    .' → '.$measurement->periodEnd->format('Y-m-d');
                $periods[$periodLabel] = true;

                if (count($previewRows) < 10) {
                    $previewRows[] = [
                        'period' => $periodLabel,
                        'campaign' => $measurement->campaignName,
                        'group' => $measurement->groupName,
                        'creative' => $measurement->creativeName,
                        'identity_quality' => $identityQuality,
                        'spend' => $measurement->spend,
                        'currency' => $measurement->currency,
                        'impressions' => $measurement->impressions,
                        'link_clicks' => $measurement->linkClicks,
                        'landing_page_views' => $measurement->landingPageViews,
                        'result_type' => $measurement->resultType,
                        'results' => $measurement->results,
                    ];
                }
            }
        } finally {
            fclose($handle);
        }

        if ($rowCount === 0) {
            throw new InvalidArgumentException('The Meta Ads CSV contains no data rows.');
        }

        if ($measurements === []) {
            throw new InvalidArgumentException(
                $errors[0] ?? 'The Meta Ads CSV contains no importable rows.',
            );
        }

        $warnings = [];

        if ($fallbackCount > 0) {
            $warnings[] = $stableCount === 0
                ? 'This export does not include stable campaign/ad IDs. It can be imported, but exact automatic ad-to-Engage reconciliation will not be available for these rows.'
                : 'Some rows do not include stable campaign/ad IDs and will use name fallback identity.';
        }

        if ($errors !== []) {
            $warnings[] = count($errors).' row(s) could not be normalized and will be skipped.';
        }

        if (count($periods) > 1) {
            $warnings[] = 'The export contains more than one reporting period. Each period will remain separate.';
        }

        if (count($currencies) > 1) {
            $warnings[] = 'The export contains more than one currency. Spend will not be combined across currencies.';
        }

        return [
            'measurements' => $measurements,
            'preview_rows' => $previewRows,
            'headers' => $headers,
            'row_count' => $rowCount,
            'valid_count' => count($measurements),
            'skipped_count' => $rowCount - count($measurements),
            'identity_counts' => [
                'stable_ids' => $stableCount,
                'name_fallback' => $fallbackCount,
            ],
            'currencies' => array_keys($currencies),
            'periods' => array_keys($periods),
            'warnings' => $warnings,
            'errors' => $errors,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, string> $index
     */
    private function measurement(
        array $row,
        array $index,
        string $periodStartHeader,
        string $periodEndHeader,
        ?string $spendHeader,
        ?string $currencyFromHeader,
        ?string $accountIdOverride,
        ?string $accountTimezone,
        ?string $sourceFileHash,
    ): ReportingExternalMeasurementData {
        $periodStart = $this->date($row[$periodStartHeader] ?? null, 'reporting start');
        $periodEnd = $this->date($row[$periodEndHeader] ?? null, 'reporting end');

        if ($periodEnd->lessThan($periodStart)) {
            throw new InvalidArgumentException('reporting end is before reporting start.');
        }

        $campaignName = $this->value($row, $index, ['campaign name']);
        $campaignId = $this->value($row, $index, ['campaign id']);
        $groupName = $this->value($row, $index, ['ad set name', 'ad group name']);
        $groupId = $this->value($row, $index, ['ad set id', 'ad group id']);
        $creativeName = $this->value($row, $index, ['ad name']);
        $creativeId = $this->value($row, $index, ['ad id']);

        if ($campaignId === null
            && $groupId === null
            && $creativeId === null
            && $campaignName === null
            && $groupName === null
            && $creativeName === null
        ) {
            throw new InvalidArgumentException('no campaign, ad-set/group, or ad identity was found.');
        }

        $results = $this->decimal($this->value($row, $index, ['results']), 'results');
        $resultIndicator = $this->value($row, $index, ['result indicator']);
        $resultType = $this->canonicalResultType($resultIndicator);
        $linkClicks = $this->count($this->value($row, $index, ['link clicks']), 'link clicks');
        $outboundClicks = $this->count($this->value($row, $index, ['outbound clicks']), 'outbound clicks');
        $landingPageViews = $this->count($this->value(
            $row,
            $index,
            ['landing-page views', 'landing page views'],
        ), 'landing-page views');

        if ($results !== null) {
            $resultCount = $this->decimalAsCount($results);

            if ($resultType === 'link_click' && $linkClicks === null) {
                $linkClicks = $resultCount;
            }

            if ($resultType === 'outbound_click' && $outboundClicks === null) {
                $outboundClicks = $resultCount;
            }

            if ($resultType === 'landing_page_view' && $landingPageViews === null) {
                $landingPageViews = $resultCount;
            }
        }

        $currency = $currencyFromHeader;
        $spend = $spendHeader !== null
            ? $this->decimal($row[$spendHeader] ?? null, 'amount spent')
            : null;
        $rowAccountId = $this->value($row, $index, ['account id', 'ad account id']);

        return new ReportingExternalMeasurementData(
            periodStart: $periodStart,
            periodEnd: $periodEnd,
            platform: 'meta',
            source: 'meta_ads_csv',
            accountId: $this->nullableTrim($accountIdOverride) ?? $rowAccountId,
            accountTimezone: $accountTimezone,
            campaignId: $campaignId,
            groupId: $groupId,
            creativeId: $creativeId,
            campaignName: $campaignName,
            groupName: $groupName,
            creativeName: $creativeName,
            placement: $this->value($row, $index, ['placement']),
            currency: $currency,
            impressions: $this->count($this->value($row, $index, ['impressions']), 'impressions'),
            reach: $this->count($this->value($row, $index, ['reach']), 'reach'),
            linkClicks: $linkClicks,
            outboundClicks: $outboundClicks,
            landingPageViews: $landingPageViews,
            spend: $spend,
            resultType: $resultType,
            results: $results,
            sourceFileHash: $sourceFileHash,
            meta: array_filter([
                'delivery_status' => $this->value($row, $index, ['ad delivery', 'delivery']),
                'attribution_setting' => $this->value($row, $index, ['attribution setting']),
            ], fn (mixed $value): bool => $value !== null),
        );
    }

    /** @param array<int, mixed> $values */
    private function blankCsvRow(array $values): bool
    {
        foreach ($values as $value) {
            if (trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }

    private function cleanHeader(string $header): string
    {
        $header = preg_replace('/^\xEF\xBB\xBF/', '', $header) ?? $header;

        return trim($header);
    }

    /**
     * @param array<int, string> $headers
     * @return array<string, string>
     */
    private function headerIndex(array $headers): array
    {
        $index = [];

        foreach ($headers as $header) {
            $key = strtolower(trim($header));

            if ($key !== '' && ! isset($index[$key])) {
                $index[$key] = $header;
            }
        }

        return $index;
    }

    /**
     * @param array<string, string> $index
     * @param array<int, string> $aliases
     */
    private function firstHeader(array $index, array $aliases): ?string
    {
        foreach ($aliases as $alias) {
            if (isset($index[$alias])) {
                return $index[$alias];
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, string> $index
     * @param array<int, string> $aliases
     */
    private function value(array $row, array $index, array $aliases): ?string
    {
        $header = $this->firstHeader($index, $aliases);

        return $header !== null
            ? $this->nullableTrim($row[$header] ?? null)
            : null;
    }

    /** @param array<int, string> $headers */
    private function spendHeader(array $headers): ?string
    {
        foreach ($headers as $header) {
            if (preg_match('/^Amount spent(?: \([A-Za-z]{3}\))?$/i', $header) === 1) {
                return $header;
            }
        }

        return null;
    }

    private function currencyFromSpendHeader(string $header): ?string
    {
        if (preg_match('/\(([A-Za-z]{3})\)$/', $header, $matches) !== 1) {
            return null;
        }

        return strtoupper($matches[1]);
    }

    private function canonicalResultType(?string $indicator): ?string
    {
        if ($indicator === null) {
            return null;
        }

        return match (strtolower($indicator)) {
            'actions:link_click' => 'link_click',
            'actions:outbound_click' => 'outbound_click',
            'actions:omni_landing_page_view', 'actions:landing_page_view' => 'landing_page_view',
            default => mb_substr(strtolower($indicator), 0, 80),
        };
    }

    private function date(mixed $value, string $label): CarbonImmutable
    {
        $value = $this->nullableTrim($value);

        if ($value === null) {
            throw new InvalidArgumentException("{$label} is missing.");
        }

        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, 'UTC');
        } catch (Throwable) {
            $date = null;
        }

        if (! $date instanceof CarbonImmutable || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException("{$label} must use YYYY-MM-DD.");
        }

        return $date;
    }

    private function count(?string $value, string $label): ?int
    {
        if ($this->missingMetricValue($value)) {
            return null;
        }

        $normalized = str_replace([',', ' '], '', (string) $value);

        if (preg_match('/^\d+$/', $normalized) !== 1) {
            throw new InvalidArgumentException("{$label} must be a non-negative whole number.");
        }

        return (int) $normalized;
    }

    private function decimal(?string $value, string $label): ?string
    {
        if ($this->missingMetricValue($value)) {
            return null;
        }

        $normalized = str_replace([',', '$', ' '], '', (string) $value);

        if (preg_match('/^\d+(?:\.\d+)?$/', $normalized) !== 1) {
            throw new InvalidArgumentException("{$label} must be a non-negative number.");
        }

        return $normalized;
    }

    private function missingMetricValue(?string $value): bool
    {
        if ($value === null) {
            return true;
        }

        return in_array(strtolower(trim($value)), [
            '',
            '-',
            '--',
            '—',
            'n/a',
            'na',
            'not available',
        ], true);
    }

    private function decimalAsCount(string $value): ?int
    {
        $numeric = (float) $value;
        $rounded = (int) round($numeric);

        return abs($numeric - $rounded) < 0.000001
            ? $rounded
            : null;
    }

    private function nullableTrim(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    private function normalizeTimezoneAlias(?string $timezone): ?string
    {
        $timezone = $this->nullableTrim($timezone);

        if ($timezone === null) {
            return null;
        }

        $normalized = match (strtolower($timezone)) {
            'eastern time', 'et' => 'America/New_York',
            'central time', 'ct' => 'America/Chicago',
            'mountain time', 'mt' => 'America/Denver',
            'pacific time', 'pt' => 'America/Los_Angeles',
            default => $timezone,
        };

        try {
            new DateTimeZone($normalized);
        } catch (Throwable) {
            throw new InvalidArgumentException(
                'Ad account timezone must be an IANA timezone or a supported US timezone label.',
            );
        }

        return $normalized;
    }

    private function identityQuality(ReportingExternalMeasurementData $measurement): string
    {
        if ($measurement->creativeName !== null || $measurement->creativeId !== null) {
            return $measurement->creativeId !== null ? 'stable_ids' : 'name_fallback';
        }

        if ($measurement->groupName !== null || $measurement->groupId !== null) {
            return $measurement->groupId !== null ? 'stable_ids' : 'name_fallback';
        }

        return $measurement->campaignId !== null ? 'stable_ids' : 'name_fallback';
    }
}