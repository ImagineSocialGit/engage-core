<x-layouts.crm
    :title="$event->title"
    :heading="$event->title"
    subheading="Review the occurrence, readiness, announcement timing, and linked Event records."
    module="events"
>
    <div class="space-y-6" data-event-workspace="{{ $event->id }}">
        @if (session('status'))
            <x-ui.feedback.alert type="success">{{ session('status') }}</x-ui.feedback.alert>
        @endif

        @if ($errors->any())
            <x-ui.feedback.alert type="error">
                <ul class="list-disc space-y-1 pl-5">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </x-ui.feedback.alert>
        @endif

        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <a href="{{ route('crm.events.index') }}" class="inline-flex items-center text-sm font-semibold text-slate-600 hover:text-slate-950">
                ← Back to Events
            </a>

            @if ($event->status === \App\Modules\Events\Enums\EventStatus::Draft)
                <div class="flex flex-wrap gap-2">
                    <a href="{{ route('crm.events.edit', $event) }}" class="inline-flex items-center justify-center rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50">
                        Edit draft
                    </a>
                    <form method="POST" action="{{ route('crm.events.promote', $event) }}" class="space-y-2">
                        @csrf
                        @if ($errors->has('confirm_duplicate'))
                            <label class="flex max-w-sm items-start gap-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs leading-5 text-amber-900">
                                <input type="checkbox" name="confirm_duplicate" value="1" class="mt-0.5 rounded border-slate-300">
                                <span>Confirm this is a separate Event before moving it to upcoming.</span>
                            </label>
                        @endif
                        <x-ui.button type="submit">Move to upcoming</x-ui.button>
                    </form>
                </div>
            @endif
        </div>

        <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_22rem]">
            <div class="space-y-6">
                <x-ui.card class="space-y-5">
                    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                        <div>
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold {{ module_tone('events', 'badge') }}">
                                    {{ $statusLabel }}
                                </span>
                                <span class="text-sm font-medium text-slate-500">{{ $typeLabel ?: 'No type' }}</span>
                            </div>
                            @if ($event->description)
                                <p class="mt-4 max-w-3xl whitespace-pre-line text-sm leading-6 text-slate-700">{{ $event->description }}</p>
                            @endif
                        </div>
                        <div class="text-sm font-semibold text-slate-600">{{ $attendanceModeLabel }}</div>
                    </div>

                    <dl class="grid gap-4 border-t border-slate-200 pt-5 sm:grid-cols-2 xl:grid-cols-3">
                        <div>
                            <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Starts</dt>
                            <dd class="mt-1 text-sm font-medium text-slate-950">{{ $event->starts_at?->timezone($event->timezone)->format('M j, Y g:i A') }}</dd>
                            <dd class="mt-1 text-xs text-slate-500">{{ $event->timezone }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Ends</dt>
                            <dd class="mt-1 text-sm font-medium text-slate-950">{{ $event->ends_at?->timezone($event->timezone)->format('M j, Y g:i A') ?? 'Not set' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Announcement begins</dt>
                            <dd class="mt-1 text-sm font-medium text-slate-950">{{ $event->announcement_at?->timezone($event->timezone)->format('M j, Y g:i A') ?? 'Unknown' }}</dd>
                        </div>
                    </dl>
                </x-ui.card>

                <x-ui.card class="space-y-4">
                    <div>
                        <h2 class="text-lg font-semibold text-slate-950">Location</h2>
                        <p class="mt-1 text-sm text-slate-600">Historical location snapshot for this occurrence.</p>
                    </div>
                    <dl class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Venue</dt>
                            <dd class="mt-1 text-sm text-slate-950">{{ $event->venue_name ?: 'Not set' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">City / region</dt>
                            <dd class="mt-1 text-sm text-slate-950">{{ collect([$event->city, $event->region])->filter()->implode(', ') ?: 'Not set' }}</dd>
                        </div>
                        <div class="sm:col-span-2">
                            <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Address</dt>
                            <dd class="mt-1 text-sm text-slate-950">
                                {{ collect([$event->address_line_1, $event->address_line_2, $event->postal_code, $event->country])->filter()->implode(', ') ?: 'Not set' }}
                            </dd>
                        </div>
                    </dl>
                </x-ui.card>

                <x-ui.card class="space-y-4">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <h2 class="text-lg font-semibold text-slate-950">Linked Event records</h2>
                            <p class="mt-1 text-sm text-slate-600">Passive references, external stakeholders, and Contact attendance stay owned by Events.</p>
                        </div>
                    </div>
                    <div class="grid gap-3 sm:grid-cols-3">
                        <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                            <div class="text-2xl font-semibold text-slate-950">{{ $event->externalReferences->count() }}</div>
                            <div class="mt-1 text-xs font-semibold uppercase tracking-wide text-slate-500">External references</div>
                        </div>
                        <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                            <div class="text-2xl font-semibold text-slate-950">{{ $event->stakeholders->count() }}</div>
                            <div class="mt-1 text-xs font-semibold uppercase tracking-wide text-slate-500">Stakeholders</div>
                        </div>
                        <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                            <div class="text-2xl font-semibold text-slate-950">{{ $event->attendances->count() }}</div>
                            <div class="mt-1 text-xs font-semibold uppercase tracking-wide text-slate-500">Attendance records</div>
                        </div>
                    </div>
                </x-ui.card>
            </div>

            <aside class="space-y-6">
                <x-ui.card class="space-y-4">
                    <div>
                        <div class="inline-flex rounded-full px-2 py-1 text-xs font-semibold {{ $coreReadiness->ready() ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800' }}">
                            {{ $coreReadiness->ready() ? 'Ready' : 'Needs details' }}
                        </div>
                        <h2 class="mt-3 text-lg font-semibold text-slate-950">Core readiness</h2>
                    </div>

                    @if ($coreReadiness->ready())
                        <p class="text-sm leading-6 text-slate-600">The universal Event requirements are complete.</p>
                    @else
                        <ul class="space-y-2 text-sm text-slate-700">
                            @foreach ($coreReadiness->findings as $finding)
                                <li class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2">{{ $finding->message }}</li>
                            @endforeach
                        </ul>
                    @endif
                </x-ui.card>

                <x-ui.card class="space-y-4">
                    <div>
                        <div class="inline-flex rounded-full px-2 py-1 text-xs font-semibold {{ $announcementDecision->allowed() ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-700' }}">
                            {{ $announcementDecision->allowed() ? 'Open' : 'Blocked' }}
                        </div>
                        <h2 class="mt-3 text-lg font-semibold text-slate-950">Announcement timing</h2>
                    </div>
                    <p class="text-sm leading-6 text-slate-600">
                        @if ($event->announcement_at === null)
                            Announcement timing is unknown, so downstream promotion remains blocked.
                        @elseif ($announcementDecision->allowed())
                            The announcement date has been reached.
                        @else
                            Downstream promotion remains embargoed until {{ $event->announcement_at->timezone($event->timezone)->format('M j, Y g:i A') }}.
                        @endif
                    </p>
                </x-ui.card>

                <x-ui.card class="space-y-4">
                    <div>
                        <div class="inline-flex rounded-full px-2 py-1 text-xs font-semibold {{ $promotionDecision->allowed() ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-700' }}">
                            {{ $promotionDecision->allowed() ? 'Allowed' : 'Not yet' }}
                        </div>
                        <h2 class="mt-3 text-lg font-semibold text-slate-950">Downstream promotion</h2>
                    </div>
                    <p class="text-sm leading-6 text-slate-600">
                        Promotion is allowed only after the Event is upcoming, readiness passes, and announcement timing is open.
                    </p>
                </x-ui.card>

                @if ($duplicates->isNotEmpty())
                    <x-ui.card class="space-y-3 border-amber-200 bg-amber-50">
                        <h2 class="text-base font-semibold text-amber-950">Similar Event records</h2>
                        <div class="space-y-2">
                            @foreach ($duplicates as $duplicate)
                                <a href="{{ route('crm.events.show', $duplicate) }}" class="block rounded-lg border border-amber-200 bg-white px-3 py-2 text-sm font-semibold text-amber-900 hover:border-amber-300">
                                    {{ $duplicate->title }} · {{ $duplicate->starts_at?->timezone($duplicate->timezone)->format('M j, Y g:i A') }}
                                </a>
                            @endforeach
                        </div>
                    </x-ui.card>
                @endif
            </aside>
        </div>
    </div>
</x-layouts.crm>