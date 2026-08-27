@php
    $timezone = config('client.timezone', config('app.timezone', 'UTC'));
    $reviewLabel = str($submission['review_status'])->replace('_', ' ')->title();
@endphp

<x-layouts.crm
    :title="$title"
    :heading="$heading"
    :subheading="$subheading"
    module="forms"
>
    <div class="space-y-6" data-form-submission-detail data-form-submission-id="{{ $submission['id'] }}">
        <div class="flex flex-wrap gap-3 text-sm font-semibold">
            <a href="{{ route('crm.forms.index') }}" class="text-slate-600 hover:text-slate-950">Forms</a>
            <span class="text-slate-300">/</span>
            <a
                href="{{ route('crm.forms.submissions.index', ['formDefinition' => $submission['form']['key']]) }}"
                class="text-slate-600 hover:text-slate-950"
            >
                {{ $submission['form']['name'] }}
            </a>
        </div>

        <section class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
            <div class="grid gap-5 lg:grid-cols-[minmax(0,1fr)_auto] lg:items-start">
                <div>
                    <div class="flex flex-wrap items-center gap-2">
                        <h2 class="text-xl font-semibold text-slate-950">
                            {{ $submission['contact_name'] ?: $submission['contact_email'] ?: 'Unlinked submission' }}
                        </h2>
                        <span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-600 ring-1 ring-slate-200">
                            {{ $reviewLabel }}
                        </span>
                    </div>
                    <p class="mt-2 text-sm text-slate-500">
                        Submitted {{ $submission['submitted_at']?->setTimezone($timezone)->format('M j, Y g:i A') }}
                    </p>
                </div>

                <dl class="grid grid-cols-2 gap-2 text-sm sm:grid-cols-3">
                    <div class="rounded-2xl bg-slate-50 px-4 py-3 ring-1 ring-slate-200">
                        <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Version</dt>
                        <dd class="mt-1 font-semibold text-slate-950">{{ $submission['version'] ?? '—' }}</dd>
                    </div>
                    <div class="rounded-2xl bg-slate-50 px-4 py-3 ring-1 ring-slate-200">
                        <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Source</dt>
                        <dd class="mt-1 font-semibold text-slate-950">{{ $submission['source'] ?: '—' }}</dd>
                    </div>
                    <div class="rounded-2xl bg-slate-50 px-4 py-3 ring-1 ring-slate-200">
                        <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Provider</dt>
                        <dd class="mt-1 font-semibold text-slate-950">{{ $submission['provider'] ?: '—' }}</dd>
                    </div>
                </dl>
            </div>

            <p class="mt-5 border-t border-slate-100 pt-4 text-xs leading-5 text-slate-500">
                Review status is informational here. This screen does not approve, reject, or otherwise change the submission.
            </p>
        </section>

        @if($submission['contact'] !== null)
            <section class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6" data-form-submission-contact>
                <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <h2 class="text-lg font-semibold text-slate-950">Contact</h2>
                        <p class="mt-2 font-semibold text-slate-900">{{ $submission['contact']['name'] }}</p>
                        @if(filled($submission['contact']['email']))
                            <p class="mt-1 text-sm text-slate-600">{{ $submission['contact']['email'] }}</p>
                        @endif
                        @if(filled($submission['contact']['phone']))
                            <p class="mt-1 text-sm text-slate-600">{{ $submission['contact']['phone'] }}</p>
                        @endif
                    </div>

                    <a
                        href="{{ route('crm.contacts.show', ['contact' => $submission['contact']['id']]) }}"
                        class="inline-flex items-center rounded-xl px-4 py-2 text-sm font-semibold ring-1 transition {{ module_tone('forms', 'badge') }} hover:brightness-95"
                        data-form-submission-contact-link
                    >
                        Open contact
                    </a>
                </div>
            </section>
        @endif

        <section class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
            <h2 class="text-lg font-semibold text-slate-950">Submitted answers</h2>
            <dl class="mt-4 divide-y divide-slate-100">
                @forelse($submission['values'] as $value)
                    <div class="grid gap-1 py-4 sm:grid-cols-[minmax(10rem,0.4fr)_minmax(0,1fr)] sm:gap-6" data-submission-value-key="{{ $value['key'] }}">
                        <dt class="text-sm font-semibold text-slate-600">{{ $value['label'] }}</dt>
                        <dd class="whitespace-pre-wrap break-words text-sm text-slate-950">{{ $value['display_value'] }}</dd>
                    </div>
                @empty
                    <div class="py-4 text-sm text-slate-500" data-form-submission-values-empty>No normalized answers were stored.</div>
                @endforelse
            </dl>
        </section>

        @if($submission['consents'] !== [])
            <section class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6" data-form-submission-consents>
                <h2 class="text-lg font-semibold text-slate-950">Submitted consent choices</h2>
                <div class="mt-4 grid gap-3 md:grid-cols-2">
                    @foreach($submission['consents'] as $consent)
                        <div class="rounded-2xl bg-slate-50 p-4 ring-1 ring-slate-200" data-consent-field="{{ $consent['field'] }}">
                            <div class="flex items-start justify-between gap-4">
                                <div>
                                    <p class="font-semibold text-slate-950">{{ $consent['label'] }}</p>
                                    <p class="mt-1 text-xs text-slate-500">{{ ucfirst($consent['channel']) }} · {{ str($consent['purpose'])->replace('_', ' ')->title() }}</p>
                                </div>
                                <span class="rounded-full px-2.5 py-1 text-xs font-bold ring-1 {{ $consent['accepted'] ? 'bg-emerald-100 text-emerald-800 ring-emerald-200' : 'bg-slate-100 text-slate-600 ring-slate-200' }}">
                                    {{ $consent['accepted'] ? 'Accepted' : 'Not accepted' }}
                                </span>
                            </div>
                        </div>
                    @endforeach
                </div>
            </section>
        @endif

        <section class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6" data-form-submission-verification>
            <h2 class="text-lg font-semibold text-slate-950">Human verification</h2>
            @if($submission['verification'] === null)
                <p class="mt-3 text-sm leading-6 text-slate-600" data-verification-none>No normalized human-verification evidence was stored for this submission.</p>
            @else
                <dl class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3" data-verification-provider="{{ $submission['verification']['provider'] }}">
                    <div class="rounded-2xl bg-slate-50 p-4 ring-1 ring-slate-200">
                        <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Outcome</dt>
                        <dd class="mt-1 font-semibold text-slate-950">{{ str($submission['verification']['outcome'] ?? 'unknown')->title() }}</dd>
                    </div>
                    <div class="rounded-2xl bg-slate-50 p-4 ring-1 ring-slate-200">
                        <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Provider</dt>
                        <dd class="mt-1 font-semibold text-slate-950">{{ $submission['verification']['provider'] ?? '—' }}</dd>
                    </div>
                    <div class="rounded-2xl bg-slate-50 p-4 ring-1 ring-slate-200">
                        <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Hostname</dt>
                        <dd class="mt-1 break-words font-semibold text-slate-950">{{ $submission['verification']['hostname'] ?? '—' }}</dd>
                    </div>
                    @if(filled($submission['verification']['verified_at'] ?? null))
                        <div class="rounded-2xl bg-slate-50 p-4 ring-1 ring-slate-200">
                            <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Verified</dt>
                            <dd class="mt-1 font-semibold text-slate-950">{{ \Carbon\CarbonImmutable::parse($submission['verification']['verified_at'])->setTimezone($timezone)->format('M j, Y g:i A') }}</dd>
                        </div>
                    @endif
                    @if(filled($submission['verification']['action'] ?? null))
                        <div class="rounded-2xl bg-slate-50 p-4 ring-1 ring-slate-200">
                            <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Action</dt>
                            <dd class="mt-1 font-semibold text-slate-950">{{ $submission['verification']['action'] }}</dd>
                        </div>
                    @endif
                </dl>
            @endif
        </section>
    </div>
</x-layouts.crm>