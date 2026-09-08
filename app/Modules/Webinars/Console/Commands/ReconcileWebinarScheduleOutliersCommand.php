<?php

namespace App\Modules\Webinars\Console\Commands;

use App\Modules\Webinars\Actions\RemoveWebinarOccurrenceAction;
use App\Modules\Webinars\Models\Webinar;
use App\Modules\Webinars\Models\WebinarSeries;
use App\Modules\Webinars\Services\WebinarProviderSchedulePolicy;
use Illuminate\Console\Command;

final class ReconcileWebinarScheduleOutliersCommand extends Command
{
    protected $signature = 'webinars:reconcile-schedule-outliers
        {--series= : Limit to one Webinar Type ID or slug}
        {--apply : Remove or hide detected provider schedule outliers}';

    protected $description = 'Find provider occurrences whose start time does not match the configured scheduling grid.';

    public function __construct(
        private readonly WebinarProviderSchedulePolicy $schedulePolicy,
        private readonly RemoveWebinarOccurrenceAction $removeOccurrence,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $series = $this->resolveSeries();

        if ($this->option('series') !== null && $series === null) {
            $this->error('The requested Webinar Type was not found.');

            return self::FAILURE;
        }

        $query = Webinar::query()
            ->with('webinarSeries')
            ->visible()
            ->orderBy('starts_at')
            ->orderBy('id');

        if ($series instanceof WebinarSeries) {
            $query->where('webinar_series_id', $series->getKey());
        }

        $outliers = $query
            ->get()
            ->filter(fn (Webinar $webinar): bool =>
                $this->schedulePolicy->isConfiguredScheduleOutlier($webinar)
            )
            ->values();

        if ($outliers->isEmpty()) {
            $this->info('No provider schedule outliers found.');

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Webinar Type', 'Provider type', 'Starts at', 'Registrations'],
            $outliers->map(fn (Webinar $webinar): array => [
                (int) $webinar->getKey(),
                $webinar->webinarSeries?->title ?? 'Unknown',
                $webinar->providerKey().':'.$webinar->providerEventTypeKey(),
                $webinar->starts_at?->copy()->setTimezone($webinar->timezone)->format('Y-m-d H:i:s T')
                    ?? 'Date unavailable',
                $webinar->registrations()->count(),
            ])->all(),
        );

        if (! $this->option('apply')) {
            $this->newLine();
            $this->comment('Dry run only. Re-run with --apply to remove or hide these occurrences through the normal Webinar removal contract.');

            return self::SUCCESS;
        }

        $deleted = 0;
        $hidden = 0;

        foreach ($outliers as $webinar) {
            $result = $this->removeOccurrence->handle($webinar);

            if ($result['outcome'] === 'deleted') {
                $deleted++;
            } else {
                $hidden++;
            }
        }

        $this->info(sprintf(
            'Schedule outliers reconciled. Deleted: %d. Hidden with history preserved: %d.',
            $deleted,
            $hidden,
        ));

        return self::SUCCESS;
    }

    private function resolveSeries(): ?WebinarSeries
    {
        $value = $this->option('series');

        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        $value = trim((string) $value);

        $query = WebinarSeries::query()->where('slug', $value);

        if (ctype_digit($value)) {
            $query->orWhere('id', (int) $value);
        }

        return $query->first();
    }
}