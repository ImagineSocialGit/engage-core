<?php

namespace App\Modules\Reporting\Contracts;

use App\Modules\Reporting\Data\ScheduledReportResult;
use Carbon\CarbonInterface;

interface ScheduledReportProvider
{
    public const TAG = 'reporting.scheduled_report_providers';

    public function key(): string;

    public function label(): string;

    public function description(): string;

    public function settingsView(): string;

    /** @return array<string, mixed> */
    public function settingsData(array $parameters = []): array;

    /** @return array<string, mixed> */
    public function defaultParameters(): array;

    /** @return array<string, mixed> */
    public function normalizeParameters(array $input): array;

    public function build(
        array $parameters,
        CarbonInterface $generatedAt,
        string $timezone,
    ): ScheduledReportResult;
}