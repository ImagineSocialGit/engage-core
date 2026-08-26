<?php

namespace App\Modules\Core\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Models\BusinessCalendarExclusion;
use App\Modules\Core\Requests\UpdateBusinessCalendarRequest;
use App\Modules\Core\Services\BusinessCalendar\DefaultBusinessCalendarResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

class BusinessCalendarController extends Controller
{
    public function __construct(
        private readonly DefaultBusinessCalendarResolver $calendars,
    ) {}

    public function edit(): View
    {
        return view('crm.business-calendar.edit', [
            'calendar' => $this->calendars->resolve(),
            'weekdays' => [
                1 => 'Monday',
                2 => 'Tuesday',
                3 => 'Wednesday',
                4 => 'Thursday',
                5 => 'Friday',
                6 => 'Saturday',
                7 => 'Sunday',
            ],
        ]);
    }

    public function update(UpdateBusinessCalendarRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        DB::transaction(function () use ($validated): void {
            $calendar = $this->calendars->resolve();
            $calendar->forceFill([
                'skipped_weekdays' => collect($validated['skipped_weekdays'] ?? [])
                    ->map(fn (mixed $day): int => (int) $day)
                    ->unique()
                    ->sort()
                    ->values()
                    ->all(),
            ])->save();

            $retainedKeys = [];

            foreach ($validated['exclusions'] ?? [] as $exclusion) {
                $key = is_string($exclusion['key'] ?? null)
                    ? $exclusion['key']
                    : (string) Str::uuid();
                $recurrence = (string) $exclusion['recurrence'];

                BusinessCalendarExclusion::query()->updateOrCreate(
                    [
                        'business_calendar_id' => $calendar->getKey(),
                        'key' => $key,
                    ],
                    [
                        'name' => trim((string) $exclusion['name']),
                        'recurrence' => $recurrence,
                        'exact_date' => $recurrence === BusinessCalendarExclusion::RECURRENCE_ONCE
                            ? $exclusion['exact_date']
                            : null,
                        'month' => $recurrence === BusinessCalendarExclusion::RECURRENCE_ANNUAL
                            ? (int) $exclusion['month']
                            : null,
                        'day' => $recurrence === BusinessCalendarExclusion::RECURRENCE_ANNUAL
                            ? (int) $exclusion['day']
                            : null,
                    ],
                );

                $retainedKeys[] = $key;
            }

            $calendar->exclusions()
                ->when(
                    $retainedKeys !== [],
                    fn ($query) => $query->whereNotIn('key', $retainedKeys),
                )
                ->delete();
        });

        return redirect()
            ->route('crm.business-calendar.edit')
            ->with('status', 'Business days updated.');
    }
}