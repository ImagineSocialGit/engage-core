<x-layouts.crm
    :title="$title"
    :heading="$heading"
    subheading="Configure selective-overlap resources, host capacities, and service requirements."
>
    @php
        $resourceStatuses = [
            \App\Modules\Scheduling\Models\SchedulingResource::STATUS_ACTIVE,
            \App\Modules\Scheduling\Models\SchedulingResource::STATUS_INACTIVE,
            \App\Modules\Scheduling\Models\SchedulingResource::STATUS_ARCHIVED,
        ];
        $inputClass = 'mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-200';
        $labelClass = 'block text-sm font-medium text-slate-700';
        $reasonLabels = [
            'service_inactive' => 'Service is not active',
            'host_inactive' => 'Host is not active',
            'resource_inactive' => 'A required resource is not active',
            'host_capacity_missing' => 'The host lacks an active required capacity',
            'quantity_exceeds_capacity' => 'A required quantity exceeds host capacity',
        ];
    @endphp

    <div class="space-y-6" data-scheduling-resource-configuration>
        <div class="flex flex-wrap items-center justify-between gap-3">
            <a
                href="{{ route('crm.scheduling.configuration.index') }}"
                class="inline-flex items-center rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50"
                data-scheduling-resource-configuration-back
            >
                Back to configuration
            </a>

            <a
                href="{{ route('crm.scheduling.configuration.availability.index') }}"
                class="inline-flex items-center rounded-lg bg-slate-900 px-3 py-2 text-sm font-semibold text-white hover:bg-slate-800"
                data-scheduling-resource-availability-link
            >
                Preview availability
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

        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <x-ui.card>
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Resources</p>
                <p class="mt-2 text-3xl font-semibold text-slate-900" data-resource-count="{{ $resources->count() }}">
                    {{ $resources->count() }}
                </p>
            </x-ui.card>

            <x-ui.card>
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Active resources</p>
                <p class="mt-2 text-3xl font-semibold text-slate-900">
                    {{ $resources->where('status', \App\Modules\Scheduling\Models\SchedulingResource::STATUS_ACTIVE)->count() }}
                </p>
            </x-ui.card>

            <x-ui.card>
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Configured hosts</p>
                <p class="mt-2 text-3xl font-semibold text-slate-900" data-resource-host-count="{{ $hosts->count() }}">
                    {{ $hosts->count() }}
                </p>
            </x-ui.card>

            <x-ui.card>
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Configured services</p>
                <p class="mt-2 text-3xl font-semibold text-slate-900" data-resource-service-count="{{ $services->count() }}">
                    {{ $services->count() }}
                </p>
            </x-ui.card>
        </div>

        <section class="space-y-5" data-resource-section="identities">
            <div>
                <div class="inline-flex rounded-full px-2 py-1 text-xs font-semibold {{ module_tone('scheduling', 'badge') }}">
                    Resource identities
                </div>
                <h2 class="mt-3 text-xl font-semibold tracking-tight text-slate-900">
                    Selective-overlap resource definitions
                </h2>
            </div>

            <x-ui.card class="space-y-4">
                <h3 class="font-semibold text-slate-900">Create a manual resource</h3>

                <form
                    method="POST"
                    action="{{ route('crm.scheduling.configuration.resources.store') }}"
                    class="grid gap-4 md:grid-cols-2 xl:grid-cols-4"
                    data-resource-create
                >
                    @csrf

                    <label class="{{ $labelClass }}">
                        Key
                        <input class="{{ $inputClass }}" name="key" value="{{ old('key') }}" required pattern="[a-z0-9]+(?:[-_][a-z0-9]+)*">
                    </label>

                    <label class="{{ $labelClass }}">
                        Name
                        <input class="{{ $inputClass }}" name="name" value="{{ old('name') }}" required>
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
                        <button type="submit" class="inline-flex rounded-lg bg-teal-700 px-4 py-2 text-sm font-semibold text-white hover:bg-teal-800">
                            Create resource
                        </button>
                    </div>
                </form>
            </x-ui.card>

            <div class="grid gap-4 xl:grid-cols-2">
                @forelse ($resources as $resource)
                    @php
                        $resourceEditable = (bool) $resource->getAttribute('crm_editable');
                    @endphp

                    <div
                        data-scheduling-resource-id="{{ $resource->id }}"
                        data-resource-editable="{{ $resourceEditable ? '1' : '0' }}"
                    >
                        <x-ui.card class="space-y-4">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div>
                                    <h3 class="font-semibold text-slate-900">{{ $resource->name }}</h3>
                                    <p class="mt-1 font-mono text-xs text-slate-500">{{ $resource->key }}</p>
                                </div>
                                <span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-700">
                                    {{ str($resource->status)->replace('_', ' ')->title() }}
                                </span>
                            </div>

                            <dl class="grid grid-cols-2 gap-3 text-sm sm:grid-cols-4">
                                <div>
                                    <dt class="text-slate-500">Source</dt>
                                    <dd class="font-medium text-slate-900">{{ $resource->source }}</dd>
                                </div>
                                <div>
                                    <dt class="text-slate-500">Active host capacities</dt>
                                    <dd class="font-medium text-slate-900" data-resource-active-host-count="{{ $resource->active_host_capacities_count }}">
                                        {{ $resource->active_host_capacities_count }}
                                    </dd>
                                </div>
                                <div>
                                    <dt class="text-slate-500">Active requirements</dt>
                                    <dd class="font-medium text-slate-900" data-resource-active-requirement-count="{{ $resource->active_service_requirements_count }}">
                                        {{ $resource->active_service_requirements_count }}
                                    </dd>
                                </div>
                                <div>
                                    <dt class="text-slate-500">Committed snapshots</dt>
                                    <dd class="font-medium text-slate-900" data-resource-occupancy-count="{{ $resource->occupancies_count }}">
                                        {{ $resource->occupancies_count }}
                                    </dd>
                                </div>
                            </dl>

                            @if ($resourceEditable)
                                <form
                                    method="POST"
                                    action="{{ route('crm.scheduling.configuration.resources.update', $resource) }}"
                                    class="grid gap-4 sm:grid-cols-3"
                                    data-resource-update="{{ $resource->id }}"
                                >
                                    @csrf
                                    @method('PATCH')
                                    <input type="hidden" name="current_version" value="{{ $resource->updated_at?->toISOString() }}">

                                    <label class="{{ $labelClass }} sm:col-span-2">
                                        Name
                                        <input class="{{ $inputClass }}" name="name" value="{{ $resource->name }}" required>
                                    </label>

                                    <label class="{{ $labelClass }}">
                                        Status
                                        <select class="{{ $inputClass }}" name="status" required>
                                            @foreach ($resourceStatuses as $status)
                                                <option value="{{ $status }}" @selected($resource->status === $status)>
                                                    {{ str($status)->replace('_', ' ')->title() }}
                                                </option>
                                            @endforeach
                                        </select>
                                    </label>

                                    <label class="{{ $labelClass }}">
                                        Sort order
                                        <input class="{{ $inputClass }}" type="number" min="0" max="100000" name="sort_order" value="{{ $resource->sort_order }}" required>
                                    </label>

                                    <div class="sm:col-span-3">
                                        <button type="submit" class="inline-flex rounded-lg bg-teal-700 px-4 py-2 text-sm font-semibold text-white hover:bg-teal-800">
                                            Save resource
                                        </button>
                                    </div>
                                </form>
                            @else
                                <div class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-600" data-resource-read-only="{{ $resource->id }}">
                                    This resource is controlled outside the manual CRM configuration boundary.
                                </div>
                            @endif
                        </x-ui.card>
                    </div>
                @empty
                    <x-ui.card>
                        <p class="text-sm text-slate-500">No resource identities are configured.</p>
                    </x-ui.card>
                @endforelse
            </div>
        </section>

        <section class="space-y-5" data-resource-section="host-capacities">
            <div>
                <div class="inline-flex rounded-full px-2 py-1 text-xs font-semibold {{ module_tone('scheduling', 'badge') }}">
                    Host capacities
                </div>
                <h2 class="mt-3 text-xl font-semibold tracking-tight text-slate-900">
                    Resource units owned by each host
                </h2>
            </div>

            <div class="space-y-4">
                @forelse ($hosts as $host)
                    @php
                        $hostRows = $host->getRelation('resourceCapacities')->keyBy('scheduling_resource_id');
                        $editableResourceIndex = 0;
                    @endphp

                    <x-ui.card class="space-y-4" data-resource-host-id="{{ $host->id }}">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <h3 class="font-semibold text-slate-900">{{ $host->name }}</h3>
                                <p class="mt-1 font-mono text-xs text-slate-500">{{ $host->key }}</p>
                            </div>
                            <span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-700">
                                {{ str($host->status)->replace('_', ' ')->title() }}
                            </span>
                        </div>

                        <form
                            method="POST"
                            action="{{ route('crm.scheduling.configuration.resources.hosts.update', $host) }}"
                            class="space-y-3"
                            data-resource-host-form="{{ $host->id }}"
                        >
                            @csrf
                            @method('PUT')
                            <input type="hidden" name="current_version" value="{{ $host->updated_at?->toISOString() }}">

                            <div class="overflow-x-auto">
                                <table class="min-w-full divide-y divide-slate-200 text-sm">
                                    <thead>
                                        <tr class="text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                                            <th class="px-3 py-2">Resource</th>
                                            <th class="px-3 py-2">Active</th>
                                            <th class="px-3 py-2">Capacity</th>
                                            <th class="px-3 py-2">Sort</th>
                                            <th class="px-3 py-2">Ownership</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-slate-100">
                                        @foreach ($resources as $resource)
                                            @php
                                                $row = $hostRows->get($resource->id);
                                                $rowEditable = ! $row || (bool) $row->getAttribute('crm_editable');
                                                $active = $row?->is_active ?? false;
                                            @endphp
                                            <tr data-host-resource-row="{{ $host->id }}:{{ $resource->id }}">
                                                <td class="px-3 py-3">
                                                    <div class="font-medium text-slate-900">{{ $resource->name }}</div>
                                                    <div class="font-mono text-xs text-slate-500">{{ $resource->key }}</div>
                                                </td>
                                                @if ($rowEditable)
                                                    <td class="px-3 py-3">
                                                        <input type="hidden" name="resources[{{ $editableResourceIndex }}][scheduling_resource_id]" value="{{ $resource->id }}">
                                                        <input type="hidden" name="resources[{{ $editableResourceIndex }}][is_active]" value="0">
                                                        <input
                                                            type="checkbox"
                                                            name="resources[{{ $editableResourceIndex }}][is_active]"
                                                            value="1"
                                                            @checked($active)
                                                        >
                                                    </td>
                                                    <td class="px-3 py-3">
                                                        <input class="w-28 rounded-lg border border-slate-300 px-2 py-1.5" type="number" min="1" max="100000" name="resources[{{ $editableResourceIndex }}][capacity]" value="{{ $row?->capacity ?? 1 }}">
                                                    </td>
                                                    <td class="px-3 py-3">
                                                        <input class="w-24 rounded-lg border border-slate-300 px-2 py-1.5" type="number" min="0" max="100000" name="resources[{{ $editableResourceIndex }}][sort_order]" value="{{ $row?->sort_order ?? $resource->sort_order }}" required>
                                                    </td>
                                                    <td class="px-3 py-3 text-slate-500">{{ $row?->source ?? 'manual' }}</td>
                                                    @php $editableResourceIndex++; @endphp
                                                @else
                                                    <td class="px-3 py-3 font-medium text-slate-900">{{ $active ? 'Yes' : 'No' }}</td>
                                                    <td class="px-3 py-3 font-medium text-slate-900">{{ $row->capacity }}</td>
                                                    <td class="px-3 py-3 font-medium text-slate-900">{{ $row->sort_order }}</td>
                                                    <td class="px-3 py-3 text-slate-500" data-host-resource-read-only="{{ $row->id }}">{{ $row->source }}</td>
                                                @endif
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>

                            <button type="submit" class="inline-flex rounded-lg bg-teal-700 px-4 py-2 text-sm font-semibold text-white hover:bg-teal-800">
                                Save host capacities
                            </button>
                        </form>
                    </x-ui.card>
                @empty
                    <x-ui.card>
                        <p class="text-sm text-slate-500">Create a scheduling host before assigning resource capacity.</p>
                    </x-ui.card>
                @endforelse
            </div>
        </section>

        <section class="space-y-5" data-resource-section="service-requirements">
            <div>
                <div class="inline-flex rounded-full px-2 py-1 text-xs font-semibold {{ module_tone('scheduling', 'badge') }}">
                    Service requirements
                </div>
                <h2 class="mt-3 text-xl font-semibold tracking-tight text-slate-900">
                    Resource units consumed by one Appointment
                </h2>
            </div>

            <div class="space-y-4">
                @forelse ($services as $service)
                    @php
                        $serviceRows = $service->getRelation('resourceRequirements')->keyBy('scheduling_resource_id');
                        $editableResourceIndex = 0;
                    @endphp

                    <x-ui.card class="space-y-4" data-resource-service-id="{{ $service->id }}">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <h3 class="font-semibold text-slate-900">{{ $service->name }}</h3>
                                <p class="mt-1 font-mono text-xs text-slate-500">{{ $service->key }}</p>
                            </div>
                            <span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-700">
                                {{ str($service->status)->replace('_', ' ')->title() }}
                            </span>
                        </div>

                        <form
                            method="POST"
                            action="{{ route('crm.scheduling.configuration.resources.services.update', $service) }}"
                            class="space-y-3"
                            data-resource-service-form="{{ $service->id }}"
                        >
                            @csrf
                            @method('PUT')
                            <input type="hidden" name="current_version" value="{{ $service->updated_at?->toISOString() }}">

                            <div class="overflow-x-auto">
                                <table class="min-w-full divide-y divide-slate-200 text-sm">
                                    <thead>
                                        <tr class="text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                                            <th class="px-3 py-2">Resource</th>
                                            <th class="px-3 py-2">Required</th>
                                            <th class="px-3 py-2">Quantity</th>
                                            <th class="px-3 py-2">Sort</th>
                                            <th class="px-3 py-2">Ownership</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-slate-100">
                                        @foreach ($resources as $resource)
                                            @php
                                                $row = $serviceRows->get($resource->id);
                                                $rowEditable = ! $row || (bool) $row->getAttribute('crm_editable');
                                                $active = $row?->is_active ?? false;
                                            @endphp
                                            <tr data-service-resource-row="{{ $service->id }}:{{ $resource->id }}">
                                                <td class="px-3 py-3">
                                                    <div class="font-medium text-slate-900">{{ $resource->name }}</div>
                                                    <div class="font-mono text-xs text-slate-500">{{ $resource->key }}</div>
                                                </td>
                                                @if ($rowEditable)
                                                    <td class="px-3 py-3">
                                                        <input type="hidden" name="resources[{{ $editableResourceIndex }}][scheduling_resource_id]" value="{{ $resource->id }}">
                                                        <input type="hidden" name="resources[{{ $editableResourceIndex }}][is_active]" value="0">
                                                        <input
                                                            type="checkbox"
                                                            name="resources[{{ $editableResourceIndex }}][is_active]"
                                                            value="1"
                                                            @checked($active)
                                                        >
                                                    </td>
                                                    <td class="px-3 py-3">
                                                        <input class="w-28 rounded-lg border border-slate-300 px-2 py-1.5" type="number" min="1" max="100000" name="resources[{{ $editableResourceIndex }}][quantity]" value="{{ $row?->quantity ?? 1 }}">
                                                    </td>
                                                    <td class="px-3 py-3">
                                                        <input class="w-24 rounded-lg border border-slate-300 px-2 py-1.5" type="number" min="0" max="100000" name="resources[{{ $editableResourceIndex }}][sort_order]" value="{{ $row?->sort_order ?? $resource->sort_order }}" required>
                                                    </td>
                                                    <td class="px-3 py-3 text-slate-500">{{ $row?->source ?? 'manual' }}</td>
                                                    @php $editableResourceIndex++; @endphp
                                                @else
                                                    <td class="px-3 py-3 font-medium text-slate-900">{{ $active ? 'Yes' : 'No' }}</td>
                                                    <td class="px-3 py-3 font-medium text-slate-900">{{ $row->quantity }}</td>
                                                    <td class="px-3 py-3 font-medium text-slate-900">{{ $row->sort_order }}</td>
                                                    <td class="px-3 py-3 text-slate-500" data-service-resource-read-only="{{ $row->id }}">{{ $row->source }}</td>
                                                @endif
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>

                            <button type="submit" class="inline-flex rounded-lg bg-teal-700 px-4 py-2 text-sm font-semibold text-white hover:bg-teal-800">
                                Save service requirements
                            </button>
                        </form>
                    </x-ui.card>
                @empty
                    <x-ui.card>
                        <p class="text-sm text-slate-500">Create a bookable service before assigning resource requirements.</p>
                    </x-ui.card>
                @endforelse
            </div>
        </section>

        <section class="space-y-5" data-resource-section="effects">
            <div>
                <div class="inline-flex rounded-full px-2 py-1 text-xs font-semibold {{ module_tone('scheduling', 'badge') }}">
                    Live effects
                </div>
                <h2 class="mt-3 text-xl font-semibold tracking-tight text-slate-900">
                    Configured resource result by active service-host assignment
                </h2>
            </div>

            <div class="grid gap-4 xl:grid-cols-2">
                @forelse ($effects as $effect)
                    <x-ui.card class="space-y-3" data-resource-effect="{{ $effect['service_id'] }}:{{ $effect['host_id'] }}" data-resource-effect-state="{{ $effect['state'] }}">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <h3 class="font-semibold text-slate-900">{{ $effect['service_name'] }}</h3>
                                <p class="mt-1 text-sm text-slate-500">{{ $effect['host_name'] }}</p>
                            </div>
                            @if ($effect['state'] === 'available')
                                <span class="rounded-full bg-emerald-100 px-2.5 py-1 text-xs font-semibold text-emerald-800" data-resource-effect-ceiling="{{ $effect['resource_ceiling'] }}">
                                    {{ $effect['resource_ceiling'] }} concurrent by resources
                                </span>
                            @elseif ($effect['state'] === 'no_limit')
                                <span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-700">
                                    No resource requirement
                                </span>
                            @else
                                <span class="rounded-full bg-rose-100 px-2.5 py-1 text-xs font-semibold text-rose-800" data-resource-effect-reason="{{ $effect['reason'] }}">
                                    Closed
                                </span>
                            @endif
                        </div>

                        @if ($effect['state'] === 'closed')
                            <p class="text-sm text-rose-700">
                                {{ $reasonLabels[$effect['reason']] ?? str($effect['reason'])->replace('_', ' ')->title() }}
                            </p>
                        @endif

                        @if ($effect['requirements'] !== [])
                            <dl class="space-y-2 text-sm">
                                @foreach ($effect['requirements'] as $requirement)
                                    <div class="grid grid-cols-4 gap-2 rounded-lg bg-slate-50 px-3 py-2" data-resource-effect-requirement="{{ $requirement['resource_id'] }}">
                                        <div class="col-span-2">
                                            <dt class="text-slate-500">Resource</dt>
                                            <dd class="font-medium text-slate-900">{{ $requirement['resource_name'] ?? $requirement['resource_key'] }}</dd>
                                        </div>
                                        <div>
                                            <dt class="text-slate-500">Host / required</dt>
                                            <dd class="font-medium text-slate-900">{{ $requirement['host_capacity'] }} / {{ $requirement['quantity'] }}</dd>
                                        </div>
                                        <div>
                                            <dt class="text-slate-500">Ceiling</dt>
                                            <dd class="font-medium text-slate-900">{{ $requirement['ceiling'] }}</dd>
                                        </div>
                                    </div>
                                @endforeach
                            </dl>
                        @endif
                    </x-ui.card>
                @empty
                    <x-ui.card>
                        <p class="text-sm text-slate-500">No active service-host assignments are available for a resource-effect summary.</p>
                    </x-ui.card>
                @endforelse
            </div>
        </section>
    </div>
</x-layouts.crm>