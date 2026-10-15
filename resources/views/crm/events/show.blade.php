<x-layouts.crm
    :title="$event->title"
    :heading="$event->title"
    subheading="Review the occurrence, lifecycle, references, stakeholders, attendance, and promotion readiness."
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

                @if ($lifecycleActions['postpone'] || $lifecycleActions['reschedule'] || $lifecycleActions['cancel'] || $lifecycleActions['complete'])
                    <x-ui.card class="space-y-4">
                        <div>
                            <h2 class="text-lg font-semibold text-slate-950">Lifecycle</h2>
                            <p class="mt-1 text-sm text-slate-600">Use the Event lifecycle actions instead of editing status directly.</p>
                        </div>

                        @if ($lifecycleActions['reschedule'])
                            <form method="POST" action="{{ route('crm.events.reschedule', $event) }}" class="grid gap-4 rounded-xl border border-blue-200 bg-blue-50/40 p-4 sm:grid-cols-2">
                                @csrf
                                <div>
                                    <x-ui.form.label for="reschedule-starts-at">New start</x-ui.form.label>
                                    <x-ui.form.input id="reschedule-starts-at" name="starts_at_local" type="datetime-local" :value="old('starts_at_local', $rescheduleValues['starts_at_local'])" required />
                                    <x-ui.form.error name="starts_at_local" />
                                </div>
                                <div>
                                    <x-ui.form.label for="reschedule-ends-at">New end</x-ui.form.label>
                                    <x-ui.form.input id="reschedule-ends-at" name="ends_at_local" type="datetime-local" :value="old('ends_at_local', $rescheduleValues['ends_at_local'])" />
                                    <x-ui.form.error name="ends_at_local" />
                                </div>
                                <div class="sm:col-span-2">
                                    <x-ui.form.label for="reschedule-timezone">Timezone</x-ui.form.label>
                                    <select id="reschedule-timezone" name="timezone" class="mt-1 block w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-100">
                                        @foreach ($timezoneOptions as $timezone)
                                            <option value="{{ $timezone }}" @selected(old('timezone', $rescheduleValues['timezone']) === $timezone)>{{ $timezone }}</option>
                                        @endforeach
                                    </select>
                                    <x-ui.form.error name="timezone" />
                                </div>
                                <div class="sm:col-span-2 flex justify-end">
                                    <x-ui.button type="submit">Confirm new schedule</x-ui.button>
                                </div>
                            </form>
                        @endif

                        @if ($lifecycleActions['postpone'] || $lifecycleActions['cancel'] || $lifecycleActions['complete'])
                            <div class="flex flex-wrap gap-2">
                                @if ($lifecycleActions['postpone'])
                                    <form method="POST" action="{{ route('crm.events.postpone', $event) }}">
                                        @csrf
                                        <x-ui.button type="submit" variant="secondary">Postpone</x-ui.button>
                                    </form>
                                @endif

                                @if ($lifecycleActions['complete'])
                                    <form method="POST" action="{{ route('crm.events.complete', $event) }}" onsubmit="return confirm('Mark this Event complete?')">
                                        @csrf
                                        <x-ui.button type="submit">Mark complete</x-ui.button>
                                    </form>
                                @endif

                                @if ($lifecycleActions['cancel'])
                                    <form method="POST" action="{{ route('crm.events.cancel', $event) }}" onsubmit="return confirm('Cancel this Event?')">
                                        @csrf
                                        <button type="submit" class="inline-flex items-center justify-center rounded-lg border border-red-300 bg-white px-4 py-2 text-sm font-semibold text-red-700 shadow-sm hover:bg-red-50">
                                            Cancel Event
                                        </button>
                                    </form>
                                @endif
                            </div>
                        @endif
                    </x-ui.card>
                @endif

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

                <x-ui.card class="space-y-5">
                    <div>
                        <h2 class="text-lg font-semibold text-slate-950">External references</h2>
                        <p class="mt-1 text-sm text-slate-600">Maintain passive provider links and choose the primary reference for this occurrence.</p>
                    </div>

                    @forelse ($event->externalReferences as $reference)
                        <div class="rounded-xl border border-slate-200 p-4">
                            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                <div>
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="text-sm font-semibold text-slate-950">{{ $reference->label ?: $reference->external_id ?: $reference->url }}</span>
                                        @if ((int) $event->primary_external_reference_id === (int) $reference->getKey())
                                            <span class="rounded-full bg-blue-100 px-2 py-0.5 text-xs font-semibold text-blue-800">Primary</span>
                                        @endif
                                    </div>
                                    <p class="mt-1 text-xs text-slate-500">
                                        {{ $externalReferenceProviderLabels[$reference->provider_key] ?? $reference->provider_key }} · {{ $externalReferenceTypeLabels[$reference->reference_type] ?? $reference->reference_type }}
                                    </p>
                                    @if ($reference->url)
                                        <a href="{{ $reference->url }}" target="_blank" rel="noopener noreferrer" class="mt-2 inline-flex break-all text-sm font-semibold text-blue-700 underline">{{ $reference->url }}</a>
                                    @endif
                                </div>
                                <div class="flex flex-wrap gap-2">
                                    @if ((int) $event->primary_external_reference_id !== (int) $reference->getKey())
                                        <form method="POST" action="{{ route('crm.events.external-references.primary', $event) }}">
                                            @csrf
                                            @method('PATCH')
                                            <input type="hidden" name="external_reference_id" value="{{ $reference->getKey() }}">
                                            <button type="submit" class="text-xs font-semibold text-blue-700 underline">Make primary</button>
                                        </form>
                                    @endif
                                    <form method="POST" action="{{ route('crm.events.external-references.destroy', [$event, $reference]) }}" onsubmit="return confirm('Remove this external reference?')">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="text-xs font-semibold text-red-700 underline">Remove</button>
                                    </form>
                                </div>
                            </div>

                            <details class="mt-4">
                                <summary class="cursor-pointer text-xs font-semibold text-slate-600">Edit reference</summary>
                                <form method="POST" action="{{ route('crm.events.external-references.update', [$event, $reference]) }}" class="mt-3 grid gap-3 sm:grid-cols-2">
                                    @csrf
                                    @method('PATCH')
                                    <div>
                                        <x-ui.form.label>Provider</x-ui.form.label>
                                        <select name="provider_key" class="mt-1 block w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm">
                                            @foreach ($externalReferenceProviders as $option)
                                                <option value="{{ $option['key'] }}" @selected($reference->provider_key === $option['key'])>{{ $option['label'] }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div>
                                        <x-ui.form.label>Type</x-ui.form.label>
                                        <select name="reference_type" class="mt-1 block w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm">
                                            @foreach ($externalReferenceTypes as $option)
                                                <option value="{{ $option['key'] }}" @selected($reference->reference_type === $option['key'])>{{ $option['label'] }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div>
                                        <x-ui.form.label>External ID</x-ui.form.label>
                                        <x-ui.form.input name="external_id" :value="$reference->external_id" />
                                    </div>
                                    <div>
                                        <x-ui.form.label>Label</x-ui.form.label>
                                        <x-ui.form.input name="label" :value="$reference->label" />
                                    </div>
                                    <div class="sm:col-span-2">
                                        <x-ui.form.label>URL</x-ui.form.label>
                                        <x-ui.form.input name="url" type="url" :value="$reference->url" />
                                    </div>
                                    <div class="sm:col-span-2 flex justify-end">
                                        <x-ui.button type="submit" variant="secondary">Save reference</x-ui.button>
                                    </div>
                                </form>
                            </details>
                        </div>
                    @empty
                        <p class="text-sm text-slate-500">No external references yet.</p>
                    @endforelse

                    @if ($event->primary_external_reference_id)
                        <form method="POST" action="{{ route('crm.events.external-references.primary', $event) }}">
                            @csrf
                            @method('PATCH')
                            <input type="hidden" name="external_reference_id" value="">
                            <button type="submit" class="text-xs font-semibold text-slate-600 underline">Clear primary reference</button>
                        </form>
                    @endif

                    <details class="rounded-xl border border-dashed border-slate-300 p-4" @if ($errors->has('provider_key') || $errors->has('reference_type') || $errors->has('external_id') || $errors->has('url')) open @endif>
                        <summary class="cursor-pointer text-sm font-semibold text-slate-800">Add external reference</summary>
                        <form method="POST" action="{{ route('crm.events.external-references.store', $event) }}" class="mt-4 grid gap-3 sm:grid-cols-2">
                            @csrf
                            <div>
                                <x-ui.form.label for="new-reference-provider">Provider</x-ui.form.label>
                                <select id="new-reference-provider" name="provider_key" class="mt-1 block w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm">
                                    @foreach ($externalReferenceProviders as $option)
                                        <option value="{{ $option['key'] }}" @selected(old('provider_key') === $option['key'])>{{ $option['label'] }}</option>
                                    @endforeach
                                </select>
                                <x-ui.form.error name="provider_key" />
                            </div>
                            <div>
                                <x-ui.form.label for="new-reference-type">Type</x-ui.form.label>
                                <select id="new-reference-type" name="reference_type" class="mt-1 block w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm">
                                    @foreach ($externalReferenceTypes as $option)
                                        <option value="{{ $option['key'] }}" @selected(old('reference_type') === $option['key'])>{{ $option['label'] }}</option>
                                    @endforeach
                                </select>
                                <x-ui.form.error name="reference_type" />
                            </div>
                            <div>
                                <x-ui.form.label for="new-reference-external-id">External ID</x-ui.form.label>
                                <x-ui.form.input id="new-reference-external-id" name="external_id" :value="old('external_id')" />
                                <x-ui.form.error name="external_id" />
                            </div>
                            <div>
                                <x-ui.form.label for="new-reference-label">Label</x-ui.form.label>
                                <x-ui.form.input id="new-reference-label" name="label" :value="old('label')" />
                                <x-ui.form.error name="label" />
                            </div>
                            <div class="sm:col-span-2">
                                <x-ui.form.label for="new-reference-url">URL</x-ui.form.label>
                                <x-ui.form.input id="new-reference-url" name="url" type="url" :value="old('url')" />
                                <x-ui.form.error name="url" />
                            </div>
                            <div class="sm:col-span-2 flex justify-end">
                                <x-ui.button type="submit">Add reference</x-ui.button>
                            </div>
                        </form>
                    </details>
                </x-ui.card>

                <x-ui.card class="space-y-5">
                    <div>
                        <h2 class="text-lg font-semibold text-slate-950">Stakeholders</h2>
                        <p class="mt-1 text-sm text-slate-600">Occurrence-specific external people and organizations stay as Event snapshots.</p>
                    </div>

                    @forelse ($event->stakeholders as $stakeholder)
                        <div class="rounded-xl border border-slate-200 p-4">
                            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                <div>
                                    <p class="text-sm font-semibold text-slate-950">{{ $stakeholder->name }}</p>
                                    <p class="mt-1 text-xs text-slate-500">{{ $stakeholderRoleLabels[$stakeholder->role_key] ?? $stakeholder->role_key }}{{ $stakeholder->organization ? ' · '.$stakeholder->organization : '' }}</p>
                                    @if ($stakeholder->email || $stakeholder->phone)
                                        <p class="mt-2 text-sm text-slate-600">{{ $stakeholder->email }}{{ $stakeholder->email && $stakeholder->phone ? ' · ' : '' }}{{ $stakeholder->phone }}</p>
                                    @endif
                                    @if ($stakeholder->notes)
                                        <p class="mt-2 whitespace-pre-line text-sm text-slate-600">{{ $stakeholder->notes }}</p>
                                    @endif
                                </div>
                                <form method="POST" action="{{ route('crm.events.stakeholders.destroy', [$event, $stakeholder]) }}" onsubmit="return confirm('Remove this stakeholder snapshot?')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="text-xs font-semibold text-red-700 underline">Remove</button>
                                </form>
                            </div>

                            <details class="mt-4">
                                <summary class="cursor-pointer text-xs font-semibold text-slate-600">Edit stakeholder</summary>
                                <form method="POST" action="{{ route('crm.events.stakeholders.update', [$event, $stakeholder]) }}" class="mt-3 grid gap-3 sm:grid-cols-2">
                                    @csrf
                                    @method('PATCH')
                                    <div>
                                        <x-ui.form.label>Role</x-ui.form.label>
                                        <select name="role_key" class="mt-1 block w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm">
                                            @foreach ($stakeholderRoles as $option)
                                                <option value="{{ $option['key'] }}" @selected($stakeholder->role_key === $option['key'])>{{ $option['label'] }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div>
                                        <x-ui.form.label>Name</x-ui.form.label>
                                        <x-ui.form.input name="name" :value="$stakeholder->name" required />
                                    </div>
                                    <div>
                                        <x-ui.form.label>Organization</x-ui.form.label>
                                        <x-ui.form.input name="organization" :value="$stakeholder->organization" />
                                    </div>
                                    <div>
                                        <x-ui.form.label>Email</x-ui.form.label>
                                        <x-ui.form.input name="email" type="email" :value="$stakeholder->email" />
                                    </div>
                                    <div>
                                        <x-ui.form.label>Phone</x-ui.form.label>
                                        <x-ui.form.input name="phone" :value="$stakeholder->phone" />
                                    </div>
                                    <div class="sm:col-span-2">
                                        <x-ui.form.label>Notes</x-ui.form.label>
                                        <x-ui.form.textarea name="notes">{{ $stakeholder->notes }}</x-ui.form.textarea>
                                    </div>
                                    <div class="sm:col-span-2 flex justify-end">
                                        <x-ui.button type="submit" variant="secondary">Save stakeholder</x-ui.button>
                                    </div>
                                </form>
                            </details>
                        </div>
                    @empty
                        <p class="text-sm text-slate-500">No stakeholder snapshots yet.</p>
                    @endforelse

                    <details class="rounded-xl border border-dashed border-slate-300 p-4" @if ($errors->has('role_key') || $errors->has('name')) open @endif>
                        <summary class="cursor-pointer text-sm font-semibold text-slate-800">Add stakeholder</summary>
                        <form method="POST" action="{{ route('crm.events.stakeholders.store', $event) }}" class="mt-4 grid gap-3 sm:grid-cols-2">
                            @csrf
                            <div>
                                <x-ui.form.label for="new-stakeholder-role">Role</x-ui.form.label>
                                <select id="new-stakeholder-role" name="role_key" class="mt-1 block w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm">
                                    @foreach ($stakeholderRoles as $option)
                                        <option value="{{ $option['key'] }}" @selected(old('role_key') === $option['key'])>{{ $option['label'] }}</option>
                                    @endforeach
                                </select>
                                <x-ui.form.error name="role_key" />
                            </div>
                            <div>
                                <x-ui.form.label for="new-stakeholder-name">Name</x-ui.form.label>
                                <x-ui.form.input id="new-stakeholder-name" name="name" :value="old('name')" required />
                                <x-ui.form.error name="name" />
                            </div>
                            <div>
                                <x-ui.form.label for="new-stakeholder-organization">Organization</x-ui.form.label>
                                <x-ui.form.input id="new-stakeholder-organization" name="organization" :value="old('organization')" />
                            </div>
                            <div>
                                <x-ui.form.label for="new-stakeholder-email">Email</x-ui.form.label>
                                <x-ui.form.input id="new-stakeholder-email" name="email" type="email" :value="old('email')" />
                            </div>
                            <div>
                                <x-ui.form.label for="new-stakeholder-phone">Phone</x-ui.form.label>
                                <x-ui.form.input id="new-stakeholder-phone" name="phone" :value="old('phone')" />
                            </div>
                            <div class="sm:col-span-2">
                                <x-ui.form.label for="new-stakeholder-notes">Notes</x-ui.form.label>
                                <x-ui.form.textarea id="new-stakeholder-notes" name="notes">{{ old('notes') }}</x-ui.form.textarea>
                            </div>
                            <div class="sm:col-span-2 flex justify-end">
                                <x-ui.button type="submit">Add stakeholder</x-ui.button>
                            </div>
                        </form>
                    </details>
                </x-ui.card>

                <x-ui.card class="space-y-5">
                    <div>
                        <h2 class="text-lg font-semibold text-slate-950">Attendance</h2>
                        <p class="mt-1 text-sm text-slate-600">Record or correct attendance for Contacts you are allowed to view.</p>
                    </div>

                    <div class="space-y-3">
                        @forelse ($attendances as $attendance)
                            <form method="POST" action="{{ route('crm.events.attendance.store', $event) }}" class="grid gap-3 rounded-xl border border-slate-200 p-4 sm:grid-cols-[minmax(0,1fr)_11rem_12rem_auto] sm:items-end">
                                @csrf
                                <input type="hidden" name="contact_id" value="{{ $attendance->contact_id }}">
                                <div class="min-w-0">
                                    <p class="truncate text-sm font-semibold text-slate-950">{{ $attendance->contact?->name ?: trim(($attendance->contact?->first_name ?? '').' '.($attendance->contact?->last_name ?? '')) ?: $attendance->contact?->email ?: 'Contact #'.$attendance->contact_id }}</p>
                                    <p class="truncate text-xs text-slate-500">{{ $attendance->contact?->email }}{{ $attendance->contact?->email && $attendance->contact?->phone ? ' · ' : '' }}{{ $attendance->contact?->phone }}</p>
                                </div>
                                <div>
                                    <x-ui.form.label>Status</x-ui.form.label>
                                    <select name="status" class="mt-1 block w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm">
                                        @foreach ($attendanceStatusOptions as $value => $label)
                                            <option value="{{ $value }}" @selected($attendance->status->value === $value)>{{ $label }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div>
                                    <x-ui.form.label>Observed</x-ui.form.label>
                                    <x-ui.form.input name="observed_at_local" type="datetime-local" :value="$attendance->observed_at?->timezone($event->timezone)->format('Y-m-d\\TH:i')" />
                                </div>
                                <x-ui.button type="submit" variant="secondary">Update</x-ui.button>
                            </form>
                        @empty
                            <p class="text-sm text-slate-500">No visible Contact attendance has been recorded.</p>
                        @endforelse
                    </div>

                    <details class="rounded-xl border border-dashed border-slate-300 p-4" @if ($errors->has('contact_id') || $errors->has('status') || $errors->has('observed_at_local')) open @endif>
                        <summary class="cursor-pointer text-sm font-semibold text-slate-800">Record attendance</summary>
                        <form method="POST" action="{{ route('crm.events.attendance.store', $event) }}" class="mt-4 grid gap-4 sm:grid-cols-2">
                            @csrf
                            <div class="sm:col-span-2">
                                @include('crm.events.partials.attendance-contact-picker')
                            </div>
                            <div>
                                <x-ui.form.label for="new-attendance-status">Status</x-ui.form.label>
                                <select id="new-attendance-status" name="status" class="mt-1 block w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm">
                                    @foreach ($attendanceStatusOptions as $value => $label)
                                        <option value="{{ $value }}" @selected(old('status') === $value)>{{ $label }}</option>
                                    @endforeach
                                </select>
                                <x-ui.form.error name="status" />
                            </div>
                            <div>
                                <x-ui.form.label for="new-attendance-observed">Observed</x-ui.form.label>
                                <x-ui.form.input id="new-attendance-observed" name="observed_at_local" type="datetime-local" :value="old('observed_at_local', $attendanceObservedAtLocal)" />
                                <x-ui.form.error name="observed_at_local" />
                                <p class="mt-1 text-xs text-slate-500">{{ $event->timezone }}</p>
                            </div>
                            <div class="sm:col-span-2 flex justify-end">
                                <x-ui.button type="submit">Record attendance</x-ui.button>
                            </div>
                        </form>
                    </details>
                </x-ui.card>

                <x-ui.card class="space-y-4">
                    <div>
                        <h2 class="text-lg font-semibold text-slate-950">Linked Event records</h2>
                        <p class="mt-1 text-sm text-slate-600">Passive references, external stakeholders, and visible Contact attendance stay owned by Events.</p>
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
                            <div class="text-2xl font-semibold text-slate-950">{{ $attendances->count() }}</div>
                            <div class="mt-1 text-xs font-semibold uppercase tracking-wide text-slate-500">Visible attendance</div>
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