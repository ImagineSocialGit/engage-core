<?php

namespace App\Modules\Reporting\Console\Commands;

use App\Modules\Reporting\Actions\ProjectReportingDailyMetricsAction;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

class ProjectReportingMetricsCommand extends Command
{
    protected $signature = 'reporting:project
        {--days=2 : Number of client-local calendar days to rebuild, ending today}
        {--through= : Optional client-local YYYY-MM-DD end date}';

    protected $description = 'Rebuild privacy-safe Reporting daily metrics from browser observations and registered producer facts.';

    public function handle(
        ProjectReportingDailyMetricsAction $project,
    ): int {
        $days = (int) $this->option('days');
        $maximumDays = max(
            1,
            (int) config('reporting.retention.raw_observations_days', 45),
        );

        if ($days < 1 || $days > $maximumDays) {
            $this->error(
                "Reporting projection days must be between 1 and {$maximumDays}.",
            );

            return self::FAILURE;
        }

        $timezone = $this->reportingTimezone();

        $throughInput = $this->option('through');

        try {
            $through = $throughInput
                ? CarbonImmutable::createFromFormat(
                    '!Y-m-d',
                    (string) $throughInput,
                    $timezone,
                )
                : CarbonImmutable::now($timezone)->startOfDay();
        } catch (Throwable) {
            $through = null;
        }

        if (! $through instanceof CarbonImmutable
            || ($throughInput
                && $through->format('Y-m-d') !== (string) $throughInput)
        ) {
            $this->error(
                'Reporting --through must be a valid YYYY-MM-DD client-local date.',
            );

            return self::FAILURE;
        }

        $from = $through
            ->startOfDay()
            ->subDays($days - 1);

        $result = $project->handle(
            fromDate: $from,
            throughDate: $through,
        );

        $this->info(sprintf(
            'Reporting projection rebuilt %d day(s) and wrote %d metric row(s) through %s.',
            $result['days'],
            $result['metrics'],
            $result['projected_through'],
        ));

        return self::SUCCESS;
    }

    private function reportingTimezone(): string
    {
        $timezone = config(
            'client.timezone',
            config('app.timezone', 'UTC'),
        );

        return is_string($timezone) && trim($timezone) !== ''
            ? trim($timezone)
            : 'UTC';
    }
}