<?php

namespace App\Modules\Scheduling\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Modules\Core\Models\Contact;
use App\Modules\Scheduling\Actions\CreateAppointmentAction;
use App\Modules\Scheduling\Data\AppointmentBookingData;
use App\Modules\Scheduling\Data\AppointmentCreationData;
use App\Modules\Scheduling\Data\AppointmentLifecycleContext;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Models\BookableService;
use App\Modules\Scheduling\Models\SchedulingHost;
use App\Modules\Scheduling\Requests\StoreAppointmentRequest;
use App\Modules\Scheduling\Services\SchedulingLocationSnapshotResolver;
use App\Modules\Scheduling\Services\SchedulingReadService;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;
use LogicException;

class SchedulingController extends Controller
{
    public function index(
        Request $request,
        SchedulingReadService $read,
    ): View {
        $query = $request->validate([
            'contact_id' => ['nullable', 'integer', 'exists:contacts,id'],
            'bookable_service_id' => ['nullable', 'integer'],
            'scheduling_host_id' => ['nullable', 'integer'],
            'date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $services = $read->activeServices();
        $requestedServiceId = $this->oldOrQueryInteger(
            request: $request,
            oldKey: 'bookable_service_id',
            query: $query,
        );
        $selectedService = $services->first(
            fn (BookableService $service): bool =>
                (int) $service->getKey() === $requestedServiceId,
        );

        $hosts = $selectedService instanceof BookableService
            ? $read->eligibleHosts($selectedService)
            : collect();
        $requiresHost = $selectedService instanceof BookableService
            && $read->serviceRequiresHost($selectedService);
        $requestedHostId = $this->oldOrQueryInteger(
            request: $request,
            oldKey: 'scheduling_host_id',
            query: $query,
        );
        $selectedHost = $hosts->first(
            fn (SchedulingHost $host): bool =>
                (int) $host->getKey() === $requestedHostId,
        );

        if ($selectedHost === null && $requiresHost && $hosts->count() === 1) {
            $selectedHost = $hosts->first();
        }

        $timezone = $selectedService?->timezone
            ?? config('client.timezone', config('app.timezone', 'UTC'));
        $timezone = in_array($timezone, timezone_identifiers_list(), true)
            ? $timezone
            : 'UTC';
        $dateValue = $request->old('date')
            ?? ($query['date'] ?? CarbonImmutable::now($timezone)->toDateString());
        $selectedDate = CarbonImmutable::createFromFormat(
            '!Y-m-d',
            (string) $dateValue,
            $timezone,
        );

        if (! $selectedDate instanceof CarbonImmutable) {
            $selectedDate = CarbonImmutable::now($timezone)->startOfDay();
        }

        $dateMinimum = CarbonImmutable::now($timezone)->startOfDay();
        $dateMaximum = $selectedService instanceof BookableService
            ? $dateMinimum->addDays(max(0, (int) $selectedService->booking_horizon_days))
            : $dateMinimum->addDays(60);
        $dateInRange = $selectedDate->betweenIncluded($dateMinimum, $dateMaximum);
        $slots = $selectedService instanceof BookableService
            && $selectedService->usesFixedDuration()
            && $dateInRange
                ? $read->availabilityForDate(
                    service: $selectedService,
                    date: $selectedDate,
                    host: $selectedHost,
                )
                : [];
        $upcomingAppointments = $read->upcomingAppointments();
        $requestedContactId = $this->oldOrQueryInteger(
            request: $request,
            oldKey: 'contact_id',
            query: $query,
        );
        $selectedContact = $requestedContactId > 0
            ? Contact::query()->find($requestedContactId)
            : null;
        $selectedContactLabel = $selectedContact instanceof Contact
            ? $this->contactLabel($selectedContact)
            : '';

        return view('crm.scheduling.index', [
            'title' => 'Scheduling',
            'heading' => 'Scheduling',
            'services' => $services,
            'selectedService' => $selectedService,
            'hosts' => $hosts,
            'selectedHost' => $selectedHost,
            'requiresHost' => $requiresHost,
            'selectedDate' => $selectedDate,
            'dateMinimum' => $dateMinimum,
            'dateMaximum' => $dateMaximum,
            'dateInRange' => $dateInRange,
            'slots' => $slots,
            'upcomingAppointments' => $upcomingAppointments,
            'pendingCount' => $upcomingAppointments
                ->where('status', Appointment::STATUS_PENDING)
                ->count(),
            'selectedContact' => $selectedContact,
            'selectedContactLabel' => $selectedContactLabel,
            'idempotencyKey' => $request->old(
                'idempotency_key',
                (string) Str::uuid(),
            ),
        ]);
    }

    public function store(
        StoreAppointmentRequest $request,
        CreateAppointmentAction $createAppointment,
        SchedulingLocationSnapshotResolver $locationSnapshots,
    ): RedirectResponse {
        $validated = $request->validated();
        $contact = Contact::query()->findOrFail($validated['contact_id']);
        $service = BookableService::query()
            ->where('status', BookableService::STATUS_ACTIVE)
            ->findOrFail($validated['bookable_service_id']);
        $host = isset($validated['scheduling_host_id'])
            ? SchedulingHost::query()
                ->where('status', SchedulingHost::STATUS_ACTIVE)
                ->findOrFail($validated['scheduling_host_id'])
            : null;

        try {
            $location = $service->location_type === BookableService::LOCATION_TYPE_CUSTOMER_SITE
                ? $locationSnapshots->normalizeAddress(
                    type: BookableService::LOCATION_TYPE_CUSTOMER_SITE,
                    input: $request->customerSiteAddress(),
                )
                : null;
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'address_line_1' => $exception->getMessage(),
            ]);
        }

        try {
            $startsAt = $request->startsAt();
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                $service->usesRangeDuration() ? 'range_starts_at' : 'starts_at' => $exception->getMessage(),
            ]);
        }

        try {
            $endsAt = $request->endsAt();
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'range_ends_at' => $exception->getMessage(),
            ]);
        }

        try {
            $appointment = $createAppointment->handle(new AppointmentCreationData(
                service: $service,
                host: $host,
                startsAt: $startsAt,
                endsAt: $endsAt,
                idempotencyKey: $validated['idempotency_key'],
                booking: new AppointmentBookingData(
                    contact: $contact,
                    primaryAttendee: $contact,
                    name: $contact->name,
                    email: $contact->email,
                    phone: $contact->phone,
                    location: $location,
                    createdBy: $request->user(),
                    source: 'crm',
                    appointmentMeta: [
                        'creation' => [
                            'surface' => 'crm_scheduling',
                        ],
                    ],
                    attendeeMeta: [
                        'creation' => [
                            'surface' => 'crm_scheduling',
                        ],
                    ],
                ),
                lifecycle: new AppointmentLifecycleContext(
                    actor: $request->user(),
                    source: 'crm',
                    reason: 'crm_manual_create',
                    context: [
                        'surface' => 'crm_scheduling',
                    ],
                ),
            ));
        } catch (DomainException|InvalidArgumentException|LogicException $exception) {
            throw ValidationException::withMessages([
                $service->usesRangeDuration() ? 'range_ends_at' : 'starts_at' => $exception->getMessage(),
            ]);
        }

        return redirect()
            ->route('crm.scheduling.index', array_filter([
                'contact_id' => $contact->getKey(),
                'bookable_service_id' => $service->getKey(),
                'scheduling_host_id' => $host?->getKey(),
                'date' => $appointment->starts_at
                    ->setTimezone($service->timezone)
                    ->toDateString(),
            ], static fn (mixed $value): bool => $value !== null))
            ->with(
                'success',
                $appointment->status === Appointment::STATUS_PENDING
                    ? 'Appointment created and awaiting confirmation.'
                    : 'Appointment scheduled.',
            );
    }

    private function contactLabel(Contact $contact): string
    {
        $name = trim((string) ($contact->name ?: trim(
            trim((string) $contact->first_name).' '.trim((string) $contact->last_name),
        )));

        if ($name !== '' && filled($contact->email)) {
            return $name.' — '.$contact->email;
        }

        if ($name !== '') {
            return $name;
        }

        return $contact->email
            ?: $contact->phone
            ?: 'Contact #'.$contact->getKey();
    }

    /**
     * @param array<string, mixed> $query
     */
    private function oldOrQueryInteger(
        Request $request,
        string $oldKey,
        array $query,
    ): int {
        $value = $request->old($oldKey, $query[$oldKey] ?? 0);

        return is_numeric($value) ? (int) $value : 0;
    }
}