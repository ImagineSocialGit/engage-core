<?php

namespace App\Modules\Scheduling\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Modules\Scheduling\Enums\SchedulingAvailabilityWindowType;
use App\Modules\Scheduling\Models\BookableService;
use App\Modules\Scheduling\Models\SchedulingAvailabilityWindow;
use App\Modules\Scheduling\Models\SchedulingHost;
use App\Modules\Scheduling\Services\SchedulingAvailabilityConfigurationWriter;
use App\Modules\Scheduling\Services\SchedulingAvailableStartRangeBuilder;
use App\Modules\Scheduling\Services\SchedulingReadService;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;
use LogicException;

class SchedulingAvailabilityController extends Controller
{
    public function index(
        Request $request,
        SchedulingReadService $read,
        SchedulingAvailableStartRangeBuilder $startRanges,
    ): View {
        $validated = $request->validate([
            'service_id' => [
                'nullable',
                'integer',
                Rule::exists('bookable_services', 'id')
                    ->where(fn ($query) => $query
                        ->whereNull('deleted_at')
                        ->where('status', BookableService::STATUS_ACTIVE)),
            ],
            'preview_host_id' => [
                'nullable',
                'integer',
                Rule::exists('scheduling_hosts', 'id')
                    ->where(fn ($query) => $query
                        ->whereNull('deleted_at')
                        ->where('status', SchedulingHost::STATUS_ACTIVE)),
            ],
            'preview_date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $services = $read->configurationServices();
        $hosts = $read->configurationHosts();
        $windows = $read->configurationAvailabilityWindows();
        $activeServices = $services
            ->where('status', BookableService::STATUS_ACTIVE)
            ->values();
        $selectedService = isset($validated['service_id'])
            ? $activeServices->firstWhere('id', (int) $validated['service_id'])
            : $activeServices->first();

        if (! $selectedService instanceof BookableService) {
            $selectedService = null;
        }

        $previewHosts = $selectedService instanceof BookableService
            ? $read->eligibleHosts($selectedService)
            : collect();
        $previewRequiresHost = $selectedService instanceof BookableService
            && $read->serviceRequiresHost($selectedService);
        $previewHost = null;

        if (isset($validated['preview_host_id'])) {
            $previewHost = $previewHosts->firstWhere(
                'id',
                (int) $validated['preview_host_id'],
            );

            if (! $previewHost instanceof SchedulingHost) {
                throw ValidationException::withMessages([
                    'preview_host_id' => 'The selected staff member or provider is not actively assigned to that service.',
                ]);
            }
        } elseif ($previewRequiresHost) {
            $previewHost = $previewHosts->first();

            if (! $previewHost instanceof SchedulingHost) {
                $previewHost = null;
            }
        }

        $timezone = $selectedService?->timezone
            ?? config('client.timezone', config('app.timezone', 'UTC'));
        $previewDate = $validated['preview_date']
            ?? CarbonImmutable::now($timezone)->toDateString();
        $previewSlots = [];

        if ($selectedService instanceof BookableService
            && (! $read->serviceRequiresHost($selectedService)
                || $previewHost instanceof SchedulingHost)
        ) {
            $previewSlots = $read->availabilityForDate(
                service: $selectedService,
                date: CarbonImmutable::createFromFormat(
                    '!Y-m-d',
                    $previewDate,
                    $selectedService->timezone,
                ),
                host: $previewHost,
            );
        }

        $previewStartRanges = $selectedService instanceof BookableService
            && $selectedService->usesFixedDuration()
                ? $startRanges->build(
                    slots: $previewSlots,
                    intervalMinutes: max(1, (int) $selectedService->slot_interval_minutes),
                )
                : [];

        return view('crm.scheduling.availability', [
            'title' => 'Scheduling Availability',
            'heading' => 'Availability',
            'services' => $services,
            'activeServices' => $activeServices,
            'hosts' => $hosts,
            'windows' => $windows,
            'timezones' => timezone_identifiers_list(),
            'defaultTimezone' => config(
                'client.timezone',
                config('app.timezone', 'UTC'),
            ),
            'selectedService' => $selectedService,
            'regularHours' => $this->regularHoursForService(
                $windows,
                $selectedService,
            ),
            'dateChanges' => $this->dateChangesForService(
                $windows,
                $selectedService,
            ),
            'previewHosts' => $previewHosts,
            'previewRequiresHost' => $previewRequiresHost,
            'previewHost' => $previewHost,
            'previewDate' => $previewDate,
            'previewSlots' => $previewSlots,
            'previewStartRanges' => $previewStartRanges,
        ]);
    }

    public function saveRegularHours(
        Request $request,
        BookableService $bookableService,
        SchedulingAvailabilityConfigurationWriter $writer,
    ): RedirectResponse {
        $this->assertActiveService($bookableService);
        $this->assertAllowedFields($request, ['regular_hours']);
        $validated = $request->validate([
            'regular_hours' => ['required', 'array', 'size:7'],
            'regular_hours.*.weekday' => [
                'required',
                'integer',
                'between:0,6',
                'distinct',
            ],
            'regular_hours.*.ranges' => ['nullable', 'array', 'max:8'],
            'regular_hours.*.ranges.*.start' => [
                'required',
                'date_format:H:i',
            ],
            'regular_hours.*.ranges.*.end' => [
                'required',
                'date_format:H:i',
            ],
        ]);

        $ranges = [];

        foreach ($validated['regular_hours'] as $day) {
            foreach (($day['ranges'] ?? []) as $range) {
                $ranges[] = [
                    'weekday' => (int) $day['weekday'],
                    'start_time' => $range['start'],
                    'end_time' => $range['end'],
                ];
            }
        }

        try {
            $writer->replaceRegularHours($bookableService, $ranges);
        } catch (DomainException|InvalidArgumentException|LogicException $exception) {
            throw $this->availabilityException($exception);
        }

        return $this->businessRedirect(
            service: $bookableService,
            message: 'Regular hours updated.',
        );
    }

    public function saveSpecialHours(
        Request $request,
        BookableService $bookableService,
        SchedulingAvailabilityConfigurationWriter $writer,
    ): RedirectResponse {
        $this->assertActiveService($bookableService);
        $this->assertAllowedFields($request, ['date', 'ranges']);
        $validated = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'ranges' => ['required', 'array', 'min:1', 'max:8'],
            'ranges.*.start' => ['required', 'date_format:H:i'],
            'ranges.*.end' => ['required', 'date_format:H:i'],
        ]);

        $ranges = array_map(
            static fn (array $range): array => [
                'start_time' => $range['start'],
                'end_time' => $range['end'],
            ],
            $validated['ranges'],
        );

        try {
            $writer->replaceSpecialHours(
                service: $bookableService,
                date: $validated['date'],
                ranges: $ranges,
            );
        } catch (DomainException|InvalidArgumentException|LogicException $exception) {
            throw $this->availabilityException($exception);
        }

        return $this->businessRedirect(
            service: $bookableService,
            message: 'Special hours saved for '.$validated['date'].'.',
        );
    }

