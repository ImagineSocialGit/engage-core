@props([
    'panel' => [],
    'layout' => 'work',
])

@php
    $module = $panel['module'] ?? 'core';
    $tone = module_tone($module);
    $targetRef = (string) ($panel['target_ref'] ?? str_replace(['.', '-'], '_', (string) ($panel['key'] ?? 'dashboard_panel')).'Panel');
    $panelBaseClass = 'transition duration-700 ease-out rounded-3xl border p-6 shadow-sm lg:p-7';
    $panelFocusClass = 'scale-[1.01] '.module_tone($module, 'panel_focus');
    $panelRestClass = 'ring-1 ring-transparent';
    $itemsGridClass = $layout === 'context' ? 'mt-5 grid gap-3 lg:grid-cols-2' : 'mt-5 space-y-3';
@endphp

<section
    x-ref="{{ $targetRef }}"
    data-module-panel="{{ $module }}"
    data-dashboard-panel="{{ $panel['key'] ?? '' }}"
    class="{{ $panelBaseClass }} {{ module_tone($module, 'panel') }}"
    :class="focusedPanel === @js($targetRef) ? @js($panelFocusClass) : @js($panelRestClass)"
>
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h2 class="text-lg font-semibold tracking-tight text-slate-950">
                {{ $panel['title'] ?? 'Dashboard panel' }}
            </h2>

            @if(filled($panel['description'] ?? null))
                <p class="mt-1 text-sm text-slate-500">
                    {{ $panel['description'] }}
                </p>
            @endif
        </div>

        <div class="flex flex-wrap items-center gap-2">
            @if(($panel['key'] ?? null) === 'tasks.today' && (int) ($panel['overdue_count'] ?? 0) > 0)
                <span class="rounded-full bg-amber-50 px-3 py-1 text-xs font-bold text-amber-700 ring-1 ring-amber-200">
                    {{ (int) ($panel['overdue_count'] ?? 0) }} overdue
                </span>
            @endif

            @if(($panel['key'] ?? null) === 'tasks.today')
                <a
                    href="{{ route('crm.tasks.today.print') }}"
                    target="_blank"
                    class="rounded-xl border border-slate-200 px-3 py-2 text-xs font-bold text-slate-700 transition hover:bg-slate-50"
                >
                    Print
                </a>

                @if($panel['can_broadcast'] ?? false)
                    <form method="POST" action="{{ route('crm.tasks.today.broadcast') }}">
                        @csrf
                        <button type="submit" class="rounded-xl border border-slate-200 px-3 py-2 text-xs font-bold text-slate-700 transition hover:bg-slate-50">
                            Broadcast
                        </button>
                    </form>
                @else
                    <span class="rounded-xl border border-dashed border-slate-200 px-3 py-2 text-xs font-semibold text-slate-400">
                        Broadcast unavailable
                    </span>
                @endif
            @endif

            @foreach(($panel['actions'] ?? []) as $action)
                @if(filled($action['href'] ?? null))
                    <a href="{{ $action['href'] }}" class="rounded-xl border border-slate-200 px-3 py-2 text-xs font-bold text-slate-700 transition hover:bg-slate-50">
                        {{ $action['label'] ?? 'Open' }}
                    </a>
                @endif
            @endforeach
        </div>
    </div>

    <div class="{{ $itemsGridClass }}">
        @forelse($panel['items'] ?? [] as $item)
            <x-crm.dashboard.panel-item :panel="$panel" :item="$item" :module="$module" :target-ref="$targetRef" />
        @empty
            <x-crm.dashboard.panel-empty :panel="$panel" :layout="$layout" />
        @endforelse
    </div>

    @if(($panel['key'] ?? null) === 'tasks.today' && filled($panel['upcoming_summary'] ?? null))
        <div class="mt-5 rounded-2xl border border-slate-200 bg-white p-4">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <p class="text-sm font-semibold text-slate-950">
                        Upcoming this week
                    </p>
                    <p class="mt-1 text-sm text-slate-500">
                        {{ (int) $panel['upcoming_summary']['count'] }} {{ str('task')->plural((int) $panel['upcoming_summary']['count']) }} due after today.
                    </p>
                </div>

                <form method="POST" action="{{ route('crm.dashboard.acknowledgements.store') }}">
                    @csrf
                    <input type="hidden" name="item_type" value="{{ $panel['upcoming_summary']['type'] }}">
                    <input type="hidden" name="item_key" value="{{ $panel['upcoming_summary']['key'] }}">
                    <input type="hidden" name="return_to" value="{{ request()->fullUrl() }}">
                    <button type="submit" class="text-xs font-bold text-slate-600 underline underline-offset-4 hover:text-slate-950">
                        Hide for today
                    </button>
                </form>
            </div>
        </div>
    @endif

    @if(($panel['key'] ?? null) === 'tasks.today' && ! ($panel['can_broadcast'] ?? false))
        <p class="mt-4 text-xs leading-5 text-slate-400">
            Broadcast becomes available when Team Members, Internal Notifications, and Messaging are enabled. Until then, use Print or View.
        </p>
    @endif
</section>
