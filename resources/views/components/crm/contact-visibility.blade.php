@props([
    'sections' => [],
])

@php
    $sectionLabels = [
        'Tasks' => [
            'title' => 'Needs attention',
            'description' => 'Open and recent manual follow-up work.',
            'empty' => 'No manual action needed right now.',
            'priority' => 10,
        ],
        'Workflow' => [
            'title' => 'Current status',
            'description' => 'Where this lead sits in the process.',
            'empty' => 'No current status is available.',
            'priority' => 20,
        ],
        'Routes' => [
            'title' => 'Automatic follow-ups',
            'description' => 'Recent or active automated steps for this lead.',
            'empty' => 'No automatic follow-up is active right now.',
            'priority' => 30,
        ],
        'Scheduled Messages' => [
            'title' => 'Upcoming messages',
            'description' => 'Scheduled and recent outbound messages.',
            'empty' => 'No upcoming messages.',
            'priority' => 40,
        ],
        'Campaigns' => [
            'title' => 'Campaign follow-up',
            'description' => 'Current and recent nurture campaign activity.',
            'empty' => 'No campaign follow-up is active right now.',
            'priority' => 50,
        ],
    ];

    $sections = collect($sections)
        ->filter(fn ($section) => filled($section['title'] ?? null))
        ->map(function ($section) use ($sectionLabels) {
            $originalTitle = $section['title'] ?? '';
            $label = $sectionLabels[$originalTitle] ?? null;

            return array_replace($section, [
                'title' => $label['title'] ?? $originalTitle,
                'description' => $label['description'] ?? ($section['description'] ?? null),
                'empty' => $label['empty'] ?? ($section['empty'] ?? 'Nothing to show.'),
                '_priority' => $label['priority'] ?? 99,
            ]);
        })
        ->sortBy('_priority')
        ->values();
@endphp

