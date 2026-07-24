@php
    $displayTimezone = in_array($appointment->timezone, timezone_identifiers_list(), true)
        ? $appointment->timezone
        : config('client.timezone', 'UTC');
    $activeStatuses = [
        \App\Modules\Scheduling\Models\Appointment::STATUS_PENDING,
        \App\Modules\Scheduling\Models\Appointment::STATUS_SCHEDULED,
        \App\Modules\Scheduling\Models\Appointment::STATUS_CONFIRMED,
    ];
    $isActive = in_array($appointment->status, $activeStatuses, true);
    $canConfirm = in_array($appointment->status, [
        \App\Modules\Scheduling\Models\Appointment::STATUS_PENDING,
        \App\Modules\Scheduling\Models\Appointment::STATUS_SCHEDULED,
    ], true);
    $hasStarted = $appointment->starts_at?->lessThanOrEqualTo(now('UTC')) ?? false;
    $noticeMinutes = max(0, (int) ($appointment->bookableService?->cancellation_notice_minutes ?? 0));
    $cancellationDeadline = $appointment->starts_at?->copy()->subMinutes($noticeMinutes);
    $requiresCancellationOverride = $isActive
        && $cancellationDeadline !== null
        && now('UTC')->greaterThan($cancellationDeadline);
    $primaryAttendee = $appointment->attendees->first();
    $replacement = $appointment->rescheduledAppointments->first();
    $statusClasses = match($appointment->status) {
        \App\Modules\Scheduling\Models\Appointment::STATUS_PENDING => 'bg-amber-100 text-amber-800',
        \App\Modules\Scheduling\Models\Appointment::STATUS_CONFIRMED => 'bg-emerald-100 text-emerald-800',
        \App\Modules\Scheduling\Models\Appointment::STATUS_COMPLETED => 'bg-teal-100 text-teal-800',
        \App\Modules\Scheduling\Models\Appointment::STATUS_CANCELED => 'bg-slate-200 text-slate-700',
        \App\Modules\Scheduling\Models\Appointment::STATUS_NO_SHOW => 'bg-rose-100 text-rose-800',
        default => 'bg-sky-100 text-sky-800',
    };
@endphp

<x-layouts.crm
    :title="$title"
    :heading="$heading"
    subheading="Review the appointment record, attendee snapshots, and durable lifecycle history."
