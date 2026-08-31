@php
    $flowRoute = $editor['model'];
    $routePresentation = $editor['route'];
    $points = $editor['points'];
    $capabilities = $editor['capabilities'];
    $leadInWait = $points->first()?->type === \App\Modules\FlowRoutes\Enums\FlowRoutePointType::Wait->value
        ? $points->first()
        : null;
    $businessPoints = $leadInWait ? $points->slice(1)->values() : $points;
    $leadInDefinition = $leadInWait
        ? array_replace_recursive($leadInWait->settings ?? [], $leadInWait->definition ?? [])
        : [];
    $leadInWaitMode = filled($leadInDefinition['resume_at'] ?? null) ? 'resume_at' : 'duration';
    $leadInDurationUnit = 'days';
    $leadInDurationValue = 1;

    foreach (['weeks', 'business_days', 'days', 'hours', 'minutes'] as $candidateUnit) {
        if (is_numeric($leadInDefinition[$candidateUnit] ?? null)) {
            $leadInDurationUnit = $candidateUnit;
            $leadInDurationValue = (int) $leadInDefinition[$candidateUnit];
            break;
        }
    }

    $leadInPresentation = $leadInWait
        ? collect($routePresentation['presented_points'])->firstWhere('key', $leadInWait->key)
        : null;
    $isCurrentEditor = (int) request()->integer('edit_route') === (int) $flowRoute->getKey();
    $startTimingValue = $isCurrentEditor
        ? old('start_timing', $leadInWait ? 'delayed' : 'immediate')
        : ($leadInWait ? 'delayed' : 'immediate');
    $waitModeValue = $isCurrentEditor ? old('wait_mode', $leadInWaitMode) : $leadInWaitMode;
    $durationValue = $isCurrentEditor ? old('duration_value', $leadInDurationValue) : $leadInDurationValue;
    $durationUnit = $isCurrentEditor ? old('duration_unit', $leadInDurationUnit) : $leadInDurationUnit;
    $resumeAtValue = $isCurrentEditor
        ? old('resume_at', (string) ($leadInDefinition['resume_at'] ?? ''))
        : (string) ($leadInDefinition['resume_at'] ?? '');
    $capabilityModules = $capabilities
        ->map(fn (array $capability): array => [
            'key' => (string) $capability['module_key'],
            'label' => config(
                'modules.modules.'.$capability['module_key'].'.name',
                \Illuminate\Support\Str::headline($capability['module_key']),
            ),
        ])
        ->unique('key')
        ->values();
@endphp

<template x-teleport="body">
<div
    x-cloak
    style="display: none;"
    x-show="openRouteEditor === {{ $flowRoute->getKey() }}"
    x-transition.opacity
    class="fixed inset-0 z-50 flex min-h-screen items-center justify-center bg-slate-950/55 p-3 sm:p-6"
    role="dialog"
    aria-modal="true"
    aria-labelledby="route-editor-title-{{ $flowRoute->getKey() }}"
