<?php

namespace App\Modules\Scheduling\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Scheduling\Actions\FindBookableAvailabilityAction;
use App\Modules\Scheduling\Data\AvailabilitySearch;
use App\Modules\Scheduling\Data\BookableSlot;
use App\Modules\Scheduling\Models\BookableService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class PublicBookingController extends Controller
{
    public function index(): View
    {
        return view('scheduling.public.index', [
            'services' => $this->publicServices(),
            'selectedService' => null,
            'selectedDate' => null,
            'displayTimezone' => null,
            'availableTimes' => [],
            'maximumDate' => null,
        ]);
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

        return view('scheduling.public.index', [
            'services' => $this->publicServices(),
            'selectedService' => $service,
            'selectedDate' => $selectedDate,
            'displayTimezone' => $displayTimezone,
            'availableTimes' => $this->publicTimes($slots, $displayTimezone),
            'maximumDate' => $maximumDate,
        ]);
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
     * @return array<int, array{starts_at: string, ends_at: string, label: string}>
     */
    private function publicTimes(array $slots, string $displayTimezone): array
    {
        $times = [];

        foreach ($slots as $slot) {
            $startsAt = $slot->startsAt->setTimezone($displayTimezone);
            $endsAt = $slot->endsAt->setTimezone($displayTimezone);
            $key = $startsAt->toISOString().'|'.$endsAt->toISOString();

            $times[$key] = [
                'starts_at' => $startsAt->toISOString(),
                'ends_at' => $endsAt->toISOString(),
                'label' => $startsAt->format('g:i A').'–'.$endsAt->format('g:i A'),
            ];
        }

        return array_values($times);
    }
}