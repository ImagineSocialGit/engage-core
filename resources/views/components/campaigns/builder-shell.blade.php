@props([
    'stages' => [],
    'mode' => 'edit',
])

@php
    $stageLabels = [
        'start' => 'Who gets it',
        'messages' => 'What they receive',
        'schedule' => 'When it happens',
        'review' => 'Review & turn on',
    ];

    $stateLabels = [
        'not_managed' => 'Current audience rules',
        'configured' => 'Ready',
        'empty' => 'Needs attention',
        'active' => 'On',
        'inactive' => 'Off',
    ];

    $builderHeading = $mode === 'create'
        ? 'Four simple decisions, then you are ready'
        : 'Everything important in one place';

    $builderDescription = $mode === 'create'
        ? 'Choose who should get the campaign, what they should receive, when it should happen, and review everything before turning it on.'
        : 'Review the audience, messages, and timing here. The technical delivery machinery stays out of the way.';
@endphp

<div {{ $attributes->merge(['class' => 'min-w-0 space-y-6']) }} data-campaign-builder-mode="{{ $mode }}">
    <section class="min-w-0 rounded-3xl border border-rose-200 bg-white/95 p-4 shadow-sm sm:p-6">
        <div class="min-w-0">
            <p class="text-xs font-bold uppercase tracking-[0.16em] text-rose-700">
                Campaign setup
            </p>
            <h2 class="mt-2 break-words text-xl font-semibold tracking-tight text-slate-950">
                {{ $builderHeading }}
            </h2>
            <p class="mt-2 max-w-3xl break-words text-sm leading-6 text-slate-600">
                {{ $builderDescription }}
            </p>
        </div>

        <ol class="mt-6 grid min-w-0 gap-3 sm:grid-cols-2 xl:grid-cols-4">
            @foreach($stages as $index => $stage)
                @php
                    $stageKey = (string) ($stage['key'] ?? '');
                    $state = (string) ($stage['state'] ?? '');
                @endphp

                <li
                    class="min-w-0 rounded-2xl border border-slate-200 bg-slate-50/80 p-4"
                    data-campaign-builder-stage="{{ $stageKey }}"
                >
                    <div class="flex min-w-0 items-start gap-3">
                        <span class="inline-flex size-8 shrink-0 items-center justify-center rounded-full bg-slate-950 text-xs font-bold text-white">
                            {{ $index + 1 }}
                        </span>

                        <div class="min-w-0">
                            <p class="break-words font-semibold text-slate-950">
                                {{ $stageLabels[$stageKey] ?? \Illuminate\Support\Str::headline($stageKey) }}
                            </p>
                            <p class="mt-1 break-words text-xs font-semibold text-slate-500">
                                {{ $stateLabels[$state] ?? \Illuminate\Support\Str::headline($state) }}
                            </p>
                        </div>
                    </div>
                </li>
            @endforeach
        </ol>
    </section>

    {{ $slot }}
</div>