@php
    $clientTimezone = config('client.timezone', config('app.timezone', 'UTC'));

    $formatDate = fn ($date) => $date
        ?->timezone($clientTimezone)
        ->format('M j, Y g:i A');
@endphp

<x-ui.card class="space-y-4 {{ module_tone('webinars', 'panel') }}" data-module-panel="webinars">
    <div>
        <h3 class="text-lg font-semibold tracking-tight">
            {{ $contactPanel->title }}
        </h3>

        <p class="text-sm text-slate-500">
            Recent webinar registrations and attendance context for this {{ config('contacts.labels.singular') }}.
        </p>
    </div>

    <div class="space-y-3">
        @forelse($registrations as $registration)
            <div class="rounded-xl border p-3 {{ module_tone('webinars', 'item') }}">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <p class="font-medium text-slate-900">
                            {{ $registration->webinar?->title ?? $registration->webinar_slug ?? 'Webinar' }}
                        </p>

                        <p class="mt-1 text-sm text-slate-500">
                            Registered:
                            <span class="font-medium text-slate-700">
                                {{ $formatDate($registration->registered_at) ?? '—' }}
                            </span>
                        </p>
                    </div>

                    <span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-700">
                        {{ str($registration->status)->replace('_', ' ')->title() }}
                    </span>
                </div>

                <div class="mt-3 grid gap-2 text-xs text-slate-500">
                    <p>
                        Webinar Date:
                        <span class="font-medium text-slate-700">
                            {{ $formatDate($registration->webinar?->starts_at) ?? '—' }}
                        </span>
                    </p>

                    <p>
                        Attended:
                        <span class="font-medium text-slate-700">
                            {{ $formatDate($registration->attended_at) ?? '—' }}
                        </span>
                    </p>
                </div>

                @if($registration->responses->isNotEmpty())
                    <div class="mt-3 rounded-lg border border-slate-200 bg-white/70 p-3">
                        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">
                            Registration Questions
                        </p>

                        <dl class="mt-3 space-y-3">
                            @foreach($registration->responses as $response)
                                <div>
                                    <dt class="text-xs font-medium leading-5 text-slate-500">
                                        {{ $response->question_label }}
                                    </dt>

                                    <dd class="mt-1 text-sm font-semibold leading-5 text-slate-800">
                                        {{ $response->answer_label }}
                                    </dd>

                                    @if(filled($response->answer_text))
                                        <dd class="mt-1 whitespace-pre-wrap text-sm leading-5 text-slate-700">
                                            {{ $response->answer_text }}
                                        </dd>
                                    @endif
                                </div>
                            @endforeach
                        </dl>
                    </div>
                @endif
            </div>
        @empty
            <p class="text-sm text-slate-500">
                No webinar history yet.
            </p>
        @endforelse
    </div>
</x-ui.card>