<?php

namespace App\Modules\Scheduling\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Modules\Core\Actions\Contacts\ResolveContactByEmailAction;
use App\Modules\Core\Models\Contact;
use App\Modules\Scheduling\Actions\CreateAppointmentAction;
use App\Modules\Scheduling\Data\AppointmentBookingData;
use App\Modules\Scheduling\Data\AppointmentCreationData;
use App\Modules\Scheduling\Data\AppointmentLifecycleContext;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Models\BookableService;
use App\Modules\Scheduling\Models\SchedulingHost;
use App\Modules\Scheduling\Requests\StoreAppointmentRequest;
use App\Modules\Scheduling\Services\SchedulingAvailableStartRangeBuilder;
use App\Modules\Scheduling\Services\SchedulingLocationSnapshotResolver;
use App\Modules\Scheduling\Services\SchedulingReadService;
use App\Modules\Scheduling\Services\SchedulingSetupReadiness;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
        SchedulingSetupReadiness $setupReadiness,
    ): View|RedirectResponse {
        $legacyCreateQuery = array_filter([
            'contact_id' => $request->query('contact_id'),
            'bookable_service_id' => $request->query('bookable_service_id'),
            'scheduling_host_id' => $request->query('scheduling_host_id'),
            'date' => $request->query('date'),
        ], static fn (mixed $value): bool => $value !== null && $value !== '');

        if ($legacyCreateQuery !== []) {
            return redirect()->route('crm.scheduling.appointments.create', $legacyCreateQuery);
        }

        $setupSummary = $setupReadiness->summary();

        if (($setupSummary['has_service'] ?? false) !== true) {
            return redirect()
                ->route('crm.scheduling.configuration.services.index');
        }

        $services = $read->activeServices();
        $upcomingAppointments = $read->upcomingAppointments();

        return view('crm.scheduling.index', [
            'title' => 'Scheduling',
            'heading' => 'Scheduling',
            'services' => $services,
            'upcomingAppointments' => $upcomingAppointments,
            'upcomingAppointmentRows' => $this->presentUpcomingAppointments($upcomingAppointments),
            'pendingCount' => $upcomingAppointments
                ->where('status', Appointment::STATUS_PENDING)
                ->count(),
            'setupReadiness' => $setupSummary,
        ]);
    }

    public function create(
        Request $request,
        SchedulingReadService $read,
        SchedulingSetupReadiness $setupReadiness,
        SchedulingAvailableStartRangeBuilder $startRanges,
    ): View|RedirectResponse {
        $query = $request->validate([
            'contact_id' => ['nullable', 'integer', 'exists:contacts,id'],
            'bookable_service_id' => ['nullable', 'integer'],
            'scheduling_host_id' => ['nullable', 'integer'],
            'date' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $setupSummary = $setupReadiness->summary();

        if (($setupSummary['has_service'] ?? false) !== true) {
            return redirect()
                ->route('crm.scheduling.configuration.services.index');
        }

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
        $availableStartRanges = $selectedService instanceof BookableService
            && $selectedService->usesFixedDuration()
                ? $startRanges->build(
                    slots: $slots,
                    intervalMinutes: max(1, (int) $selectedService->slot_interval_minutes),
                )
                : [];
        $availableStartRanges = $this->presentStartRanges($availableStartRanges);
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

        return view('crm.scheduling.create', [
            'title' => 'Schedule Appointment',
            'heading' => 'Schedule Appointment',
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
            'availableStartRanges' => $availableStartRanges,
            'setupReadiness' => $setupSummary,
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
        ResolveContactByEmailAction $resolveContactByEmail,
    ): RedirectResponse {
        $validated = $request->validated();
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
            $appointment = DB::transaction(function () use (
                $request,
                $validated,
                $service,
                $host,
                $location,
                $startsAt,
                $endsAt,
                $createAppointment,
                $resolveContactByEmail,
            ): Appointment {
                $attendee = $this->resolveBookingAttendee(
                    request: $request,
                    validated: $validated,
                    resolveContactByEmail: $resolveContactByEmail,
                );

                return $createAppointment->handle(new AppointmentCreationData(
                    service: $service,
                    host: $host,
                    startsAt: $startsAt,
                    endsAt: $endsAt,
                    idempotencyKey: $validated['idempotency_key'],
                    booking: new AppointmentBookingData(
                        contact: $attendee['contact'],
                        primaryAttendee: $attendee['contact'],
                        name: $attendee['name'],
                        email: $attendee['email'],
                        phone: $attendee['phone'],
                        description: $request->attendeeContext(),
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
            });
        } catch (DomainException|InvalidArgumentException|LogicException $exception) {
            throw ValidationException::withMessages([
                $service->usesRangeDuration() ? 'range_ends_at' : 'starts_at' => $exception->getMessage(),
            ]);
        }

        $success = $appointment->status === Appointment::STATUS_PENDING
            ? 'Appointment created and awaiting confirmation.'
            : 'Appointment scheduled.';

        if ($request->attendeeMode() === StoreAppointmentRequest::ATTENDEE_MODE_GUEST) {
            $success .= ' The attendee was not added to Contacts.';
        }

        return redirect()
            ->route('crm.scheduling.appointments.create', array_filter([
                'contact_id' => $appointment->contact_id,
                'bookable_service_id' => $service->getKey(),
                'scheduling_host_id' => $host?->getKey(),
                'date' => $appointment->starts_at
                    ->setTimezone($service->timezone)
                    ->toDateString(),
            ], static fn (mixed $value): bool => $value !== null))
            ->with('success', $success);
    }

    /**
     * @param array<string, mixed> $validated
     * @return array{contact: Contact|null, name: string|null, email: string|null, phone: string|null}
     */
    private function resolveBookingAttendee(
        StoreAppointmentRequest $request,
        array $validated,
        ResolveContactByEmailAction $resolveContactByEmail,
    ): array {
        if ($request->attendeeMode() === StoreAppointmentRequest::ATTENDEE_MODE_CONTACT) {
            $contact = Contact::query()->find($validated['contact_id']);

            if (! $contact instanceof Contact) {
                throw ValidationException::withMessages([
                    'contact_id' => 'The selected Contact could not be found.',
                ]);
            }

            return [
                'contact' => $contact,
                'name' => $contact->name,
                'email' => $contact->email,
                'phone' => $contact->phone,
            ];
        }

        if ($request->attendeeMode() === StoreAppointmentRequest::ATTENDEE_MODE_NEW_CONTACT) {
            try {
                $contact = $resolveContactByEmail->handle(
                    email: (string) $request->attendeeEmail(),
                    name: $request->attendeeName(),
                    phone: $request->attendeePhone(),
                    source: 'crm',
                    subsource: 'scheduling',
                );
            } catch (InvalidArgumentException $exception) {
                throw ValidationException::withMessages([
                    'attendee_email' => $exception->getMessage(),
                ]);
            }

            return [
                'contact' => $contact,
                'name' => $contact->name,
                'email' => $contact->email,
                'phone' => $contact->phone,
            ];
        }

        return [
            'contact' => null,
            'name' => $request->attendeeName(),
            'email' => $request->attendeeEmail(),
            'phone' => $request->attendeePhone(),
        ];
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
     * @param \Illuminate\Support\Collection<int, Appointment> $appointments
     * @return array<int, array<string, mixed>>
     */
    private function presentUpcomingAppointments($appointments): array
    {
        return $appointments->map(function (Appointment $appointment): array {
            $timezone = in_array($appointment->timezone, timezone_identifiers_list(), true)
                ? $appointment->timezone
                : config('client.timezone', 'UTC');
            $timezone = in_array($timezone, timezone_identifiers_list(), true)
                ? $timezone
                : 'UTC';
            $attendee = $appointment->attendees->first();

            return [
                'appointment' => $appointment,
                'title' => $appointment->title
                    ?: $appointment->bookableService?->name
                    ?: 'Appointment',
                'contact_label' => $appointment->contact?->name
                    ?: $attendee?->name
                    ?: $appointment->contact?->email
                    ?: $attendee?->email
                    ?: 'Unidentified attendee',
                'time_label' => $appointment->starts_at
                    ->copy()
                    ->setTimezone($timezone)
                    ->format('D, M j, Y \\a\\t g:i A'),
                'host_label' => $appointment->schedulingHost?->name,
                'status_label' => str($appointment->status)->replace('_', ' ')->title()->toString(),
                'status_class' => match ($appointment->status) {
                    Appointment::STATUS_PENDING => 'bg-amber-100 text-amber-800',
                    Appointment::STATUS_CONFIRMED => 'bg-emerald-100 text-emerald-800',
                    default => 'bg-sky-100 text-sky-800',
                },
            ];
        })->values()->all();
    }

    /**
     * @param array<int, array<string, mixed>> $ranges
     * @return array<int, array<string, mixed>>
     */
    private function presentStartRanges(array $ranges): array
    {
        return array_map(function (array $range): array {
            $start = $range['starts_at']->setTimezone($range['display_timezone']);
            $end = $range['last_start_at']->setTimezone($range['display_timezone']);

            return [
                ...$range,
                'first_iso' => $range['starts_at']->toIso8601String(),
                'last_iso' => $range['last_start_at']->toIso8601String(),
                'display_label' => $start->format('g:i A')
                    .(($range['slot_count'] ?? 0) > 1 ? '–'.$end->format('g:i A') : ''),
                'cadence_label' => ($range['slot_count'] ?? 0) > 1
                    ? 'Start every '.$range['interval_minutes'].' minutes'
                    : 'One available start',
                'capacity_label' => $range['remaining_capacity'].' open '
                    .($range['remaining_capacity'] === 1 ? 'spot' : 'spots').' per start',
                'slot_options' => array_map(
                    static fn ($slot): array => [
                        'value' => $slot->startsAt->toIso8601String(),
                        'label' => $slot->localStartsAt()->format('g:i A'),
                    ],
                    $range['slots'] ?? [],
                ),
            ];
        }, $ranges);
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