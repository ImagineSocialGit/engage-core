
@php
    $forms = collect($overview['forms'] ?? []);
    $outcomeLabels = [
        'contact_upsert' => 'Create or update the contact',
        'contact_tags' => 'Apply the mapped contact tags',
        'submission_review' => 'Save the submission for review',
        'consent_record' => 'Record the submitted channel consent',
    ];
@endphp

<x-layouts.crm
    :title="$title"
    :heading="$heading"
    :subheading="$subheading"
    module="forms"
>
    <div class="space-y-6" data-forms-surface>
        <section class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
            <div class="grid gap-5 p-5 sm:p-8 lg:grid-cols-[minmax(0,1fr)_auto] lg:items-end">
                <div>
                    <p class="text-sm font-semibold uppercase tracking-[0.16em] text-slate-600">
                        Published intake
                    </p>
                    <h2 class="mt-2 text-2xl font-semibold tracking-tight text-slate-950">
                        Forms currently accepting submissions
                    </h2>
                    <p class="mt-3 max-w-3xl text-sm leading-6 text-slate-600">
                        Availability comes from the active external-intake contract. Successful-submission steps come from each form’s current published version.
                    </p>
                </div>

                <dl class="grid grid-cols-2 gap-2">
                    <div class="min-w-28 rounded-2xl px-4 py-3 text-center ring-1 {{ module_tone('forms', 'item') }}">
                        <dd class="text-2xl font-semibold text-slate-950">{{ (int) ($overview['form_count'] ?? 0) }}</dd>
                        <dt class="text-xs font-medium text-slate-500">accepting forms</dt>
                    </div>
                    <div class="min-w-28 rounded-2xl px-4 py-3 text-center ring-1 {{ module_tone('forms', 'item') }}">
                        <dd class="text-2xl font-semibold text-slate-950">{{ (int) ($overview['domain_count'] ?? 0) }}</dd>
                        <dt class="text-xs font-medium text-slate-500">public domains</dt>
                    </div>
                </dl>
            </div>
        </section>

        @if(! ($overview['external_intake_enabled'] ?? false))
            <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8" data-forms-state="disabled">
                <h2 class="text-lg font-semibold text-slate-950">External form intake is disabled</h2>
                <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-600">
                    No public form is currently accepting submissions through an external site.
                </p>
            </section>
        @elseif(! ($overview['configuration_valid'] ?? false))
            <section class="rounded-3xl border border-amber-200 bg-amber-50 p-6 shadow-sm sm:p-8" data-forms-state="configuration-invalid">
                <h2 class="text-lg font-semibold text-amber-950">Forms availability cannot be confirmed</h2>
                <p class="mt-2 max-w-2xl text-sm leading-6 text-amber-800">
                    The external-intake configuration is invalid. Run setup validation for the exact Forms finding.
                </p>
            </section>
        @elseif($forms->isEmpty())
            <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8" data-forms-state="empty">
                <h2 class="text-lg font-semibold text-slate-950">No form is accepting submissions</h2>
                <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-600">
                    A form appears here only when it is active, public, currently published, runtime-valid, and allowed for a configured intake client.
                </p>
            </section>
        @else
            <section class="grid gap-5 xl:grid-cols-2">
                @foreach($forms as $form)
                    <article
                        class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6"
                        data-form-key="{{ $form['key'] }}"
                    >
                        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                            <div>
                                <div class="flex flex-wrap items-center gap-2">
                                    <h2 class="text-xl font-semibold tracking-tight text-slate-950">{{ $form['name'] }}</h2>
                                    <span class="rounded-full bg-emerald-100 px-2.5 py-1 text-xs font-bold text-emerald-800 ring-1 ring-emerald-200">
                                        Accepting submissions
                                    </span>
                                </div>
                                @if(filled($form['description'] ?? null))
                                    <p class="mt-2 text-sm leading-6 text-slate-600">{{ $form['description'] }}</p>
                                @endif
                            </div>

                            <span class="shrink-0 rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-600 ring-1 ring-slate-200">
                                Version {{ (int) $form['version'] }}
                            </span>
                        </div>

                        <div class="mt-6 grid gap-5 md:grid-cols-2">
                            <section aria-label="Available domains">
                                <h3 class="text-xs font-bold uppercase tracking-[0.14em] text-slate-500">Available on</h3>
                                <div class="mt-3 flex flex-wrap gap-2">
                                    @forelse($form['domains'] as $domain)
                                        <a
                                            href="https://{{ $domain }}"
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            class="rounded-full px-3 py-1.5 text-sm font-semibold ring-1 transition {{ module_tone('forms', 'badge') }} hover:brightness-95"
                                        >
                                            {{ $domain }}
                                        </a>
                                    @empty
                                        <span class="text-sm leading-6 text-slate-500">No public domain configured.</span>
                                    @endforelse
                                </div>
                            </section>

                            <section aria-label="Successful submission behavior">
                                <h3 class="text-xs font-bold uppercase tracking-[0.14em] text-slate-500">After successful submission</h3>
                                <ol class="mt-3 space-y-2">
                                    @foreach($form['outcome_keys'] as $outcomeKey)
                                        <li class="flex gap-2 text-sm leading-5 text-slate-700">
                                            <span class="mt-2 h-1.5 w-1.5 shrink-0 rounded-full bg-teal-500"></span>
                                            <span>{{ $outcomeLabels[$outcomeKey] ?? $outcomeKey }}</span>
                                        </li>
                                    @endforeach
                                </ol>
                            </section>
                        </div>

                        <section
                            class="mt-6 border-t border-slate-100 pt-5"
                            data-form-after-submission="{{ $form['key'] }}"
                            data-form-after-submission-mode="{{ ($form['after_submission']['available'] ?? false) ? 'automation-available' : 'manual-only' }}"
                        >
                            <div>
                                <h3 class="text-sm font-semibold text-slate-950">What should happen after someone submits this form?</h3>
                                <p class="mt-1 text-sm leading-6 text-slate-600">
                                    Manual follow-up is always available. Add automatic behavior only when it removes real follow-up work.
                                </p>
                            </div>

                            <div class="mt-4 rounded-2xl border border-slate-200 bg-slate-50 p-4" data-form-manual-follow-up>
                                <div class="text-sm font-semibold text-slate-950">Follow up manually</div>
                                <p class="mt-1 text-xs leading-5 text-slate-600">
                                    Keep the submission in the review queue and let a person decide what happens next.
                                </p>
                            </div>

                            @if(($form['after_submission']['automations'] ?? []) !== [])
                                <div class="mt-4 space-y-2" data-form-linked-automations>
                                    @foreach($form['after_submission']['automations'] as $automation)
                                        <a
                                            href="{{ $automation['url'] }}"
                                            class="flex items-center justify-between gap-3 rounded-2xl border border-orange-200 bg-orange-50/60 px-4 py-3 transition hover:bg-orange-50"
                                            data-form-linked-automation="{{ $automation['id'] }}"
                                        >
                                            <span class="min-w-0">
                                                <span class="block truncate text-sm font-semibold text-slate-950">{{ $automation['name'] }}</span>
                                                <span class="mt-0.5 block text-xs text-slate-600">
                                                    {{ $automation['step_count'] }} {{ \Illuminate\Support\Str::plural('step', $automation['step_count']) }} · {{ $automation['is_enabled'] ? 'On' : 'Off' }}
                                                </span>
                                            </span>
                                            <span class="shrink-0 text-xs font-semibold text-orange-800">Edit</span>
                                        </a>
                                    @endforeach
                                </div>
                            @endif

                            @if($form['after_submission']['available'] ?? false)
                                @if(! ($form['after_submission']['contact_available'] ?? false))
                                    <div class="mt-4 rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm leading-6 text-amber-900" data-form-automation-contact-required>
                                        Automatic follow-up starts from a Contact. Add Contact mapping to this form before creating a form-submission automation.
                                    </div>
                                @else
                                    <div class="mt-4 grid gap-3 sm:grid-cols-2" data-form-automation-actions>
                                        @foreach($form['after_submission']['actions'] as $action)
                                            <a
                                                href="{{ $action['url'] }}"
                                                class="rounded-2xl p-4 ring-1 transition hover:-translate-y-0.5 hover:shadow-sm {{ module_tone($action['module_key'], 'item') }}"
                                                data-form-automation-action="{{ $action['key'] }}"
                                            >
                                                <span class="block text-sm font-semibold text-slate-950">{{ $action['label'] }}</span>
                                                <span class="mt-1 block text-xs leading-5 text-slate-600">{{ $action['detail'] }}</span>
                                            </a>
                                        @endforeach
                                    </div>
                                @endif
                            @else
                                <p class="mt-4 text-xs leading-5 text-slate-500" data-form-automation-unavailable>
                                    Automatic follow-up is not available in this installation.
                                </p>
                            @endif
                        </section>

                        <div class="mt-6 border-t border-slate-100 pt-5">
                            <a
                                href="{{ route('crm.forms.submissions.index', ['formDefinition' => $form['key']]) }}"
                                class="inline-flex items-center rounded-xl px-4 py-2 text-sm font-semibold ring-1 transition {{ module_tone('forms', 'badge') }} hover:brightness-95"
                                data-form-submissions-link="{{ $form['key'] }}"
                            >
                                View submissions
                            </a>
                        </div>
                    </article>
                @endforeach
            </section>
        @endif
    </div>
</x-layouts.crm>