<?php

namespace App\Modules\Webinars\Actions;

use App\Modules\Webinars\Models\WebinarSeries;
use Illuminate\Support\Facades\DB;
use LogicException;

final class RestoreWebinarSeriesAction
{
    public function handle(WebinarSeries $series): WebinarSeries
    {
        return DB::transaction(function () use ($series): WebinarSeries {
            $locked = WebinarSeries::query()
                ->whereKey($series->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ((string) $locked->status !== 'inactive') {
                throw new LogicException('Only an archived webinar type can be restored.');
            }

            $locked->forceFill([
                'status' => 'active',
            ])->save();

            return $locked;
        }, 3);
    }
}