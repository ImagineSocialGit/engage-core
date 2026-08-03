<x-layouts.crm
    :title="$title"
    :heading="$heading"
    subheading="Manage weekly schedules, absolute exceptions, blackouts, and the rule layers used by the live availability engine."
>
    @php
        $inputClass = 'mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-200';
        $labelClass = 'block text-sm font-medium text-slate-700';
        $weekdays = [
            0 => 'Sunday',
            1 => 'Monday',
            2 => 'Tuesday',
            3 => 'Wednesday',
            4 => 'Thursday',
            5 => 'Friday',
            6 => 'Saturday',
        ];
        $scopeOptions = [
            \App\Modules\Scheduling\Services\SchedulingAvailabilityConfigurationWriter::SCOPE_SERVICE => 'Service',
            \App\Modules\Scheduling\Services\SchedulingAvailabilityConfigurationWriter::SCOPE_HOST => 'Host',
            \App\Modules\Scheduling\Services\SchedulingAvailabilityConfigurationWriter::SCOPE_SERVICE_HOST => 'Service + host',
        ];
        $activeWindows = $windows->reject(fn ($window) => $window->trashed())->values();
        $archivedWindows = $windows->filter(fn ($window) => $window->trashed())->values();
    @endphp

    <div class="space-y-6" data-scheduling-availability-configuration>
        <div class="flex flex-wrap items-center justify-between gap-3">
            <a
                href="{{ route('crm.scheduling.configuration.index') }}"
                class="inline-flex items-center rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50"
                data-scheduling-availability-back
            >
                Back to configuration
            </a>

            <p class="text-sm text-slate-500">
                Weekly times are recurring wall-clock values. Absolute values are stored as UTC after strict local-time validation.
            </p>
        </div>

        @if (session('success'))
            <x-ui.feedback.alert type="success">
                {{ session('success') }}
            </x-ui.feedback.alert>
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

        <datalist id="scheduling-availability-timezones">
            @foreach ($timezones as $timezone)
                <option value="{{ $timezone }}"></option>
            @endforeach
        </datalist>

        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <x-ui.card>
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Active rules</p>
                <p class="mt-2 text-3xl font-semibold text-slate-900" data-availability-active-count="{{ $activeWindows->count() }}">
                    {{ $activeWindows->count() }}
                </p>
            </x-ui.card>

            <x-ui.card>
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Blackouts</p>
                <p class="mt-2 text-3xl font-semibold text-slate-900" data-availability-blackout-count="{{ $activeWindows->where('is_available', false)->count() }}">
                    {{ $activeWindows->where('is_available', false)->count() }}
                </p>
            </x-ui.card>

            <x-ui.card>
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Archived</p>
                <p class="mt-2 text-3xl font-semibold text-slate-900" data-availability-archived-count="{{ $archivedWindows->count() }}">
                    {{ $archivedWindows->count() }}
                </p>
            </x-ui.card>

            <x-ui.card>
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Rule model</p>
                <p class="mt-2 text-sm font-semibold text-slate-900">Union → intersection → blackout subtraction</p>
            </x-ui.card>
        </div>

        <x-ui.card class="space-y-5">
            <div>
                <div class="inline-flex rounded-full px-2 py-1 text-xs font-semibold {{ module_tone('scheduling', 'badge') }}">
                    New rule
                </div>
                <h2 class="mt-3 text-xl font-semibold tracking-tight text-slate-900">
                    Add availability or a blackout
                </h2>
            </div>

            <form
                method="POST"
                action="{{ route('crm.scheduling.configuration.availability.store') }}"
                class="grid gap-4 md:grid-cols-2 xl:grid-cols-4"
                data-availability-create
                x-data="{
                    scope: @js(old('scope', 'service')),
                    shape: @js(old('window_type', 'weekly'))
                }"
            >
                @csrf

                <label class="{{ $labelClass }}">
                    Effect
                    <select class="{{ $inputClass }}" name="is_available" required>
                        <option value="1" @selected((string) old('is_available', '1') === '1')>Available</option>
                        <option value="0" @selected((string) old('is_available') === '0')>Blackout</option>
                    </select>
                </label>

                <label class="{{ $labelClass }}">
                    Scope
                    <select class="{{ $inputClass }}" name="scope" x-model="scope" required>
                        @foreach ($scopeOptions as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="{{ $labelClass }}" x-show="scope === 'service' || scope === 'service_host'">
                    Service
                    <select class="{{ $inputClass }}" name="bookable_service_id" x-bind:disabled="scope === 'host'">
                        <option value="">Select a service</option>
                        @foreach ($services as $service)
                            <option value="{{ $service->id }}" @selected((int) old('bookable_service_id') === (int) $service->id)>
                                {{ $service->name }} · {{ $service->status }}
                            </option>
                        @endforeach
                    </select>
                </label>

                <label class="{{ $labelClass }}" x-show="scope === 'host' || scope === 'service_host'">
                    Host
                    <select class="{{ $inputClass }}" name="scheduling_host_id" x-bind:disabled="scope === 'service'">
                        <option value="">Select a host</option>
                        @foreach ($hosts as $host)
                            <option value="{{ $host->id }}" @selected((int) old('scheduling_host_id') === (int) $host->id)>
                                {{ $host->name }} · {{ $host->status }}
                            </option>
                        @endforeach
                    </select>
                </label>

                <label class="{{ $labelClass }}">
                    Shape
                    <select class="{{ $inputClass }}" name="window_type" x-model="shape" required>
                        <option value="weekly">Weekly recurring</option>
                        <option value="absolute">Absolute date and time</option>
                    </select>
                </label>

                <label class="{{ $labelClass }}">
                    Timezone
                    <input class="{{ $inputClass }}" name="timezone" list="scheduling-availability-timezones" value="{{ old('timezone', $defaultTimezone) }}" required>
                </label>

                <label class="{{ $labelClass }}" x-show="shape === 'weekly'">
                    Weekday
                    <select class="{{ $inputClass }}" name="weekday" x-bind:disabled="shape !== 'weekly'">
                        @foreach ($weekdays as $value => $label)
                            <option value="{{ $value }}" @selected((int) old('weekday', 1) === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="{{ $labelClass }}" x-show="shape === 'weekly'">
                    Start time
                    <input class="{{ $inputClass }}" type="time" name="start_time" x-bind:disabled="shape !== 'weekly'" value="{{ old('start_time', '09:00') }}">
                </label>

                <label class="{{ $labelClass }}" x-show="shape === 'weekly'">
                    End time
                    <input class="{{ $inputClass }}" type="time" name="end_time" x-bind:disabled="shape !== 'weekly'" value="{{ old('end_time', '17:00') }}">
                </label>

                <label class="{{ $labelClass }}" x-show="shape === 'absolute'">
                    Local start
                    <input class="{{ $inputClass }}" type="datetime-local" name="local_starts_at" x-bind:disabled="shape !== 'absolute'" value="{{ old('local_starts_at') }}">
                </label>

                <label class="{{ $labelClass }}" x-show="shape === 'absolute'">
                    Local end
                    <input class="{{ $inputClass }}" type="datetime-local" name="local_ends_at" x-bind:disabled="shape !== 'absolute'" value="{{ old('local_ends_at') }}">
                </label>

                <label class="{{ $labelClass }}">
                    Overall capacity ceiling
                    <input class="{{ $inputClass }}" type="number" min="1" max="100000" name="capacity" value="{{ old('capacity') }}">
                    <span class="mt-1 block text-xs font-normal text-slate-500">Leave blank to inherit the other applicable capacity ceilings.</span>
                </label>

                <div class="md:col-span-2 xl:col-span-4">
                    <button type="submit" class="inline-flex rounded-lg bg-teal-700 px-4 py-2 text-sm font-semibold text-white hover:bg-teal-800">
                        Create rule
                    </button>
                </div>
            </form>
        </x-ui.card>

        <section class="space-y-4" data-availability-rules>
            <div>
                <div class="inline-flex rounded-full px-2 py-1 text-xs font-semibold {{ module_tone('scheduling', 'badge') }}">
                    Active configuration
                </div>
                <h2 class="mt-3 text-xl font-semibold tracking-tight text-slate-900">
                    Availability rules
                </h2>
            </div>

            <div class="grid gap-4 xl:grid-cols-2">
                @forelse ($activeWindows as $window)
                    @php
                        $editable = (bool) $window->getAttribute('crm_editable');
                        $scope = (string) $window->getAttribute('crm_scope');
                        $shape = $window->window_type->value;
                        $localStart = $window->starts_at?->setTimezone($window->timezone)->format('Y-m-d\TH:i');
                        $localEnd = $window->ends_at?->setTimezone($window->timezone)->format('Y-m-d\TH:i');
                    @endphp

                    <div
                        data-availability-window-id="{{ $window->id }}"
                        data-availability-source="{{ $window->source }}"
                        data-availability-archived="0"
                    >
                        <x-ui.card class="space-y-4">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div>
                                    <h3 class="font-semibold text-slate-900">
                                        {{ $window->is_available ? 'Available' : 'Blackout' }} · {{ $scopeOptions[$scope] ?? $scope }}
                                    </h3>
                                    <p class="mt-1 text-sm text-slate-500">
                                        {{ $window->bookableService?->name ?? 'All services for host' }}
                                        @if ($window->schedulingHost)
                                            · {{ $window->schedulingHost->name }}
                                        @endif
                                    </p>
                                </div>
                                <span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-700">
                                    {{ $window->source }}
                                </span>
                            </div>

                            <dl class="grid grid-cols-2 gap-3 text-sm sm:grid-cols-4">
                                <div>
                                    <dt class="text-slate-500">Shape</dt>
                                    <dd class="font-medium text-slate-900">{{ $shape }}</dd>
                                </div>
                                <div>
                                    <dt class="text-slate-500">Timezone</dt>
                                    <dd class="font-medium text-slate-900">{{ $window->timezone }}</dd>
                                </div>
                                <div>
                                    <dt class="text-slate-500">Capacity</dt>
                                    <dd class="font-medium text-slate-900">{{ $window->capacity ?? 'inherit' }}</dd>
                                </div>
                                <div>
                                    <dt class="text-slate-500">Version</dt>
                                    <dd class="font-mono text-xs text-slate-900">{{ $window->updated_at?->toISOString() }}</dd>
                                </div>
                            </dl>

                            @if ($editable)
                                <details>
                                    <summary class="cursor-pointer text-sm font-semibold text-teal-700">Edit rule</summary>

                                    <form
                                        method="POST"
                                        action="{{ route('crm.scheduling.configuration.availability.update', $window) }}"
                                        class="mt-4 grid gap-4 md:grid-cols-2"
                                        data-availability-update="{{ $window->id }}"
                                        x-data="{ scope: @js($scope), shape: @js($shape) }"
                                    >
                                        @csrf
                                        @method('PATCH')
                                        <input type="hidden" name="current_version" value="{{ $window->updated_at?->toISOString() }}">

                                        <label class="{{ $labelClass }}">
                                            Effect
                                            <select class="{{ $inputClass }}" name="is_available" required>
                                                <option value="1" @selected($window->is_available)>Available</option>
                                                <option value="0" @selected(! $window->is_available)>Blackout</option>
                                            </select>
                                        </label>

                                        <label class="{{ $labelClass }}">
                                            Scope
                                            <select class="{{ $inputClass }}" name="scope" x-model="scope" required>
                                                @foreach ($scopeOptions as $value => $label)
                                                    <option value="{{ $value }}">{{ $label }}</option>
                                                @endforeach
                                            </select>
                                        </label>

                                        <label class="{{ $labelClass }}" x-show="scope === 'service' || scope === 'service_host'">
                                            Service
                                            <select class="{{ $inputClass }}" name="bookable_service_id" x-bind:disabled="scope === 'host'">
                                                <option value="">Select a service</option>
                                                @foreach ($services as $service)
                                                    <option value="{{ $service->id }}" @selected((int) $window->bookable_service_id === (int) $service->id)>
                                                        {{ $service->name }} · {{ $service->status }}
                                                    </option>
                                                @endforeach
                                            </select>
                                        </label>

                                        <label class="{{ $labelClass }}" x-show="scope === 'host' || scope === 'service_host'">
                                            Host
                                            <select class="{{ $inputClass }}" name="scheduling_host_id" x-bind:disabled="scope === 'service'">
                                                <option value="">Select a host</option>
                                                @foreach ($hosts as $host)
                                                    <option value="{{ $host->id }}" @selected((int) $window->scheduling_host_id === (int) $host->id)>
                                                        {{ $host->name }} · {{ $host->status }}
                                                    </option>
                                                @endforeach
                                            </select>
                                        </label>

                                        <label class="{{ $labelClass }}">
                                            Shape
                                            <select class="{{ $inputClass }}" name="window_type" x-model="shape" required>
                                                <option value="weekly">Weekly recurring</option>
                                                <option value="absolute">Absolute date and time</option>
                                            </select>
                                        </label>

                                        <label class="{{ $labelClass }}">
                                            Timezone
                                            <input class="{{ $inputClass }}" name="timezone" list="scheduling-availability-timezones" value="{{ $window->timezone }}" required>
                                        </label>

                                        <label class="{{ $labelClass }}" x-show="shape === 'weekly'">
                                            Weekday
                                            <select class="{{ $inputClass }}" name="weekday" x-bind:disabled="shape !== 'weekly'">
                                                @foreach ($weekdays as $value => $label)
                                                    <option value="{{ $value }}" @selected((int) $window->weekday === $value)>{{ $label }}</option>
                                                @endforeach
                                            </select>
                                        </label>

                                        <label class="{{ $labelClass }}" x-show="shape === 'weekly'">
                                            Start time
                                            <input class="{{ $inputClass }}" type="time" name="start_time" x-bind:disabled="shape !== 'weekly'" value="{{ $window->start_time ? substr((string) $window->start_time, 0, 5) : '' }}">
                                        </label>

                                        <label class="{{ $labelClass }}" x-show="shape === 'weekly'">
                                            End time
                                            <input class="{{ $inputClass }}" type="time" name="end_time" x-bind:disabled="shape !== 'weekly'" value="{{ $window->end_time ? substr((string) $window->end_time, 0, 5) : '' }}">
                                        </label>

                                        <label class="{{ $labelClass }}" x-show="shape === 'absolute'">
                                            Local start
                                            <input class="{{ $inputClass }}" type="datetime-local" name="local_starts_at" x-bind:disabled="shape !== 'absolute'" value="{{ $localStart }}">
                                        </label>

                                        <label class="{{ $labelClass }}" x-show="shape === 'absolute'">
                                            Local end
                                            <input class="{{ $inputClass }}" type="datetime-local" name="local_ends_at" x-bind:disabled="shape !== 'absolute'" value="{{ $localEnd }}">
                                        </label>

                                        <label class="{{ $labelClass }}">
                                            Overall capacity ceiling
                                            <input class="{{ $inputClass }}" type="number" min="1" max="100000" name="capacity" value="{{ $window->capacity }}">
                                        </label>

                                        <div class="md:col-span-2">
                                            <button type="submit" class="inline-flex rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">
                                                Save rule
                                            </button>
                                        </div>
                                    </form>
                                </details>

                                <form
                                    method="POST"
                                    action="{{ route('crm.scheduling.configuration.availability.archive', $window) }}"
                                    data-availability-archive="{{ $window->id }}"
                                >
                                    @csrf
                                    @method('DELETE')
                                    <input type="hidden" name="current_version" value="{{ $window->updated_at?->toISOString() }}">
                                    <button type="submit" class="text-sm font-semibold text-rose-700 hover:text-rose-800">
                                        Archive rule
                                    </button>
                                </form>
                            @else
                                <div class="rounded-xl border border-slate-200 bg-slate-50 p-4" data-availability-read-only="{{ $window->id }}">
                                    <p class="text-sm text-slate-600">This provider- or system-owned rule is visible for diagnosis but cannot be changed here.</p>
                                </div>
                            @endif
                        </x-ui.card>
                    </div>
                @empty
                    <x-ui.card>
                        <div data-availability-empty="active" class="rounded-xl border border-dashed border-slate-300 p-6 text-center text-sm text-slate-500">
                            No active availability rules are configured.
                        </div>
                    </x-ui.card>
                @endforelse
            </div>
        </section>

        <x-ui.card class="space-y-4" data-availability-preview>
            <div>
                <div class="inline-flex rounded-full px-2 py-1 text-xs font-semibold {{ module_tone('scheduling', 'badge') }}">
                    Live engine
                </div>
                <h2 class="mt-3 text-xl font-semibold tracking-tight text-slate-900">Resolved availability preview</h2>
            </div>

            <form method="GET" action="{{ route('crm.scheduling.configuration.availability.index') }}" class="grid gap-4 md:grid-cols-3">
                <label class="{{ $labelClass }}">
                    Service
                    <select class="{{ $inputClass }}" name="preview_service_id" required>
                        <option value="">Select a service</option>
                        @foreach ($services->where('status', \App\Modules\Scheduling\Models\BookableService::STATUS_ACTIVE) as $service)
                            <option value="{{ $service->id }}" @selected((int) $previewService?->id === (int) $service->id)>
                                {{ $service->name }}
                            </option>
                        @endforeach
                    </select>
                </label>

                <label class="{{ $labelClass }}">
                    Host
                    <select class="{{ $inputClass }}" name="preview_host_id">
                        <option value="">No explicit host</option>
                        @foreach ($previewHosts as $host)
                            <option value="{{ $host->id }}" @selected((int) $previewHost?->id === (int) $host->id)>
                                {{ $host->name }}
                            </option>
                        @endforeach
                    </select>
                </label>

                <label class="{{ $labelClass }}">
                    Date
                    <input class="{{ $inputClass }}" type="date" name="preview_date" value="{{ $previewDate }}" required>
                </label>

                <div class="md:col-span-3">
                    <button type="submit" class="inline-flex rounded-lg bg-teal-700 px-4 py-2 text-sm font-semibold text-white hover:bg-teal-800">Resolve slots</button>
                </div>
            </form>

            @if ($previewService)
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200 text-sm">
                        <thead>
                            <tr class="text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                                <th class="px-3 py-2">Local time</th>
                                <th class="px-3 py-2">UTC</th>
                                <th class="px-3 py-2">Capacity</th>
                                <th class="px-3 py-2">Provenance</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @forelse ($previewSlots as $slot)
                                <tr
                                    data-preview-slot-start="{{ $slot->startsAt->toISOString() }}"
                                    data-preview-slot-capacity="{{ $slot->capacity }}"
                                    data-preview-slot-remaining="{{ $slot->remainingCapacity }}"
                                    data-preview-slot-source-window-ids="{{ implode(',', $slot->sourceWindowIds) }}"
                                >
                                    <td class="px-3 py-3 font-medium text-slate-900">
                                        {{ $slot->localStartsAt()->format('M j, Y g:i A') }}–{{ $slot->localEndsAt()->format('g:i A T') }}
                                    </td>
                                    <td class="px-3 py-3 font-mono text-xs text-slate-600">{{ $slot->startsAt->toISOString() }}</td>
                                    <td class="px-3 py-3 text-slate-700">{{ $slot->remainingCapacity }} / {{ $slot->capacity }}</td>
                                    <td class="px-3 py-3 text-xs text-slate-600">
                                        scopes {{ implode(', ', $slot->sourceScopes) }} · rules {{ implode(', ', $slot->sourceWindowIds) }}
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="px-3 py-6 text-center text-slate-500" data-availability-empty="preview">No slots resolved for this selection.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            @endif
        </x-ui.card>

        <section class="space-y-4" data-availability-archived-rules>
            <div>
                <div class="inline-flex rounded-full px-2 py-1 text-xs font-semibold {{ module_tone('scheduling', 'badge') }}">
                    History
                </div>
                <h2 class="mt-3 text-xl font-semibold tracking-tight text-slate-900">Archived rules</h2>
            </div>

            <div class="grid gap-4 xl:grid-cols-2">
                @forelse ($archivedWindows as $window)
                    @php
                        $editable = (bool) $window->getAttribute('crm_editable');
                        $scope = (string) $window->getAttribute('crm_scope');
                    @endphp

                    <div
                        data-availability-window-id="{{ $window->id }}"
                        data-availability-archived="1"
                    >
                        <x-ui.card class="space-y-4">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div>
                                    <h3 class="font-semibold text-slate-900">
                                        {{ $window->is_available ? 'Available' : 'Blackout' }} · {{ $scopeOptions[$scope] ?? $scope }}
                                    </h3>
                                    <p class="mt-1 text-sm text-slate-500">
                                        {{ $window->bookableService?->name ?? 'All services for host' }}
                                        @if ($window->schedulingHost)
                                            · {{ $window->schedulingHost->name }}
                                        @endif
                                    </p>
                                </div>
                                <span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-700">archived</span>
                            </div>

                            @if ($editable)
                                <form
                                    method="POST"
                                    action="{{ route('crm.scheduling.configuration.availability.restore', $window) }}"
                                    data-availability-restore="{{ $window->id }}"
                                >
                                    @csrf
                                    <input type="hidden" name="current_version" value="{{ $window->updated_at?->toISOString() }}">
                                    <button type="submit" class="inline-flex rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">Restore rule</button>
                                </form>
                            @else
                                <div class="rounded-xl border border-slate-200 bg-slate-50 p-4" data-availability-read-only="{{ $window->id }}">
                                    <p class="text-sm text-slate-600">This archived provider- or system-owned rule remains read-only.</p>
                                </div>
                            @endif
                        </x-ui.card>
                    </div>
                @empty
                    <x-ui.card>
                        <div data-availability-empty="archived" class="rounded-xl border border-dashed border-slate-300 p-6 text-center text-sm text-slate-500">No rules are archived.</div>
                    </x-ui.card>
                @endforelse
            </div>
        </section>
    </div>
</x-layouts.crm>