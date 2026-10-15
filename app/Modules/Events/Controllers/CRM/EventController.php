<?php

namespace App\Modules\Events\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Core\Access\Services\ContactVisibility;
use App\Modules\Core\Models\Contact;
use App\Modules\Events\Actions\CancelEventAction;
use App\Modules\Events\Actions\CompleteEventAction;
use App\Modules\Events\Actions\CreateEventAction;
use App\Modules\Events\Actions\DeleteEventExternalReferenceAction;
use App\Modules\Events\Actions\DeleteEventStakeholderAction;
use App\Modules\Events\Actions\PostponeEventAction;
use App\Modules\Events\Actions\PromoteEventAction;
use App\Modules\Events\Actions\RecordEventAttendanceAction;
use App\Modules\Events\Actions\RescheduleEventAction;
use App\Modules\Events\Actions\SaveEventExternalReferenceAction;
use App\Modules\Events\Actions\SaveEventStakeholderAction;
use App\Modules\Events\Actions\SetPrimaryEventExternalReferenceAction;
use App\Modules\Events\Actions\UpdateDraftEventAction;
use App\Modules\Events\Data\EventActionContext;
use App\Modules\Events\Data\EventDefinitionContribution;
use App\Modules\Events\Enums\EventAttendanceMode;
use App\Modules\Events\Enums\EventAttendanceStatus;
use App\Modules\Events\Enums\EventStatus;
use App\Modules\Events\Exceptions\EventActionBlockedException;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventAttendance;
use App\Modules\Events\Models\EventExternalReference;
use App\Modules\Events\Models\EventStakeholder;
use App\Modules\Events\Readiness\CoreEventReadinessContributor;
use App\Modules\Events\Requests\RecordEventAttendanceRequest;
use App\Modules\Events\Requests\RescheduleEventRequest;
use App\Modules\Events\Requests\SaveEventExternalReferenceRequest;
use App\Modules\Events\Requests\SaveEventRequest;
use App\Modules\Events\Requests\SaveEventStakeholderRequest;
use App\Modules\Events\Services\EventAnnouncementGate;
use App\Modules\Events\Services\EventDefinitionRegistry;
use App\Modules\Events\Services\EventDuplicateDetector;
use App\Modules\Events\Services\EventPromotionGate;
use App\Modules\Events\Services\EventReadinessRegistry;
use DateTimeZone;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class EventController extends Controller
{
    public function __construct(
        private readonly EventDefinitionRegistry $definitions,
        private readonly EventReadinessRegistry $readiness,
        private readonly EventDuplicateDetector $duplicates,
        private readonly EventAnnouncementGate $announcement,
        private readonly EventPromotionGate $promotion,
    ) {}

    public function index(Request $request): View
    {
        $validated = $request->validate([
            'status' => ['nullable', Rule::enum(EventStatus::class)],
            'q' => ['nullable', 'string', 'max:150'],
        ]);

        $status = is_string($validated['status'] ?? null)
            ? $validated['status']
            : null;
        $query = is_string($validated['q'] ?? null)
            ? trim($validated['q'])
            : '';

        $events = Event::query()
            ->when(
                $status !== null,
                fn ($builder) => $builder->where('status', $status),
            )
            ->when(
                $query !== '',
                function ($builder) use ($query): void {
                    $builder->where(function ($nested) use ($query): void {
                        $nested
                            ->where('title', 'like', '%'.$query.'%')
                            ->orWhere('venue_name', 'like', '%'.$query.'%')
                            ->orWhere('city', 'like', '%'.$query.'%');
                    });
                },
            )
            ->orderByRaw('CASE WHEN starts_at IS NULL THEN 1 ELSE 0 END')
            ->orderBy('starts_at')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        return view('crm.events.index', [
            'events' => $events,
            'filters' => [
                'status' => $status,
                'q' => $query,
            ],
            'statusOptions' => $this->statusOptions(),
            'typeLabels' => $this->typeLabels(),
        ]);
    }

    public function create(): View
    {
        return view('crm.events.create', $this->formViewData());
    }

    public function store(
        SaveEventRequest $request,
        CreateEventAction $createEvent,
    ): RedirectResponse {
        try {
            $event = $createEvent->handle(
                attributes: $request->eventAttributes(),
                context: $this->actionContext(),
                confirmDuplicate: $request->boolean('confirm_duplicate'),
            );
        } catch (EventActionBlockedException $exception) {
            $this->throwValidationFor($exception);
        }

        return redirect()
            ->route('crm.events.show', $event)
            ->with('status', 'Event draft created.');
    }

    public function show(
        Request $request,
        Event $event,
        ContactVisibility $contactVisibility,
    ): View {
        $event->load([
            'primaryExternalReference',
            'externalReferences',
            'stakeholders',
        ]);

        $coreReadiness = $this->readiness->evaluate(
            $event,
            CoreEventReadinessContributor::CAPABILITY,
        );
        $duplicates = $this->duplicates->similar($event);
        $user = $request->user();

        $attendances = EventAttendance::query()
            ->where('event_id', $event->getKey())
            ->when(
                $user instanceof User,
                fn ($query) => $query->whereIn(
                    'contact_id',
                    $contactVisibility->apply(
                        Contact::query()->select('contacts.id'),
                        $user,
                    ),
                ),
                fn ($query) => $query->whereRaw('1 = 0'),
            )
            ->with('contact')
            ->orderByDesc('observed_at')
            ->orderByDesc('id')
            ->get();

        return view('crm.events.show', [
            'event' => $event,
            'typeLabel' => $this->typeLabels()[$event->type_key] ?? $event->type_key,
            'statusLabel' => $this->statusOptions()[$event->status->value] ?? $event->status->value,
            'attendanceModeLabel' => $this->attendanceModeOptions()[$event->attendance_mode->value]
                ?? $event->attendance_mode->value,
            'coreReadiness' => $coreReadiness,
            'duplicates' => $duplicates,
            'announcementDecision' => $this->announcement->decision($event),
            'promotionDecision' => $this->promotion->decision($event),
            'externalReferenceProviders' => $this->definitionOptions(
                EventDefinitionContribution::CATEGORY_EXTERNAL_REFERENCE_PROVIDER,
            ),
            'externalReferenceProviderLabels' => $this->definitionLabels(
                EventDefinitionContribution::CATEGORY_EXTERNAL_REFERENCE_PROVIDER,
            ),
            'externalReferenceTypes' => $this->definitionOptions(
                EventDefinitionContribution::CATEGORY_EXTERNAL_REFERENCE_TYPE,
            ),
            'externalReferenceTypeLabels' => $this->definitionLabels(
                EventDefinitionContribution::CATEGORY_EXTERNAL_REFERENCE_TYPE,
            ),
            'stakeholderRoles' => $this->definitionOptions(
                EventDefinitionContribution::CATEGORY_STAKEHOLDER_ROLE,
            ),
            'stakeholderRoleLabels' => $this->definitionLabels(
                EventDefinitionContribution::CATEGORY_STAKEHOLDER_ROLE,
            ),
            'attendanceStatusOptions' => $this->attendanceStatusOptions(),
            'attendances' => $attendances,
            'timezoneOptions' => DateTimeZone::listIdentifiers(),
            'lifecycleActions' => [
                'postpone' => $event->status === EventStatus::Upcoming,
                'reschedule' => $event->status === EventStatus::Postponed,
                'cancel' => $event->status === EventStatus::Upcoming,
                'complete' => $event->status === EventStatus::Upcoming,
            ],
            'rescheduleValues' => [
                'starts_at_local' => $event->starts_at
                    ?->copy()
                    ->setTimezone($event->timezone)
                    ->format('Y-m-d\\TH:i'),
                'ends_at_local' => $event->ends_at
                    ?->copy()
                    ->setTimezone($event->timezone)
                    ->format('Y-m-d\\TH:i'),
                'timezone' => $event->timezone,
            ],
            'attendanceObservedAtLocal' => now($event->timezone)->format('Y-m-d\\TH:i'),
        ]);
    }

    public function edit(Event $event): View
    {
        abort_if($event->status !== EventStatus::Draft, 409);

        return view('crm.events.edit', $this->formViewData($event) + [
            'event' => $event,
        ]);
    }

    public function update(
        SaveEventRequest $request,
        Event $event,
        UpdateDraftEventAction $updateEvent,
    ): RedirectResponse {
        try {
            $event = $updateEvent->handle(
                event: $event,
                attributes: $request->eventAttributes(),
                confirmDuplicate: $request->boolean('confirm_duplicate'),
            );
        } catch (EventActionBlockedException $exception) {
            $this->throwValidationFor($exception);
        }

        return redirect()
            ->route('crm.events.show', $event)
            ->with('status', 'Event draft updated.');
    }

    public function promote(
        Request $request,
        Event $event,
        PromoteEventAction $promoteEvent,
    ): RedirectResponse {
        $validated = $request->validate([
            'confirm_duplicate' => ['nullable', 'boolean'],
        ]);

        try {
            $event = $promoteEvent->handle(
                event: $event,
                context: $this->actionContext(),
                confirmDuplicate: (bool) ($validated['confirm_duplicate'] ?? false),
            );
        } catch (EventActionBlockedException $exception) {
            $this->throwValidationFor($exception, readinessField: 'promotion');
        }

        return redirect()
            ->route('crm.events.show', $event)
            ->with('status', 'Event moved to upcoming.');
    }

    public function postpone(
        Event $event,
        PostponeEventAction $postponeEvent,
    ): RedirectResponse {
        try {
            $event = $postponeEvent->handle(
                event: $event,
                context: $this->actionContext(),
            );
        } catch (EventActionBlockedException $exception) {
            $this->throwValidationFor($exception);
        }

        return redirect()
            ->route('crm.events.show', $event)
            ->with('status', 'Event postponed.');
    }

    public function reschedule(
        RescheduleEventRequest $request,
        Event $event,
        RescheduleEventAction $rescheduleEvent,
    ): RedirectResponse {
        try {
            $event = $rescheduleEvent->handle(
                event: $event,
                startsAt: $request->startsAt(),
                endsAt: $request->endsAt(),
                timezone: $request->timezone(),
                context: $this->actionContext(),
            );
        } catch (EventActionBlockedException $exception) {
            $this->throwValidationFor($exception, readinessField: 'starts_at_local');
        }

        return redirect()
            ->route('crm.events.show', $event)
            ->with('status', 'Event rescheduled.');
    }

    public function cancel(
        Event $event,
        CancelEventAction $cancelEvent,
    ): RedirectResponse {
        try {
            $event = $cancelEvent->handle(
                event: $event,
                context: $this->actionContext(),
            );
        } catch (EventActionBlockedException $exception) {
            $this->throwValidationFor($exception);
        }

        return redirect()
            ->route('crm.events.show', $event)
            ->with('status', 'Event cancelled.');
    }

    public function complete(
        Event $event,
        CompleteEventAction $completeEvent,
    ): RedirectResponse {
        try {
            $event = $completeEvent->handle(
                event: $event,
                context: $this->actionContext(),
            );
        } catch (EventActionBlockedException $exception) {
            $this->throwValidationFor($exception);
        }

        return redirect()
            ->route('crm.events.show', $event)
            ->with('status', 'Event completed.');
    }

    public function storeExternalReference(
        SaveEventExternalReferenceRequest $request,
        Event $event,
        SaveEventExternalReferenceAction $saveReference,
    ): RedirectResponse {
        try {
            $saveReference->handle(
                event: $event,
                attributes: $request->referenceAttributes(),
            );
        } catch (EventActionBlockedException $exception) {
            $this->throwValidationFor($exception);
        }

        return $this->redirectToEvent($event, 'External reference added.');
    }

    public function updateExternalReference(
        SaveEventExternalReferenceRequest $request,
        Event $event,
        EventExternalReference $externalReference,
        SaveEventExternalReferenceAction $saveReference,
    ): RedirectResponse {
        try {
            $saveReference->handle(
                event: $event,
                attributes: $request->referenceAttributes(),
                reference: $externalReference,
            );
        } catch (EventActionBlockedException $exception) {
            $this->throwValidationFor($exception);
        }

        return $this->redirectToEvent($event, 'External reference updated.');
    }

    public function destroyExternalReference(
        Event $event,
        EventExternalReference $externalReference,
        DeleteEventExternalReferenceAction $deleteReference,
    ): RedirectResponse {
        try {
            $deleteReference->handle($event, $externalReference);
        } catch (EventActionBlockedException $exception) {
            $this->throwValidationFor($exception);
        }

        return $this->redirectToEvent($event, 'External reference removed.');
    }

    public function updatePrimaryExternalReference(
        Request $request,
        Event $event,
        SetPrimaryEventExternalReferenceAction $setPrimaryReference,
    ): RedirectResponse {
        $validated = $request->validate([
            'external_reference_id' => ['nullable', 'integer', 'exists:event_external_references,id'],
        ]);
        $referenceId = $validated['external_reference_id'] ?? null;
        $reference = filled($referenceId)
            ? EventExternalReference::query()->findOrFail((int) $referenceId)
            : null;

        try {
            $setPrimaryReference->handle($event, $reference);
        } catch (EventActionBlockedException $exception) {
            $this->throwValidationFor($exception);
        }

        return $this->redirectToEvent(
            $event,
            $reference instanceof EventExternalReference
                ? 'Primary external reference updated.'
                : 'Primary external reference cleared.',
        );
    }

    public function storeStakeholder(
        SaveEventStakeholderRequest $request,
        Event $event,
        SaveEventStakeholderAction $saveStakeholder,
    ): RedirectResponse {
        try {
            $saveStakeholder->handle(
                event: $event,
                attributes: $request->stakeholderAttributes(),
            );
        } catch (EventActionBlockedException $exception) {
            $this->throwValidationFor($exception);
        }

        return $this->redirectToEvent($event, 'Stakeholder added.');
    }

    public function updateStakeholder(
        SaveEventStakeholderRequest $request,
        Event $event,
        EventStakeholder $stakeholder,
        SaveEventStakeholderAction $saveStakeholder,
    ): RedirectResponse {
        try {
            $saveStakeholder->handle(
                event: $event,
                attributes: $request->stakeholderAttributes(),
                stakeholder: $stakeholder,
            );
        } catch (EventActionBlockedException $exception) {
            $this->throwValidationFor($exception);
        }

        return $this->redirectToEvent($event, 'Stakeholder updated.');
    }

    public function destroyStakeholder(
        Event $event,
        EventStakeholder $stakeholder,
        DeleteEventStakeholderAction $deleteStakeholder,
    ): RedirectResponse {
        try {
            $deleteStakeholder->handle($event, $stakeholder);
        } catch (EventActionBlockedException $exception) {
            $this->throwValidationFor($exception);
        }

        return $this->redirectToEvent($event, 'Stakeholder removed.');
    }

    public function recordAttendance(
        RecordEventAttendanceRequest $request,
        Event $event,
        ContactVisibility $contactVisibility,
        RecordEventAttendanceAction $recordAttendance,
    ): RedirectResponse {
        $user = $request->user();
        $contact = Contact::query()->findOrFail((int) $request->validated('contact_id'));

        if (! $user instanceof User || ! $contactVisibility->canView($user, $contact)) {
            abort(404);
        }

        try {
            $recordAttendance->handle(
                event: $event,
                contact: $contact,
                status: (string) $request->validated('status'),
                observedAt: $request->observedAt($event),
                sourceKey: 'operator',
            );
        } catch (EventActionBlockedException $exception) {
            $this->throwValidationFor($exception);
        }

        return $this->redirectToEvent($event, 'Attendance updated.');
    }

    /**
     * @return array<string, mixed>
     */
    private function formViewData(?Event $event = null): array
    {
        $timezone = $event?->timezone
            ?: (string) config('client.timezone', config('app.timezone', 'UTC'));

        return [
            'eventTypes' => $this->definitionOptions(
                EventDefinitionContribution::CATEGORY_EVENT_TYPE,
            ),
            'attendanceModeOptions' => $this->attendanceModeOptions(),
            'timezoneOptions' => DateTimeZone::listIdentifiers(),
            'formValues' => [
                'type_key' => $event?->type_key,
                'title' => $event?->title ?? '',
                'description' => $event?->description ?? '',
                'attendance_mode' => $event?->attendance_mode->value
                    ?? EventAttendanceMode::Physical->value,
                'starts_at_local' => $event?->starts_at
                    ?->copy()
                    ->setTimezone($timezone)
                    ->format('Y-m-d\\TH:i'),
                'ends_at_local' => $event?->ends_at
                    ?->copy()
                    ->setTimezone($timezone)
                    ->format('Y-m-d\\TH:i'),
                'timezone' => $timezone,
                'announcement_at_local' => $event?->announcement_at
                    ?->copy()
                    ->setTimezone($timezone)
                    ->format('Y-m-d\\TH:i'),
                'venue_name' => $event?->venue_name ?? '',
                'address_line_1' => $event?->address_line_1 ?? '',
                'address_line_2' => $event?->address_line_2 ?? '',
                'city' => $event?->city ?? '',
                'region' => $event?->region ?? '',
                'postal_code' => $event?->postal_code ?? '',
                'country' => $event?->country ?? '',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function statusOptions(): array
    {
        $labels = [];

        foreach (EventStatus::cases() as $status) {
            $labels[$status->value] = match ($status) {
                EventStatus::Draft => 'Draft',
                EventStatus::Upcoming => 'Upcoming',
                EventStatus::Postponed => 'Postponed',
                EventStatus::Completed => 'Completed',
                EventStatus::Cancelled => 'Cancelled',
            };
        }

        return $labels;
    }

    /**
     * @return array<string, string>
     */
    private function attendanceModeOptions(): array
    {
        return [
            EventAttendanceMode::Physical->value => 'Physical',
            EventAttendanceMode::Virtual->value => 'Virtual',
            EventAttendanceMode::Hybrid->value => 'Hybrid',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function attendanceStatusOptions(): array
    {
        return [
            EventAttendanceStatus::Attended->value => 'Attended',
            EventAttendanceStatus::DidNotAttend->value => 'Did not attend',
        ];
    }

    /**
     * @return array<int, array{key: string, label: string}>
     */
    private function definitionOptions(string $category): array
    {
        return array_values(array_map(
            static fn ($definition): array => [
                'key' => $definition->key,
                'label' => $definition->label,
            ],
            $this->definitions->definitions($category),
        ));
    }

    /**
     * @return array<string, string>
     */
    private function definitionLabels(string $category): array
    {
        $labels = [];

        foreach ($this->definitions->definitions(
            $category,
            activeOnly: false,
        ) as $definition) {
            $labels[$definition->key] = $definition->label;
        }

        return $labels;
    }

    private function typeLabels(): array
    {
        $labels = [];

        foreach ($this->definitions->definitions(
            EventDefinitionContribution::CATEGORY_EVENT_TYPE,
            activeOnly: false,
        ) as $definition) {
            $labels[$definition->key] = $definition->label;
        }

        return $labels;
    }

    private function actionContext(): EventActionContext
    {
        return new EventActionContext(
            source: 'crm_events',
        );
    }

    private function redirectToEvent(Event $event, string $status): RedirectResponse
    {
        return redirect()
            ->route('crm.events.show', $event)
            ->with('status', $status);
    }

    private function throwValidationFor(
        EventActionBlockedException $exception,
        ?string $readinessField = null,
    ): never {
        if ($exception->blocker === 'event_type_unregistered') {
            throw ValidationException::withMessages([
                'type_key' => 'The selected Event type is no longer available.',
            ]);
        }

        if ($exception->blocker === 'duplicate_confirmation_required') {
            throw ValidationException::withMessages([
                'confirm_duplicate' => 'A similar Event already exists. Confirm that this is a separate Event before continuing.',
            ]);
        }

        if ($readinessField !== null && $exception->blocker === 'core_readiness_failed') {
            $codes = array_values(array_filter(
                $exception->context['codes'] ?? [],
                static fn (mixed $code): bool => is_string($code) && $code !== '',
            ));

            throw ValidationException::withMessages([
                $readinessField => 'This Event is not ready'.(
                    $codes !== [] ? ': '.implode(', ', $codes) : '.'
                ),
            ]);
        }

        if ($exception->blocker === 'external_reference_provider_unregistered') {
            throw ValidationException::withMessages([
                'provider_key' => 'Choose a registered external-reference provider.',
            ]);
        }

        if ($exception->blocker === 'external_reference_type_unregistered') {
            throw ValidationException::withMessages([
                'reference_type' => 'Choose a registered external-reference type.',
            ]);
        }

        if ($exception->blocker === 'external_reference_target_missing') {
            throw ValidationException::withMessages([
                'external_id' => 'Provide an external ID or URL.',
            ]);
        }

        if ($exception->blocker === 'external_reference_identity_conflict') {
            throw ValidationException::withMessages([
                'external_id' => 'That provider reference is already linked to an Event.',
            ]);
        }

        if ($exception->blocker === 'stakeholder_role_unregistered') {
            throw ValidationException::withMessages([
                'role_key' => 'Choose a registered stakeholder role.',
            ]);
        }

        if (in_array($exception->blocker, [
            'external_reference_event_mismatch',
            'stakeholder_event_mismatch',
        ], true)) {
            abort(404);
        }

        abort(409, $exception->getMessage());
    }
}