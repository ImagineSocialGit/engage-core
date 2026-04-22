<?php

namespace App\Console\Commands;

use App\Actions\Webinars\AdvanceWebinarSeriesStatusAction;
use App\Models\WebinarSeries;
use Illuminate\Console\Command;

class AdvanceWebinarSeriesStatuses extends Command
{
    protected $signature = 'webinars:advance-series';

    protected $description = 'Advance webinar series statuses (active → completed, scheduled → active)';

    public function handle(AdvanceWebinarSeriesStatusAction $action): int
    {
        WebinarSeries::query()->each(function ($series) use ($action) {
            $action->execute($series);
        });

        return self::SUCCESS;
    }
}