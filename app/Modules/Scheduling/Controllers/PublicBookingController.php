<?php

namespace App\Modules\Scheduling\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Scheduling\Actions\CompletePublicBookingAction;
use App\Modules\Scheduling\Actions\CreatePublicBookingHoldAction;
use App\Modules\Scheduling\Actions\FindBookableAvailabilityAction;
use App\Modules\Scheduling\Data\AvailabilitySearch;
use App\Modules\Scheduling\Data\BookableSlot;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Models\BookableService;
use App\Modules\Scheduling\Models\BookingHold;
use App\Modules\Scheduling\Requests\CompletePublicBookingRequest;
use App\Modules\Scheduling\Requests\CreatePublicBookingHoldRequest;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class PublicBookingController extends Controller
{
    public function index(): View
    {
        return view('scheduling.public.index', $this->pageData());
    }

    public function show(
        Request $request,
        string $serviceKey,
        FindBookableAvailabilityAction $findAvailability,
    ): View {
        $service = $this->publicService($serviceKey);
        $displayTimezone = $this->serviceTimezone($service);
        $today = CarbonImmutable::now($displayTimezone)->startOfDay();
        $maximumDate = $this->maximumPublicDate($service, $today);
        $selectedDate = $this->selectedDate(
            value: $request->query('date'),
            timezone: $displayTimezone,
            minimum: $today,
            maximum: $maximumDate,
        );

        $slots = $findAvailability->handle(new AvailabilitySearch(
            service: $service,
            startsAt: $selectedDate->utc(),
            endsAt: $selectedDate->addDay()->utc(),
            displayTimezone: $displayTimezone,
            evaluatedAt: CarbonImmutable::now('UTC'),
        ));

        return view('scheduling.public.index', $this->pageData([
            'selectedService' => $service,
            'selectedDate' => $selectedDate,
            'displayTimezone' => $displayTimezone,
            'availableTimes' => $this->publicTimes($slots, $displayTimezone),
            'maximumDate' => $maximumDate,
        ]));
    }

    public function reserve(
        CreatePublicBookingHoldRequest $request,
        string $serviceKey,
        CreatePublicBookingHoldAction $createPublicBookingHold,
    ): RedirectResponse {
        $service = $this->publicService($serviceKey);

        try {
            $hold = $createPublicBookingHold->handle(
                service: $service,
                startsAt: $request->startsAt(),
                idempotencyKey: $request->idempotencyKey(),
            );
        } catch (DomainException) {
            throw ValidationException::withMessages([
                'starts_at' => 'That appointment time is no longer available. Choose another time.',
            ]);
        }

        return redirect()->route(
            'scheduling.public.holds.show',
            ['holdId' => $hold->hold_id],
        );
    }

    public function review(string $holdId): View
    {
        $hold = $this->publicHold($holdId);
        $service = BookableService::withTrashed()
            ->whereKey($hold->bookable_service_id)
            ->first();

        abort_unless($service instanceof BookableService, 404);

        return view('scheduling.public.index', $this->pageData([
            'holdSummary' => $this->holdSummary($hold, $service),
        ]));
    }

    public function complete(
        CompletePublicBookingRequest $request,
        string $holdId,
        CompletePublicBookingAction $completePublicBooking,
    ): RedirectResponse {
        $hold = $this->publicHold($holdId);

        try {
            $completePublicBooking->handle(
                holdId: $hold->hold_id,
                name: $request->attendeeName(),
                email: $request->attendeeEmail(),
                phone: $request->attendeePhone(),
            );
        } catch (DomainException) {
            throw ValidationException::withMessages([
                'booking' => 'This reservation can no longer be completed. Choose another appointment time.',
            ]);
        }

        return redirect()->route(
            'scheduling.public.holds.show',
            ['holdId' => $hold->hold_id],
        );
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function pageData(array $overrides = []): array
    {
        return array_replace([
            'services' => $this->publicServices(),
            'selectedService' => null,
            'selectedDate' => null,
            'displayTimezone' => null,
            'availableTimes' => [],
            'maximumDate' => null,
            'holdSummary' => null,
        ], $overrides);
    }

    /**
     * @return Collection<int, BookableService>
     */
    private function publicServices(): Collection
    {
        return BookableService::query()
            ->where('status', BookableService::STATUS_ACTIVE)
            ->where('is_public', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->orderBy('id')
            ->get();
    }

    private function publicService(string $serviceKey): BookableService
    {
        $serviceKey = trim($serviceKey);

        if ($serviceKey === '') {
            abort(404);
        }

        $service = BookableService::query()
            ->where('key', $serviceKey)
            ->where('status', BookableService::STATUS_ACTIVE)
            ->where('is_public', true)
            ->first();

        abort_unless($service instanceof BookableService, 404);

        return $service;
    }

    private function publicHold(string $holdId): BookingHold
    {
        $holdId = trim($holdId);

        if ($holdId === '') {
            abort(404);
        }

        $hold = BookingHold::query()
            ->with('appointment')
            ->where('hold_id', $holdId)
            ->first();

        abort_unless($hold instanceof BookingHold, 404);

        return $hold;
    }

    private function serviceTimezone(BookableService $service): string
    {
        $timezone = trim((string) $service->timezone);

        return in_array($timezone, timezone_identifiers_list(), true)
            ? $timezone
            : 'UTC';
    }

    private function maximumPublicDate(
        BookableService $service,
        CarbonImmutable $today,
    ): CarbonImmutable {
        $configuredDays = max(
            1,
            (int) config('scheduling.public.availability_max_days', 31),
        );
        $serviceDays = max(1, (int) $service->booking_horizon_days);

        return $today->addDays(min($configuredDays, $serviceDays) - 1);
    }

    private function selectedDate(
        mixed $value,
        string $timezone,
        CarbonImmutable $minimum,
        CarbonImmutable $maximum,
    ): CarbonImmutable {
        if ($value === null || $value === '') {
            return $minimum;
        }

        if (! is_string($value)
            || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1
        ) {
            throw ValidationException::withMessages([
                'date' => 'Choose a valid appointment date.',
            ]);
        }

        try {
            $date = CarbonImmutable::createFromFormat(
                '!Y-m-d',
                $value,
                $timezone,
            );
        } catch (Throwable) {
            $date = null;
        }

        if (! $date instanceof CarbonImmutable
            || $date->format('Y-m-d') !== $value
        ) {
            throw ValidationException::withMessages([
                'date' => 'Choose a valid appointment date.',
            ]);
        }

        if ($date->lessThan($minimum) || $date->greaterThan($maximum)) {
            throw ValidationException::withMessages([
                'date' => sprintf(
                    'Choose a date from %s through %s.',
                    $minimum->format('M j, Y'),
                    $maximum->format('M j, Y'),
                ),
            ]);
        }

        return $date;
    }

    /**
     * @param array<int, BookableSlot> $slots
     * @return array<int, array{starts_at: string, label: string, idempotency_key: string}>
     */
    private function publicTimes(array $slots, string $displayTimezone): array
    {
        $times = [];

        foreach ($slots as $slot) {
            $startsAt = $slot->startsAt->setTimezone($displayTimezone);
            $endsAt = $slot->endsAt->setTimezone($displayTimezone);
            $key = $slot->startsAt->toISOString().'|'.$slot->endsAt->toISOString();

            if (array_key_exists($key, $times)) {
                continue;
            }

            $times[$key] = [
                'starts_at' => $slot->startsAt->toISOString(),
                'label' => $startsAt->format('g:i A').'–'.$endsAt->format('g:i A'),
                'idempotency_key' => (string) Str::uuid(),
            ];
        }

        return array_values($times);
    }

    /**
     * @return array<string, mixed>
     */
    private function holdSummary(
        BookingHold $hold,
        BookableService $service,
    ): array {
        $now = CarbonImmutable::now('UTC');
        $timezone = $this->serviceTimezone($service);
        $startsAt = CarbonImmutable::instance($hold->starts_at)->setTimezone($timezone);
        $endsAt = CarbonImmutable::instance($hold->ends_at)->setTimezone($timezone);
        $status = $hold->status;

        if ($status === BookingHold::STATUS_ACTIVE
            && ! $hold->isEffectivelyActive($now)
        ) {
            $status = BookingHold::STATUS_EXPIRED;
        }

        $appointment = $hold->appointment;
        $appointmentStatus = $appointment instanceof Appointment
            ? (string) $appointment->status
            : null;

        return [
            'hold_id' => $hold->hold_id,
            'status' => $status,
            'remaining_seconds' => $hold->remainingSeconds($now),
            'expires_at' => $hold->expires_at?->toISOString(),
            'service_key' => $service->key,
            'service_name' => $service->name,
            'date' => $startsAt->format('Y-m-d'),
            'date_label' => $startsAt->format('l, F j, Y'),
            'time_label' => $startsAt->format('g:i A').'–'.$endsAt->format('g:i A'),
            'timezone' => $timezone,
            'appointment_status' => $appointmentStatus,
            'confirmation_pending' => $appointmentStatus === Appointment::STATUS_PENDING,
        ];
    }
}