@if($sections->isNotEmpty())
    <x-ui.card class="space-y-4">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h3 class="text-lg font-semibold tracking-tight">
                    Lead tracking
                </h3>

                <p class="text-sm text-slate-500">
                    A quick read on what is happening now, what already happened, and what may happen next.
                </p>
            </div>
        </div>

        <div class="grid gap-4 lg:grid-cols-2">
            @foreach($sections as $sectionIndex => $section)
                @php
                    $items = collect($section['items'] ?? [])->values();
                    $previewItems = $items->take((int) ($section['preview_count'] ?? 1));
                    $hasMore = $items->count() > $previewItems->count();
                    $drawerId = 'contact-visibility-drawer-'.$sectionIndex;
                @endphp

                <div
                    class="rounded-xl border border-slate-200 p-4"
                    x-data="{
                        open: false,
                        visibleCount: 5,
                        totalCount: {{ $items->count() }},
                        showMore() {
                            this.visibleCount = Math.min(this.visibleCount + 5, this.totalCount);
                        },
                        close() {
                            this.open = false;
                            this.visibleCount = 5;
                        }
                    }"
                    x-on:keydown.escape.window="close()"
                >
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <h4 class="font-semibold text-slate-900">
                                {{ $section['title'] }}
                            </h4>

                            @if(filled($section['description'] ?? null))
                                <p class="mt-1 text-sm text-slate-500">
                                    {{ $section['description'] }}
                                </p>
                            @endif
                        </div>

                        @if($items->isNotEmpty())
                            <span class="shrink-0 rounded-full bg-slate-50 px-2.5 py-1 text-xs font-semibold text-slate-600 ring-1 ring-slate-200">
                                {{ $items->count() }}
                            </span>
                        @endif
                    </div>

                    <div class="mt-4 min-h-[9rem] space-y-3">
                        @forelse($previewItems as $item)
                            <div class="rounded-lg bg-slate-50 p-3">
                                <div class="flex items-start justify-between gap-3">
                                    <div>
                                        <p class="text-sm font-semibold text-slate-900">
                                            {{ $item['title'] ?? 'Untitled' }}
                                        </p>

                                        @if(filled($item['subtitle'] ?? null))
                                            <p class="mt-1 line-clamp-2 text-xs text-slate-500">
                                                {{ $item['subtitle'] }}
                                            </p>
                                        @endif
                                    </div>

                                    @if(filled($item['status'] ?? null))
                                        <span class="shrink-0 rounded-full bg-white px-2.5 py-1 text-xs font-semibold text-slate-700 ring-1 ring-slate-200">
                                            {{ $item['status'] }}
                                        </span>
                                    @endif
                                </div>

                                @if(! empty($item['meta'] ?? []))
                                    <dl class="mt-3 grid gap-2 text-xs sm:grid-cols-2">
                                        @foreach(collect($item['meta'])->filter(fn ($value) => filled($value))->take(4) as $label => $value)
                                            <div>
                                                <dt class="text-slate-500">
                                                    {{ $label }}
                                                </dt>
                                                <dd class="font-medium text-slate-700">
                                                    {{ $value }}
                                                </dd>
                                            </div>
                                        @endforeach
                                    </dl>
                                @endif
                            </div>
                        @empty
                            <div class="flex min-h-[7rem] items-center rounded-lg bg-slate-50 p-3">
                                <p class="text-sm text-slate-500">
                                    {{ $section['empty'] ?? 'Nothing to show.' }}
                                </p>
                            </div>
                        @endforelse
                    </div>

                    @if($hasMore)
                        <div class="mt-4">
                            <button
                                type="button"
                                class="text-sm font-semibold text-slate-900 underline underline-offset-4 hover:text-slate-700"
                                x-on:click="open = true"
                                aria-controls="{{ $drawerId }}"
                            >
                                View more
                            </button>
                        </div>
                    @endif

                    @if($items->isNotEmpty())
                        <div
                            x-cloak
                            x-show="open"
                            class="fixed inset-0 z-50"
                            role="dialog"
                            aria-modal="true"
                            aria-labelledby="{{ $drawerId }}-title"
                        >
                            <div
                                class="absolute inset-0 bg-slate-950/40"
                                x-on:click="close()"
                                x-transition.opacity
                            ></div>

                            <div class="absolute inset-y-0 right-0 flex max-w-full pl-10">
                                <div
                                    id="{{ $drawerId }}"
                                    class="h-full w-screen max-w-xl overflow-hidden bg-white shadow-xl"
                                    x-transition:enter="transform transition ease-in-out duration-200"
                                    x-transition:enter-start="translate-x-full"
                                    x-transition:enter-end="translate-x-0"
                                    x-transition:leave="transform transition ease-in-out duration-200"
                                    x-transition:leave-start="translate-x-0"
                                    x-transition:leave-end="translate-x-full"
                                >
                                    <div class="flex h-full flex-col">
                                        <div class="border-b border-slate-200 px-6 py-4">
                                            <div class="flex items-start justify-between gap-4">
                                                <div>
                                                    <h3 id="{{ $drawerId }}-title" class="text-lg font-semibold text-slate-900">
                                                        {{ $section['title'] }}
                                                    </h3>

                                                    @if(filled($section['description'] ?? null))
                                                        <p class="mt-1 text-sm text-slate-500">
                                                            {{ $section['description'] }}
                                                        </p>
                                                    @endif
                                                </div>

                                                <button
                                                    type="button"
                                                    class="rounded-lg px-2 py-1 text-sm font-semibold text-slate-500 hover:bg-slate-100 hover:text-slate-900"
                                                    x-on:click="close()"
                                                >
                                                    Close
                                                </button>
                                            </div>
                                        </div>

                                        <div class="flex-1 overflow-y-auto px-6 py-4">
                                            <div class="space-y-3">
                                                @foreach($items as $itemIndex => $item)
                                                    <div
                                                        class="rounded-lg bg-slate-50 p-4"
                                                        x-show="{{ $itemIndex }} < visibleCount"
                                                    >
                                                        <div class="flex items-start justify-between gap-3">
                                                            <div>
                                                                <p class="text-sm font-semibold text-slate-900">
                                                                    {{ $item['title'] ?? 'Untitled' }}
                                                                </p>

                                                                @if(filled($item['subtitle'] ?? null))
                                                                    <p class="mt-1 text-xs text-slate-500">
                                                                        {{ $item['subtitle'] }}
                                                                    </p>
                                                                @endif
                                                            </div>

                                                            @if(filled($item['status'] ?? null))
                                                                <span class="shrink-0 rounded-full bg-white px-2.5 py-1 text-xs font-semibold text-slate-700 ring-1 ring-slate-200">
                                                                    {{ $item['status'] }}
                                                                </span>
                                                            @endif
                                                        </div>

                                                        @if(! empty($item['meta'] ?? []))
                                                            <dl class="mt-3 grid gap-2 text-xs sm:grid-cols-2">
                                                                @foreach($item['meta'] as $label => $value)
                                                                    <div>
                                                                        <dt class="text-slate-500">
                                                                            {{ $label }}
                                                                        </dt>
                                                                        <dd class="font-medium text-slate-700">
                                                                            {{ filled($value) ? $value : '—' }}
                                                                        </dd>
                                                                    </div>
                                                                @endforeach
                                                            </dl>
                                                        @endif
                                                    </div>
                                                @endforeach
                                            </div>

                                            @if($items->count() > 5)
                                                <div class="mt-4">
                                                    <button
                                                        type="button"
                                                        class="w-full rounded-lg border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50"
                                                        x-show="visibleCount < totalCount"
                                                        x-on:click="showMore()"
                                                    >
                                                        Show 5 more
                                                    </button>
                                                </div>
                                            @endif
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    </x-ui.card>
@endif
