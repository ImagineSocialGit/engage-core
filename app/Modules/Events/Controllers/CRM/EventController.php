<?php

namespace App\Modules\Events\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Modules\Events\Actions\CreateEventAction;
use App\Modules\Events\Actions\PromoteEventAction;
use App\Modules\Events\Actions\UpdateDraftEventAction;
use App\Modules\Events\Data\EventActionContext;
use App\Modules\Events\Data\EventDefinitionContribution;
use App\Modules\Events\Enums\EventAttendanceMode;
use App\Modules\Events\Enums\EventStatus;
use App\Modules\Events\Exceptions\EventActionBlockedException;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Readiness\CoreEventReadinessContributor;
use App\Modules\Events\Requests\SaveEventRequest;
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

    public function show(Event $event): View
    {
        $event->load([
            'primaryExternalReference',
            'externalReferences',
            'stakeholders',
            'attendances',
        ]);

        $coreReadiness = $this->readiness->evaluate(
            $event,
            CoreEventReadinessContributor::CAPABILITY,
        );
        $duplicates = $this->duplicates->similar($event);

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
            $this->throwValidationFor($exception, promotion: true);
        }

        return redirect()
            ->route('crm.events.show', $event)
            ->with('status', 'Event moved to upcoming.');
    }

    /**
     * @return array<string, mixed>
     */
    private function formViewData(?Event $event = null): array
    {
        $timezone = $event?->timezone
            ?: (string) config('client.timezone', config('app.timezone', 'UTC'));

        return [
            'eventTypes' => array_values(array_map(
                static fn ($definition): array => [
                    'key' => $definition->key,
                    'label' => $definition->label,
                ],
                $this->definitions->definitions(
                    EventDefinitionContribution::CATEGORY_EVENT_TYPE,
                ),
            )),
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

    private function throwValidationFor(
        EventActionBlockedException $exception,
        bool $promotion = false,
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

        if ($promotion && $exception->blocker === 'core_readiness_failed') {
            $codes = array_values(array_filter(
                $exception->context['codes'] ?? [],
                static fn (mixed $code): bool => is_string($code) && $code !== '',
            ));

            throw ValidationException::withMessages([
                'promotion' => 'This Event is not ready to become upcoming'.(
                    $codes !== [] ? ': '.implode(', ', $codes) : '.'
                ),
            ]);
        }

        abort(409, $exception->getMessage());
    }
}