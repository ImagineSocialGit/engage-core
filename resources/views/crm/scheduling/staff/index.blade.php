<x-layouts.crm
    :title="$title"
    :heading="$heading"
    subheading="Choose which CRM users can be assigned to appointments."
>
    <div class="space-y-6" data-scheduling-staff-workspace>
        <datalist id="scheduling-timezones">
            @foreach ($timezones as $timezone)
                <option value="{{ $timezone }}"></option>
            @endforeach
        </datalist>

        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <a
                href="{{ route('crm.scheduling.configuration.index') }}"
                class="inline-flex w-full items-center justify-center rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50 sm:w-auto"
                data-scheduling-staff-back
            >
                Back to Scheduling Setup
            </a>

            <div class="flex flex-col gap-2 sm:flex-row">
                <a
                    href="{{ route('crm.settings.team.index') }}"
                    class="inline-flex w-full items-center justify-center rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50 sm:w-auto"
                >
                    Team & Access
                </a>
                <a
                    href="{{ route('crm.scheduling.configuration.services.index') }}"
                    class="inline-flex w-full items-center justify-center rounded-lg border border-teal-600 bg-white px-3 py-2 text-sm font-semibold text-teal-700 shadow-sm hover:bg-teal-50 sm:w-auto"
                >
                    Manage appointment types
                </a>
            </div>
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

        <x-ui.card class="space-y-5" data-configuration-host-create>
            <div>
                <div class="inline-flex rounded-full px-2 py-1 text-xs font-semibold {{ module_tone('scheduling', 'badge') }}">
                    Appointment assignment
                </div>
                <h2 class="mt-3 text-lg font-semibold text-slate-900">Add scheduling staff</h2>
                <p class="mt-1 max-w-2xl text-sm text-slate-500">
                    Scheduling staff come from Team & Access. Add a CRM user here only when appointments should be assigned to that person.
                </p>
            </div>

            @if ($availableHostUsers->isNotEmpty())
                <form
                    method="POST"
                    action="{{ route('crm.scheduling.configuration.hosts.store') }}"
                    class="flex flex-col gap-4 sm:flex-row sm:items-end"
                >
                    @csrf

                    <label class="block min-w-0 flex-1 text-sm font-medium text-slate-700">
                        CRM user
                        <select
                            class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-200"
                            name="user_id"
                            required
                        >
                            <option value="">Choose a person</option>
                            @foreach ($availableHostUsers as $user)
                                <option value="{{ $user->id }}" @selected((string) old('user_id') === (string) $user->id)>
                                    {{ $user->name }}{{ $user->email ? ' — '.$user->email : '' }}
                                </option>
                            @endforeach
                        </select>
                    </label>

                    <button
                        type="submit"
                        class="inline-flex w-full justify-center rounded-lg bg-teal-700 px-4 py-2 text-sm font-semibold text-white hover:bg-teal-800 sm:w-auto"
                    >
                        Add to Scheduling
                    </button>
                </form>
            @else
                <div class="rounded-xl border border-slate-200 bg-slate-50 p-4 text-sm text-slate-600">
                    Every active CRM user is already linked to Scheduling, or there are no active users available. Add or reactivate people in
                    <a href="{{ route('crm.settings.team.index') }}" class="font-semibold text-teal-700 hover:text-teal-800">Team & Access</a>.
                </div>
            @endif
        </x-ui.card>

        <section class="space-y-4" data-scheduling-staff-list>
            <div>
                <div class="inline-flex rounded-full px-2 py-1 text-xs font-semibold {{ module_tone('scheduling', 'badge') }}">
                    Staff & providers
                </div>
                <h2 class="mt-3 text-xl font-semibold tracking-tight text-slate-900">People who can handle appointments</h2>
            </div>

            <div class="grid gap-4 xl:grid-cols-2">
                @forelse ($hosts as $host)
                    <x-ui.card class="space-y-4">
                        <div
                            data-scheduling-host-id="{{ $host->id }}"
                            data-crm-editable="{{ $host->getAttribute('crm_editable') ? '1' : '0' }}"
                        >
                            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                <div>
                                    <h3 class="font-semibold text-slate-900">{{ $host->name }}</h3>
                                    @if ($host->email)
                                        <p class="mt-1 text-sm text-slate-500">{{ $host->email }}</p>
                                    @endif
                                    @if ($host->getAttribute('identity_user_id'))
                                        <p class="mt-1 text-xs font-medium text-teal-700">Linked to Team & Access</p>
                                    @endif
                                </div>
                                <span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-700">
                                    {{ str($host->status)->replace('_', ' ')->title() }}
                                </span>
                            </div>

                            @if ($host->getAttribute('identity_reconnect_required'))
                                <div class="mt-4 rounded-xl border border-amber-200 bg-amber-50 p-4" data-scheduling-host-reconnect-required>
                                    <p class="text-sm font-semibold text-amber-900">Reconnect this staff record</p>
                                    <p class="mt-1 text-sm text-amber-800">
                                        Its environment-specific CRM user link is missing. Choose the matching active user before relying on host-assigned tasks or reminders.
                                    </p>
                                </div>
                            @elseif ($host->getAttribute('identity_user_id') && ! $host->getAttribute('identity_user_active'))
                                <div class="mt-4 rounded-xl border border-amber-200 bg-amber-50 p-4">
                                    <p class="text-sm text-amber-900">
                                        This CRM user is inactive in Team & Access. Reactivate the user before making this Scheduling staff record active.
                                    </p>
                                </div>
                            @endif

                            <dl class="mt-4 grid grid-cols-3 gap-3 text-sm">
                                <div>
                                    <dt class="text-slate-500">Appointment types</dt>
                                    <dd class="font-medium text-slate-900" data-active-assignment-count="{{ $host->active_service_assignments_count }}">
                                        {{ $host->active_service_assignments_count }}
                                    </dd>
                                </div>
                                <div>
                                    <dt class="text-slate-500">Appointments</dt>
                                    <dd class="font-medium text-slate-900" data-appointment-count="{{ $host->appointments_count }}">
                                        {{ $host->appointments_count }}
                                    </dd>
                                </div>
                                <div>
                                    <dt class="text-slate-500">Availability</dt>
                                    <dd class="font-medium text-slate-900">{{ $host->availability_windows_count }}</dd>
                                </div>
                            </dl>

                            @if ($host->getAttribute('crm_editable'))
                                <details class="mt-4" @if ($host->getAttribute('identity_reconnect_required')) open @endif>
                                    <summary class="cursor-pointer text-sm font-semibold text-teal-700">
                                        {{ $host->getAttribute('identity_reconnect_required') ? 'Reconnect staff identity' : 'Edit scheduling settings' }}
                                    </summary>

                                    <form
                                        method="POST"
                                        action="{{ route('crm.scheduling.configuration.hosts.update', $host) }}"
                                        class="mt-4 grid gap-4 sm:grid-cols-2"
                                        data-configuration-host-update="{{ $host->id }}"
                                    >
                                        @csrf
                                        @method('PATCH')
                                        <input type="hidden" name="current_version" value="{{ $host->updated_at?->toISOString() }}">
                                        <input type="hidden" name="sort_order" value="{{ $host->sort_order }}">

                                        @if ($host->getAttribute('identity_reconnect_required'))
                                            <label class="block text-sm font-medium text-slate-700 sm:col-span-2">
                                                CRM user
                                                <select
                                                    class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-200"
                                                    name="user_id"
                                                    required
                                                >
                                                    <option value="">Choose the matching person</option>
                                                    @foreach ($availableHostUsers as $user)
                                                        <option value="{{ $user->id }}">
                                                            {{ $user->name }}{{ $user->email ? ' — '.$user->email : '' }}
                                                        </option>
                                                    @endforeach
                                                </select>
                                            </label>
                                        @else
                                            <input type="hidden" name="user_id" value="{{ $host->getAttribute('identity_user_id') }}">
                                        @endif

                                        <label class="block text-sm font-medium text-slate-700">
                                            Status
                                            <select
                                                class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-200"
                                                name="status"
                                                required
                                            >
                                                @foreach ($hostStatuses as $status)
                                                    <option value="{{ $status }}" @selected($host->status === $status)>
                                                        {{ str($status)->replace('_', ' ')->title() }}
                                                    </option>
                                                @endforeach
                                            </select>
                                        </label>

                                        <label class="block text-sm font-medium text-slate-700">
                                            Timezone
                                            <input
                                                class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-200"
                                                name="timezone"
                                                list="scheduling-timezones"
                                                value="{{ $host->timezone }}"
                                                required
                                            >
                                        </label>

                                        <label class="block text-sm font-medium text-slate-700">
                                            Simultaneous appointment capacity
                                            <input
                                                class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-200"
                                                type="number"
                                                min="1"
                                                max="100000"
                                                name="capacity"
                                                value="{{ $host->capacity }}"
                                                required
                                            >
                                        </label>

                                        <div class="sm:col-span-2">
                                            <button
                                                type="submit"
                                                class="inline-flex w-full justify-center rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800 sm:w-auto"
                                            >
                                                {{ $host->getAttribute('identity_reconnect_required') ? 'Reconnect staff' : 'Save changes' }}
                                            </button>
                                        </div>
                                    </form>
                                </details>
                            @else
                                <div class="mt-4 rounded-xl border border-slate-200 bg-slate-50 p-4" data-configuration-read-only="host">
                                    <p class="text-sm text-slate-600">
                                        This provider-managed staff record is read-only here.
                                    </p>
                                </div>
                            @endif
                        </div>
                    </x-ui.card>
                @empty
                    <x-ui.card class="xl:col-span-2">
                        <div
                            class="rounded-xl border border-dashed border-slate-300 p-8 text-center text-sm text-slate-500"
                            data-configuration-empty="hosts"
                        >
                            No scheduling staff have been added. That is fine when appointment types do not need a specific assignee.
                        </div>
                    </x-ui.card>
                @endforelse
            </div>
        </section>
    </div>
</x-layouts.crm>