>
    <div
        x-data="flowRouteEditor(
            @js($businessPoints->map(fn ($point) => [
                'id' => (int) $point->getKey(),
                'type' => (string) $point->type,
            ])->values()->all()),
            @js([
                'wait' => [
                    'type' => \App\Modules\FlowRoutes\Enums\FlowRoutePointType::Wait->value,
                    'message' => "Wait can't be the final Point. Add or move another Point after Wait first.",
                    'remove_message' => "This Point can't be removed because it would leave Wait as the final Point. Add or move another Point after Wait first.",
                ],
                'change_status' => [
                    'type' => \App\Modules\FlowRoutes\Enums\FlowRoutePointType::ChangeStatus->value,
                    'message' => 'Change Status must be the final Point in the Route because changing workflow status hands the contact off to what comes next.',
                    'remove_message' => "This Point can't be removed because Change Status must remain the final Point in the Route.",
                ],
            ])
        )"
        @keydown.escape.window="if (pointModal) closePoint(); else if (addPointModal) closeAddPoint(); else if (openRouteEditor === {{ $flowRoute->getKey() }}) closeRoute()"
        @click.outside="if (! pointModal && ! addPointModal) closeRoute()"
        class="flex max-h-[calc(100dvh-1.5rem)] w-full max-w-6xl flex-col overflow-hidden rounded-3xl bg-white shadow-2xl ring-1 ring-black/10 sm:max-h-[94vh]"
    >
        <header class="flex items-start justify-between gap-3 border-b border-orange-100 px-4 py-4 sm:gap-4 sm:px-7 sm:py-5">
            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                    <p class="text-sm font-semibold uppercase tracking-[0.16em] text-orange-800">Route editor</p>

                    @if($flowRoute->is_customized)
                        <span class="rounded-full bg-orange-50 px-2.5 py-1 text-xs font-semibold text-orange-900 ring-1 ring-orange-200">
                            Customized
                        </span>
                    @endif
                </div>

                <h2 id="route-editor-title-{{ $flowRoute->getKey() }}" class="mt-2 text-2xl font-semibold tracking-tight text-slate-950">
                    {{ $flowRoute->name }}
                </h2>

                @if($flowRoute->description)
                    <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-700">
                        {{ $flowRoute->description }}
                    </p>
                @endif
            </div>

            <button
                type="button"
                @click="closeRoute()"
                class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-slate-300 bg-white text-xl text-slate-700 shadow-sm transition hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-orange-400"
                aria-label="Close Route editor"
            >
                ×
            </button>
        </header>

        <div class="overflow-y-auto px-4 py-5 sm:px-7 sm:py-6">
            @if(session('status') && (int) request()->integer('edit_route') === (int) $flowRoute->getKey())
                <div class="mb-5">
                    <x-ui.feedback.alert type="success">
                        {{ session('status') }}
                    </x-ui.feedback.alert>
                </div>
            @endif

            @if($errors->any() && (int) request()->integer('edit_route') === (int) $flowRoute->getKey())
                <div class="mb-5">
                    <x-ui.feedback.alert type="error">
                        <div class="space-y-1">
                            @foreach($errors->all() as $error)
                                <p>{{ $error }}</p>
                            @endforeach
                        </div>
                    </x-ui.feedback.alert>
                </div>
            @endif

            <section class="rounded-2xl border border-orange-200 bg-orange-50/70 p-4 sm:p-5">
                <p class="text-xs font-bold uppercase tracking-[0.16em] text-orange-800">Runs when</p>
                <p class="mt-2 text-sm font-semibold leading-6 text-slate-950">
                    {{ $routePresentation['trigger_summary'] }}
                </p>

                @foreach(($routePresentation['entry_condition_summaries'] ?? []) as $entryConditionSummary)
                    <p class="mt-1 text-sm leading-6 text-slate-700">
                        {{ $entryConditionSummary }}
                    </p>
                @endforeach
            </section>

            <section
                x-data="{
                    startTiming: @js($startTimingValue),
                    waitMode: @js($waitModeValue),
                    durationUnit: @js($durationUnit),
                }"
                class="mt-5 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5"
            >
                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <h3 class="font-semibold text-slate-950">When should this Route begin?</h3>
                        <p class="mt-1 text-sm leading-6 text-slate-700">
                            Choose whether the first action happens right away or after a lead-in delay.
                        </p>
                    </div>

                    @if($leadInPresentation)
                        <span class="rounded-full bg-orange-50 px-3 py-1.5 text-xs font-semibold text-orange-900 ring-1 ring-orange-200">
                            {{ rtrim((string) ($leadInPresentation['summary'] ?? 'Delay before first action'), '.') }}
                        </span>
                    @endif
                </div>

                <form method="POST" action="{{ route('crm.flow-routes.start-delay.update', $flowRoute) }}" class="mt-5">
                    @csrf
                    @method('PATCH')

                    <div class="grid gap-3 sm:grid-cols-2">
                        <label class="flex cursor-pointer gap-3 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 has-checked:border-orange-400 has-checked:bg-orange-50">
                            <input
                                type="radio"
                                name="start_timing"
                                value="immediate"
                                x-model="startTiming"
                                class="mt-1 h-4 w-4 border-slate-300 text-orange-700 focus:ring-orange-500"
                            >
                            <span>
                                <span class="block text-sm font-semibold text-slate-950">Immediately</span>
                                <span class="mt-1 block text-xs leading-5 text-slate-600">Begin with the first action as soon as this Route starts.</span>
                            </span>
                        </label>

                        <label class="flex cursor-pointer gap-3 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 has-checked:border-orange-400 has-checked:bg-orange-50">
                            <input
                                type="radio"
                                name="start_timing"
                                value="delayed"
                                x-model="startTiming"
                                class="mt-1 h-4 w-4 border-slate-300 text-orange-700 focus:ring-orange-500"
                            >
                            <span>
                                <span class="block text-sm font-semibold text-slate-950">After a delay</span>
                                <span class="mt-1 block text-xs leading-5 text-slate-600">Wait before the first action happens.</span>
                            </span>
                        </label>
                    </div>

                    <div x-show="startTiming === 'delayed'" x-cloak class="mt-4 rounded-xl border border-orange-200 bg-orange-50/60 p-4">
                        <fieldset>
                            <legend class="text-sm font-semibold text-slate-950">How should the delay be set?</legend>

                            <div class="mt-3 flex flex-col gap-3 sm:flex-row sm:gap-6">
                                <label class="inline-flex items-center gap-2 text-sm font-medium text-slate-800">
                                    <input type="radio" name="wait_mode" value="duration" x-model="waitMode" class="text-orange-700 focus:ring-orange-500">
                                    For an amount of time
                                </label>
                                <label class="inline-flex items-center gap-2 text-sm font-medium text-slate-800">
                                    <input type="radio" name="wait_mode" value="resume_at" x-model="waitMode" class="text-orange-700 focus:ring-orange-500">
                                    Until a date and time
                                </label>
                            </div>
                        </fieldset>

                        <div x-show="waitMode === 'duration'" class="mt-4 grid gap-4 sm:grid-cols-2">
                            <label class="block">
                                <span class="text-sm font-semibold text-slate-900">Wait</span>
                                <input
                                    type="number"
                                    name="duration_value"
                                    value="{{ $durationValue }}"
                                    min="0"
                                    max="100000"
                                    class="mt-1 block min-h-11 w-full rounded-xl border-slate-300 bg-white text-sm shadow-sm focus:border-orange-500 focus:ring-orange-500"
                                >
                            </label>

                            <label class="block">
                                <span class="text-sm font-semibold text-slate-900">Time period</span>
                                <select
                                    name="duration_unit"
                                    x-model="durationUnit"
                                    class="mt-1 block min-h-11 w-full rounded-xl border-slate-300 bg-white text-sm shadow-sm focus:border-orange-500 focus:ring-orange-500"
                                >
                                    <option value="minutes">Minutes</option>
                                    <option value="hours">Hours</option>
                                    <option value="days">Days</option>
                                    <option value="business_days">Business days</option>
                                    <option value="weeks">Weeks</option>
                                </select>
                            </label>
                        </div>

                        <div x-show="waitMode === 'resume_at'" class="mt-4 max-w-md">
                            <label class="block">
                                <span class="text-sm font-semibold text-slate-900">Begin the first action at</span>
                                <input
                                    type="datetime-local"
                                    name="resume_at"
                                    value="{{ $resumeAtValue }}"
                                    class="mt-1 block min-h-11 w-full rounded-xl border-slate-300 bg-white text-sm shadow-sm focus:border-orange-500 focus:ring-orange-500"
                                >
                            </label>
                        </div>

                        <div x-show="waitMode === 'duration' && durationUnit === 'business_days'" class="mt-4 rounded-xl border border-slate-200 bg-white p-4">
                            <p class="text-sm font-semibold text-slate-950">How business days are counted</p>
                            <p class="mt-1 text-xs leading-5 text-slate-600">
                                Business-day waits skip the weekdays and dates selected for this business. Calendar changes affect waits that begin later, not people already waiting.
                            </p>
                            <a
                                href="{{ route('crm.business-calendar.edit', ['from' => 'routes']) }}"
                                class="mt-3 inline-flex min-h-10 items-center justify-center rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-900 shadow-sm hover:bg-slate-50"
                            >
                                Manage business days
                            </a>
                        </div>
                    </div>

                    <div class="mt-4 flex justify-end">
                        <button
                            type="submit"
                            class="inline-flex min-h-11 w-full items-center justify-center rounded-xl bg-slate-950 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-slate-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-orange-400 sm:w-auto"
                        >
                            Save Route start
                        </button>
                    </div>
                </form>
            </section>

            <section class="mt-7">
                <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <h3 class="text-lg font-semibold tracking-tight text-slate-950">Actions</h3>
                        <p class="mt-1 text-sm leading-6 text-slate-700">
                            The first action happens after any lead-in delay. Actions normally continue from top to bottom; a Decision can direct the Route to a later action or end it.
                        </p>
                    </div>

                    <form method="POST" action="{{ route('crm.flow-routes.points.order', $flowRoute) }}" x-show="orderChanged" x-transition class="w-full sm:w-auto">
                        @csrf
                        @method('PATCH')

                        @if($leadInWait)
                            <input type="hidden" name="point_ids[]" value="{{ $leadInWait->getKey() }}">
                        @endif

                        <template x-for="pointId in pointOrder" :key="pointId">
                            <input type="hidden" name="point_ids[]" :value="pointId">
                        </template>

                        <button
                            type="submit"
                            class="inline-flex w-full items-center justify-center rounded-xl bg-orange-700 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-orange-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-orange-400 sm:w-auto"
                        >
                            Save order
                        </button>
                    </form>
                </div>

                <div x-ref="pointList" class="mt-5 space-y-3">
                    @forelse($businessPoints as $index => $point)
                        @php
                            $presented = collect($routePresentation['presented_points'])->firstWhere('key', $point->key);
                            $moduleKey = $presented['module_key'] ?? ($point->capability?->module_key ?: 'flow_routes');
                        @endphp

                        <article
                            data-point-id="{{ $point->getKey() }}"
                            data-point-movable="{{ $point->type === \App\Modules\FlowRoutes\Enums\FlowRoutePointType::ChangeStatus->value ? 'false' : 'true' }}"
                            @if($point->type !== \App\Modules\FlowRoutes\Enums\FlowRoutePointType::ChangeStatus->value)
                                draggable="true"
                                @dragstart="startDrag($event, {{ $point->getKey() }})"
                                @dragend="endDrag()"
                            @endif
                            @dragover="dragOver($event, {{ $point->getKey() }})"
                            @dragleave="clearInvalidDropPreview({{ $point->getKey() }})"
                            class="group relative overflow-hidden rounded-2xl p-4 ring-1 transition {{ module_tone($moduleKey, 'item') }}"
                            :class="draggedPointId === {{ $point->getKey() }} ? 'opacity-50 scale-[0.99]' : ''"
                        >
                            <div
                                x-cloak
                                x-show="invalidDropTargetId === {{ $point->getKey() }}"
                                class="absolute inset-0 z-10 flex items-center justify-center bg-slate-950/90 p-5 text-center"
                                aria-live="polite"
                            >
                                <p class="max-w-md text-sm font-semibold leading-6 text-white" x-text="invalidDropMessage"></p>
                            </div>

                            <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                                <div class="flex min-w-0 flex-1 items-start gap-3">
                                    @if($point->type !== \App\Modules\FlowRoutes\Enums\FlowRoutePointType::ChangeStatus->value)
                                        <button
                                            type="button"
                                            class="mt-0.5 inline-flex h-8 w-8 shrink-0 cursor-grab items-center justify-center rounded-lg bg-white/90 text-base font-bold text-slate-500 ring-1 ring-black/10 active:cursor-grabbing"
                                            aria-label="Drag to reorder Point"
                                            title="Drag to reorder"
                                        >
                                            ⋮⋮
                                        </button>
                                    @else
                                        <span class="h-8 w-8 shrink-0" aria-hidden="true"></span>
                                    @endif

                                    <div class="min-w-0 flex-1">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <span class="rounded-full px-2.5 py-1 text-xs font-semibold ring-1 {{ module_tone($moduleKey, 'badge') }}">
                                                {{ $presented['type_label'] ?? \Illuminate\Support\Str::headline($point->type) }}
                                            </span>
                                        </div>

                                        <p class="mt-2 font-semibold leading-6 text-slate-950">
                                            {{ $presented['summary'] ?? $point->description ?? $point->name }}
                                        </p>

                                        @if(($presented['label'] ?? null) && \Illuminate\Support\Str::lower(trim($presented['label'])) !== \Illuminate\Support\Str::lower(trim($presented['summary'] ?? '')))
                                            <p class="mt-1 text-xs leading-5 text-slate-600">
                                                Label: {{ $presented['label'] }}
                                            </p>
                                        @endif

                                        @foreach(($presented['condition_summaries'] ?? []) as $conditionSummary)
                                            <p class="mt-1 text-xs leading-5 text-slate-700">
                                                {{ $conditionSummary }}
                                            </p>
                                        @endforeach

                                        @if(($presented['decision_paths'] ?? []) !== [])
                                            <div data-flow-route-decision-paths class="mt-3 grid gap-2 sm:grid-cols-2">
                                                @foreach($presented['decision_paths'] as $path)
                                                    <div class="rounded-xl bg-white/85 px-3 py-2 text-xs leading-5 ring-1 ring-black/10">
                                                        <span class="block font-semibold text-slate-800">{{ $path['condition'] }}</span>
                                                        <span class="mt-0.5 block text-slate-600">
                                                            @if(($path['destination_key'] ?? null) === null)
                                                                End this Route
                                                            @else
                                                                Continue at: {{ $path['destination'] }}
                                                            @endif
                                                        </span>
                                                    </div>
                                                @endforeach
                                            </div>
                                        @endif

                                        @foreach(($presented['resources'] ?? []) as $resource)
                                            <div class="mt-3 rounded-xl bg-white/85 p-3 text-xs leading-5 ring-1 ring-black/10">
                                                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                                    <div class="min-w-0">
                                                        <p class="font-semibold text-slate-900">{{ $resource['title'] ?? '' }}</p>
                                                        @if(filled($resource['subtitle'] ?? null))
                                                            <p class="mt-0.5 font-medium text-slate-700">{{ $resource['subtitle'] }}</p>
                                                        @endif
                                                        @if(filled($resource['body'] ?? null))
                                                            <p class="mt-1 text-slate-600">{{ $resource['body'] }}</p>
                                                        @endif
                                                    </div>

                                                    @if(filled($resource['action_url'] ?? null))
                                                        <a
                                                            href="{{ $resource['action_url'] }}"
                                                            @if(($resource['action_target'] ?? null) === '_blank') target="_blank" rel="noopener" @endif
                                                            class="inline-flex w-full shrink-0 items-center justify-center rounded-lg border border-slate-300 bg-white px-3 py-2 font-semibold text-slate-900 shadow-sm hover:bg-slate-50 sm:w-auto"
                                                        >
                                                            {{ $resource['action_label'] ?? 'Open' }}
                                                        </a>
                                                    @endif
                                                </div>

                                                @if(is_array($resource['details'] ?? null) && $resource['details'] !== [])
                                                    <dl class="mt-3 grid gap-2 border-t border-slate-200 pt-3 sm:grid-cols-2">
                                                        @foreach($resource['details'] as $detailLabel => $detailValue)
                                                            <div>
                                                                <dt class="font-semibold uppercase tracking-wide text-slate-500">{{ $detailLabel }}</dt>
                                                                <dd class="mt-0.5 font-medium text-slate-800">{{ $detailValue }}</dd>
                                                            </div>
                                                        @endforeach
                                                    </dl>
                                                @endif
                                            </div>
                                        @endforeach
                                    </div>
                                </div>

                                <div class="grid w-full shrink-0 grid-cols-2 gap-2 sm:flex sm:w-auto sm:flex-wrap sm:items-center">
                                    <button
                                        type="button"
                                        @click="openPoint({{ $point->getKey() }})"
                                        class="inline-flex w-full items-center justify-center rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm font-semibold text-slate-800 shadow-sm transition hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-orange-400 sm:w-auto sm:py-2"
                                    >
                                        Edit
                                    </button>

                                    <form
                                        method="POST"
                                        action="{{ route('crm.flow-routes.points.destroy', [$flowRoute, $point]) }}"
                                        @submit="handleRemove($event, {{ $point->getKey() }})"
                                        class="w-full sm:w-auto"
                                    >
                                        @csrf
                                        @method('DELETE')

                                        <span
                                            class="inline-flex w-full sm:w-auto"
                                            :title="removalError({{ $point->getKey() }}) ?? ''"
                                        >
                                            <button
                                                type="submit"
                                                :disabled="!canRemove({{ $point->getKey() }})"
                                                class="inline-flex w-full items-center justify-center rounded-xl border px-3 py-2.5 text-sm font-semibold shadow-sm transition focus-visible:outline-none focus-visible:ring-2 sm:w-auto sm:py-2"
                                                :class="canRemove({{ $point->getKey() }})
                                                    ? 'border-red-300 bg-white text-red-700 hover:bg-red-50 focus-visible:ring-red-300'
                                                    : 'cursor-not-allowed border-slate-300 bg-slate-200 text-slate-500 shadow-none'"
                                            >
                                                Remove
                                            </button>
                                        </span>
                                    </form>

                                    <div class="col-span-2 flex items-center justify-end gap-2 sm:col-auto sm:justify-start" aria-label="Move Point without dragging">
                                        <form method="POST" action="{{ route('crm.flow-routes.points.move-up', [$flowRoute, $point]) }}">
                                            @csrf
                                            @method('PATCH')

                                            <button
                                                type="submit"
                                                class="inline-flex h-10 w-10 items-center justify-center rounded-lg border border-slate-300 bg-white text-sm font-bold text-slate-700 shadow-sm transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-35"
                                                aria-label="Move Point up"
                                                :disabled="!canMove({{ $point->getKey() }}, -1)"
                                                @disabled($loop->first)
                                            >
                                                ↑
                                            </button>
                                        </form>

                                        <form method="POST" action="{{ route('crm.flow-routes.points.move-down', [$flowRoute, $point]) }}">
                                            @csrf
                                            @method('PATCH')

                                            <button
                                                type="submit"
                                                class="inline-flex h-10 w-10 items-center justify-center rounded-lg border border-slate-300 bg-white text-sm font-bold text-slate-700 shadow-sm transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-35"
                                                aria-label="Move Point down"
                                                :disabled="!canMove({{ $point->getKey() }}, 1)"
                                                @disabled($loop->last)
                                            >
                                                ↓
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </article>

                        <div
                            x-cloak
                            style="display: none;"
                            x-show="pointModal === {{ $point->getKey() }}"
                            x-transition.opacity
                            class="fixed inset-0 z-[60] flex items-center justify-center bg-slate-950/60 p-3 sm:p-6"
                        >
                            <div @click.outside="closePoint()" class="max-h-[calc(100dvh-1.5rem)] w-full max-w-2xl overflow-y-auto rounded-3xl bg-white p-4 shadow-2xl ring-1 ring-black/10 sm:max-h-[92vh] sm:p-7">
                                <div class="flex items-start justify-between gap-4">
                                    <div>
                                        <p class="text-sm font-semibold uppercase tracking-[0.14em] text-orange-800">Edit Point</p>
                                        <h3 class="mt-1 text-xl font-semibold text-slate-950">{{ $presented['type_label'] ?? \Illuminate\Support\Str::headline($point->type) }}</h3>
                                    </div>

                                    <button type="button" @click="closePoint()" class="inline-flex h-9 w-9 items-center justify-center rounded-full border border-slate-300 bg-white text-xl text-slate-700" aria-label="Close Point editor">×</button>
                                </div>

                                <form method="POST" action="{{ route('crm.flow-routes.points.update', [$flowRoute, $point]) }}" class="mt-6 space-y-4">
                                    @csrf
                                    @method('PATCH')

                                    <div>
                                        <label for="point-name-{{ $point->id }}" class="text-sm font-semibold text-slate-900">
                                            {{ $point->type === \App\Modules\FlowRoutes\Enums\FlowRoutePointType::BranchEvaluate->value ? 'Decision question' : 'Internal label' }}
                                            <span class="font-normal text-slate-600">(optional)</span>
                                        </label>
                                        <input
                                            id="point-name-{{ $point->id }}"
                                            name="name"
                                            type="text"
                                            value="{{ old('name', $point->name) }}"
                                            class="mt-1 block w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm text-slate-950 shadow-sm focus:border-orange-500 focus:outline-none focus:ring-2 focus:ring-orange-200"
                                        >
                                    </div>

                                    @include('crm.flow-routes.partials.point-fields', [
                                        'fields' => $presented['fields'] ?? [],
                                        'fieldSuffix' => 'edit-'.$point->id,
                                    ])

                                    <div class="grid gap-2 pt-2 sm:flex sm:justify-end sm:gap-3">
                                        <button type="button" @click="closePoint()" class="w-full rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-800 sm:w-auto">Cancel</button>
                                        <button type="submit" class="w-full rounded-xl bg-slate-950 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-slate-800 sm:w-auto">Save Point</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    @empty
                        <div class="rounded-2xl border border-dashed border-amber-300 bg-amber-50 px-5 py-6 text-sm text-amber-950">
                            This Route has no active actions. Add the first action below, then choose whether it should happen immediately or after a delay.
                        </div>
                    @endforelse
                </div>
            </section>

            <section
                class="mt-8 border-t border-orange-100 pt-7"
                x-data="{
                    selectedPointModules: [],
                    togglePointModule(module) {
                        this.selectedPointModules = this.selectedPointModules.includes(module)
                            ? this.selectedPointModules.filter(selected => selected !== module)
                            : [...this.selectedPointModules, module];
                    },
                    pointModuleVisible(module) {
                        return this.selectedPointModules.length === 0
                            || this.selectedPointModules.includes(module);
                    },
                }"
                data-flow-route-point-module-filters
            >
                <div>
                    <h3 class="text-lg font-semibold tracking-tight text-slate-950">Add a Point</h3>
                    <p class="mt-1 max-w-3xl text-sm leading-6 text-slate-700">
                        Choose what should happen next. Each Point type includes a practical tip and example use cases.
                    </p>
                </div>

                @if($capabilityModules->count() > 1)
                    <div class="mt-4 flex flex-wrap gap-2" aria-label="Filter Points by module">
                        <button
                            type="button"
                            @click="selectedPointModules = []"
                            :aria-pressed="selectedPointModules.length === 0"
                            :class="selectedPointModules.length === 0 ? 'ring-2 ring-slate-950' : 'opacity-70 hover:opacity-100'"
                            class="rounded-full bg-white px-3 py-1.5 text-xs font-semibold text-slate-800 ring-1 ring-slate-300 transition"
                            data-flow-route-point-module-filter="all"
                        >
                            All
                        </button>

                        @foreach($capabilityModules as $module)
                            <button
                                type="button"
                                @click="togglePointModule(@js($module['key']))"
                                :aria-pressed="selectedPointModules.includes(@js($module['key']))"
                                :class="selectedPointModules.includes(@js($module['key'])) ? 'ring-2 ring-slate-950' : 'opacity-70 hover:opacity-100'"
                                class="rounded-full px-3 py-1.5 text-xs font-semibold ring-1 transition {{ module_tone($module['key'], 'badge') }}"
                                data-flow-route-point-module-filter="{{ $module['key'] }}"
                            >
                                {{ $module['label'] }}
                            </button>
                        @endforeach
                    </div>
                @endif

                <div class="mt-5 grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                    @forelse($capabilities as $capability)
                        <button
                            type="button"
                            @click="openAddPoint({{ $capability['id'] }})"
                            x-show="pointModuleVisible(@js($capability['module_key']))"
                            x-transition.opacity
                            class="rounded-2xl p-4 text-left ring-1 transition hover:-translate-y-0.5 hover:shadow-md {{ module_tone($capability['module_key'], 'item') }}"
                            data-flow-route-point-module-card="{{ $capability['module_key'] }}"
                        >
                            <div class="flex flex-col items-start gap-2 sm:flex-row sm:items-center sm:justify-between sm:gap-3">
                                <span class="font-semibold text-slate-950">{{ $capability['name'] }}</span>
                                <span class="rounded-full px-2.5 py-1 text-xs font-semibold ring-1 {{ module_tone($capability['module_key'], 'badge') }}">
                                    {{ config('modules.modules.'.$capability['module_key'].'.name', \Illuminate\Support\Str::headline($capability['module_key'])) }}
                                </span>
                            </div>
                            <p class="mt-2 text-sm leading-6 text-slate-700">{{ $capability['description'] }}</p>
                        </button>

                        <div
                            x-cloak
                            style="display: none;"
                            x-show="addPointModal === {{ $capability['id'] }}"
                            x-transition.opacity
                            class="fixed inset-0 z-[60] flex items-center justify-center bg-slate-950/60 p-3 sm:p-6"
                        >
                            <div @click.outside="closeAddPoint()" class="max-h-[calc(100dvh-1.5rem)] w-full max-w-2xl overflow-y-auto rounded-3xl bg-white p-4 shadow-2xl ring-1 ring-black/10 sm:max-h-[92vh] sm:p-7">
                                <div class="flex items-start justify-between gap-4">
                                    <div>
                                        <p class="text-sm font-semibold uppercase tracking-[0.14em] text-orange-800">Add Point</p>
                                        <h3 class="mt-1 text-xl font-semibold text-slate-950">{{ $capability['name'] }}</h3>
                                    </div>
                                    <button type="button" @click="closeAddPoint()" class="inline-flex h-9 w-9 items-center justify-center rounded-full border border-slate-300 bg-white text-xl text-slate-700" aria-label="Close Add Point dialog">×</button>
                                </div>

                                <p class="mt-3 text-sm leading-6 text-slate-700">{{ $capability['description'] }}</p>

                                @if($capability['tip'])
                                    <div class="mt-4 rounded-xl bg-orange-50 p-3 text-sm leading-6 text-orange-950 ring-1 ring-orange-200">
                                        <span class="font-semibold">Tip:</span> {{ $capability['tip'] }}
                                    </div>
                                @endif

                                @if($capability['use_cases'] !== [])
                                    <div class="mt-4">
                                        <p class="text-xs font-bold uppercase tracking-wide text-slate-700">Common use cases</p>
                                        <ul class="mt-2 space-y-1 text-sm leading-6 text-slate-700">
                                            @foreach($capability['use_cases'] as $useCase)
                                                <li class="flex gap-2"><span aria-hidden="true">•</span><span>{{ $useCase }}</span></li>
                                            @endforeach
                                        </ul>
                                    </div>
                                @endif

                                <form method="POST" action="{{ route('crm.flow-routes.points.store', $flowRoute) }}" class="mt-6 space-y-4">
                                    @csrf
                                    <input type="hidden" name="capability_id" value="{{ $capability['id'] }}">

                                    <div>
                                        <label for="point-name-{{ $flowRoute->id }}-{{ $capability['id'] }}" class="text-sm font-semibold text-slate-900">
                                            {{ ($capability['point_type'] ?? '') === \App\Modules\FlowRoutes\Enums\FlowRoutePointType::BranchEvaluate->value ? 'Decision question' : 'Internal label' }}
                                            <span class="font-normal text-slate-600">(optional)</span>
                                        </label>
                                        <input
                                            id="point-name-{{ $flowRoute->id }}-{{ $capability['id'] }}"
                                            name="name"
                                            type="text"
                                            value="{{ old('name') }}"
                                            placeholder="{{ $capability['name'] }}"
                                            class="mt-1 block w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm text-slate-950 shadow-sm focus:border-orange-500 focus:outline-none focus:ring-2 focus:ring-orange-200"
                                        >
                                    </div>

                                    @include('crm.flow-routes.partials.point-fields', [
                                        'fields' => $capability['fields'] ?? [],
                                        'fieldSuffix' => 'create-'.$flowRoute->id.'-'.$capability['id'],
                                    ])

                                    <div class="grid gap-2 pt-2 sm:flex sm:justify-end sm:gap-3">
                                        <button type="button" @click="closeAddPoint()" class="w-full rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-800 sm:w-auto">Cancel</button>
                                        <button type="submit" class="w-full rounded-xl bg-slate-950 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-slate-800 sm:w-auto">Add Point</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    @empty
                        <div class="rounded-2xl border border-dashed border-amber-300 bg-amber-50 px-5 py-6 text-sm text-amber-950 md:col-span-2 xl:col-span-3">
                            No authorable capabilities are currently available.
                        </div>
                    @endforelse
                </div>
            </section>
        </div>
    </div>
</div>
</template>