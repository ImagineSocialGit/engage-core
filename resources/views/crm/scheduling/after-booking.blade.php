<x-layouts.crm
    :title="$title"
    :heading="$heading"
    subheading="Choose what should happen after an appointment is scheduled."
>
    <div class="space-y-6" data-scheduling-after-booking>
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <a
                href="{{ route('crm.scheduling.configuration.index') }}"
                class="inline-flex w-full items-center justify-center rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50 sm:w-auto"
                data-scheduling-after-booking-back
            >
                Back to Scheduling setup
            </a>

            @if ($afterBooking['mode'] === 'flow_routes')
                <a
                    href="{{ route('crm.flow-routes.index') }}"
                    class="inline-flex w-full items-center justify-center rounded-lg border border-teal-600 bg-white px-3 py-2 text-sm font-semibold text-teal-700 shadow-sm hover:bg-teal-50 sm:w-auto"
                    data-scheduling-after-booking-flow-routes
                >
                    Open all automations
                </a>
            @endif
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

        @if ($afterBooking['mode'] === 'flow_routes')
            <x-ui.card class="space-y-4" data-after-booking-orchestration="flow-routes">
                <div>
                    <div class="inline-flex rounded-full px-2 py-1 text-xs font-semibold {{ module_tone('flow_routes', 'badge') }}">
                        Flow Routes available
                    </div>
                    <h2 class="mt-3 text-xl font-semibold tracking-tight text-slate-900">
                        Use the full automation system when you need it
                    </h2>
                    <p class="mt-1 max-w-3xl text-sm text-slate-500">
                        These shortcuts create ordinary Flow Routes already scoped to a scheduled appointment. You can build the same automation directly from Flow Routes at any time.
                    </p>
                </div>
            </x-ui.card>

            <section class="space-y-4" data-after-booking-scope="all">
                <div>
                    <h2 class="text-lg font-semibold text-slate-900">Any appointment type</h2>
                    <p class="mt-1 text-sm text-slate-500">
                        Use this when the same follow-up should happen after every scheduled appointment.
                    </p>
                </div>

                <x-ui.card class="space-y-5">
                    <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                        @foreach ($afterBooking['global']['actions'] as $action)
                            <a
                                href="{{ $action['url'] }}"
                                class="rounded-xl border border-slate-200 p-4 transition hover:border-teal-300 hover:bg-teal-50/40"
                                data-after-booking-action="{{ $action['key'] }}"
                            >
                                <p class="font-semibold text-slate-900">{{ $action['label'] }}</p>
                                <p class="mt-1 text-sm text-slate-500">{{ $action['detail'] }}</p>
                            </a>
                        @endforeach
                    </div>

                    <div class="border-t border-slate-100 pt-4">
                        <h3 class="text-sm font-semibold text-slate-900">Existing automations</h3>

                        @forelse ($afterBooking['global']['automations'] as $automation)
                            <a
                                href="{{ $automation['url'] }}"
                                class="mt-3 flex flex-col gap-2 rounded-xl border border-slate-200 p-3 hover:bg-slate-50 sm:flex-row sm:items-center sm:justify-between"
                                data-after-booking-automation="{{ $automation['id'] }}"
                            >
                                <div>
                                    <p class="font-medium text-slate-900">{{ $automation['name'] }}</p>
                                    <p class="text-xs text-slate-500">
                                        {{ $automation['step_count'] }} {{ $automation['step_label'] }}
                                    </p>
                                </div>
                                <span class="inline-flex self-start rounded-full px-2 py-1 text-xs font-semibold {{ $automation['is_enabled'] ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-600' }}">
                                    {{ $automation['is_enabled'] ? 'Enabled' : 'Disabled' }}
                                </span>
                            </a>
                        @empty
                            <p class="mt-2 text-sm text-slate-500" data-after-booking-empty="global-automations">
                                No all-appointment automation is linked yet.
                            </p>
                        @endforelse
                    </div>
                </x-ui.card>
            </section>

            <section class="space-y-4" data-after-booking-scope="services">
                <div>
                    <h2 class="text-lg font-semibold text-slate-900">By appointment type</h2>
                    <p class="mt-1 text-sm text-slate-500">
                        Use a service-specific automation when different appointments need different follow-up.
                    </p>
                </div>

                @forelse ($afterBooking['services'] as $item)
                    <x-ui.card class="space-y-5" data-after-booking-service="{{ $item['service']->id }}">
                        <div>
                            <h3 class="text-lg font-semibold text-slate-900">{{ $item['service']->name }}</h3>
                        </div>

                        <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                            @foreach ($item['actions'] as $action)
                                <a
                                    href="{{ $action['url'] }}"
                                    class="rounded-xl border border-slate-200 p-4 transition hover:border-teal-300 hover:bg-teal-50/40"
                                    data-after-booking-action="{{ $action['key'] }}"
                                >
                                    <p class="font-semibold text-slate-900">{{ $action['label'] }}</p>
                                    <p class="mt-1 text-sm text-slate-500">{{ $action['detail'] }}</p>
                                </a>
                            @endforeach
                        </div>

                        <div class="border-t border-slate-100 pt-4">
                            <h4 class="text-sm font-semibold text-slate-900">Existing automations for this appointment type</h4>

                            @forelse ($item['automations'] as $automation)
                                <a
                                    href="{{ $automation['url'] }}"
                                    class="mt-3 flex flex-col gap-2 rounded-xl border border-slate-200 p-3 hover:bg-slate-50 sm:flex-row sm:items-center sm:justify-between"
                                    data-after-booking-automation="{{ $automation['id'] }}"
                                >
                                    <div>
                                        <p class="font-medium text-slate-900">{{ $automation['name'] }}</p>
                                        <p class="text-xs text-slate-500">
                                            {{ $automation['step_count'] }} {{ $automation['step_label'] }}
                                        </p>
                                    </div>
                                    <span class="inline-flex self-start rounded-full px-2 py-1 text-xs font-semibold {{ $automation['is_enabled'] ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-600' }}">
                                        {{ $automation['is_enabled'] ? 'Enabled' : 'Disabled' }}
                                    </span>
                                </a>
                            @empty
                                <p class="mt-2 text-sm text-slate-500" data-after-booking-empty="service-automations">
                                    No service-specific automation is linked yet.
                                </p>
                            @endforelse
                        </div>
                    </x-ui.card>
                @empty
                    <x-ui.card>
                        <p class="text-sm text-slate-500">Add an active appointment type before creating service-specific follow-up.</p>
                    </x-ui.card>
                @endforelse
            </section>
        @elseif ($afterBooking['mode'] === 'simple')
            <x-ui.card class="space-y-4" data-after-booking-orchestration="simple">
                <div>
                    <div class="inline-flex rounded-full px-2 py-1 text-xs font-semibold {{ module_tone('scheduling', 'badge') }}">
                        Simple follow-up
                    </div>
                    <h2 class="mt-3 text-xl font-semibold tracking-tight text-slate-900">
                        Keep the fallback small and predictable
                    </h2>
                    <p class="mt-1 max-w-3xl text-sm text-slate-500">
                        Without Flow Routes, Scheduling can handle a few common follow-up actions directly. It does not duplicate branching, waits, campaigns, or message automation here.
                    </p>
                </div>
            </x-ui.card>

            <section class="space-y-4">
                @forelse ($afterBooking['services'] as $item)
                    <x-ui.card class="space-y-5" data-after-booking-service="{{ $item['service']->id }}">
                        <div>
                            <h2 class="text-lg font-semibold text-slate-900">{{ $item['service']->name }}</h2>
                            <p class="mt-1 text-sm text-slate-500">
                                What should happen after this appointment type is scheduled?
                            </p>
                        </div>

                        <form
                            method="POST"
                            action="{{ route('crm.scheduling.configuration.after-booking.update', $item['service']) }}"
                            class="space-y-5"
                            data-after-booking-simple-form="{{ $item['service']->id }}"
                        >
                            @csrf
                            @method('PUT')

                            <div class="grid gap-3 md:grid-cols-2">
                                <label class="flex items-start gap-3 rounded-xl border border-slate-200 p-4">
                                    <input
                                        class="mt-1"
                                        type="radio"
                                        name="mode"
                                        value="manual"
                                        @checked($item['configuration']['mode'] === 'manual')
                                    >
                                    <span>
                                        <span class="block font-semibold text-slate-900">Follow up manually</span>
                                        <span class="mt-1 block text-sm text-slate-500">Do not create an automatic follow-up action.</span>
                                    </span>
                                </label>

                                <label class="flex items-start gap-3 rounded-xl border border-slate-200 p-4">
                                    <input
                                        class="mt-1"
                                        type="radio"
                                        name="mode"
                                        value="simple"
                                        @checked($item['configuration']['mode'] === 'simple')
                                    >
                                    <span>
                                        <span class="block font-semibold text-slate-900">Use simple automatic follow-up</span>
                                        <span class="mt-1 block text-sm text-slate-500">Choose one or more of the available actions below.</span>
                                    </span>
                                </label>
                            </div>

                            <div class="grid gap-4 lg:grid-cols-3">
                                <label class="block rounded-xl border border-slate-200 p-4 text-sm font-medium text-slate-700">
                                    Add tag
                                    <input
                                        class="mt-2 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-200"
                                        name="tag"
                                        value="{{ $item['configuration']['tag'] }}"
                                        placeholder="appointment:booked"
                                    >
                                    <span class="mt-2 block text-xs font-normal text-slate-500">
                                        Optional. Tags are additive and do not replace the Contact's lifecycle status.
                                    </span>
                                </label>

                                @if ($afterBooking['workflow_available'])
                                    <label class="block rounded-xl border border-slate-200 p-4 text-sm font-medium text-slate-700">
                                        Change status
                                        <select
                                            class="mt-2 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-200"
                                            name="contact_status_key"
                                        >
                                            <option value="">Do not change status</option>
                                            @foreach ($afterBooking['status_options'] as $option)
                                                <option
                                                    value="{{ $option['value'] }}"
                                                    @selected($item['configuration']['contact_status_key'] === $option['value'])
                                                >
                                                    {{ $option['label'] }}
                                                </option>
                                            @endforeach
                                        </select>
                                    </label>
                                @endif

                                @if ($afterBooking['tasks_available'])
                                    <label class="block rounded-xl border border-slate-200 p-4 text-sm font-medium text-slate-700">
                                        Create follow-up task
                                        <select
                                            class="mt-2 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-200"
                                            name="task_template_key"
                                        >
                                            <option value="">Do not create a task</option>
                                            @foreach ($afterBooking['task_template_options'] as $option)
                                                <option
                                                    value="{{ $option['value'] }}"
                                                    @selected($item['configuration']['task_template_key'] === $option['value'])
                                                >
                                                    {{ $option['label'] }}
                                                </option>
                                            @endforeach
                                        </select>
                                        <span class="mt-2 block text-xs font-normal text-slate-500">
                                            Uses the selected Task Template's normal assignment and due-date defaults.
                                        </span>
                                    </label>
                                @endif
                            </div>

                            <div class="flex justify-end">
                                <button
                                    type="submit"
                                    class="inline-flex w-full items-center justify-center rounded-lg bg-teal-700 px-4 py-2 text-sm font-semibold text-white hover:bg-teal-800 sm:w-auto"
                                >
                                    Save follow-up
                                </button>
                            </div>
                        </form>
                    </x-ui.card>
                @empty
                    <x-ui.card>
                        <p class="text-sm text-slate-500">Add an active appointment type before configuring after-booking follow-up.</p>
                    </x-ui.card>
                @endforelse
            </section>
        @else
            <x-ui.card>
                <p class="text-sm text-slate-500">
                    After-booking configuration is not available in the current module setup.
                </p>
            </x-ui.card>
        @endif
    </div>
</x-layouts.crm>