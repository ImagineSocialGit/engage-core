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
            addLeadOpen: @js($errors->has('first_name') || $errors->has('last_name') || $errors->has('email') || $errors->has('phone') || $errors->has('contact_status_id')),
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

            <div class="flex flex-wrap items-center gap-3">
                <x-ui.button
                    type="button"
                    variant="secondary"
                    x-on:click="addLeadOpen = ! addLeadOpen"
                >
                    Add {{ str($leadSingular)->title() }}
                </x-ui.button>

                <x-ui.button
                    href="{{ route('crm.contacts.import') }}"
                    variant="secondary"
                >
                    Import {{ str($leadPlural)->title() }}
                </x-ui.button>

                <x-ui.button
                    href="{{ route('crm.contacts.import-batches.index') }}"
                    variant="outline"
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

                <div class="flex flex-wrap items-center gap-3 border-t border-slate-200 pt-4">
                    <x-ui.button type="submit">
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

        <x-ui.card padding="none" class="overflow-hidden">
            <div class="border-b border-slate-200 px-6 py-4">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h3 class="text-lg font-semibold tracking-tight text-slate-950 capitalize">
                            All {{ $leadPlural }}
                        </h3>

                        <p class="mt-1 text-sm text-slate-500">
                            Open a {{ $leadSingular }} to review tasks, status, messages, and follow-up activity.
                        </p>
                    </div>

                    <div class="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-600">
                        {{ $contacts->total() }} total
                    </div>
                </div>
            </div>

            <div class="divide-y divide-slate-200">
                @forelse ($contacts as $contact)
                    @php
                        $displayName = $contact->name ?: trim($contact->first_name.' '.$contact->last_name) ?: $contact->email ?: str($leadSingular)->title().' #'.$contact->id;
                        $statusName = $contact->workflowProfile?->contactStatus?->name;
                    @endphp

                    <a
                        href="{{ route('crm.contacts.show', $contact) }}"
                        class="block px-6 py-4 transition hover:bg-slate-50"
                    >
                        <div class="grid gap-4 md:grid-cols-[minmax(0,1.4fr)_minmax(0,1fr)_minmax(8rem,auto)] md:items-center">
                            <div>
                                <p class="font-semibold text-slate-950">
                                    {{ $displayName }}
                                </p>

                                <p class="mt-1 text-sm text-slate-500">
                                    {{ collect([$contact->email, $contact->phone])->filter()->join(' · ') ?: 'No contact method saved' }}
                                </p>
                            </div>

                            <div>
                                <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">
                                    Current status
                                </p>

                                <p class="mt-1 text-sm font-medium text-slate-800">
                                    {{ $statusName ?: 'No status' }}
                                </p>
                            </div>

                            <div class="md:text-right">
                                <span class="inline-flex rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-600">
                                    Open profile
                                </span>
                            </div>
                        </div>
                    </a>
                @empty
                    <div class="px-6 py-10 text-center">
                        <p class="text-sm font-medium text-slate-900 capitalize">
                            No {{ $leadPlural }} yet.
                        </p>

                        <p class="mt-1 text-sm text-slate-500">
                            Add one manually or import a CSV list to get started.
                        </p>
                    </div>
                @endforelse
            </div>
        </x-ui.card>

        <div>
            {{ $contacts->links() }}
        </div>
    </div>
</x-layouts.crm>
