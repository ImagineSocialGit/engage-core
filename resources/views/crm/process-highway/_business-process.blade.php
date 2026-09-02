@php
    $filterItem = [
        'key' => $businessHighway['key'],
        'subject_key' => $businessHighway['subject_key'],
        'lane_scope' => $businessHighway['lane_scope'],
        'relationship_key' => $businessHighway['relationship_key'],
        'qualifiers' => $businessHighway['qualifiers'],
        'entry_requirements' => $businessHighway['entry_requirements'],
        'search_text' => $businessHighway['search_text'],
    ];
    $entryNodesByKey = collect($businessHighway['entry_nodes'])->keyBy('key');
    $entryRequirements = collect($businessHighway['entry_requirements']);
    $segments = collect($businessHighway['segments']);
    $attachedAcknowledgementKeys = $segments
        ->flatMap(fn (array $segment): array => array_column($segment['supporting_acknowledgements'] ?? [], 'key'))
        ->unique();
    $visibleSegments = $segments
        ->reject(fn (array $segment): bool => ($segment['attributes']['role'] ?? null) === 'reply_messaging'
            && $attachedAcknowledgementKeys->contains($segment['key']));
    $visibleSegmentsByKey = $visibleSegments->keyBy('key');
    $segmentStages = collect($businessHighway['segment_stages'] ?? [])
        ->map(fn (array $stage) => collect($stage)
            ->map(fn (mixed $key): ?array => is_string($key) ? $visibleSegmentsByKey->get($key) : null)
            ->filter(fn (mixed $segment): bool => is_array($segment))
            ->values())
        ->filter(fn ($stage): bool => $stage->isNotEmpty())
        ->values();
    $stagedSegmentKeys = $segmentStages
        ->flatMap(fn ($stage) => $stage->pluck('key'))
        ->unique();
    $unstagedSegments = $visibleSegments
        ->reject(fn (array $segment): bool => $stagedSegmentKeys->contains($segment['key']))
        ->values();

    if ($unstagedSegments->isNotEmpty()) {
        $segmentStages->push($unstagedSegments);
    }

    $terminalSegmentKeys = collect($businessHighway['terminal_segment_keys'] ?? []);
    $junctionsByStage = collect($businessHighway['junctions'] ?? [])
        ->filter(fn (mixed $junction): bool => is_array($junction))
        ->groupBy(fn (array $junction): int => (int) ($junction['before_stage_index'] ?? 0));
@endphp

<article
    x-show="{{ $matchMethod }}(@js($filterItem))"
    x-transition.opacity
    data-business-highway="{{ $businessHighway['key'] }}"
    data-process-highway-result
    data-process-highway-match="{{ $matchKind }}"
    class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm"
