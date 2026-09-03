<x-layouts.crm
    :title="$title"
    :heading="$heading"
    subheading="Add people only when appointments need explicit assignment."
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

            <a
                href="{{ route('crm.scheduling.configuration.services.index') }}"
                class="inline-flex w-full items-center justify-center rounded-lg border border-teal-600 bg-white px-3 py-2 text-sm font-semibold text-teal-700 shadow-sm hover:bg-teal-50 sm:w-auto"
            >
                Manage services
            </a>
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
                    Optional assignment
                </div>
                <h2 class="mt-3 text-lg font-semibold text-slate-900">Add staff or a provider</h2>
                <p class="mt-1 max-w-2xl text-sm text-slate-500">
                    Hostless services are valid. Add a person only when an appointment should be tied to a specific staff member or provider.
                </p>
            </div>

            <form
                method="POST"
                action="{{ route('crm.scheduling.configuration.hosts.store') }}"
                class="grid gap-4 md:grid-cols-3"
            >
                @csrf

                <label class="block text-sm font-medium text-slate-700">
                    Name
                    <input
                        class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-200"
                        name="name"
                        value="{{ old('name') }}"
                        placeholder="Taylor Smith"
                        required
                    >
                </label>

                <label class="block text-sm font-medium text-slate-700">
                    Email <span class="font-normal text-slate-400">(optional)</span>
                    <input
                        class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-200"
                        type="email"
                        name="email"
                        value="{{ old('email') }}"
                    >
                </label>

                <label class="block text-sm font-medium text-slate-700">
                    Phone <span class="font-normal text-slate-400">(optional)</span>
                    <input
                        class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-200"
                        name="phone"
                        value="{{ old('phone') }}"
                    >
                </label>

                <div class="md:col-span-3">
                    <button
                        type="submit"
                        class="inline-flex w-full justify-center rounded-lg bg-teal-700 px-4 py-2 text-sm font-semibold text-white hover:bg-teal-800 sm:w-auto"
                    >
                        Add staff or provider
                    </button>
                </div>
            </form>
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
                                    @if ($host->phone)
                                        <p class="mt-0.5 text-sm text-slate-500">{{ $host->phone }}</p>
                                    @endif
                                </div>
                                <span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-700">
                                    {{ str($host->status)->replace('_', ' ')->title() }}
                                </span>
                            </div>

                            <dl class="mt-4 grid grid-cols-3 gap-3 text-sm">
                                <div>
                                    <dt class="text-slate-500">Services</dt>
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
                                <details class="mt-4">
                                    <summary class="cursor-pointer text-sm font-semibold text-teal-700">
                                        Edit staff settings
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

                                        <label class="block text-sm font-medium text-slate-700">
                                            Name
                                            <input
                                                class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-200"
                                                name="name"
                                                value="{{ $host->name }}"
                                                required
                                            >
                                        </label>

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

                                        <label class="block text-sm font-medium text-slate-700">
                                            Email
                                            <input
                                                class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-200"
                                                type="email"
                                                name="email"
                                                value="{{ $host->email }}"
                                            >
                                        </label>

                                        <label class="block text-sm font-medium text-slate-700">
                                            Phone
                                            <input
                                                class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-200"
                                                name="phone"
                                                value="{{ $host->phone }}"
                                            >
                                        </label>

                                        <div class="sm:col-span-2">
                                            <button
                                                type="submit"
                                                class="inline-flex w-full justify-center rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800 sm:w-auto"
                                            >
                                                Save changes
                                            </button>
                                        </div>
                                    </form>
                                </details>
                            @else
                                <div class="mt-4 rounded-xl border border-slate-200 bg-slate-50 p-4" data-configuration-read-only="host">
                                    <p class="text-sm text-slate-600">
                                        This person is managed automatically and cannot be edited here.
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
                            No staff or providers have been added. That is fine when appointments do not need a specific assignee.
                        </div>
                    </x-ui.card>
                @endforelse
            </div>
        </section>
    </div>
</x-layouts.crm>