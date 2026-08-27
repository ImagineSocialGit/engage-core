@php
    $timezone = config('client.timezone', config('app.timezone', 'UTC'));
@endphp

<x-layouts.crm
    :title="$title"
    :heading="$heading"
    :subheading="$subheading"
    module="forms"
>
    <div class="space-y-6" data-form-submissions data-form-key="{{ $form['key'] }}">
        <div>
            <a href="{{ route('crm.forms.index') }}" class="text-sm font-semibold text-slate-600 hover:text-slate-950">
                ← Forms
            </a>
        </div>

        <section class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
            <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <p class="text-sm font-semibold uppercase tracking-[0.14em] text-slate-500">Submission history</p>
                    <h2 class="mt-1 text-xl font-semibold text-slate-950">{{ $form['name'] }}</h2>
                    @if(filled($form['description']))
                        <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-600">{{ $form['description'] }}</p>
                    @endif
                </div>
                <span class="rounded-full bg-slate-100 px-3 py-1.5 text-xs font-semibold text-slate-600 ring-1 ring-slate-200">
                    {{ $submissions->total() }} {{ $submissions->total() === 1 ? 'submission' : 'submissions' }}
                </span>
            </div>
        </section>

        @if($submissions->isEmpty())
            <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8" data-form-submissions-empty>
                <h2 class="text-lg font-semibold text-slate-950">No submissions yet</h2>
                <p class="mt-2 text-sm leading-6 text-slate-600">New successful submissions will appear here.</p>
            </section>
        @else
            <section class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
                <div class="divide-y divide-slate-100">
                    @foreach($submissions as $submission)
                        <a
                            href="{{ route('crm.forms.submissions.show', ['formSubmission' => $submission['id']]) }}"
                            class="grid gap-3 p-5 transition hover:bg-slate-50 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-center sm:p-6"
                            data-form-submission-id="{{ $submission['id'] }}"
                        >
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <p class="truncate font-semibold text-slate-950">
                                        {{ $submission['contact_name'] ?: $submission['contact_email'] ?: 'Unlinked submission' }}
                                    </p>
                                    <span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-600 ring-1 ring-slate-200">
                                        {{ str($submission['review_status'])->replace('_', ' ')->title() }}
                                    </span>
                                </div>
                                <p class="mt-1 text-sm text-slate-500">
                                    {{ $submission['submitted_at']?->setTimezone($timezone)->format('M j, Y g:i A') }}
                                    @if($submission['version'] !== null)
                                        · version {{ $submission['version'] }}
                                    @endif
                                </p>
                            </div>

                            <div class="text-sm font-semibold text-slate-600">Review →</div>
                        </a>
                    @endforeach
                </div>
            </section>

            @if($submissions->hasPages())
                <div>{{ $submissions->links() }}</div>
            @endif
        @endif
    </div>
</x-layouts.crm>