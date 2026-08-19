<x-layouts.crm>
    <div class="mx-auto w-full max-w-6xl space-y-6 px-4 py-6 sm:px-6 lg:px-8">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div class="min-w-0">
                <div class="text-xs font-bold uppercase tracking-[0.18em] text-amber-700">Development only</div>
                <h1 class="mt-2 break-words text-3xl font-bold tracking-tight text-slate-950">Campaign Simulator</h1>
                <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-600">
                    Run a real Campaign MessageChain against an existing test contact with a fake clock. Simulator work is isolated from normal background progression and local message delivery goes only to the development message sink.
                </p>
            </div>

            <a
                href="{{ route('crm.campaigns.index') }}"
                class="inline-flex min-h-11 w-full shrink-0 items-center justify-center rounded-full border border-slate-300 bg-white px-5 text-sm font-bold text-slate-800 hover:bg-slate-50 sm:w-auto"
            >
                Back to Campaigns
            </a>
        </div>

        @if(session('status'))
            <div class="rounded-2xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-900">
                {{ session('status') }}
            </div>
        @endif

        @if(session('error'))
            <div class="rounded-2xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-900">
                {{ session('error') }}
            </div>
        @endif

        @if($errors->any())
            <div class="rounded-2xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-900">
                <ul class="list-disc space-y-1 pl-5">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <section class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
            <div class="border-b border-slate-200 p-5 sm:p-6">
                <h2 class="text-lg font-semibold text-slate-950">Start a simulation</h2>
                <p class="mt-1 text-sm text-slate-600">Use a test contact. A contact with a real open enrollment in the selected Campaign cannot be used.</p>
            </div>

            <form method="POST" action="{{ route('crm.campaigns.simulator.store') }}" class="grid gap-5 p-5 sm:p-6 lg:grid-cols-3">
                @csrf

                <label class="block min-w-0">
                    <span class="text-sm font-semibold text-slate-900">Campaign</span>
                    <select name="campaign_id" required class="mt-2 min-h-11 w-full rounded-xl border border-slate-300 bg-white px-3 text-sm">
                        <option value="">Choose a Campaign</option>
                        @foreach($campaigns as $campaign)
                            <option value="{{ $campaign->id }}" @selected((string) old('campaign_id') === (string) $campaign->id)>
                                {{ $campaign->name }}
                            </option>
                        @endforeach
                    </select>
                </label>

                <label class="block min-w-0">
                    <span class="text-sm font-semibold text-slate-900">Test contact</span>
                    <select name="contact_id" required class="mt-2 min-h-11 w-full rounded-xl border border-slate-300 bg-white px-3 text-sm">
                        <option value="">Choose a contact</option>
                        @foreach($contacts as $contact)
                            @php
                                $contactLabel = $contact->name ?: trim($contact->first_name.' '.$contact->last_name) ?: $contact->email ?: $contact->phone ?: 'Contact #'.$contact->id;
                            @endphp
                            <option value="{{ $contact->id }}" @selected((string) old('contact_id') === (string) $contact->id)>
                                {{ $contactLabel }}{{ $contact->email ? ' · '.$contact->email : '' }}
                            </option>
                        @endforeach
                    </select>
                    <span class="mt-1 block text-xs text-slate-500">Shows the 250 most recently created contacts.</span>
                </label>

                <label class="block min-w-0">
                    <span class="text-sm font-semibold text-slate-900">Fake start time</span>
                    <input
                        type="datetime-local"
                        name="fake_now"
                        value="{{ old('fake_now', $defaultFakeNow) }}"
                        required
                        class="mt-2 min-h-11 w-full rounded-xl border border-slate-300 bg-white px-3 text-sm"
                    >
                    <span class="mt-1 block text-xs text-slate-500">{{ $timezone }}</span>
                </label>

                <div class="lg:col-span-3">
                    <button type="submit" class="inline-flex min-h-11 w-full items-center justify-center rounded-full bg-slate-950 px-6 text-sm font-bold text-white hover:bg-slate-800 sm:w-auto">
                        Start simulation
                    </button>
                </div>
            </form>
        </section>

        <section class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
            <div class="border-b border-slate-200 p-5 sm:p-6">
                <h2 class="text-lg font-semibold text-slate-950">Simulation runs</h2>
                <p class="mt-1 text-sm text-slate-600">Reset completed experiments when you are done so development data stays easy to reason about.</p>
            </div>

            <div class="divide-y divide-slate-200">
                @forelse($runs as $run)
                    <div class="flex flex-col gap-4 p-5 sm:flex-row sm:items-center sm:justify-between sm:p-6">
                        <div class="min-w-0">
                            <div class="font-semibold text-slate-950">{{ $run->campaign?->name ?? $run->campaign_key }}</div>
                            <div class="mt-1 break-words text-sm text-slate-600">
                                {{ $run->contact?->name ?: trim(($run->contact?->first_name ?? '').' '.($run->contact?->last_name ?? '')) ?: $run->contact?->email ?: 'Contact #'.$run->contact_id }}
                            </div>
                            <div class="mt-1 text-xs text-slate-500">
                                Fake time: {{ data_get($run->meta, 'testing_tool.current_at') }} · Chain: {{ $run->messageChainEnrollment?->status ?? 'missing' }}
                            </div>
                        </div>

                        <a
                            href="{{ route('crm.campaigns.simulator.show', $run) }}"
                            class="inline-flex min-h-11 w-full shrink-0 items-center justify-center rounded-full border border-slate-300 bg-white px-5 text-sm font-bold text-slate-800 hover:bg-slate-50 sm:w-auto"
                        >
                            Open run
                        </a>
                    </div>
                @empty
                    <div class="p-6 text-sm text-slate-500">No simulator runs yet.</div>
                @endforelse
            </div>
        </section>
    </div>
</x-layouts.crm>