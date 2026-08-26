<?php

namespace App\Modules\Core\Services\BusinessCalendar;

use App\Modules\Core\Models\BusinessCalendar;

class DefaultBusinessCalendarResolver
{
    public function resolve(): BusinessCalendar
    {
        $calendar = BusinessCalendar::query()
            ->defaultCalendar()
            ->orderBy('id')
            ->first();

        if (! $calendar instanceof BusinessCalendar) {
            $calendar = BusinessCalendar::query()->firstOrCreate(
                ['key' => 'default'],
                [
                    'name' => 'Business days',
                    'skipped_weekdays' => [6, 7],
                    'is_default' => true,
                ],
            );

            if (! $calendar->is_default) {
                $calendar->forceFill(['is_default' => true])->save();
            }
        }

        return $calendar->loadMissing('exclusions');
    }
}