@props([
    'sections' => [],
])

@php
    $sections = collect($sections)->filter(fn ($section) => filled($section['title'] ?? null));
@endphp

@if($sections->isNotEmpty())
    <x-ui.card class="space-y-4">
        <div>
            <h3 class="text-lg font-semibold tracking-tight">
                Contact Visibility
            </h3>

            <p class="text-sm text-slate-500">
                Read-only debug view of what is happening with this {{ config('contacts.labels.singular') }}.
            </p>
        </div>

        <div class="grid gap-4 lg:grid-cols-2">
            @foreach($sections as $section)
                <div class="rounded-xl border border-slate-200 p-4">
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

                    <div class="mt-4 space-y-3">
                        @forelse(($section['items'] ?? []) as $item)
                            <div class="rounded-lg bg-slate-50 p-3">
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
                        @empty
                            <p class="text-sm text-slate-500">
                                {{ $section['empty'] ?? 'Nothing to show.' }}
                            </p>
                        @endforelse
                    </div>
                </div>
            @endforeach
        </div>
    </x-ui.card>
@endif