@php
    $leadSingular = config('contacts.labels.singular');
    $leadPlural = config('contacts.labels.plural');
@endphp

<x-layouts.crm
    :title="str($leadPlural)->title()"
    :heading="str($leadPlural)->title()"
    subheading="Find the right lead and choose the next step."
>
    <div
        class="space-y-6"
        x-data="{
            addLeadOpen: @js($errors->has('first_name') || $errors->has('last_name') || $errors->has('email') || $errors->has('phone') || $errors->has('contact_status_id') || $errors->has('existing_relationship_confirmed')),
            moreFiltersOpen: @js(($contactFilters['secondary_active_count'] ?? 0) > 0),
        }"
    >
        @if (session('success'))
            <x-ui.feedback.alert type="success">
                {{ session('success') }}
            </x-ui.feedback.alert>
        @endif

        @error('csv')
            <div class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-semibold text-red-700">
                {{ $message }}
            </div>
        @enderror

        <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_auto] lg:items-start">
            <div>
                <h2 class="text-2xl font-semibold tracking-tight text-slate-950 capitalize">
                    Work your {{ $leadPlural }}
                </h2>

                <p class="mt-1 max-w-2xl text-sm text-slate-500">
                    Review who needs attention, open a {{ $leadSingular }}, and update the next step from their profile.
                </p>
            </div>

            <div class="grid gap-2 sm:flex sm:flex-wrap sm:items-center sm:gap-3">
                <x-ui.button
                    type="button"
                    variant="secondary"
                    class="w-full sm:w-auto"
                    x-on:click="addLeadOpen = ! addLeadOpen"
                >
                    Add {{ str($leadSingular)->title() }}
                </x-ui.button>

                <x-ui.button
                    href="{{ route('crm.contacts.import') }}"
                    variant="secondary"
                    class="w-full sm:w-auto"
                >
                    Import {{ str($leadPlural)->title() }}
                </x-ui.button>

                <x-ui.button
                    href="{{ route('crm.contacts.import-batches.index') }}"
                    variant="outline"
                    class="w-full sm:w-auto"
                >
                    View Imports
                </x-ui.button>
            </div>
        </div>

        <x-ui.card
            x-cloak
            x-show="addLeadOpen"
            class="space-y-5"
        >
            <div>
                <h3 class="text-lg font-semibold tracking-tight text-slate-950 capitalize">
                    Add a {{ $leadSingular }}
                </h3>

                <p class="mt-1 text-sm text-slate-500">
                    Add one person manually. Use Import {{ str($leadPlural)->title() }} when you have a CSV list.
                </p>
            </div>

            <form method="POST" action="{{ route('crm.contacts.store') }}" class="space-y-4">
                @csrf

                <div class="grid gap-4 md:grid-cols-2">
                    <div>
                        <x-ui.form.label for="first_name">
                            First name
                        </x-ui.form.label>

                        <x-ui.form.input
                            id="first_name"
                            name="first_name"
                            value="{{ old('first_name') }}"
                        />

                        <x-ui.form.error name="first_name" />
                    </div>

                    <div>
                        <x-ui.form.label for="last_name">
                            Last name
                        </x-ui.form.label>

                        <x-ui.form.input
                            id="last_name"
                            name="last_name"
                            value="{{ old('last_name') }}"
                        />

                        <x-ui.form.error name="last_name" />
                    </div>
                </div>

                <div class="grid gap-4 md:grid-cols-2">
                    <div>
                        <x-ui.form.label for="email">
                            Email
                        </x-ui.form.label>

                        <x-ui.form.input
                            id="email"
                            type="email"
                            name="email"
                            value="{{ old('email') }}"
                            required
                        />

                        <x-ui.form.error name="email" />
                    </div>

                    <div>
                        <x-ui.form.label for="phone">
                            Phone
                        </x-ui.form.label>

                        <x-ui.form.input
                            id="phone"
                            name="phone"
                            value="{{ old('phone') }}"
                        />

                        <x-ui.form.error name="phone" />
                    </div>
                </div>

                @if(module_enabled('workflow'))
                    <div>
                        <x-ui.form.label for="contact_status_id">
                            Starting status
                        </x-ui.form.label>

                        <x-ui.form.select id="contact_status_id" name="contact_status_id">
                            <option value="">Use default status</option>

                            @foreach ($contactStatuses as $status)
                                <option
                                    value="{{ $status->id }}"
                                    @selected((string) old('contact_status_id') === (string) $status->id)
                                >
                                    {{ $status->name }}
                                </option>
                            @endforeach
                        </x-ui.form.select>

                        <x-ui.form.error name="contact_status_id" />
                    </div>
                @endif

                @if($messagingAvailable)
                    <div class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-4">
                        <p class="text-sm font-semibold text-amber-950">
                            Only add people who already have a relationship with your organization or have expressed interest in hearing from you.
                        </p>

                        <p class="mt-1 text-sm text-amber-800">
                            Engage is not intended for unsolicited marketing. Do not add someone for the purpose of sending marketing email or text unless they have previously contacted you, requested information, done business with you, or otherwise expressed interest.
                        </p>

                        <label class="mt-4 flex items-start gap-3 text-sm text-amber-950">
                            <input
                                type="checkbox"
                                name="existing_relationship_confirmed"
                                value="1"
                                class="mt-0.5 h-4 w-4 rounded border-amber-300 text-slate-900 focus:ring-slate-500"
                                @checked(old('existing_relationship_confirmed'))
                                required
                            >

                            <span>
                                I confirm this person has an existing relationship with us or has expressed interest in hearing from us. Creating a new contact will record transactional and marketing permission for their saved contact methods supported by Messaging. Any later opt-out remains authoritative.
                            </span>
                        </label>

                        <x-ui.form.error name="existing_relationship_confirmed" />
                    </div>
                @endif

                <div class="grid gap-3 border-t border-slate-200 pt-4 sm:flex sm:flex-wrap sm:items-center">
                    <x-ui.button type="submit" class="w-full sm:w-auto">
                        Create {{ str($leadSingular)->title() }}
                    </x-ui.button>

                    <button
                        type="button"
                        class="text-sm font-semibold text-slate-500 hover:text-slate-900"
                        x-on:click="addLeadOpen = false"
                    >
                        Cancel
                    </button>
                </div>
            </form>
        </x-ui.card>

        <x-ui.card class="space-y-4">
            <form method="GET" action="{{ route('crm.contacts.index') }}" class="space-y-4">
                <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-5 xl:items-end">
                    <div class="sm:col-span-2 xl:col-span-1">
                        <x-ui.form.label for="contact_search">
                            Search {{ $leadPlural }}
                        </x-ui.form.label>

                        <x-ui.form.input
                            id="contact_search"
                            name="search"
                            value="{{ $contactFilters['search'] }}"
                            placeholder="Name, email, or phone"
                            autocomplete="off"
                        />
                    </div>

                    @foreach($contactFilters['primary'] as $filter)
                        <div>
                            <x-ui.form.label for="contact_filter_{{ $filter['key'] }}">
                                {{ $filter['label'] }}
                            </x-ui.form.label>

                            <x-ui.form.select
                                id="contact_filter_{{ $filter['key'] }}"
                                name="{{ $filter['key'] }}"
                                x-on:change="$el.form.submit()"
                            >
                                <option value="">Any {{ str($filter['label'])->lower() }}</option>

                                @foreach($filter['options'] as $option)
                                    <option
                                        value="{{ $option['value'] }}"
                                        @selected(($filter['selected']['value'] ?? null) === $option['value'])
                                    >
                                        {{ $option['label'] }}
                                    </option>
                                @endforeach
                            </x-ui.form.select>
                        </div>
                    @endforeach

                    <div class="flex flex-wrap gap-2">
                        <x-ui.button type="submit" variant="secondary">
                            Search
                        </x-ui.button>

                        @if($contactFilters['secondary'] !== [])
                            <button
                                type="button"
                                class="inline-flex items-center rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50"
                                x-on:click="moreFiltersOpen = ! moreFiltersOpen"
                                x-bind:aria-expanded="moreFiltersOpen.toString()"
                            >
                                More filters{{ $contactFilters['secondary_active_count'] > 0 ? ' ('.$contactFilters['secondary_active_count'].')' : '' }}
                            </button>
                        @endif
                    </div>
                </div>

                @if($contactFilters['secondary'] !== [])
                    <div
                        x-cloak
                        x-show="moreFiltersOpen"
                        class="grid gap-3 border-t border-slate-200 pt-4 sm:grid-cols-2 lg:grid-cols-3"
                    >
                        @foreach($contactFilters['secondary'] as $filter)
                            <div>
                                <x-ui.form.label for="contact_filter_{{ $filter['key'] }}">
                                    {{ $filter['label'] }}
                                </x-ui.form.label>

                                <x-ui.form.select
                                    id="contact_filter_{{ $filter['key'] }}"
                                    name="{{ $filter['key'] }}"
                                    x-on:change="$el.form.submit()"
                                >
                                    <option value="">Any {{ str($filter['label'])->lower() }}</option>

                                    @foreach($filter['options'] as $option)
                                        <option
                                            value="{{ $option['value'] }}"
                                            @selected(($filter['selected']['value'] ?? null) === $option['value'])
                                        >
                                            {{ $option['label'] }}
                                        </option>
                                    @endforeach
                                </x-ui.form.select>
                            </div>
                        @endforeach
                    </div>
                @endif
            </form>

            @if($contactFilters['has_filters'])
                <div class="flex flex-wrap items-center gap-2 border-t border-slate-200 pt-4">
                    @foreach($contactFilters['active'] as $activeFilter)
                        @php
                            $removeFilterQuery = request()->query();
                            unset($removeFilterQuery[$activeFilter['key']], $removeFilterQuery['page']);
                        @endphp

                        <a
                            href="{{ route('crm.contacts.index', $removeFilterQuery) }}"
                            class="inline-flex items-center gap-2 rounded-full bg-slate-100 px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-200"
                            aria-label="Remove {{ $activeFilter['label'] }} filter"
                        >
                            <span>{{ $activeFilter['label'] }}: {{ $activeFilter['value_label'] }}</span>
                            <span aria-hidden="true">×</span>
                        </a>
                    @endforeach

                    <a
                        href="{{ route('crm.contacts.index') }}"
                        class="text-xs font-semibold text-slate-500 hover:text-slate-900"
                    >
                        Clear all
                    </a>
                </div>
            @endif
        </x-ui.card>

        <x-ui.card padding="none" class="overflow-hidden">
            <div class="border-b border-slate-200 px-4 py-4 sm:px-6">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h3 class="text-lg font-semibold tracking-tight text-slate-950 capitalize">
                            {{ $contactFilters['has_filters'] ? 'Matching '.$leadPlural : 'All '.$leadPlural }}
                        </h3>

                        <p class="mt-1 text-sm text-slate-500">
                            Open a {{ $leadSingular }} to review tasks, status, messages, and follow-up activity.
                        </p>
                    </div>

                    <div class="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-600">
                        @if($contactFilters['has_filters'])
                            {{ number_format($contacts->total()) }} matching · {{ number_format($totalContacts) }} total
                        @else
                            {{ number_format($totalContacts) }} total
                        @endif
                    </div>
                </div>
            </div>

            <div class="divide-y divide-slate-200">
                @forelse ($contacts as $contact)
                    @php
                        $displayName = $contact->name ?: trim($contact->first_name.' '.$contact->last_name) ?: $contact->email ?: str($leadSingular)->title().' #'.$contact->id;
                        $statusName = module_enabled('workflow')
                            ? $contact->workflowProfile?->contactStatus?->name
                            : null;
                    @endphp

                    <a
                        href="{{ route('crm.contacts.show', $contact) }}"
                        class="block px-4 py-4 transition hover:bg-slate-50 sm:px-6"
                    >
                        <div class="grid gap-4 {{ module_enabled('workflow') ? 'md:grid-cols-[minmax(0,1.4fr)_minmax(0,1fr)_minmax(8rem,auto)]' : 'md:grid-cols-[minmax(0,1fr)_minmax(8rem,auto)]' }} md:items-center">
                            <div>
                                <p class="font-semibold text-slate-950">
                                    {{ $displayName }}
                                </p>

                                <p class="mt-1 break-words text-sm text-slate-500">
                                    {{ collect([$contact->email, $contact->phone])->filter()->join(' · ') ?: 'No contact method saved' }}
                                </p>
                            </div>

                            @if(module_enabled('workflow'))
                                <div>
                                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">
                                        Current status
                                    </p>

                                    <p class="mt-1 text-sm font-medium text-slate-800">
                                        {{ $statusName ?: 'No status' }}
                                    </p>
                                </div>
                            @endif

                            <div class="md:text-right">
                                <span class="inline-flex rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-600">
                                    Open profile
                                </span>
                            </div>
                        </div>
                    </a>
                @empty
                    <div class="px-4 py-10 text-center sm:px-6">
                        @if($contactFilters['has_filters'])
                            <p class="text-sm font-medium text-slate-900">
                                No {{ $leadPlural }} match these filters.
                            </p>

                            <p class="mt-1 text-sm text-slate-500">
                                Change a search or filter, or clear everything to see all {{ $leadPlural }}.
                            </p>

                            <div class="mt-4">
                                <x-ui.button href="{{ route('crm.contacts.index') }}" variant="secondary">
                                    Clear filters
                                </x-ui.button>
                            </div>
                        @else
                            <p class="text-sm font-medium text-slate-900 capitalize">
                                No {{ $leadPlural }} yet.
                            </p>

                            <p class="mt-1 text-sm text-slate-500">
                                Add one manually or import a CSV list to get started.
                            </p>
                        @endif
                    </div>
                @endforelse
            </div>
        </x-ui.card>

        <div>
            {{ $contacts->links() }}
        </div>
    </div>
</x-layouts.crm>