<x-layouts.crm
    title="Contact Maintenance"
    heading="Contact Maintenance"
    subheading="Clean up contact identity data and review records that may represent the same person."
>
    <div class="space-y-6">
        @if(session('status'))
            <div class="rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-900">
                {{ session('status') }}
            </div>
        @endif

        <section class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-7">
            <div class="flex flex-col gap-5 lg:flex-row lg:items-start lg:justify-between">
                <div class="max-w-3xl">
                    <p class="text-xs font-bold uppercase tracking-[0.14em] text-slate-500">Normalization</p>
                    <h2 class="mt-2 text-xl font-semibold tracking-tight text-slate-950">
                        Normalize contact names and phone numbers
                    </h2>
                    <p class="mt-2 text-sm leading-6 text-slate-600">
                        Names that are entirely upper- or lowercase use the existing contact-name rules. Valid phone numbers are stored in canonical international format. Intentional mixed-case names and invalid phone values are left alone.
                    </p>
                </div>

                <form
                    method="POST"
                    action="{{ route('crm.settings.contact-maintenance.normalize') }}"
                    x-data
                    x-on:submit="if (!window.confirm('Normalize contact names and phone numbers now?')) $event.preventDefault()"
                >
                    @csrf
                    <x-ui.button type="submit">
                        Run normalization for contacts
                    </x-ui.button>
                </form>
            </div>

            <dl class="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <div class="rounded-2xl bg-slate-50 px-4 py-3">
                    <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Contacts checked</dt>
                    <dd class="mt-1 text-2xl font-semibold text-slate-950">{{ number_format($normalization['contacts_examined']) }}</dd>
                </div>
                <div class="rounded-2xl bg-slate-50 px-4 py-3">
                    <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Contacts to change</dt>
                    <dd class="mt-1 text-2xl font-semibold text-slate-950">{{ number_format($normalization['contacts_changed']) }}</dd>
                </div>
                <div class="rounded-2xl bg-slate-50 px-4 py-3">
                    <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Phones to normalize</dt>
                    <dd class="mt-1 text-2xl font-semibold text-slate-950">{{ number_format($normalization['phone_numbers_changed']) }}</dd>
                </div>
                <div class="rounded-2xl bg-slate-50 px-4 py-3">
                    <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Invalid phones skipped</dt>
                    <dd class="mt-1 text-2xl font-semibold text-slate-950">{{ number_format($normalization['invalid_phone_numbers']) }}</dd>
                </div>
            </dl>
        </section>

        <section class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-7">
            <div>
                <p class="text-xs font-bold uppercase tracking-[0.14em] text-slate-500">Possible duplicates</p>
                <h2 class="mt-2 text-xl font-semibold tracking-tight text-slate-950">
                    Same-name records
                </h2>
                <p class="mt-2 text-sm leading-6 text-slate-600">
                    These are review candidates only. Nothing is merged automatically.
                </p>
            </div>

            <div class="mt-5 space-y-3">
                @forelse($duplicates['name_groups'] as $group)
                    <article class="rounded-2xl border border-slate-200 p-4">
                        <div class="flex flex-wrap items-center gap-2">
                            <h3 class="font-semibold text-slate-950">{{ $group['label'] }}</h3>
                            @if($group['different_emails'])
                                <span class="rounded-full bg-amber-50 px-2 py-1 text-xs font-semibold text-amber-800">Different emails</span>
                            @endif
                            @if($group['different_phones'])
                                <span class="rounded-full bg-amber-50 px-2 py-1 text-xs font-semibold text-amber-800">Different phones</span>
                            @endif
                        </div>

                        <div class="mt-3 divide-y divide-slate-100">
                            @foreach($group['contacts'] as $contact)
                                <div class="flex flex-col gap-1 py-2 text-sm sm:flex-row sm:items-center sm:justify-between sm:gap-4">
                                    <div class="min-w-0 text-slate-700">
                                        <span>{{ $contact->email ?: 'No email' }}</span>
                                        <span class="mx-1 text-slate-300">·</span>
                                        <span>{{ $contact->phone ?: 'No phone' }}</span>
                                    </div>
                                    <a
                                        href="{{ route('crm.contacts.show', $contact) }}"
                                        class="shrink-0 text-sm font-semibold text-slate-700 underline decoration-slate-300 underline-offset-4 hover:text-slate-950"
                                    >
                                        Open contact
                                    </a>
                                </div>
                            @endforeach
                        </div>
                    </article>
                @empty
                    <div class="rounded-2xl border border-dashed border-slate-300 px-4 py-6 text-center text-sm text-slate-600">
                        No same-name duplicate groups were found.
                    </div>
                @endforelse
            </div>

            @if($duplicates['name_group_count'] > count($duplicates['name_groups']))
                <p class="mt-4 text-xs text-slate-500">
                    Showing the first {{ count($duplicates['name_groups']) }} of {{ $duplicates['name_group_count'] }} same-name groups.
                </p>
            @endif
        </section>

        <section class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-7">
            <div>
                <p class="text-xs font-bold uppercase tracking-[0.14em] text-slate-500">Phone conflicts</p>
                <h2 class="mt-2 text-xl font-semibold tracking-tight text-slate-950">
                    More than one contact has the same normalized phone number
                </h2>
                <p class="mt-2 text-sm leading-6 text-slate-600">
                    Incoming text messages cannot be attached automatically when one phone number belongs to multiple contacts.
                </p>
            </div>

            <div class="mt-5 space-y-3">
                @forelse($duplicates['phone_groups'] as $group)
                    <article class="rounded-2xl border border-amber-200 bg-amber-50/40 p-4">
                        <h3 class="font-semibold text-slate-950">{{ $group['phone'] }}</h3>
                        <div class="mt-3 divide-y divide-amber-100">
                            @foreach($group['contacts'] as $contact)
                                <div class="flex flex-col gap-1 py-2 text-sm sm:flex-row sm:items-center sm:justify-between sm:gap-4">
                                    <div class="min-w-0 text-slate-700">
                                        <span>{{ trim($contact->first_name.' '.$contact->last_name) ?: ($contact->name ?: 'Unnamed contact') }}</span>
                                        <span class="mx-1 text-slate-300">·</span>
                                        <span>{{ $contact->email ?: 'No email' }}</span>
                                        <span class="mx-1 text-slate-300">·</span>
                                        <span>{{ $contact->phone ?: 'No phone' }}</span>
                                    </div>
                                    <a
                                        href="{{ route('crm.contacts.show', $contact) }}"
                                        class="shrink-0 text-sm font-semibold text-slate-700 underline decoration-slate-300 underline-offset-4 hover:text-slate-950"
                                    >
                                        Open contact
                                    </a>
                                </div>
                            @endforeach
                        </div>
                    </article>
                @empty
                    <div class="rounded-2xl border border-dashed border-slate-300 px-4 py-6 text-center text-sm text-slate-600">
                        No normalized phone conflicts were found.
                    </div>
                @endforelse
            </div>
        </section>

        @if(module_enabled('inbound_messaging'))
            <div class="flex justify-end">
                <a
                    href="{{ route('crm.inbound-messaging.sms-maintenance.index') }}"
                    class="text-sm font-semibold text-slate-700 underline decoration-slate-300 underline-offset-4 hover:text-slate-950"
                >
                    Repair older inbound text-message links
                </a>
            </div>
        @endif
    </div>
</x-layouts.crm>