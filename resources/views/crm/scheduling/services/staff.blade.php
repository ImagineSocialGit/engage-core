<x-layouts.crm
    :title="$title"
    :heading="$heading"
    subheading="Assign the staff or providers who can handle this appointment type. Leave it unassigned when any qualified user can schedule it without a person-specific calendar."
>
    <div class="space-y-6" data-scheduling-service-staff-workspace="{{ $service->id }}">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <a
                href="{{ route('crm.scheduling.configuration.services.edit', $service) }}"
                class="inline-flex w-full items-center justify-center rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50 sm:w-auto"
            >
                Back to appointment type
            </a>
            <a
                href="{{ route('crm.scheduling.configuration.staff.index') }}"
                class="inline-flex w-full items-center justify-center rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50 sm:w-auto"
            >
                Manage staff directory
            </a>
        </div>

        @if (session('success'))
            <x-ui.feedback.alert type="success">{{ session('success') }}</x-ui.feedback.alert>
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

        @include('crm.scheduling.partials.setup-progress', ['setupProgress' => $setupProgress])

        <x-ui.card id="staff" class="scroll-mt-6 space-y-5" data-scheduling-service-staff>
            <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <div class="flex flex-wrap items-center gap-2">
                        <div class="inline-flex rounded-full px-2 py-1 text-xs font-semibold {{ module_tone('scheduling', 'badge') }}">
                            Staff & providers
                        </div>
                        <span @class([
                            'rounded-full px-2 py-1 text-xs font-semibold',
                            'bg-orange-100 text-orange-900' => $setupProgress['staff_guidance']['recommended'],
                            'bg-slate-100 text-slate-700' => ! $setupProgress['staff_guidance']['recommended'],
                        ])>
                            {{ $setupProgress['staff_guidance']['label'] }}
                        </span>
                    </div>
                    <h2 class="mt-3 text-lg font-semibold text-slate-900">Who can handle this appointment type?</h2>
                    <p class="mt-1 max-w-2xl text-sm text-slate-500">
                        {{ $setupProgress['staff_guidance']['description'] }}
                    </p>
                </div>

                <a
                    href="{{ route('crm.scheduling.configuration.staff.index') }}"
                    class="inline-flex w-full items-center justify-center rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 sm:w-auto"
                >
                    Manage staff
                </a>
            </div>

            @if ($serviceEditable)
                <form
                    method="POST"
                    action="{{ route('crm.scheduling.configuration.services.hosts.update', $service) }}"
                    class="space-y-3"
                    data-service-assignment-form="{{ $service->id }}"
                >
                    @csrf
                    @method('PUT')
                    <input type="hidden" name="current_version" value="{{ $service->updated_at?->toISOString() }}">

                    @forelse ($assignmentRows as $row)
                        <div
                            class="grid gap-3 rounded-xl border border-slate-200 p-4 sm:grid-cols-[minmax(0,1fr)_180px] sm:items-end"
                            data-assignment-host-id="{{ $row['id'] }}"
                        >
                            <div>
                                <input type="hidden" name="assignments[{{ $loop->index }}][scheduling_host_id]" value="{{ $row['id'] }}">
                                <input type="hidden" name="assignments[{{ $loop->index }}][is_active]" value="0">

                                <label class="inline-flex items-start gap-2 text-sm font-medium text-slate-900">
                                    <input
                                        type="checkbox"
                                        name="assignments[{{ $loop->index }}][is_active]"
                                        value="1"
                                        @checked((bool) old("assignments.{$loop->index}.is_active", $row['active']))
                                        @disabled(! $row['selectable'])
                                    >
                                    <span>
                                        {{ $row['name'] }}
                                        <span class="mt-0.5 block text-xs font-normal text-slate-500">
                                            {{ str($row['status'])->replace('_', ' ')->title() }} · {{ $row['timezone'] }}
                                        </span>
                                    </span>
                                </label>
                            </div>

                            <label class="block text-sm font-medium text-slate-700">
                                Pairing capacity <span class="font-normal text-slate-400">(optional)</span>
                                <input
                                    class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-200"
                                    type="number"
                                    min="1"
                                    max="100000"
                                    name="assignments[{{ $loop->index }}][capacity_override]"
                                    value="{{ old("assignments.{$loop->index}.capacity_override", $row['capacity_override']) }}"
                                >
                                <span class="mt-1 block text-xs font-normal text-slate-500">Leave blank to use the normal appointment-type and staff limits.</span>
                            </label>

                            <input
                                type="hidden"
                                name="assignments[{{ $loop->index }}][sort_order]"
                                value="{{ $row['sort_order'] }}"
                            >
                        </div>
                    @empty
                        <div
                            class="rounded-xl border border-dashed border-slate-300 p-6 text-center text-sm text-slate-500"
                            data-configuration-empty="assignment-hosts"
                        >
                            No staff or providers have been added. This appointment type can remain unassigned.
                        </div>
                    @endforelse

                    <button
                        type="submit"
                        class="inline-flex w-full justify-center rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800 sm:w-auto"
                    >
                        Save staff assignments
                    </button>
                </form>
            @else
                <p class="text-sm text-slate-500">
                    Staff assignment for this provider-managed appointment type is read-only here.
                </p>
            @endif
        </x-ui.card>

    </div>
</x-layouts.crm>