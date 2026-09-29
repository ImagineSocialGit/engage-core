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
        'not_managed' => 'Ready',
        'configured' => 'Ready',
        'empty' => 'Needs attention',
        'active' => 'Ready',
        'inactive' => 'Needs attention',
    ];
@endphp

<div {{ $attributes->merge(['class' => 'min-w-0 space-y-6']) }} data-campaign-builder-mode="{{ $mode }}">
    @if($mode === 'create')
        <section class="min-w-0 rounded-3xl border border-slate-200 bg-white p-4 shadow-sm sm:p-6">
            <div class="min-w-0">
                <p class="text-xs font-bold uppercase tracking-[0.16em] text-slate-500">Campaign setup</p>
                <h2 class="mt-2 break-words text-xl font-semibold tracking-tight text-slate-950">Four simple decisions, then you are ready</h2>
                <p class="mt-2 max-w-3xl break-words text-sm leading-6 text-slate-600">
                    Finish each item below. Green means it is ready; red means it still needs something.
                </p>
            </div>

            <ol class="mt-6 grid min-w-0 gap-3 sm:grid-cols-2 xl:grid-cols-4">
                @foreach($stages as $index => $stage)
                    @php
                        $stageKey = (string) ($stage['key'] ?? '');
                        $state = (string) ($stage['state'] ?? '');
                        $ready = in_array($state, ['not_managed', 'configured', 'active'], true);
                    @endphp

                    <li
                        class="min-w-0 rounded-2xl border p-4 {{ $ready ? 'border-emerald-200 bg-emerald-50/70' : 'border-red-200 bg-red-50/70' }}"
                        data-campaign-builder-stage="{{ $stageKey }}"
                    >
                        <div class="flex min-w-0 items-start gap-3">
                            <span class="inline-flex size-8 shrink-0 items-center justify-center rounded-full text-xs font-bold text-white {{ $ready ? 'bg-emerald-700' : 'bg-red-700' }}">
                                {{ $index + 1 }}
                            </span>

                            <div class="min-w-0">
                                <p class="break-words font-semibold text-slate-950">
                                    {{ $stageLabels[$stageKey] ?? \Illuminate\Support\Str::headline($stageKey) }}
                                </p>
                                <p class="mt-1 break-words text-xs font-bold {{ $ready ? 'text-emerald-800' : 'text-red-800' }}">
                                    {{ $stateLabels[$state] ?? \Illuminate\Support\Str::headline($state) }}
                                </p>
                            </div>
                        </div>
                    </li>
                @endforeach
            </ol>
        </section>
    @endif

    {{ $slot }}
</div>