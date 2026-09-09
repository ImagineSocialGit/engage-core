<x-layouts.crm
    :title="$title"
    :heading="$heading"
    subheading="Use shared-capacity controls only when otherwise separate appointments compete for the same limited room, equipment, vehicle, or facility."
>
    <div class="space-y-6" data-scheduling-resource-configuration>
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <a
                href="{{ route('crm.scheduling.configuration.index') }}"
                class="inline-flex w-full items-center justify-center rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50 sm:w-auto"
                data-scheduling-resource-configuration-back
            >
                Back to configuration
            </a>

            <a
                href="{{ route('crm.scheduling.configuration.availability.index') }}"
                class="inline-flex w-full items-center justify-center rounded-lg bg-slate-900 px-3 py-2 text-sm font-semibold text-white hover:bg-slate-800 sm:w-auto"
                data-scheduling-resource-availability-link
            >
                Review availability
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

        <x-ui.card class="space-y-5" data-resource-purpose>
            <div>
                <div class="inline-flex rounded-full px-2 py-1 text-xs font-semibold {{ module_tone('scheduling', 'badge') }}">
                    Advanced
                </div>
                <h2 class="mt-3 text-xl font-semibold tracking-tight text-slate-900">
                    Do appointments compete for something limited?
                </h2>
                <p class="mt-2 max-w-4xl text-sm leading-6 text-slate-600">
                    Most businesses do not need this page. Use it only when two appointments could otherwise happen at the same time but cannot because they share the same limited thing.
                </p>
            </div>

            <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                <div class="rounded-xl border border-slate-200 bg-slate-50 p-4 text-sm">
                    <div class="font-semibold text-slate-900">One conference room</div>
                    <div class="mt-1 text-slate-600">Several consultants can work at once, but only one appointment can use the room.</div>
                </div>
                <div class="rounded-xl border border-slate-200 bg-slate-50 p-4 text-sm">
                    <div class="font-semibold text-slate-900">Two courts or work areas</div>
                    <div class="mt-1 text-slate-600">Multiple staff members can take bookings, but the facility only supports two at a time.</div>
                </div>
                <div class="rounded-xl border border-slate-200 bg-slate-50 p-4 text-sm">
                    <div class="font-semibold text-slate-900">Specialized equipment</div>
                    <div class="mt-1 text-slate-600">An appointment type needs one machine or device that several staff members share.</div>
                </div>
                <div class="rounded-xl border border-slate-200 bg-slate-50 p-4 text-sm">
                    <div class="font-semibold text-slate-900">Limited vehicles</div>
                    <div class="mt-1 text-slate-600">Field appointments can overlap only while enough shared vehicles are available.</div>
                </div>
            </div>

            <div class="rounded-xl border border-teal-200 bg-teal-50 p-4 text-sm text-teal-900">
                If none of those situations resemble your business, there is nothing to configure here.
            </div>
        </x-ui.card>

        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <x-ui.card>
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Shared items</p>
                <p class="mt-2 text-3xl font-semibold text-slate-900" data-resource-count="{{ $resourceStats['total'] }}">
                    {{ $resourceStats['total'] }}
                </p>
            </x-ui.card>
            <x-ui.card>
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Active shared items</p>
                <p class="mt-2 text-3xl font-semibold text-slate-900">{{ $resourceStats['active'] }}</p>
            </x-ui.card>
            <x-ui.card>
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Staff records</p>
                <p class="mt-2 text-3xl font-semibold text-slate-900" data-resource-host-count="{{ $resourceStats['hosts'] }}">
                    {{ $resourceStats['hosts'] }}
                </p>
            </x-ui.card>
            <x-ui.card>
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Appointment types</p>
                <p class="mt-2 text-3xl font-semibold text-slate-900" data-resource-service-count="{{ $resourceStats['services'] }}">
                    {{ $resourceStats['services'] }}
                </p>
            </x-ui.card>
        </div>

        <section class="space-y-5" data-resource-section="identities">
            <div>
                <div class="inline-flex rounded-full px-2 py-1 text-xs font-semibold {{ module_tone('scheduling', 'badge') }}">
                    Step 1
                </div>
                <h2 class="mt-3 text-xl font-semibold tracking-tight text-slate-900">What limited thing is being shared?</h2>
                <p class="mt-1 text-sm text-slate-500">
                    Create one shared item for each room, equipment pool, vehicle pool, or other capacity that can block overlapping appointments.
                </p>
            </div>

            <x-ui.card class="space-y-4">
                <h3 class="font-semibold text-slate-900">Add a shared item</h3>

                <form
                    method="POST"
                    action="{{ route('crm.scheduling.configuration.resources.store') }}"
                    class="grid gap-4 md:grid-cols-2 xl:grid-cols-4"
                    data-resource-create
                >
                    @csrf

                    <label class="{{ $labelClass }}">
                        Key
                        <input class="{{ $inputClass }}" name="key" value="{{ old('key') }}" required pattern="[a-z0-9]+(?:[-_][a-z0-9]+)*" placeholder="conference_room">
                        <span class="mt-1 block text-xs font-normal text-slate-500">Stable internal name; lowercase letters, numbers, dashes, and underscores.</span>
                    </label>

                    <label class="{{ $labelClass }}">
                        Name
                        <input class="{{ $inputClass }}" name="name" value="{{ old('name') }}" required placeholder="Conference room">
                    </label>

                    <label class="{{ $labelClass }}">
                        Status
                        <select class="{{ $inputClass }}" name="status" required>
                            @foreach ($resourceStatuses as $status)
                                <option value="{{ $status }}" @selected(old('status', 'active') === $status)>
                                    {{ str($status)->replace('_', ' ')->title() }}
                                </option>
                            @endforeach
                        </select>
                    </label>

                    <label class="{{ $labelClass }}">
                        Sort order
                        <input class="{{ $inputClass }}" type="number" min="0" max="100000" name="sort_order" value="{{ old('sort_order', 0) }}" required>
                    </label>

                    <div class="md:col-span-2 xl:col-span-4">
                        <button type="submit" class="inline-flex w-full justify-center rounded-lg bg-teal-700 px-4 py-2 text-sm font-semibold text-white hover:bg-teal-800 sm:w-auto">
                            Add shared item
                        </button>
                    </div>
                </form>
            </x-ui.card>

            <div class="grid gap-4 xl:grid-cols-2">
                @forelse ($resourceRows as $resourceRow)
                    <div
                        data-scheduling-resource-id="{{ $resourceRow['model']->id }}"
                        data-resource-editable="{{ $resourceRow['editable'] ? '1' : '0' }}"
                    >
                        <x-ui.card class="space-y-4">
                            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                <div>
                                    <h3 class="font-semibold text-slate-900">{{ $resourceRow['model']->name }}</h3>
                                    <p class="mt-1 font-mono text-xs text-slate-500">{{ $resourceRow['model']->key }}</p>
                                </div>
                                <span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-700">
                                    {{ str($resourceRow['model']->status)->replace('_', ' ')->title() }}
                                </span>
                            </div>

                            <dl class="grid gap-3 text-sm sm:grid-cols-3">
                                <div>
                                    <dt class="text-slate-500">Staff capacities</dt>
                                    <dd class="font-medium text-slate-900" data-resource-active-host-count="{{ $resourceRow['model']->active_host_capacities_count }}">
                                        {{ $resourceRow['model']->active_host_capacities_count }}
                                    </dd>
                                </div>
                                <div>
                                    <dt class="text-slate-500">Appointment requirements</dt>
                                    <dd class="font-medium text-slate-900" data-resource-active-requirement-count="{{ $resourceRow['model']->active_service_requirements_count }}">
                                        {{ $resourceRow['model']->active_service_requirements_count }}
                                    </dd>
                                </div>
                                <div>
                                    <dt class="text-slate-500">Committed bookings</dt>
                                    <dd class="font-medium text-slate-900" data-resource-occupancy-count="{{ $resourceRow['model']->occupancies_count }}">
                                        {{ $resourceRow['model']->occupancies_count }}
                                    </dd>
                                </div>
                            </dl>

                            @if ($resourceRow['editable'])
                                <form
                                    method="POST"
                                    action="{{ route('crm.scheduling.configuration.resources.update', $resourceRow['model']) }}"
                                    class="grid gap-3 sm:grid-cols-3"
                                >
                                    @csrf
                                    @method('PATCH')
                                    <input type="hidden" name="current_version" value="{{ $resourceRow['model']->updated_at?->toISOString() }}">

                                    <label class="{{ $labelClass }} sm:col-span-2">
                                        Name
                                        <input class="{{ $inputClass }}" name="name" value="{{ $resourceRow['model']->name }}" required>
                                    </label>
                                    <label class="{{ $labelClass }}">
                                        Status
                                        <select class="{{ $inputClass }}" name="status" required>
                                            @foreach ($resourceStatuses as $status)
                                                <option value="{{ $status }}" @selected($resourceRow['model']->status === $status)>
                                                    {{ str($status)->replace('_', ' ')->title() }}
                                                </option>
                                            @endforeach
                                        </select>
                                    </label>
                                    <label class="{{ $labelClass }}">
                                        Sort order
                                        <input class="{{ $inputClass }}" type="number" min="0" max="100000" name="sort_order" value="{{ $resourceRow['model']->sort_order }}" required>
                                    </label>
                                    <div class="sm:col-span-3">
                                        <button type="submit" class="inline-flex rounded-lg bg-slate-900 px-3 py-2 text-sm font-semibold text-white hover:bg-slate-800">
                                            Save shared item
                                        </button>
                                    </div>
                                </form>
                            @else
                                <p class="rounded-xl border border-slate-200 bg-slate-50 p-4 text-sm text-slate-600">
                                    This shared item is managed externally and is read-only here.
                                </p>
                            @endif
                        </x-ui.card>
                    </div>
                @empty
                    <x-ui.card>
                        <p class="text-sm text-slate-500">No shared items are configured. If appointments do not compete for anything limited, leave this page empty.</p>
                    </x-ui.card>
                @endforelse
            </div>
        </section>

        <section class="space-y-5" data-resource-section="host_capacities">
            <div>
                <div class="inline-flex rounded-full px-2 py-1 text-xs font-semibold {{ module_tone('scheduling', 'badge') }}">
                    Step 2
                </div>
                <h2 class="mt-3 text-xl font-semibold tracking-tight text-slate-900">How much shared capacity can each staff member use?</h2>
                <p class="mt-1 text-sm text-slate-500">
                    Example: if a trainer can supervise two courts at once, give that trainer a capacity of 2 for the Courts shared item.
                </p>
            </div>

            <div class="grid gap-4 xl:grid-cols-2">
                @forelse ($hostRows as $hostRow)
                    <x-ui.card class="space-y-4" data-resource-host-id="{{ $hostRow['host']->id }}">
                        <div>
                            <h3 class="font-semibold text-slate-900">{{ $hostRow['host']->name }}</h3>
                            <p class="mt-1 text-sm text-slate-500">{{ str($hostRow['host']->status)->replace('_', ' ')->title() }}</p>
                        </div>

                        @if ($resources->isEmpty())
                            <p class="text-sm text-slate-500">Add a shared item first.</p>
                        @else
                            <form
                                method="POST"
                                action="{{ route('crm.scheduling.configuration.resources.hosts.update', $hostRow['host']) }}"
                                class="space-y-3"
                                data-resource-host-form="{{ $hostRow['host']->id }}"
                            >
                                @csrf
                                @method('PUT')
                                <input type="hidden" name="current_version" value="{{ $hostRow['host']->updated_at?->toISOString() }}">

                                <div class="overflow-x-auto">
                                    <table class="min-w-[640px] divide-y divide-slate-200 text-sm">
                                        <thead>
                                            <tr class="text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                                                <th class="px-3 py-2">Shared item</th>
                                                <th class="px-3 py-2">Uses it</th>
                                                <th class="px-3 py-2">Capacity</th>
                                                <th class="px-3 py-2">Ownership</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-slate-100">
                                            @foreach ($hostRow['rows'] as $row)
                                                <tr data-host-resource-row="{{ $hostRow['host']->id }}:{{ $row['resource']->id }}">
                                                    <td class="px-3 py-3">
                                                        <div class="font-medium text-slate-900">{{ $row['resource']->name }}</div>
                                                        <div class="font-mono text-xs text-slate-500">{{ $row['resource']->key }}</div>
                                                    </td>

                                                    @if ($row['editable'])
                                                        <td class="px-3 py-3">
                                                            <input type="hidden" name="resources[{{ $row['form_index'] }}][scheduling_resource_id]" value="{{ $row['resource']->id }}">
                                                            <input type="hidden" name="resources[{{ $row['form_index'] }}][is_active]" value="0">
                                                            <input
                                                                type="checkbox"
                                                                name="resources[{{ $row['form_index'] }}][is_active]"
                                                                value="1"
                                                                @checked($row['active'])
                                                            >
                                                        </td>
                                                        <td class="px-3 py-3">
                                                            <input class="w-28 rounded-lg border border-slate-300 px-2 py-1.5" type="number" min="1" max="100000" name="resources[{{ $row['form_index'] }}][capacity]" value="{{ $row['capacity'] }}">
                                                            <input type="hidden" name="resources[{{ $row['form_index'] }}][sort_order]" value="{{ $row['sort_order'] }}">
                                                        </td>
                                                        <td class="px-3 py-3 text-slate-500">{{ $row['source'] }}</td>
                                                    @else
                                                        <td class="px-3 py-3 font-medium text-slate-900">{{ $row['active'] ? 'Yes' : 'No' }}</td>
                                                        <td class="px-3 py-3 font-medium text-slate-900">{{ $row['capacity'] }}</td>
                                                        <td class="px-3 py-3 text-slate-500" data-host-resource-read-only="{{ $row['model']->id }}">{{ $row['source'] }}</td>
                                                    @endif
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>

                                <button type="submit" class="inline-flex w-full justify-center rounded-lg bg-teal-700 px-4 py-2 text-sm font-semibold text-white hover:bg-teal-800 sm:w-auto">
                                    Save staff capacity
                                </button>
                            </form>
                        @endif
                    </x-ui.card>
                @empty
                    <x-ui.card>
                        <p class="text-sm text-slate-500">Add Scheduling staff before assigning person-specific shared capacity.</p>
                    </x-ui.card>
                @endforelse
            </div>
        </section>

        <section class="space-y-5" data-resource-section="service_requirements">
            <div>
                <div class="inline-flex rounded-full px-2 py-1 text-xs font-semibold {{ module_tone('scheduling', 'badge') }}">
                    Step 3
                </div>
                <h2 class="mt-3 text-xl font-semibold tracking-tight text-slate-900">What does each appointment type require?</h2>
                <p class="mt-1 text-sm text-slate-500">
                    Example: a Consultation might require one Conference room, while a Training session might require two Courts.
                </p>
            </div>

            <div class="grid gap-4 xl:grid-cols-2">
                @forelse ($serviceRows as $serviceRow)
                    <x-ui.card class="space-y-4" data-resource-service-id="{{ $serviceRow['service']->id }}">
                        <div>
                            <h3 class="font-semibold text-slate-900">{{ $serviceRow['service']->name }}</h3>
                            <p class="mt-1 text-sm text-slate-500">{{ str($serviceRow['service']->status)->replace('_', ' ')->title() }}</p>
                        </div>

                        @if ($resources->isEmpty())
                            <p class="text-sm text-slate-500">Add a shared item first.</p>
                        @else
                            <form
                                method="POST"
                                action="{{ route('crm.scheduling.configuration.resources.services.update', $serviceRow['service']) }}"
                                class="space-y-3"
                                data-resource-service-form="{{ $serviceRow['service']->id }}"
                            >
                                @csrf
                                @method('PUT')
                                <input type="hidden" name="current_version" value="{{ $serviceRow['service']->updated_at?->toISOString() }}">

                                <div class="overflow-x-auto">
                                    <table class="min-w-[640px] divide-y divide-slate-200 text-sm">
                                        <thead>
                                            <tr class="text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                                                <th class="px-3 py-2">Shared item</th>
                                                <th class="px-3 py-2">Required</th>
                                                <th class="px-3 py-2">Quantity</th>
                                                <th class="px-3 py-2">Ownership</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-slate-100">
                                            @foreach ($serviceRow['rows'] as $row)
                                                <tr data-service-resource-row="{{ $serviceRow['service']->id }}:{{ $row['resource']->id }}">
                                                    <td class="px-3 py-3">
                                                        <div class="font-medium text-slate-900">{{ $row['resource']->name }}</div>
                                                        <div class="font-mono text-xs text-slate-500">{{ $row['resource']->key }}</div>
                                                    </td>

                                                    @if ($row['editable'])
                                                        <td class="px-3 py-3">
                                                            <input type="hidden" name="resources[{{ $row['form_index'] }}][scheduling_resource_id]" value="{{ $row['resource']->id }}">
                                                            <input type="hidden" name="resources[{{ $row['form_index'] }}][is_active]" value="0">
                                                            <input
                                                                type="checkbox"
                                                                name="resources[{{ $row['form_index'] }}][is_active]"
                                                                value="1"
                                                                @checked($row['active'])
                                                            >
                                                        </td>
                                                        <td class="px-3 py-3">
                                                            <input class="w-28 rounded-lg border border-slate-300 px-2 py-1.5" type="number" min="1" max="100000" name="resources[{{ $row['form_index'] }}][quantity]" value="{{ $row['quantity'] }}">
                                                            <input type="hidden" name="resources[{{ $row['form_index'] }}][sort_order]" value="{{ $row['sort_order'] }}">
                                                        </td>
                                                        <td class="px-3 py-3 text-slate-500">{{ $row['source'] }}</td>
                                                    @else
                                                        <td class="px-3 py-3 font-medium text-slate-900">{{ $row['active'] ? 'Yes' : 'No' }}</td>
                                                        <td class="px-3 py-3 font-medium text-slate-900">{{ $row['quantity'] }}</td>
                                                        <td class="px-3 py-3 text-slate-500" data-service-resource-read-only="{{ $row['model']->id }}">{{ $row['source'] }}</td>
                                                    @endif
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>

                                <button type="submit" class="inline-flex w-full justify-center rounded-lg bg-teal-700 px-4 py-2 text-sm font-semibold text-white hover:bg-teal-800 sm:w-auto">
                                    Save appointment requirements
                                </button>
                            </form>
                        @endif
                    </x-ui.card>
                @empty
                    <x-ui.card>
                        <p class="text-sm text-slate-500">Create an appointment type before assigning shared requirements.</p>
                    </x-ui.card>
                @endforelse
            </div>
        </section>

        <section class="space-y-5" data-resource-section="effects">
            <div>
                <div class="inline-flex rounded-full px-2 py-1 text-xs font-semibold {{ module_tone('scheduling', 'badge') }}">
                    Booking effect
                </div>
                <h2 class="mt-3 text-xl font-semibold tracking-tight text-slate-900">How these limits affect booking</h2>
                <p class="mt-1 text-sm text-slate-500">
                    This shows whether each active appointment-type/staff pairing has enough shared capacity to offer times.
                </p>
            </div>

            <div class="grid gap-4 xl:grid-cols-2">
                @forelse ($effects as $effect)
                    <x-ui.card
                        class="space-y-3"
                        data-resource-effect="{{ $effect['service_id'] }}:{{ $effect['host_id'] }}"
                        data-resource-effect-state="{{ $effect['state'] }}"
                    >
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                            <div>
                                <h3 class="font-semibold text-slate-900">{{ $effect['service_name'] }}</h3>
                                <p class="mt-1 text-sm text-slate-500">{{ $effect['host_name'] }}</p>
                            </div>

                            @if ($effect['state'] === 'available')
                                <span class="rounded-full bg-emerald-100 px-2.5 py-1 text-xs font-semibold text-emerald-800" data-resource-effect-ceiling="{{ $effect['resource_ceiling'] }}">
                                    Up to {{ $effect['resource_ceiling'] }} at once by shared capacity
                                </span>
                            @elseif ($effect['state'] === 'no_limit')
                                <span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-700">
                                    No shared limit
                                </span>
                            @else
                                <span class="rounded-full bg-red-100 px-2.5 py-1 text-xs font-semibold text-red-800" data-resource-effect-reason="{{ $effect['reason'] }}">
                                    Cannot book
                                </span>
                            @endif
                        </div>

                        @if ($effect['state'] === 'closed')
                            <p class="text-sm text-red-700">{{ $effect['reason_label'] }}</p>
                        @endif

                        @if ($effect['requirements'] !== [])
                            <dl class="space-y-2 text-sm">
                                @foreach ($effect['requirements'] as $requirement)
                                    <div class="grid grid-cols-2 gap-2 rounded-lg bg-slate-50 px-3 py-2 sm:grid-cols-4" data-resource-effect-requirement="{{ $requirement['resource_id'] }}">
                                        <div class="col-span-2">
                                            <dt class="text-slate-500">Shared item</dt>
                                            <dd class="font-medium text-slate-900">{{ $requirement['resource_name'] ?? $requirement['resource_key'] }}</dd>
                                        </div>
                                        <div>
                                            <dt class="text-slate-500">Needed</dt>
                                            <dd class="font-medium text-slate-900">{{ $requirement['quantity'] }}</dd>
                                        </div>
                                        <div>
                                            <dt class="text-slate-500">Available</dt>
                                            <dd class="font-medium text-slate-900">{{ $requirement['host_capacity'] ?? 'None' }}</dd>
                                        </div>
                                    </div>
                                @endforeach
                            </dl>
                        @endif
                    </x-ui.card>
                @empty
                    <x-ui.card>
                        <p class="text-sm text-slate-500">No active appointment-type/staff pairings are available for a shared-capacity summary.</p>
                    </x-ui.card>
                @endforelse
            </div>
        </section>
    </div>
</x-layouts.crm>