<?php

namespace App\Modules\Webinars\Actions;

use App\Modules\Webinars\Models\Webinar;
use App\Modules\Webinars\Models\WebinarOccurrenceSuppression;
use App\Modules\Webinars\Models\WebinarSeries;
use Illuminate\Support\Facades\DB;
use LogicException;

final class RestoreRemovedWebinarOccurrenceAction
{
    public function __construct(
        private readonly FlushWebinarCachesAction $flushWebinarCaches,
    ) {}

    public function restoreHidden(Webinar $webinar): WebinarSeries
    {
        return DB::transaction(function () use ($webinar): WebinarSeries {
            $locked = Webinar::query()
                ->with('webinarSeries')
                ->lockForUpdate()
                ->findOrFail($webinar->getKey());

            if (! $locked->isHidden()) {
                throw new LogicException('This webinar session is not removed.');
            }

            $series = $locked->webinarSeries;

            if (! $series instanceof WebinarSeries) {
                throw new LogicException(
                    'A removed webinar session must belong to a webinar type before it can be restored.',
                );
            }

            $locked->forceFill([
                'hidden_at' => null,
                'hidden_reason' => null,
            ])->save();

            $this->flushWebinarCaches->handle(seriesSlug: $series->slug);

            return $series;
        });
    }

    public function restoreSuppression(
        WebinarOccurrenceSuppression $suppression,
    ): WebinarSeries {
        return DB::transaction(function () use ($suppression): WebinarSeries {
            $locked = WebinarOccurrenceSuppression::query()
                ->with('webinarSeries')
                ->lockForUpdate()
                ->findOrFail($suppression->getKey());

            $series = $locked->webinarSeries;

            if (! $series instanceof WebinarSeries) {
                throw new LogicException(
                    'A removed provider session must belong to a webinar type before it can be restored.',
                );
            }

            $locked->delete();

            $this->flushWebinarCaches->handle(seriesSlug: $series->slug);

            return $series;
        });
    }
}