>
    <div class="space-y-6">
        @if (session('success'))
            <x-ui.feedback.alert type="success">
                {{ session('success') }}
            </x-ui.feedback.alert>
        @endif

        @if (session('error'))
            <x-ui.feedback.alert type="error">
                {{ session('error') }}
            </x-ui.feedback.alert>
        @endif

        <a
            href="{{ route('crm.scheduling.index') }}"
            class="inline-flex text-sm font-semibold text-teal-700 hover:text-teal-900 hover:underline"
        >
            ← Back to Scheduling
        </a>

        <div class="grid gap-6 xl:grid-cols-[minmax(0,1.2fr)_minmax(340px,0.8fr)]">
            <div class="space-y-6">
                <x-ui.card class="space-y-5">
                    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                        <div>
                            <p class="text-sm font-semibold text-slate-500">
                                {{ $appointment->bookableService?->name ?? 'Appointment service' }}
                            </p>
                            <h2 class="mt-1 text-2xl font-semibold tracking-tight text-slate-900">
                                {{ $appointment->title ?: 'Appointment' }}
                            </h2>
                            <p class="mt-2 text-sm font-medium text-slate-700">
                                {{ $appointment->starts_at?->setTimezone($displayTimezone)->format('D, M j, Y \a\t g:i A') }}
                                –
                                {{ $appointment->ends_at?->setTimezone($displayTimezone)->format('g:i A') }}
                            </p>
                            <p class="mt-1 text-xs text-slate-500">
                                {{ $displayTimezone }}
                            </p>
                        </div>

                        <span class="inline-flex self-start rounded-full px-3 py-1 text-xs font-semibold {{ $statusClasses }}">
                            {{ str($appointment->status)->replace('_', ' ')->title() }}
                        </span>
                    </div>

                    @if($appointment->description)
                        <p class="whitespace-pre-line text-sm leading-6 text-slate-600">
                            {{ $appointment->description }}
                        </p>
                    @endif

                    <dl class="grid gap-4 border-t border-slate-200 pt-5 sm:grid-cols-2">
                        <div>
                            <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Contact</dt>
                            <dd class="mt-1 text-sm text-slate-900">
                                @if($appointment->contact)
                                    <a
                                        href="{{ route('crm.contacts.show', $appointment->contact) }}"
                                        class="font-semibold text-teal-700 hover:text-teal-900 hover:underline"
                                    >
                                        {{ $appointment->contact->name ?: $appointment->contact->email }}
                                    </a>
                                @else
                                    {{ $primaryAttendee?->name ?: $primaryAttendee?->email ?: 'No linked contact' }}
                                @endif
                            </dd>
                        </div>

                        <div>
                            <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Host</dt>
                            <dd class="mt-1 text-sm text-slate-900">
                                {{ $appointment->schedulingHost?->name ?? 'Unhosted' }}
                            </dd>
                        </div>

                        <div>
                            <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Location</dt>
                            <dd class="mt-1 text-sm text-slate-900">
                                {{ $appointment->location_type ? str($appointment->location_type)->replace('_', ' ')->title() : 'Not specified' }}
                            </dd>
                        </div>

                        <div>
                            <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Source</dt>
                            <dd class="mt-1 text-sm text-slate-900">
                                {{ str($appointment->source)->replace('_', ' ')->title() }}
                            </dd>
                        </div>
                    </dl>

                    @if(is_array($appointment->location_details) && $appointment->location_details !== [])
                        <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Location details</p>
                            <pre class="mt-2 overflow-auto whitespace-pre-wrap text-xs text-slate-700">{{ json_encode($appointment->location_details, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                        </div>
                    @endif

                    @if($appointment->rescheduledFrom || $replacement)
                        <div class="rounded-xl border border-sky-200 bg-sky-50 p-4 text-sm text-sky-900">
                            @if($appointment->rescheduledFrom)
                                This appointment replaced
                                <a
                                    href="{{ route('crm.scheduling.appointments.show', $appointment->rescheduledFrom) }}"
                                    class="font-semibold underline"
                                >
                                    appointment #{{ $appointment->rescheduledFrom->id }}
                                </a>.
                            @endif

                            @if($replacement)
                                This appointment was replaced by
                                <a
                                    href="{{ route('crm.scheduling.appointments.show', $replacement) }}"
                                    class="font-semibold underline"
                                >
                                    appointment #{{ $replacement->id }}
                                </a>.
                            @endif
                        </div>
                    @endif
                </x-ui.card>

                <x-ui.card class="space-y-5">
                    <div>
                        <h2 class="text-lg font-semibold tracking-tight text-slate-900">Attendees</h2>
                        <p class="mt-1 text-sm text-slate-500">Stored snapshots remain unchanged when the linked Contact changes later.</p>
                    </div>

                    @forelse($appointment->attendees as $attendee)
                        <div class="rounded-xl border border-slate-200 p-4">
                            <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                                <div>
                                    <p class="font-semibold text-slate-900">
                                        {{ $attendee->name ?: $attendee->email ?: 'Unnamed attendee' }}
                                    </p>
                                    <p class="mt-1 text-sm text-slate-500">
                                        {{ collect([$attendee->email, $attendee->phone])->filter()->implode(' · ') ?: 'No contact details stored' }}
                                    </p>
                                </div>
                                <div class="text-left text-xs text-slate-500 sm:text-right">
                                    <p class="font-semibold text-slate-700">{{ str($attendee->role)->title() }}</p>
                                    <p class="mt-1">{{ str($attendee->status)->replace('_', ' ')->title() }}</p>
                                </div>
                            </div>
                        </div>
                    @empty
                        <p class="rounded-xl border border-dashed border-slate-300 bg-slate-50 p-4 text-sm text-slate-600">
                            No attendee snapshots are stored for this appointment.
                        </p>
                    @endforelse
                </x-ui.card>

                <x-ui.card class="space-y-5">
                    <div>
                        <h2 class="text-lg font-semibold tracking-tight text-slate-900">Lifecycle history</h2>
                        <p class="mt-1 text-sm text-slate-500">Append-only appointment events in chronological order.</p>
                    </div>

                    <div class="space-y-3">
                        @forelse($appointment->lifecycleEvents as $event)
                            @php
                                $actorLabel = $event->actor?->name
                                    ?? $event->actor?->email
                                    ?? ($event->actor ? class_basename($event->actor).' #'.$event->actor->getKey() : 'System');
                            @endphp

                            <div class="rounded-xl border border-slate-200 p-4">
                                <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                                    <div>
                                        <p class="font-semibold text-slate-900">
                                            {{ str($event->event_key)->replace('_', ' ')->title() }}
                                        </p>
                                        <p class="mt-1 text-sm text-slate-500">
                                            {{ $event->from_status ? str($event->from_status)->replace('_', ' ')->title().' → ' : '' }}{{ str($event->to_status)->replace('_', ' ')->title() }}
                                        </p>
                                        @if($event->reason)
                                            <p class="mt-2 whitespace-pre-line text-sm text-slate-700">{{ $event->reason }}</p>
                                        @endif
                                    </div>
                                    <div class="text-left text-xs text-slate-500 sm:text-right">
                                        <p>{{ $event->occurred_at?->setTimezone($displayTimezone)->format('M j, Y g:i A') }}</p>
                                        <p class="mt-1">{{ $actorLabel }} · {{ str($event->source)->replace('_', ' ')->title() }}</p>
                                    </div>
                                </div>
                            </div>
                        @empty
                            <p class="rounded-xl border border-dashed border-slate-300 bg-slate-50 p-4 text-sm text-slate-600">
                                No lifecycle events are stored for this appointment.
                            </p>
                        @endforelse
                    </div>
                </x-ui.card>
            </div>

            <div class="space-y-6">
                <x-ui.card class="space-y-5">
                    <div>
                        <div class="inline-flex rounded-full px-2 py-1 text-xs font-semibold {{ module_tone('scheduling', 'badge') }}">
                            Appointment actions
                        </div>
                        <p class="mt-3 text-sm text-slate-500">
                            Actions are revalidated against current appointment state when submitted.
                        </p>
                    </div>

                    @if($canConfirm)
                        <form method="POST" action="{{ route('crm.scheduling.appointments.confirm', $appointment) }}">
                            @csrf
                            @method('PATCH')
                            <x-ui.button type="submit" class="w-full justify-center">
                                Confirm Appointment
                            </x-ui.button>
                        </form>
                    @endif

                    @if($isActive && $hasStarted)
                        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-1">
                            <form method="POST" action="{{ route('crm.scheduling.appointments.complete', $appointment) }}">
                                @csrf
                                @method('PATCH')
                                <x-ui.button type="submit" class="w-full justify-center">
                                    Mark Complete
                                </x-ui.button>
                            </form>

                            <form method="POST" action="{{ route('crm.scheduling.appointments.no-show', $appointment) }}">
                                @csrf
                                @method('PATCH')
                                <x-ui.button type="submit" class="w-full justify-center">
                                    Mark No-show
                                </x-ui.button>
                            </form>
                        </div>
                    @elseif($isActive)
                        <p class="rounded-xl border border-slate-200 bg-slate-50 p-3 text-sm text-slate-600">
                            Completion and no-show outcomes become available after the appointment starts.
                        </p>
                    @endif

                    @if($isActive)
                        <form
                            method="POST"
                            action="{{ route('crm.scheduling.appointments.cancel', $appointment) }}"
                            class="space-y-4 border-t border-slate-200 pt-5"
                        >
                            @csrf
                            @method('PATCH')

                            <div>
                                <x-ui.form.label for="cancellation_reason">
                                    Cancellation reason
                                </x-ui.form.label>
                                <textarea
                                    id="cancellation_reason"
                                    name="cancellation_reason"
                                    rows="4"
                                    maxlength="10000"
                                    class="mt-1 block w-full rounded-xl border border-slate-300 px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-200"
                                >{{ old('cancellation_reason') }}</textarea>
                                <x-ui.form.error name="cancellation_reason" />
                            </div>

                            @if($requiresCancellationOverride)
                                <label class="flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
                                    <input
                                        type="checkbox"
                                        name="override_cancellation_notice"
                                        value="1"
                                        class="mt-0.5 rounded border-amber-400"
                                        @checked(old('override_cancellation_notice'))
                                    >
                                    <span>
                                        Override the {{ $noticeMinutes }}-minute cancellation notice requirement and record that authorization in the lifecycle event.
                                    </span>
                                </label>
                                <x-ui.form.error name="override_cancellation_notice" />
                            @endif

                            <x-ui.button type="submit" class="w-full justify-center">
                                Cancel Appointment
                            </x-ui.button>
                        </form>
                    @else
                        <p class="rounded-xl border border-slate-200 bg-slate-50 p-3 text-sm text-slate-600">
                            This appointment is terminal. No further lifecycle actions are available.
                        </p>
                    @endif
                </x-ui.card>
            </div>
        </div>
    </div>
</x-layouts.crm>