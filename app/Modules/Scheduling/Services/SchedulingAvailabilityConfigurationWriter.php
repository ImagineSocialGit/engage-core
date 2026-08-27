<?php

namespace App\Modules\Scheduling\Services;

use App\Modules\Scheduling\Enums\SchedulingAvailabilityWindowType;
use App\Modules\Scheduling\Models\BookableService;
use App\Modules\Scheduling\Models\BookableServiceHost;
use App\Modules\Scheduling\Models\SchedulingAvailabilityWindow;
use App\Modules\Scheduling\Models\SchedulingHost;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

class SchedulingAvailabilityConfigurationWriter
{
    public const SCOPE_SERVICE = 'service';
    public const SCOPE_HOST = 'host';
    public const SCOPE_SERVICE_HOST = 'service_host';

    public function __construct(
        private readonly SchedulingLocalDateTimeResolver $localDateTimes,
    ) {}

    /**
     * @param array<string, mixed> $attributes
     */
    public function create(array $attributes): SchedulingAvailabilityWindow
    {
        return DB::transaction(function () use ($attributes): SchedulingAvailabilityWindow {
            [$service, $host] = $this->lockedTargets($attributes);

            return SchedulingAvailabilityWindow::query()->create([
                ...$this->windowAttributes($attributes, $service, $host),
                'source' => SchedulingAvailabilityWindow::SOURCE_MANUAL,
                'meta' => null,
            ])->refresh();
        });
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function update(
        SchedulingAvailabilityWindow $window,
        array $attributes,
        string $expectedUpdatedAt,
    ): SchedulingAvailabilityWindow {
        return DB::transaction(function () use (
            $window,
            $attributes,
            $expectedUpdatedAt,
        ): SchedulingAvailabilityWindow {
            $locked = SchedulingAvailabilityWindow::withTrashed()
                ->whereKey($window->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertEditable($locked);
            $this->assertActive($locked);
            $this->assertFresh($locked, $expectedUpdatedAt);

            [$service, $host] = $this->lockedTargets($attributes);

            $locked->forceFill(
                $this->windowAttributes($attributes, $service, $host),
            );
            $this->saveWithVersionBump($locked);

            return $locked->refresh();
        });
    }

    public function archive(
        SchedulingAvailabilityWindow $window,
        string $expectedUpdatedAt,
    ): SchedulingAvailabilityWindow {
        return DB::transaction(function () use (
            $window,
            $expectedUpdatedAt,
        ): SchedulingAvailabilityWindow {
            $locked = SchedulingAvailabilityWindow::withTrashed()
                ->whereKey($window->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertEditable($locked);
            $this->assertActive($locked);
            $this->assertFresh($locked, $expectedUpdatedAt);

            $next = $this->nextVersion($locked);

            $locked->forceFill([
                'deleted_at' => $next,
                'updated_at' => $next,
            ])->saveQuietly();

            return $locked;
        });
    }

    public function restore(
        SchedulingAvailabilityWindow $window,
        string $expectedUpdatedAt,
    ): SchedulingAvailabilityWindow {
        return DB::transaction(function () use (
            $window,
            $expectedUpdatedAt,
        ): SchedulingAvailabilityWindow {
            $locked = SchedulingAvailabilityWindow::withTrashed()
                ->whereKey($window->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertEditable($locked);
            $this->assertArchived($locked);
            $this->assertFresh($locked, $expectedUpdatedAt);
            $this->lockedTargets([
                'scope' => $this->scope($locked),
                'bookable_service_id' => $locked->bookable_service_id,
                'scheduling_host_id' => $locked->scheduling_host_id,
            ]);

            $locked->forceFill(['deleted_at' => null]);
            $this->saveWithVersionBump($locked);

            return $locked->refresh();
        });
    }

    /**
     * Replace the simple service-wide weekly schedule authored by the normal CRM
     * availability screen. Advanced weekly rows with capacity overrides, staff
     * targeting, or non-manual ownership are deliberately left alone.
     *
     * @param array<int, array{weekday: mixed, start_time: mixed, end_time: mixed}> $ranges
     */
    public function replaceRegularHours(
        BookableService $service,
        array $ranges,
    ): int {
        return DB::transaction(function () use ($service, $ranges): int {
            $lockedService = $this->lockedService($service);
            $normalized = $this->normalizedDayRanges($ranges, includeWeekday: true);

            $existing = SchedulingAvailabilityWindow::withTrashed()
                ->where('bookable_service_id', $lockedService->getKey())
                ->whereNull('scheduling_host_id')
                ->where('source', SchedulingAvailabilityWindow::SOURCE_MANUAL)
                ->where(
                    'window_type',
                    SchedulingAvailabilityWindowType::Weekly->value,
                )
                ->where('is_available', true)
                ->whereNull('capacity')
                ->orderByRaw('deleted_at is not null')
                ->orderBy('weekday')
                ->orderBy('start_time')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $definitions = array_map(
                fn (array $range): array => [
                    ...$this->windowAttributes([
                        'scope' => self::SCOPE_SERVICE,
                        'bookable_service_id' => $lockedService->getKey(),
                        'scheduling_host_id' => null,
                        'window_type' => SchedulingAvailabilityWindowType::Weekly->value,
                        'timezone' => $lockedService->timezone,
                        'weekday' => $range['weekday'],
                        'start_time' => $range['start_time'],
                        'end_time' => $range['end_time'],
                        'capacity' => null,
                        'is_available' => true,
                    ], $lockedService, null),
                    'source' => SchedulingAvailabilityWindow::SOURCE_MANUAL,
                    'meta' => null,
                ],
                $normalized,
            );

            $this->syncSimpleWindows($existing, $definitions);

            return count($definitions);
        });
    }

    /**
     * Replace one service-wide local date with explicit hours.
     *
     * Positive absolute windows add the requested hours to the normal weekly
     * layer. Complementary blackout windows cover the rest of that local day,
     * so the final resolved result is a true date-specific replacement rather
     * than merely extra availability.
     *
     * An empty range list means closed all day.
     *
     * @param array<int, array{start_time: mixed, end_time: mixed}> $ranges
     */
    public function replaceSpecialHours(
        BookableService $service,
        string $date,
        array $ranges,
    ): int {
        return DB::transaction(function () use ($service, $date, $ranges): int {
            $lockedService = $this->lockedService($service);
            $timezone = (string) $lockedService->timezone;
            [$dayStart, $dayEnd, $nextDate] = $this->localDayBounds(
                $date,
                $timezone,
            );
            $normalized = $this->normalizedDayRanges($ranges, includeWeekday: false);

            $existing = $this->simpleAbsoluteWindowsForDay(
                service: $lockedService,
                timezone: $timezone,
                dayStart: $dayStart,
                dayEnd: $dayEnd,
            );
            $definitions = [];

            if ($normalized === []) {
                $definitions[] = $this->simpleAbsoluteDefinition(
                    service: $lockedService,
                    timezone: $timezone,
                    localStartsAt: "{$date}T00:00",
                    localEndsAt: "{$nextDate}T00:00",
                    available: false,
                );

                $this->syncSimpleWindows($existing, $definitions);

                return 1;
            }

            $cursor = '00:00:00';

            foreach ($normalized as $range) {
                $start = $range['start_time'];
                $end = $range['end_time'];

                if ($this->timeInSeconds($cursor) < $this->timeInSeconds($start)) {
                    $definitions[] = $this->simpleAbsoluteDefinition(
                        service: $lockedService,
                        timezone: $timezone,
                        localStartsAt: "{$date}T".substr($cursor, 0, 5),
                        localEndsAt: "{$date}T".substr($start, 0, 5),
                        available: false,
                    );
                }

                $definitions[] = $this->simpleAbsoluteDefinition(
                    service: $lockedService,
                    timezone: $timezone,
                    localStartsAt: "{$date}T".substr($start, 0, 5),
                    localEndsAt: "{$date}T".substr($end, 0, 5),
                    available: true,
                );
                $cursor = $end;
            }

            if ($this->timeInSeconds($cursor) < 86400) {
                $definitions[] = $this->simpleAbsoluteDefinition(
                    service: $lockedService,
                    timezone: $timezone,
                    localStartsAt: "{$date}T".substr($cursor, 0, 5),
                    localEndsAt: "{$nextDate}T00:00",
                    available: false,
                );
            }

            $this->syncSimpleWindows($existing, $definitions);

            return count($definitions);
        });
    }

    public function createTimeOff(
        BookableService $service,
        string $date,
        string $startTime,
        string $endTime,
    ): SchedulingAvailabilityWindow {
        return DB::transaction(function () use (
            $service,
            $date,
            $startTime,
            $endTime,
        ): SchedulingAvailabilityWindow {
            $lockedService = $this->lockedService($service);
            $start = $this->weeklyTime($startTime, 'time-off start time');
            $end = $this->weeklyTime($endTime, 'time-off end time');

            if ($this->timeInSeconds($start) >= $this->timeInSeconds($end)) {
                throw new InvalidArgumentException(
                    'Time off requires a start time before the end time.',
                );
            }

            $timezone = (string) $lockedService->timezone;
            $definition = $this->simpleAbsoluteDefinition(
                service: $lockedService,
                timezone: $timezone,
                localStartsAt: "{$date}T".substr($start, 0, 5),
                localEndsAt: "{$date}T".substr($end, 0, 5),
                available: false,
            );
            $existing = SchedulingAvailabilityWindow::withTrashed()
                ->where('bookable_service_id', $lockedService->getKey())
                ->whereNull('scheduling_host_id')
                ->where('source', SchedulingAvailabilityWindow::SOURCE_MANUAL)
                ->where(
                    'window_type',
                    SchedulingAvailabilityWindowType::Absolute->value,
                )
                ->where('timezone', $timezone)
                ->whereNull('capacity')
                ->where('is_available', false)
                ->where('starts_at', $definition['starts_at'])
                ->where('ends_at', $definition['ends_at'])
                ->orderByRaw('deleted_at is not null')
                ->orderByDesc('updated_at')
                ->lockForUpdate()
                ->first();

            if ($existing instanceof SchedulingAvailabilityWindow) {
                $existing->forceFill([
                    ...$definition,
                    'deleted_at' => null,
                ]);

                if ($existing->isDirty()) {
                    $this->saveWithVersionBump($existing);
                }

                return $existing->refresh();
            }

            return SchedulingAvailabilityWindow::query()
                ->create($definition)
                ->refresh();
        });
    }

    public function clearDateChanges(
        BookableService $service,
        string $date,
    ): int {
        return DB::transaction(function () use ($service, $date): int {
            $lockedService = $this->lockedService($service);
            $timezone = (string) $lockedService->timezone;
            [$dayStart, $dayEnd] = $this->localDayBounds($date, $timezone);
            $existing = $this->simpleAbsoluteWindowsForDay(
                service: $lockedService,
                timezone: $timezone,
                dayStart: $dayStart,
                dayEnd: $dayEnd,
            );
            $archived = 0;

            foreach ($existing as $window) {
                if (! $window instanceof SchedulingAvailabilityWindow
                    || $window->trashed()
                ) {
                    continue;
                }

                $this->archiveLocked($window);
                $archived++;
            }

            return $archived;
        });
    }

    public function windowIsEditable(SchedulingAvailabilityWindow $window): bool
    {
        return $window->source === SchedulingAvailabilityWindow::SOURCE_MANUAL;
    }

    public function scope(SchedulingAvailabilityWindow $window): string
    {
        if ($window->bookable_service_id !== null
            && $window->scheduling_host_id !== null
        ) {
            return self::SCOPE_SERVICE_HOST;
        }

        if ($window->scheduling_host_id !== null) {
            return self::SCOPE_HOST;
        }

        return self::SCOPE_SERVICE;
    }

    /**
     * @param array<string, mixed> $attributes
     * @return array{0: BookableService|null, 1: SchedulingHost|null}
     */
    private function lockedTargets(array $attributes): array
    {
        $scope = $this->scopeValue($attributes['scope'] ?? null);
        $serviceId = $this->nullablePositiveInteger(
            $attributes['bookable_service_id'] ?? null,
            'bookable service ID',
        );
        $hostId = $this->nullablePositiveInteger(
            $attributes['scheduling_host_id'] ?? null,
            'scheduling host ID',
        );

        if ($scope === self::SCOPE_SERVICE && ($serviceId === null || $hostId !== null)) {
            throw new InvalidArgumentException(
                'Service-wide availability requires exactly one bookable service.',
            );
        }

        if ($scope === self::SCOPE_HOST && ($hostId === null || $serviceId !== null)) {
            throw new InvalidArgumentException(
                'Host-wide availability requires exactly one scheduling host.',
            );
        }

        if ($scope === self::SCOPE_SERVICE_HOST
            && ($serviceId === null || $hostId === null)
        ) {
            throw new InvalidArgumentException(
                'Service-and-host availability requires both targets.',
            );
        }

        $service = $serviceId !== null
            ? BookableService::withTrashed()
                ->whereKey($serviceId)
                ->lockForUpdate()
                ->first()
            : null;
        $host = $hostId !== null
            ? SchedulingHost::withTrashed()
                ->whereKey($hostId)
                ->lockForUpdate()
                ->first()
            : null;

        if ($serviceId !== null && (! $service instanceof BookableService || $service->trashed())) {
            throw new DomainException(
                'The selected bookable service no longer exists.',
            );
        }

        if ($hostId !== null && (! $host instanceof SchedulingHost || $host->trashed())) {
            throw new DomainException(
                'The selected scheduling host no longer exists.',
            );
        }

        if ($scope === self::SCOPE_SERVICE_HOST) {
            $assignment = BookableServiceHost::query()
                ->where('bookable_service_id', $serviceId)
                ->where('scheduling_host_id', $hostId)
                ->lockForUpdate()
                ->first();

            if (! $assignment instanceof BookableServiceHost) {
                throw new DomainException(
                    'Service-and-host availability requires an existing service assignment.',
                );
            }
        }

        return [$service, $host];
    }

    /**
     * @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    private function windowAttributes(
        array $attributes,
        ?BookableService $service,
        ?SchedulingHost $host,
    ): array {
        $windowType = $this->windowType($attributes['window_type'] ?? null);
        $timezone = $this->timezone($attributes['timezone'] ?? null);
        $capacity = $this->nullablePositiveInteger(
            $attributes['capacity'] ?? null,
            'availability capacity',
        );

        $shape = $windowType === SchedulingAvailabilityWindowType::Weekly
            ? $this->weeklyShape($attributes)
            : $this->absoluteShape($attributes, $timezone);

        return [
            'bookable_service_id' => $service?->getKey(),
            'scheduling_host_id' => $host?->getKey(),
            'window_type' => $windowType,
            'timezone' => $timezone,
            ...$shape,
            'capacity' => $capacity,
            'is_available' => (bool) ($attributes['is_available'] ?? false),
        ];
    }

    /**
     * @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    private function weeklyShape(array $attributes): array
    {
        $weekday = $this->integerBetween(
            $attributes['weekday'] ?? null,
            0,
            6,
            'weekday',
        );
        $startTime = $this->weeklyTime(
            $attributes['start_time'] ?? null,
            'weekly start time',
        );
        $endTime = $this->weeklyTime(
            $attributes['end_time'] ?? null,
            'weekly end time',
        );

        if ($this->timeInSeconds($startTime) >= $this->timeInSeconds($endTime)) {
            throw new InvalidArgumentException(
                'Weekly availability requires a start time before the end time.',
            );
        }

        return [
            'weekday' => $weekday,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'starts_at' => null,
            'ends_at' => null,
        ];
    }

    /**
     * @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    private function absoluteShape(array $attributes, string $timezone): array
    {
        $startsAt = $this->localDateTimes->resolve(
            $attributes['local_starts_at'] ?? null,
            $timezone,
            'absolute start',
        );
        $endsAt = $this->localDateTimes->resolve(
            $attributes['local_ends_at'] ?? null,
            $timezone,
            'absolute end',
        );

        if (! $startsAt->lessThan($endsAt)) {
            throw new InvalidArgumentException(
                'Absolute availability requires a start before the end.',
            );
        }

        return [
            'weekday' => null,
            'start_time' => null,
            'end_time' => null,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
        ];
    }

    private function lockedService(BookableService $service): BookableService
    {
        $locked = BookableService::withTrashed()
            ->whereKey($service->getKey())
            ->lockForUpdate()
            ->first();

        if (! $locked instanceof BookableService || $locked->trashed()) {
            throw new DomainException(
                'The selected bookable service no longer exists.',
            );
        }

        if ($locked->status !== BookableService::STATUS_ACTIVE) {
            throw new DomainException(
                'The selected service is no longer active.',
            );
        }

        return $locked;
    }

    /**
     * @param array<int, array<string, mixed>> $ranges
     * @return array<int, array{weekday?: int, start_time: string, end_time: string}>
     */
    private function normalizedDayRanges(
        array $ranges,
        bool $includeWeekday,
    ): array {
        $normalized = [];

        foreach ($ranges as $range) {
            if (! is_array($range)) {
                throw new InvalidArgumentException(
                    'Availability hours must be submitted as time ranges.',
                );
            }

            $start = $this->weeklyTime(
                $range['start_time'] ?? null,
                'availability start time',
            );
            $end = $this->weeklyTime(
                $range['end_time'] ?? null,
                'availability end time',
            );

            if ($this->timeInSeconds($start) >= $this->timeInSeconds($end)) {
                throw new InvalidArgumentException(
                    'Availability hours require a start time before the end time.',
                );
            }

            $entry = [
                'start_time' => $start,
                'end_time' => $end,
            ];

            if ($includeWeekday) {
                $entry['weekday'] = $this->integerBetween(
                    $range['weekday'] ?? null,
                    0,
                    6,
                    'weekday',
                );
            }

            $normalized[] = $entry;
        }

        usort(
            $normalized,
            function (array $left, array $right) use ($includeWeekday): int {
                if ($includeWeekday) {
                    $weekday = ($left['weekday'] ?? 0) <=> ($right['weekday'] ?? 0);

                    if ($weekday !== 0) {
                        return $weekday;
                    }
                }

                return $this->timeInSeconds($left['start_time'])
                    <=> $this->timeInSeconds($right['start_time']);
            },
        );

        $lastByDay = [];

        foreach ($normalized as $range) {
            $day = $includeWeekday ? (int) $range['weekday'] : 0;
            $previousEnd = $lastByDay[$day] ?? null;

            if (is_string($previousEnd)
                && $this->timeInSeconds($range['start_time'])
                    < $this->timeInSeconds($previousEnd)
            ) {
                throw new InvalidArgumentException(
                    'Availability hours for the same day cannot overlap.',
                );
            }

            $lastByDay[$day] = $range['end_time'];
        }

        return $normalized;
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: string}
     */
    private function localDayBounds(string $date, string $timezone): array
    {
        $date = trim($date);

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            throw new InvalidArgumentException(
                'Special dates must use YYYY-MM-DD format.',
            );
        }

        $dayStart = $this->localDateTimes->resolve(
            "{$date}T00:00",
            $timezone,
            'special-date start',
        );
        $nextDate = $dayStart
            ->setTimezone($timezone)
            ->addDay()
            ->toDateString();
        $dayEnd = $this->localDateTimes->resolve(
            "{$nextDate}T00:00",
            $timezone,
            'special-date end',
        );

        return [$dayStart, $dayEnd, $nextDate];
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, SchedulingAvailabilityWindow>
     */
    private function simpleAbsoluteWindowsForDay(
        BookableService $service,
        string $timezone,
        CarbonImmutable $dayStart,
        CarbonImmutable $dayEnd,
    ): \Illuminate\Database\Eloquent\Collection {
        return SchedulingAvailabilityWindow::withTrashed()
            ->where('bookable_service_id', $service->getKey())
            ->whereNull('scheduling_host_id')
            ->where('source', SchedulingAvailabilityWindow::SOURCE_MANUAL)
            ->where(
                'window_type',
                SchedulingAvailabilityWindowType::Absolute->value,
            )
            ->where('timezone', $timezone)
            ->whereNull('capacity')
            ->where('starts_at', '>=', $dayStart)
            ->where('starts_at', '<', $dayEnd)
            ->where('ends_at', '<=', $dayEnd)
            ->orderByRaw('deleted_at is not null')
            ->orderBy('starts_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    /**
     * @param \Illuminate\Database\Eloquent\Collection<int, SchedulingAvailabilityWindow> $existing
     * @param array<int, array<string, mixed>> $definitions
     */
    private function syncSimpleWindows(
        \Illuminate\Database\Eloquent\Collection $existing,
        array $definitions,
    ): void {
        $existing = $existing->values();

        foreach ($definitions as $index => $definition) {
            $window = $existing->get($index);

            if ($window instanceof SchedulingAvailabilityWindow) {
                $window->forceFill([
                    ...$definition,
                    'deleted_at' => null,
                ]);

                if ($window->isDirty()) {
                    $this->saveWithVersionBump($window);
                }

                continue;
            }

            SchedulingAvailabilityWindow::query()->create($definition);
        }

        for ($index = count($definitions); $index < $existing->count(); $index++) {
            $window = $existing->get($index);

            if ($window instanceof SchedulingAvailabilityWindow) {
                $this->archiveLocked($window);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function simpleAbsoluteDefinition(
        BookableService $service,
        string $timezone,
        string $localStartsAt,
        string $localEndsAt,
        bool $available,
    ): array {
        return [
            ...$this->windowAttributes([
                'scope' => self::SCOPE_SERVICE,
                'bookable_service_id' => $service->getKey(),
                'scheduling_host_id' => null,
                'window_type' => SchedulingAvailabilityWindowType::Absolute->value,
                'timezone' => $timezone,
                'local_starts_at' => $localStartsAt,
                'local_ends_at' => $localEndsAt,
                'capacity' => null,
                'is_available' => $available,
            ], $service, null),
            'source' => SchedulingAvailabilityWindow::SOURCE_MANUAL,
            'meta' => null,
        ];
    }

    private function archiveLocked(SchedulingAvailabilityWindow $window): void
    {
        if ($window->trashed()) {
            return;
        }

        $next = $this->nextVersion($window);

        $window->forceFill([
            'deleted_at' => $next,
            'updated_at' => $next,
        ])->saveQuietly();
    }

    private function assertEditable(SchedulingAvailabilityWindow $window): void
    {
        if (! $this->windowIsEditable($window)) {
            throw new DomainException(
                'Provider- and system-owned availability rules are read-only in CRM configuration.',
            );
        }
    }

    private function assertActive(SchedulingAvailabilityWindow $window): void
    {
        if ($window->trashed()) {
            throw new DomainException(
                'Archived availability rules must be restored before they can be changed.',
            );
        }
    }

    private function assertArchived(SchedulingAvailabilityWindow $window): void
    {
        if (! $window->trashed()) {
            throw new DomainException(
                'Only archived availability rules can be restored.',
            );
        }
    }

    private function assertFresh(
        SchedulingAvailabilityWindow $window,
        string $expectedUpdatedAt,
    ): void {
        $expectedUpdatedAt = trim($expectedUpdatedAt);

        if ($expectedUpdatedAt === '') {
            throw new InvalidArgumentException(
                'Availability-rule changes require the current record version.',
            );
        }

        try {
            $expected = CarbonImmutable::parse($expectedUpdatedAt)->utc();
        } catch (Throwable $exception) {
            throw new InvalidArgumentException(
                'The availability-rule record version is invalid.',
                previous: $exception,
            );
        }

        $actual = $window->getAttribute('updated_at');

        if ($actual === null
            || ! CarbonImmutable::instance($actual)->utc()->equalTo($expected)
        ) {
            throw new DomainException(
                'The availability rule changed after this form was loaded. Refresh and try again.',
            );
        }
    }

    private function saveWithVersionBump(Model $model): void
    {
        $model->forceFill([
            'updated_at' => $this->nextVersion($model),
        ])->saveQuietly();
    }

    private function nextVersion(Model $model): CarbonImmutable
    {
        $current = $model->getOriginal('updated_at');
        $current = $current !== null
            ? CarbonImmutable::parse($current)->utc()
            : null;
        $now = CarbonImmutable::now('UTC');

        return $current instanceof CarbonImmutable && ! $now->greaterThan($current)
            ? $current->addSecond()
            : $now;
    }

    private function scopeValue(mixed $value): string
    {
        $value = $this->requiredString($value, 'availability scope');

        if (! in_array($value, [
            self::SCOPE_SERVICE,
            self::SCOPE_HOST,
            self::SCOPE_SERVICE_HOST,
        ], true)) {
            throw new InvalidArgumentException(
                "Availability scope [{$value}] is invalid.",
            );
        }

        return $value;
    }

    private function windowType(mixed $value): SchedulingAvailabilityWindowType
    {
        $value = $this->requiredString($value, 'availability window type');
        $type = SchedulingAvailabilityWindowType::tryFrom($value);

        if (! $type instanceof SchedulingAvailabilityWindowType) {
            throw new InvalidArgumentException(
                "Availability window type [{$value}] is invalid.",
            );
        }

        return $type;
    }

    private function weeklyTime(mixed $value, string $label): string
    {
        $value = $this->requiredString($value, $label);

        if (preg_match(
            '/^(?<hour>[01]\d|2[0-3]):(?<minute>[0-5]\d)(?::(?<second>[0-5]\d))?$/',
            $value,
            $matches,
        ) !== 1) {
            throw new InvalidArgumentException(
                "The {$label} must use HH:MM local time.",
            );
        }

        return sprintf(
            '%02d:%02d:%02d',
            (int) $matches['hour'],
            (int) $matches['minute'],
            (int) ($matches['second'] ?? 0),
        );
    }

    private function timeInSeconds(string $time): int
    {
        [$hour, $minute, $second] = array_map('intval', explode(':', $time));

        return ($hour * 3600) + ($minute * 60) + $second;
    }

    private function requiredString(mixed $value, string $label): string
    {
        if (! is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException(
                "A non-empty {$label} is required.",
            );
        }

        return trim($value);
    }

    private function nullablePositiveInteger(mixed $value, string $label): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value) || (int) $value < 1) {
            throw new InvalidArgumentException("{$label} must be at least 1.");
        }

        return (int) $value;
    }

    private function integerBetween(
        mixed $value,
        int $minimum,
        int $maximum,
        string $label,
    ): int {
        if (! is_numeric($value)) {
            throw new InvalidArgumentException("{$label} must be an integer.");
        }

        $value = (int) $value;

        if ($value < $minimum || $value > $maximum) {
            throw new InvalidArgumentException(
                "{$label} must be between {$minimum} and {$maximum}.",
            );
        }

        return $value;
    }

    private function timezone(mixed $value): string
    {
        $value = $this->requiredString($value, 'availability timezone');

        if (! in_array($value, timezone_identifiers_list(), true)) {
            throw new InvalidArgumentException("Timezone [{$value}] is invalid.");
        }

        return $value;
    }
}