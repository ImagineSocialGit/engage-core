<?php

namespace App\Actions\Webinars;

use App\Models\Webinar;
use App\Models\WebinarSeries;
use Illuminate\Support\Facades\DB;

class AdvanceWebinarSeriesStatusAction
{
    public function execute(WebinarSeries $series): void
    {
        DB::transaction(function () use ($series): void {
            $activeWebinar = $series->webinars()
                ->where('status', 'active')
                ->orderBy('starts_at')
                ->first();

            if (! $activeWebinar) {
                $this->promoteNextScheduledWebinar($series);

                return;
            }

            if ($activeWebinar->ends_at && $activeWebinar->ends_at->isPast()) {
                $activeWebinar->update([
                    'status' => 'completed',
                ]);

                $this->promoteNextScheduledWebinar($series);
            }
        });
    }

    protected function promoteNextScheduledWebinar(WebinarSeries $series): void
    {
        $nextScheduledWebinar = $series->webinars()
            ->where('status', 'scheduled')
            ->where('ends_at', '>', now())
            ->orderBy('starts_at')
            ->first();

        if (! $nextScheduledWebinar) {
            return;
        }

        Webinar::query()
            ->where('series_id', $series->id)
            ->where('status', 'active')
            ->update([
                'status' => 'completed',
            ]);

        $nextScheduledWebinar->update([
            'status' => 'active',
        ]);
    }
}