>
    <header class="px-5 py-5 sm:px-7">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <div class="min-w-0">
                <p class="text-xs font-semibold text-slate-500">{{ $businessHighway['lane_label'] }}</p>
                <h2 class="mt-1 text-lg font-semibold tracking-tight text-slate-950 sm:text-xl">{{ $businessHighway['name'] }}</h2>
                @if($businessHighway['state'] !== 'active')
                    <p class="mt-2 text-sm font-semibold text-amber-800">{{ $businessHighway['state_label'] }}</p>
                @endif
            </div>

            <button
                type="button"
                x-on:click="toggleHighway(@js($businessHighway['key']))"
                x-bind:aria-expanded="expandedHighway === @js($businessHighway['key'])"
                data-process-highway-toggle="{{ $businessHighway['key'] }}"
                class="inline-flex min-h-10 shrink-0 items-center justify-center rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50"
            >
                <span x-text="expandedHighway === @js($businessHighway['key']) ? 'Hide process' : 'Review process'">Review process</span>
            </button>
        </div>

        @if($matchKind === 'partial')
            <div
                x-show="missingRequirements(@js($filterItem)).length > 0"
                data-process-highway-missing-requirements="{{ $businessHighway['key'] }}"
                class="mt-5 rounded-2xl border border-amber-200 bg-amber-50 p-3"
            >
                <p class="text-xs font-bold uppercase tracking-[0.1em] text-amber-800">Still required</p>
                <div class="mt-2 flex flex-wrap gap-2">
                    <template x-for="requirement in missingRequirements(@js($filterItem))" :key="requirement.criterion_key">
                        <span
                            class="rounded-full bg-white px-2.5 py-1 text-xs font-semibold text-amber-950 ring-1 ring-amber-200"
                            x-text="requirementSummary(requirement)"
                        ></span>
                    </template>
                </div>
            </div>
        @endif

        @if($entryRequirements->isNotEmpty())
            <div class="mt-5" data-process-highway-entry-expression="{{ $businessHighway['key'] }}">
                <p class="text-xs font-bold uppercase tracking-[0.12em] text-slate-500">
                    {{ $entryRequirements->count() > 1 ? 'Starts when all of these are true' : 'Starts when this is true' }}
                </p>

                <div class="mt-3 grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                    @foreach($entryRequirements as $requirement)
                        @php($values = collect($requirement['values']))
                        <div data-process-highway-entry-requirement="{{ $requirement['criterion_key'] }}" class="rounded-2xl border border-slate-200 bg-slate-50 p-3">
                            <div class="flex items-center justify-between gap-3">
                                <p class="text-xs font-bold uppercase tracking-[0.1em] text-slate-600">{{ $requirement['criterion_label'] }}</p>
                                @if($values->count() > 1)
                                    <span class="text-xs font-semibold text-slate-500">Any one</span>
                                @endif
                            </div>
                            <div class="mt-2 flex flex-wrap gap-2">
                                @foreach($values as $value)
                                    @php($node = $entryNodesByKey->get($value['node_key']))
                                    @php($inspector = is_array($node) ? ($node['inspector'] ?? null) : null)
                                    @php($target = is_array($node) ? ($node['navigation_target'] ?? null) : null)

                                    @if(is_array($inspector))
                                        <button
                                            type="button"
                                            x-on:click="openRamp(@js($inspector))"
                                            data-entry-ramp-inspector="{{ $value['node_key'] }}"
                                            class="inline-flex min-h-9 items-center gap-2 rounded-xl border border-slate-300 bg-white px-3 py-1.5 text-sm font-semibold text-slate-800 transition hover:border-slate-400"
                                        >
                                            {{ $value['label'] }}
                                            <span aria-hidden="true">›</span>
                                        </button>
                                    @elseif(is_array($target))
                                        <a href="{{ $target['url'] }}" class="inline-flex min-h-9 items-center rounded-xl border border-slate-300 bg-white px-3 py-1.5 text-sm font-semibold text-slate-800 transition hover:border-slate-400">
                                            {{ $value['label'] }}
                                        </a>
                                    @else
                                        <span class="inline-flex min-h-9 items-center rounded-xl border border-slate-300 bg-white px-3 py-1.5 text-sm font-semibold text-slate-800">{{ $value['label'] }}</span>
                                    @endif
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @elseif(collect($businessHighway['entry_nodes'])->isNotEmpty())
            <div class="mt-5 flex flex-wrap items-center gap-2">
                <span class="text-xs font-bold uppercase tracking-[0.12em] text-slate-500">Starts after this event</span>
                @foreach($businessHighway['entry_nodes'] as $node)
                    @php($target = $node['navigation_target'] ?? null)
                    @if(is_array($target))
                        <a href="{{ $target['url'] }}" class="inline-flex min-h-9 items-center rounded-xl border border-slate-300 bg-slate-50 px-3 py-1.5 text-sm font-semibold text-slate-800">{{ $node['label'] }}</a>
                    @else
                        <span class="inline-flex min-h-9 items-center rounded-xl border border-slate-300 bg-slate-50 px-3 py-1.5 text-sm font-semibold text-slate-800">{{ $node['label'] }}</span>
                    @endif
                @endforeach
            </div>
        @endif
    </header>

    <div
        x-cloak
        x-show="expandedHighway === @js($businessHighway['key'])"
        x-transition.opacity
        data-process-highway-details="{{ $businessHighway['key'] }}"
        class="border-t border-slate-200 bg-slate-50/50 px-5 py-5 sm:px-7 sm:py-7"
    >
        <div class="mx-auto max-w-6xl" data-process-highway-road="{{ $businessHighway['key'] }}">
            <div class="flex flex-col items-center" data-process-highway-road-origin>
                <span class="rounded-full bg-slate-900 px-3 py-1 text-[0.68rem] font-bold uppercase tracking-[0.12em] text-white">Automatic path</span>
                <span class="h-6 w-px bg-slate-300" aria-hidden="true"></span>
                <svg viewBox="0 0 20 20" class="-mt-1 h-5 w-5 text-slate-400" fill="currentColor" aria-hidden="true">
                    <path d="M5.25 7.5 10 12.25 14.75 7.5H5.25Z" />
                </svg>
            </div>

            @foreach($segmentStages as $stageIndex => $stage)
                @php($stageJunctions = $junctionsByStage->get($stageIndex, collect()))

                @if(! $loop->first || $stageJunctions->isNotEmpty())
                    <div class="flex h-12 flex-col items-center justify-center" data-process-highway-stage-connector>
                        <span class="h-7 w-px bg-slate-300" aria-hidden="true"></span>
                        <svg viewBox="0 0 20 20" class="-mt-1 h-5 w-5 text-slate-400" fill="currentColor" aria-hidden="true">
                            <path d="M5.25 7.5 10 12.25 14.75 7.5H5.25Z" />
                        </svg>
                    </div>
                @endif

                @foreach($stageJunctions as $junction)
                    @include('crm.process-highway._junction', [
                        'junction' => $junction,
                    ])
                @endforeach

                @if($stageJunctions->isNotEmpty())
                    <div class="flex h-14 flex-col items-center justify-center" data-process-highway-junction-branches>
                        <span class="h-7 w-px bg-slate-300" aria-hidden="true"></span>
                        @if($stage->count() > 1)
                            <span class="w-2/3 max-w-3xl border-t border-slate-300" aria-hidden="true"></span>
                        @else
                            <svg viewBox="0 0 20 20" class="-mt-1 h-5 w-5 text-slate-400" fill="currentColor" aria-hidden="true">
                                <path d="M5.25 7.5 10 12.25 14.75 7.5H5.25Z" />
                            </svg>
                        @endif
                    </div>
                @endif

                <section data-process-highway-stage="{{ $stageIndex }}">
                    @if($stage->count() > 1)
                        <div data-process-highway-parallel-stage aria-hidden="true"></div>
                    @endif

                    <div @class([
                        'grid gap-5',
                        'md:grid-cols-2' => $stage->count() > 1,
                    ])>
                        @foreach($stage as $segment)
                            @include('crm.process-highway._segment', [
                                'segment' => $segment,
                                'businessHighwayKey' => $businessHighway['key'],
                                'isTerminalSegment' => $terminalSegmentKeys->contains($segment['key']),
                            ])
                        @endforeach
                    </div>
                </section>
            @endforeach
        </div>
    </div>
</article>