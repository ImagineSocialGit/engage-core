<div
    class="space-y-3"
    data-scheduling-setup-progress
    @if ($setupProgress['service_id'])
        data-scheduling-setup-service="{{ $setupProgress['service_id'] }}"
    @endif
>
    <div class="flex flex-col gap-1 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <div class="inline-flex rounded-full px-2 py-1 text-xs font-semibold {{ module_tone('scheduling', 'badge') }}">
                Setup status
            </div>
            <h2 class="mt-2 text-lg font-semibold text-slate-900">
                {{ $setupProgress['service_name'] ? 'Finish '.$setupProgress['service_name'] : 'Create the first appointment type' }}
            </h2>
            <p class="mt-1 text-sm text-slate-500">
                Required steps come first. Recommended steps improve assignment and follow-up without blocking booking.
            </p>
        </div>
    </div>

    <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
        @foreach ($setupProgress['steps'] as $step)
            <div
                @class([
                    'rounded-xl border p-4',
                    'border-emerald-300 bg-emerald-50' => $step['state'] === 'complete',
                    'border-yellow-300 bg-yellow-50' => $step['state'] === 'current',
                    'border-red-300 bg-red-50' => $step['state'] === 'required',
                    'border-orange-300 bg-orange-50' => $step['state'] === 'recommended',
                ])
                data-scheduling-progress-step="{{ $step['key'] }}"
                data-scheduling-progress-state="{{ $step['state'] }}"
            >
                <div class="flex items-start justify-between gap-3">
                    <div class="flex items-center gap-2">
                        <span
                            @class([
                                'inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-xs font-bold',
                                'bg-emerald-700 text-white' => $step['state'] === 'complete',
                                'bg-yellow-400 text-yellow-950' => $step['state'] === 'current',
                                'bg-red-700 text-white' => $step['state'] === 'required',
                                'bg-orange-500 text-white' => $step['state'] === 'recommended',
                            ])
                        >
                            {{ $step['state'] === 'complete' ? '✓' : $step['number'] }}
                        </span>
                        <div class="font-semibold text-slate-900">{{ $step['label'] }}</div>
                    </div>

                    <span
                        @class([
                            'rounded-full px-2 py-1 text-[11px] font-semibold',
                            'bg-emerald-100 text-emerald-800' => $step['state'] === 'complete',
                            'bg-yellow-100 text-yellow-900' => $step['state'] === 'current',
                            'bg-red-100 text-red-800' => $step['state'] === 'required',
                            'bg-orange-100 text-orange-900' => $step['state'] === 'recommended',
                        ])
                    >
                        {{ $step['state_label'] }}
                    </span>
                </div>

                <p class="mt-3 text-sm leading-5 text-slate-600">{{ $step['description'] }}</p>

                @if ($setupProgress['service_id'] && $step['url'] && $step['key'] !== $setupProgress['current_step'])
                    <a
                        href="{{ $step['url'] }}"
                        data-scheduling-progress-link="{{ $step['key'] }}"
                        class="mt-3 inline-flex text-sm font-semibold text-teal-700 hover:text-teal-800"
                    >
                        {{ $step['complete'] ? 'Review' : ($step['required'] ? 'Finish step' : 'Set up') }}
                    </a>
                @endif
            </div>
        @endforeach
    </div>
</div>