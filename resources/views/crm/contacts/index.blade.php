<x-layouts.crm
    :title="config('contacts.labels.plural')"
    :heading="config('contacts.labels.plural')"
    :subheading="config('contacts.labels.singular').' list'"
>
    <div class="space-y-6">

        <div class="flex items-start justify-between gap-4">
            <div>
                <h2 class="text-xl font-semibold tracking-tight capitalize">
                    All {{ config('contacts.labels.plural') }}
                </h2>
            </div>

            <form
                method="POST"
                action="{{ route('crm.contacts.import.preview') }}"
                enctype="multipart/form-data"
                class="flex items-center gap-3"
            >
                @csrf

                <label class="block">
                    <span class="sr-only">Choose CSV file</span>

                    <input
                        type="file"
                        name="csv"
                        accept=".csv,text/csv"
                        required
                        class="block w-full text-sm text-slate-600 file:mr-4 file:rounded-lg file:border-0 file:bg-slate-900 file:px-4 file:py-2 file:text-sm file:font-semibold file:text-white hover:file:bg-slate-700"
                    />
                </label>

                <x-ui.button type="submit">
                    Import CSV
                </x-ui.button>
            </form>
        </div>

        @if (session('success'))
            <x-ui.feedback.alert type="success">
                {{ session('success') }}
            </x-ui.feedback.alert>
        @endif

        @error('csv')
            <p class="text-sm text-red-600">
                {{ $message }}
            </p>
        @enderror

        <x-ui.card>
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

                <div>
                    <x-ui.form.label for="contact_status_id">
                        Status
                    </x-ui.form.label>

                    <x-ui.form.select id="contact_status_id" name="contact_status_id">
                        <option value="">Default Status</option>

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

                <div>
                    <x-ui.button type="submit">
                        Create {{ config('contacts.labels.singular') }}
                    </x-ui.button>
                </div>
            </form>
        </x-ui.card>

        <x-ui.card padding="none" class="overflow-hidden">
            <div class="divide-y divide-slate-200">
                @forelse ($contacts as $contact)
                    <a
                        href="{{ route('crm.contacts.show', $contact) }}"
                        class="block px-6 py-4 transition hover:bg-slate-50"
                    >
                        <div class="flex items-center justify-between gap-4">
                            <div>
                                <p class="font-semibold text-slate-900">
                                    {{ $contact->name }}
                                </p>

                                <p class="text-sm text-slate-500">
                                    {{ $contact->email }}
                                </p>
                            </div>

                            <div class="text-sm text-slate-500">
                                {{ $contact->workflowProfile?->contactStatus?->name ?? '—' }}
                            </div>
                        </div>
                    </a>
                @empty
                    <div class="px-6 py-8 text-sm text-slate-500">
                        No {{ strtolower(config('contacts.labels.plural')) }} yet.
                    </div>
                @endforelse
            </div>
        </x-ui.card>

        <div>
            {{ $contacts->links() }}
        </div>
    </div>
</x-layouts.crm>