    public function storeTimeOff(
        Request $request,
        BookableService $bookableService,
        SchedulingAvailabilityConfigurationWriter $writer,
    ): RedirectResponse {
        $this->assertActiveService($bookableService);
        $this->assertAllowedFields(
            $request,
            ['date', 'all_day', 'start_time', 'end_time'],
        );
        $validated = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'all_day' => ['nullable', 'boolean'],
            'start_time' => ['nullable', 'date_format:H:i'],
            'end_time' => ['nullable', 'date_format:H:i'],
        ]);
        $allDay = $request->boolean('all_day');

        try {
            if ($allDay) {
                $writer->replaceSpecialHours(
                    service: $bookableService,
                    date: $validated['date'],
                    ranges: [],
                );
            } else {
                if (! isset($validated['start_time'], $validated['end_time'])) {
                    throw ValidationException::withMessages([
                        'start_time' => 'Choose a start and end time, or mark the whole day unavailable.',
                    ]);
                }

                $writer->createTimeOff(
                    service: $bookableService,
                    date: $validated['date'],
                    startTime: $validated['start_time'],
                    endTime: $validated['end_time'],
                );
            }
        } catch (DomainException|InvalidArgumentException|LogicException $exception) {
            throw $this->availabilityException($exception);
        }

        return $this->businessRedirect(
            service: $bookableService,
            message: $allDay
                ? 'The service is unavailable for the selected day.'
                : 'Unavailable time added.',
        );
    }

    public function clearDateChanges(
        BookableService $bookableService,
        string $date,
        SchedulingAvailabilityConfigurationWriter $writer,
    ): RedirectResponse {
        $this->assertActiveService($bookableService);

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            throw ValidationException::withMessages([
                'date' => 'Choose a valid date.',
            ]);
        }

        try {
            $writer->clearDateChanges(
                service: $bookableService,
                date: $date,
            );
        } catch (DomainException|InvalidArgumentException|LogicException $exception) {
            throw $this->availabilityException($exception);
        }

        return $this->businessRedirect(
            service: $bookableService,
            message: 'The one-off change was removed. Regular hours apply again.',
        );
    }

    public function store(
        Request $request,
        SchedulingAvailabilityConfigurationWriter $writer,
    ): RedirectResponse {
        $this->assertAllowedFields($request, $this->availabilityFieldNames());
        $validated = $request->validate($this->availabilityRules());

        try {
            $writer->create($validated);
        } catch (DomainException|InvalidArgumentException|LogicException $exception) {
            throw $this->availabilityException($exception);
        }

        return $this->availabilityRedirect('created');
    }

    public function update(
        Request $request,
        SchedulingAvailabilityWindow $availabilityWindow,
        SchedulingAvailabilityConfigurationWriter $writer,
    ): RedirectResponse {
        $this->assertAllowedFields($request, [
            'current_version',
            ...$this->availabilityFieldNames(),
        ]);
        $validated = $request->validate([
            'current_version' => ['required', 'string', 'max:80'],
            ...$this->availabilityRules(),
        ]);

        try {
            $writer->update(
                window: $availabilityWindow,
                attributes: $validated,
                expectedUpdatedAt: $validated['current_version'],
            );
        } catch (DomainException|InvalidArgumentException|LogicException $exception) {
            throw $this->availabilityException($exception);
        }

        return $this->availabilityRedirect('updated');
    }

    public function archive(
        Request $request,
        SchedulingAvailabilityWindow $availabilityWindow,
        SchedulingAvailabilityConfigurationWriter $writer,
    ): RedirectResponse {
        $this->assertAllowedFields($request, ['current_version']);
        $validated = $request->validate([
            'current_version' => ['required', 'string', 'max:80'],
        ]);

        try {
            $writer->archive(
                window: $availabilityWindow,
                expectedUpdatedAt: $validated['current_version'],
            );
        } catch (DomainException|InvalidArgumentException|LogicException $exception) {
            throw $this->availabilityException($exception);
        }

        return $this->availabilityRedirect('archived');
    }

    public function restore(
        Request $request,
        SchedulingAvailabilityWindow $availabilityWindow,
        SchedulingAvailabilityConfigurationWriter $writer,
    ): RedirectResponse {
        $this->assertAllowedFields($request, ['current_version']);
        $validated = $request->validate([
            'current_version' => ['required', 'string', 'max:80'],
        ]);

        try {
            $writer->restore(
                window: $availabilityWindow,
                expectedUpdatedAt: $validated['current_version'],
            );
        } catch (DomainException|InvalidArgumentException|LogicException $exception) {
            throw $this->availabilityException($exception);
        }

        return $this->availabilityRedirect('restored');
    }

    /**
     * @return array<string, mixed>
     */
    private function availabilityRules(): array
    {
        return [
            'scope' => [
                'required',
                'string',
                Rule::in([
                    SchedulingAvailabilityConfigurationWriter::SCOPE_SERVICE,
                    SchedulingAvailabilityConfigurationWriter::SCOPE_HOST,
                    SchedulingAvailabilityConfigurationWriter::SCOPE_SERVICE_HOST,
                ]),
            ],
            'bookable_service_id' => [
                'nullable',
                'integer',
                'required_if:scope,service,service_host',
                'prohibited_if:scope,host',
                Rule::exists('bookable_services', 'id')
                    ->where(fn ($query) => $query->whereNull('deleted_at')),
            ],
            'scheduling_host_id' => [
                'nullable',
                'integer',
                'required_if:scope,host,service_host',
                'prohibited_if:scope,service',
                Rule::exists('scheduling_hosts', 'id')
                    ->where(fn ($query) => $query->whereNull('deleted_at')),
            ],
            'window_type' => [
                'required',
                'string',
                Rule::enum(SchedulingAvailabilityWindowType::class),
            ],
            'timezone' => [
                'required',
                'string',
                Rule::in(timezone_identifiers_list()),
            ],
            'weekday' => [
                'nullable',
                'integer',
                'between:0,6',
                'required_if:window_type,weekly',
                'prohibited_if:window_type,absolute',
            ],
            'start_time' => [
                'nullable',
                'date_format:H:i',
                'required_if:window_type,weekly',
                'prohibited_if:window_type,absolute',
            ],
            'end_time' => [
                'nullable',
                'date_format:H:i',
                'required_if:window_type,weekly',
                'prohibited_if:window_type,absolute',
            ],
            'local_starts_at' => [
                'nullable',
                'date_format:Y-m-d\\TH:i',
                'required_if:window_type,absolute',
                'prohibited_if:window_type,weekly',
            ],
            'local_ends_at' => [
                'nullable',
                'date_format:Y-m-d\\TH:i',
                'required_if:window_type,absolute',
                'prohibited_if:window_type,weekly',
            ],
            'capacity' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'is_available' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<int, string>
     */
    private function availabilityFieldNames(): array
    {
        return [
            'scope',
            'bookable_service_id',
            'scheduling_host_id',
            'window_type',
            'timezone',
            'weekday',
            'start_time',
            'end_time',
            'local_starts_at',
            'local_ends_at',
            'capacity',
            'is_available',
        ];
    }

    /**
     * @param array<int, string> $allowed
     */
    private function assertAllowedFields(Request $request, array $allowed): void
    {
        $unexpected = array_values(array_diff(
            array_keys($request->all()),
            [...$allowed, '_token', '_method'],
        ));

        if ($unexpected !== []) {
            throw ValidationException::withMessages([
                'availability' => 'Unsupported availability fields were submitted.',
            ]);
        }
    }

    private function assertActiveService(BookableService $service): void
    {
        if ($service->status !== BookableService::STATUS_ACTIVE) {
            throw ValidationException::withMessages([
                'service' => 'Choose an active service before setting normal availability.',
            ]);
        }
    }

    /**
     * @param \Illuminate\Database\Eloquent\Collection<int, SchedulingAvailabilityWindow> $windows
     * @return array<int, array{
     *     weekday: int,
     *     label: string,
     *     ranges: array<int, array{start: string, end: string}>
     * }>
     */
    private function regularHoursForService(
        $windows,
        ?BookableService $service,
    ): array {
        $labels = [
            0 => 'Sunday',
            1 => 'Monday',
            2 => 'Tuesday',
            3 => 'Wednesday',
            4 => 'Thursday',
            5 => 'Friday',
            6 => 'Saturday',
        ];
        $days = [];

        foreach ($labels as $weekday => $label) {
            $days[$weekday] = [
                'weekday' => $weekday,
                'label' => $label,
                'ranges' => [],
            ];
        }

        if (! $service instanceof BookableService) {
            return array_values($days);
        }

        $simple = $windows
            ->reject(fn (SchedulingAvailabilityWindow $window): bool => $window->trashed())
            ->filter(
                fn (SchedulingAvailabilityWindow $window): bool =>
                    $window->bookable_service_id === $service->id
                    && $window->scheduling_host_id === null
                    && $window->source === SchedulingAvailabilityWindow::SOURCE_MANUAL
                    && $window->window_type === SchedulingAvailabilityWindowType::Weekly
                    && $window->is_available
                    && $window->capacity === null
                    && $window->timezone === $service->timezone,
            )
            ->sortBy([
                ['weekday', 'asc'],
                ['start_time', 'asc'],
            ]);

        foreach ($simple as $window) {
            $days[(int) $window->weekday]['ranges'][] = [
                'start' => substr((string) $window->start_time, 0, 5),
                'end' => substr((string) $window->end_time, 0, 5),
            ];
        }

        return array_values($days);
    }

    /**
     * @param \Illuminate\Database\Eloquent\Collection<int, SchedulingAvailabilityWindow> $windows
     * @return array<int, array{
     *     date: string,
     *     label: string,
     *     type: string,
     *     ranges: array<int, array{start: string, end: string}>
     * }>
     */
    private function dateChangesForService(
        $windows,
        ?BookableService $service,
    ): array {
        if (! $service instanceof BookableService) {
            return [];
        }

        $timezone = (string) $service->timezone;
        $today = CarbonImmutable::now($timezone)->toDateString();
        $groups = [];

        foreach ($windows as $window) {
            if ($window->trashed()
                || $window->bookable_service_id !== $service->id
                || $window->scheduling_host_id !== null
                || $window->source !== SchedulingAvailabilityWindow::SOURCE_MANUAL
                || $window->window_type !== SchedulingAvailabilityWindowType::Absolute
                || $window->capacity !== null
                || $window->timezone !== $timezone
                || $window->starts_at === null
                || $window->ends_at === null
            ) {
                continue;
            }

            $start = CarbonImmutable::instance($window->starts_at)
                ->setTimezone($timezone);
            $end = CarbonImmutable::instance($window->ends_at)
                ->setTimezone($timezone);
            $date = $start->toDateString();

            if ($date < $today) {
                continue;
            }

            $sameDate = $end->toDateString() === $date;
            $endsAtNextMidnight = $end->format('H:i:s') === '00:00:00'
                && $end->subDay()->toDateString() === $date;

            if (! $sameDate && ! $endsAtNextMidnight) {
                continue;
            }

            $groups[$date] ??= [
                'available' => [],
                'unavailable' => [],
            ];

            $range = [
                'start' => $start->format('H:i'),
                'end' => $endsAtNextMidnight ? '24:00' : $end->format('H:i'),
            ];

            if ($window->is_available) {
                $groups[$date]['available'][] = $range;
            } else {
                $groups[$date]['unavailable'][] = $range;
            }
        }

        ksort($groups);
        $changes = [];

        foreach ($groups as $date => $group) {
            $available = $this->mergeClockRanges($group['available']);
            $unavailable = $this->mergeClockRanges($group['unavailable']);
            $effectiveAvailable = $this->subtractClockRanges(
                ranges: $available,
                blocks: $unavailable,
            );
            $type = $available !== [] && $effectiveAvailable !== []
                ? 'special_hours'
                : 'time_off';
            $ranges = $type === 'special_hours'
                ? $effectiveAvailable
                : $unavailable;

            $changes[] = [
                'date' => $date,
                'label' => CarbonImmutable::createFromFormat(
                    '!Y-m-d',
                    $date,
                    $timezone,
                )->format('M j, Y'),
                'type' => $type,
                'ranges' => $ranges,
            ];
        }

        return $changes;
    }

    /**
     * @param array<int, array{start: string, end: string}> $ranges
     * @return array<int, array{start: string, end: string}>
     */
    private function mergeClockRanges(array $ranges): array
    {
        $segments = array_map(
            fn (array $range): array => [
                $this->clockMinutes($range['start']),
                $this->clockMinutes($range['end']),
            ],
            $ranges,
        );

        usort(
            $segments,
            static fn (array $left, array $right): int => $left[0] <=> $right[0],
        );
        $merged = [];

        foreach ($segments as [$start, $end]) {
            $lastIndex = count($merged) - 1;

            if ($lastIndex >= 0 && $start <= $merged[$lastIndex][1]) {
                $merged[$lastIndex][1] = max($merged[$lastIndex][1], $end);

                continue;
            }

            $merged[] = [$start, $end];
        }

        return array_map(
            fn (array $segment): array => [
                'start' => $this->clockFromMinutes($segment[0]),
                'end' => $this->clockFromMinutes($segment[1]),
            ],
            $merged,
        );
    }

    /**
     * @param array<int, array{start: string, end: string}> $ranges
     * @param array<int, array{start: string, end: string}> $blocks
     * @return array<int, array{start: string, end: string}>
     */
    private function subtractClockRanges(array $ranges, array $blocks): array
    {
        $remaining = array_map(
            fn (array $range): array => [
                $this->clockMinutes($range['start']),
                $this->clockMinutes($range['end']),
            ],
            $ranges,
        );
        $blocked = array_map(
            fn (array $range): array => [
                $this->clockMinutes($range['start']),
                $this->clockMinutes($range['end']),
            ],
            $blocks,
        );

        foreach ($blocked as [$blockStart, $blockEnd]) {
            $next = [];

            foreach ($remaining as [$start, $end]) {
                if ($blockEnd <= $start || $blockStart >= $end) {
                    $next[] = [$start, $end];

                    continue;
                }

                if ($blockStart > $start) {
                    $next[] = [$start, min($blockStart, $end)];
                }

                if ($blockEnd < $end) {
                    $next[] = [max($blockEnd, $start), $end];
                }
            }

            $remaining = $next;
        }

        return array_map(
            fn (array $segment): array => [
                'start' => $this->clockFromMinutes($segment[0]),
                'end' => $this->clockFromMinutes($segment[1]),
            ],
            array_values(array_filter(
                $remaining,
                static fn (array $segment): bool => $segment[0] < $segment[1],
            )),
        );
    }

    private function clockMinutes(string $time): int
    {
        if ($time === '24:00') {
            return 1440;
        }

        [$hour, $minute] = array_map('intval', explode(':', $time, 2));

        return ($hour * 60) + $minute;
    }

    private function clockFromMinutes(int $minutes): string
    {
        if ($minutes >= 1440) {
            return '24:00';
        }

        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }

    private function availabilityException(\Throwable $exception): ValidationException
    {
        return ValidationException::withMessages([
            'availability' => $exception->getMessage(),
        ]);
    }

    private function businessRedirect(
        BookableService $service,
        string $message,
    ): RedirectResponse {
        return redirect()
            ->route('crm.scheduling.configuration.availability.index', [
                'service_id' => $service->getKey(),
            ])
            ->with('success', $message);
    }

    private function availabilityRedirect(string $event): RedirectResponse
    {
        $message = match ($event) {
            'created' => 'Availability rule created.',
            'updated' => 'Availability rule updated.',
            'archived' => 'Availability rule archived.',
            'restored' => 'Availability rule restored.',
            default => 'Availability configuration updated.',
        };

        return redirect()
            ->route('crm.scheduling.configuration.availability.index')
            ->with('success', $message);
    }
}