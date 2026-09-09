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
use App\Modules\Scheduling\Services\SchedulingSetupProgress;
use App\Modules\Scheduling\Services\SchedulingConfigurationWriter;
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
        SchedulingSetupProgress $progress,
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
            'guided' => ['nullable', 'boolean'],
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
            : null;

        if (! $selectedService instanceof BookableService) {
            $selectedService = null;
        }

        $guided = $selectedService instanceof BookableService
            && (bool) ($validated['guided'] ?? false);
        $setupProgress = $selectedService instanceof BookableService
            ? $progress->forService($selectedService, 'availability')
            : null;
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
                    'preview_host_id' => 'The selected staff member or provider is not actively assigned to that appointment type.',
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
            && (! $previewRequiresHost || $previewHost instanceof SchedulingHost)
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

        $scopeOptions = [
            SchedulingAvailabilityConfigurationWriter::SCOPE_SERVICE => 'Appointment type',
            SchedulingAvailabilityConfigurationWriter::SCOPE_HOST => 'Staff/provider',
            SchedulingAvailabilityConfigurationWriter::SCOPE_SERVICE_HOST => 'Appointment type + staff/provider',
        ];
        $activeWindows = $windows
            ->reject(fn (SchedulingAvailabilityWindow $window): bool => $window->trashed())
            ->values();
        $archivedWindows = $windows
            ->filter(fn (SchedulingAvailabilityWindow $window): bool => $window->trashed())
            ->values();

        $this->decorateWindowPresentation($activeWindows, $scopeOptions, false);
        $this->decorateWindowPresentation($archivedWindows, $scopeOptions, true);

        $regularHoursState = $this->regularHoursForService(
            $windows,
            $selectedService,
        );
        $oldRegularHours = old('regular_hours');

        if (is_array($oldRegularHours)) {
            foreach ($regularHoursState as &$day) {
                $submitted = $oldRegularHours[$day['weekday']] ?? null;

                if (is_array($submitted)) {
                    $day['ranges'] = is_array($submitted['ranges'] ?? null)
                        ? array_values($submitted['ranges'])
                        : [];
                }
            }

            unset($day);
        }

        $intervalChoices = [15, 30, 60, 120];
        $currentInterval = $selectedService instanceof BookableService
            ? max(1, (int) $selectedService->slot_interval_minutes)
            : 15;
        $slotIntervalChoice = in_array($currentInterval, $intervalChoices, true)
            ? (string) $currentInterval
            : 'custom';

        return view('crm.scheduling.availability', [
            'title' => 'Scheduling Availability',
            'heading' => 'Availability',
            'services' => $services,
            'activeServices' => $activeServices,
            'hosts' => $hosts,
            'timezones' => timezone_identifiers_list(),
            'defaultTimezone' => config(
                'client.timezone',
                config('app.timezone', 'UTC'),
            ),
            'inputClass' => 'mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-200',
            'labelClass' => 'block text-sm font-medium text-slate-700',
            'weekdays' => [
                0 => 'Sunday',
                1 => 'Monday',
                2 => 'Tuesday',
                3 => 'Wednesday',
                4 => 'Thursday',
                5 => 'Friday',
                6 => 'Saturday',
            ],
            'scopeOptions' => $scopeOptions,
            'activeWindows' => $activeWindows,
            'archivedWindows' => $archivedWindows,
            'selectedService' => $selectedService,
            'guided' => $guided,
            'setupProgress' => $setupProgress,
            'availabilityOverview' => $activeServices
                ->map(function (BookableService $service) use ($progress): array {
                    $serviceProgress = $progress->forService($service, 'availability');

                    return [
                        'id' => (int) $service->getKey(),
                        'name' => (string) $service->name,
                        'has_availability' => (bool) $serviceProgress['has_availability'],
                        'interval_label' => $this->slotIntervalLabel(
                            (int) $service->slot_interval_minutes,
                        ),
                        'buffer_label' => $this->bufferLabel($service),
                        'host_summary' => (string) $service->getAttribute('active_host_summary'),
                        'url' => route('crm.scheduling.configuration.availability.index', [
                            'service_id' => $service->getKey(),
                        ]),
                    ];
                })
                ->values()
                ->all(),
            'regularHoursState' => $regularHoursState,
            'specialHoursState' => old('ranges', [
                ['start' => '09:00', 'end' => '17:00'],
            ]),
            'dateChanges' => $this->dateChangesForService(
                $windows,
                $selectedService,
            ),
            'previewHosts' => $previewHosts,
            'previewRequiresHost' => $previewRequiresHost,
            'previewHost' => $previewHost,
            'previewDate' => $previewDate,
            'previewSlots' => $previewSlots,
            'previewStartRanges' => array_map(
                fn (array $range): array => [
                    ...$range,
                    'first_iso' => $range['starts_at']->toISOString(),
                    'last_iso' => $range['last_start_at']->toISOString(),
                    'display_label' => $this->startRangeLabel($range),
                    'cadence_label' => $range['slot_count'] > 1
                        ? $this->slotIntervalLabel((int) $range['interval_minutes'])
                        : 'One available start',
                    'capacity_label' => $range['remaining_capacity'].' open '.
                        ($range['remaining_capacity'] === 1 ? 'spot' : 'spots').' per start',
                ],
                $previewStartRanges,
            ),
            'slotIntervalChoice' => old('slot_interval_choice', $slotIntervalChoice),
            'slotIntervalCustomMinutes' => old(
                'slot_interval_custom_minutes',
                $slotIntervalChoice === 'custom' ? $currentInterval : null,
            ),
            'slotStartAnchorTime' => old(
                'slot_start_anchor_time',
                $selectedService?->slotStartAnchorTime() ?? '09:00',
            ),
        ]);
    }

    public function saveBookingTiming(
        Request $request,
        BookableService $bookableService,
        SchedulingConfigurationWriter $writer,
    ): RedirectResponse {
        $this->assertActiveService($bookableService);
        $this->assertAllowedFields($request, [
            'current_version',
            'slot_interval_choice',
            'slot_interval_custom_minutes',
            'slot_start_anchor_time',
            'buffer_before_minutes',
            'buffer_after_minutes',
            'guided',
        ]);
        $validated = $request->validate([
            'current_version' => ['required', 'string', 'max:80'],
            'slot_interval_choice' => [
                'required',
                'string',
                Rule::in(['15', '30', '60', '120', 'custom']),
            ],
            'slot_interval_custom_minutes' => [
                'nullable',
                'required_if:slot_interval_choice,custom',
                'integer',
                'min:1',
                'max:1440',
            ],
            'slot_start_anchor_time' => ['required', 'date_format:H:i'],
            'buffer_before_minutes' => ['required', 'integer', 'min:0', 'max:10080'],
            'buffer_after_minutes' => ['required', 'integer', 'min:0', 'max:10080'],
            'guided' => ['nullable', 'boolean'],
        ]);
        $slotIntervalMinutes = $validated['slot_interval_choice'] === 'custom'
            ? (int) $validated['slot_interval_custom_minutes']
            : (int) $validated['slot_interval_choice'];

        try {
            $writer->updateAvailabilityPolicy(
                service: $bookableService,
                attributes: [
                    'slot_interval_minutes' => $slotIntervalMinutes,
                    'slot_start_anchor_time' => $validated['slot_start_anchor_time'],
                    'buffer_before_minutes' => (int) $validated['buffer_before_minutes'],
                    'buffer_after_minutes' => (int) $validated['buffer_after_minutes'],
                ],
                expectedUpdatedAt: $validated['current_version'],
            );
        } catch (DomainException|InvalidArgumentException|LogicException $exception) {
            throw $this->availabilityException($exception);
        }

        return $this->businessRedirect(
            service: $bookableService,
            message: 'Booking timing updated.',
            guided: (bool) ($validated['guided'] ?? false),
        );
    }

    public function saveRegularHours(
        Request $request,
        BookableService $bookableService,
        SchedulingAvailabilityConfigurationWriter $writer,
    ): RedirectResponse {
        $this->assertActiveService($bookableService);
        $this->assertAllowedFields($request, ['regular_hours', 'guided']);
        $validated = $request->validate([
            'guided' => ['nullable', 'boolean'],
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
            guided: (bool) ($validated['guided'] ?? false),
        );
    }

    public function saveSpecialHours(
        Request $request,
        BookableService $bookableService,
        SchedulingAvailabilityConfigurationWriter $writer,
    ): RedirectResponse {
        $this->assertActiveService($bookableService);
        $this->assertAllowedFields($request, ['date', 'ranges', 'guided']);
        $validated = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'guided' => ['nullable', 'boolean'],
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
            guided: (bool) ($validated['guided'] ?? false),
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
            ['date', 'all_day', 'start_time', 'end_time', 'guided'],
        );
        $validated = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'guided' => ['nullable', 'boolean'],
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
                ? 'This appointment type is unavailable for the selected day.'
                : 'Unavailable time added.',
            guided: (bool) ($validated['guided'] ?? false),
        );
    }

    public function clearDateChanges(
        Request $request,
        BookableService $bookableService,
        string $date,
        SchedulingAvailabilityConfigurationWriter $writer,
    ): RedirectResponse {
        $this->assertActiveService($bookableService);
        $this->assertAllowedFields($request, ['guided']);
        $validated = $request->validate([
            'guided' => ['nullable', 'boolean'],
        ]);

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
            guided: (bool) ($validated['guided'] ?? false),
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
                'service' => 'Choose an active appointment type before setting normal availability.',
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

    /**
     * @param \Illuminate\Database\Eloquent\Collection<int, SchedulingAvailabilityWindow> $windows
     * @param array<string, string> $scopeOptions
     */
    private function decorateWindowPresentation(
        $windows,
        array $scopeOptions,
        bool $archived,
    ): void {
        foreach ($windows as $window) {
            $scope = (string) $window->getAttribute('crm_scope');
            $window->setAttribute(
                'crm_scope_label',
                $scopeOptions[$scope] ?? str($scope)->replace('_', ' ')->title(),
            );
            $window->setAttribute(
                'crm_view_editable',
                (bool) $window->getAttribute('crm_editable'),
            );
            $window->setAttribute(
                'crm_view_shape',
                $window->window_type->value,
            );
            $window->setAttribute(
                'crm_local_start',
                $window->starts_at?->setTimezone($window->timezone)->format('Y-m-d\TH:i'),
            );
            $window->setAttribute(
                'crm_local_end',
                $window->ends_at?->setTimezone($window->timezone)->format('Y-m-d\TH:i'),
            );
            $window->setAttribute('crm_view_archived', $archived);
        }
    }

    /**
     * @param array<string, mixed> $range
     */
    private function startRangeLabel(array $range): string
    {
        $start = $range['starts_at']->setTimezone($range['display_timezone']);
        $end = $range['last_start_at']->setTimezone($range['display_timezone']);

        return $start->format('M j, Y g:i A')
            .($range['slot_count'] > 1 ? '–'.$end->format('g:i A') : '');
    }

    private function slotIntervalLabel(int $minutes): string
    {
        return match ($minutes) {
            15 => 'Every 15 minutes',
            30 => 'Every 30 minutes',
            60 => 'Every hour',
            120 => 'Every 2 hours',
            default => 'Every '.$minutes.' minutes',
        };
    }

    private function bufferLabel(BookableService $service): string
    {
        $before = max(0, (int) $service->buffer_before_minutes);
        $after = max(0, (int) $service->buffer_after_minutes);

        if ($before === 0 && $after === 0) {
            return 'No time blocked before or after';
        }

        return $before.' min before · '.$after.' min after';
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
        bool $guided = false,
    ): RedirectResponse {
        return redirect()
            ->route('crm.scheduling.configuration.availability.index', array_filter([
                'service_id' => $service->getKey(),
                'guided' => $guided ? 1 : null,
            ], static fn (mixed $value): bool => $value !== null))
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