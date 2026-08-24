@php
    $selectedCriteria = is_array($eligibility['selected'] ?? null)
        ? $eligibility['selected']
        : [];
    $selectedCriterionCount = collect($selectedCriteria)
        ->sum(fn (mixed $values): int => is_array($values) ? count($values) : 0);
    $startSummary = $campaign->usesAutomaticEnrollment()
        ? ($selectedCriterionCount > 0
            ? $selectedCriterionCount.' selected eligibility '.\Illuminate\Support\Str::plural('value', $selectedCriterionCount)
            : 'Automatic enrollment needs at least one condition')
        : 'Contacts enter only through an explicit enrollment action';
    $messagePresentation = is_array($messageReview['presentation'] ?? null)
        ? $messageReview['presentation']
        : [];
    $messageReviewCount = (int) ($messageReview['message_count'] ?? 0);
    $scheduleSteps = is_array($workspace['schedule_steps'] ?? null)
        ? array_values($workspace['schedule_steps'])
        : [];
    $startHasErrors = collect($errors->keys())->contains(
        fn (string $key): bool => in_array($key, [
            'enrollment_mode',
            'reentry_policy',
            'ineligible_behavior',
        ], true) || str_starts_with($key, 'eligibility_criteria'),
    );
    $messageReturnPath = route('crm.campaigns.edit', [
        'campaign' => $campaign,
        'panel' => 'messages',
    ], false);
    $reviewReturnPath = route('crm.campaigns.edit', [
        'campaign' => $campaign,
        'panel' => 'review',
    ], false);
@endphp

<x-layouts.crm
    :title="$campaign->name.' setup'"
    heading="Campaign setup"
    :subheading="$campaign->name"
    module="campaigns"
>
    <div
        class="min-w-0 space-y-6"
        data-campaign-setup
        x-data="{
            activeModal: @js(in_array($initialPanel, ['schedule', 'messages'], true) ? $initialPanel : null),
            startOpen: @js($initialPanel === 'start' || $startHasErrors),
            openStart() {
                this.startOpen = true;
                this.$nextTick(() => document.getElementById('campaign-start-editor')?.scrollIntoView({ behavior: 'smooth', block: 'start' }));
            },
            openModal(panel) {
                this.activeModal = panel;
            },
            closeModal() {
                this.activeModal = null;
            },
        }"
        x-init="if (@js($initialPanel) === 'review') { $nextTick(() => document.getElementById('campaign-review')?.scrollIntoView({ block: 'start' })) }"
        x-on:keydown.escape.window="closeModal()"
    >
        @if(session('status'))
            <div class="rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-900">
                {{ session('status') }}
            </div>
        @endif

        @if(session('error'))
            <div class="rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-semibold text-red-900">
                {{ session('error') }}
            </div>
        @endif

        <div class="flex min-w-0 flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <a href="{{ route('crm.campaigns.show', $campaign) }}" class="break-words text-sm font-semibold text-slate-600 hover:text-slate-950">
                &larr; Campaign overview
            </a>

            <button
                type="button"
                data-campaign-panel-open="messages"
                x-on:click="openModal('messages')"
                @disabled($messageReviewCount < 1)
                class="inline-flex min-h-11 w-full items-center justify-center rounded-full border border-slate-300 bg-white px-4 text-sm font-bold text-slate-800 transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50 sm:w-auto"
            >
                Review messages
            </button>
        </div>

        <x-campaigns.builder-shell :stages="$workspace['builder_stages']" mode="edit">
            <div class="grid min-w-0 gap-4 lg:grid-cols-2">
                <section class="min-w-0 rounded-3xl border border-slate-200 bg-white p-4 shadow-sm sm:p-6">
                    <div class="flex min-w-0 flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                        <div class="min-w-0">
                            <p class="text-xs font-bold uppercase tracking-[0.16em] text-slate-500">1 · Start</p>
                            <h3 class="mt-2 break-words text-lg font-semibold text-slate-950">What makes this campaign start?</h3>
                        </div>
                        <span class="w-fit shrink-0 rounded-full bg-emerald-100 px-3 py-1 text-xs font-bold text-emerald-800">Editable</span>
                    </div>
                    <p class="mt-3 break-words text-sm leading-6 text-slate-600">{{ $startSummary }}</p>
                    <div class="mt-4 flex flex-wrap gap-2 text-xs font-bold text-slate-600">
                        <span class="rounded-full bg-slate-100 px-3 py-1">
                            {{ $campaign->usesAutomaticEnrollment() ? 'Automatic' : 'Manual' }}
                        </span>
                        <span class="rounded-full bg-slate-100 px-3 py-1">
                            {{ number_format((int) ($eligibility['matching_count'] ?? 0)) }} matching now
                        </span>
                    </div>
                    <button
                        type="button"
                        data-campaign-panel-open="start"
                        x-on:click="openStart()"
                        class="mt-5 inline-flex min-h-11 w-full items-center justify-center rounded-full border border-slate-300 bg-white px-4 text-sm font-bold text-slate-800 transition hover:bg-slate-50 sm:w-auto"
                    >
                        Edit start settings
                    </button>
                </section>

                <section class="min-w-0 rounded-3xl border border-slate-200 bg-white p-4 shadow-sm sm:p-6">
                    <div class="flex min-w-0 flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                        <div class="min-w-0">
                            <p class="text-xs font-bold uppercase tracking-[0.16em] text-slate-500">2 · Schedule</p>
                            <h3 class="mt-2 break-words text-lg font-semibold text-slate-950">Current schedule</h3>
                        </div>
                        <span class="w-fit shrink-0 rounded-full bg-slate-100 px-3 py-1 text-xs font-bold text-slate-600">View</span>
                    </div>
                    <p class="mt-3 break-words text-sm leading-6 text-slate-600">
                        {{ $workspace['message_step_count'] }} active message {{ \Illuminate\Support\Str::plural('step', $workspace['message_step_count']) }} currently define the campaign timeline.
                    </p>
                    @if($workspace['channels'] !== [])
                        <div class="mt-4 flex min-w-0 flex-wrap gap-2">
                            @foreach($workspace['channels'] as $channel)
                                <span class="rounded-full bg-rose-50 px-3 py-1 text-xs font-bold text-rose-800 ring-1 ring-inset ring-rose-200">
                                    {{ strtoupper($channel) }}
                                </span>
                            @endforeach
                        </div>
                    @endif
                    <button
                        type="button"
                        data-campaign-panel-open="schedule"
                        x-on:click="openModal('schedule')"
                        class="mt-5 inline-flex min-h-11 w-full items-center justify-center rounded-full border border-slate-300 bg-white px-4 text-sm font-bold text-slate-800 transition hover:bg-slate-50 sm:w-auto"
                    >
                        View current schedule
                    </button>
                </section>

                <section class="min-w-0 rounded-3xl border border-slate-200 bg-white p-4 shadow-sm sm:p-6">
                    <div class="flex min-w-0 flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                        <div class="min-w-0">
                            <p class="text-xs font-bold uppercase tracking-[0.16em] text-slate-500">3 · Messages</p>
                            <h3 class="mt-2 break-words text-lg font-semibold text-slate-950">Review the messages</h3>
                        </div>
                        <span class="w-fit shrink-0 rounded-full bg-emerald-100 px-3 py-1 text-xs font-bold text-emerald-800">Editable</span>
                    </div>
                    <p class="mt-3 break-words text-sm leading-6 text-slate-600">
                        {{ $messageReviewCount }} selected {{ \Illuminate\Support\Str::plural('message', $messageReviewCount) }} can be reviewed and edited without leaving Campaign Setup.
                    </p>
                    <button
                        type="button"
                        data-campaign-panel-open="messages"
                        x-on:click="openModal('messages')"
                        @disabled($messageReviewCount < 1)
                        class="mt-5 inline-flex min-h-11 w-full items-center justify-center rounded-full bg-slate-950 px-4 text-sm font-bold text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-50 sm:w-auto"
                    >
                        Review messages
                    </button>
                    @if($messageReviewCount < 1 && (int) $workspace['message_count'] > 0)
                        <a
                            href="{{ route('crm.campaigns.message-templates.index', ['campaign' => $campaign->getKey()]) }}"
                            class="mt-3 inline-flex text-sm font-semibold text-slate-600 underline decoration-slate-300 underline-offset-4 hover:text-slate-950"
                        >
                            Choose message templates
                        </a>
                    @endif
                </section>

                <section id="campaign-review" class="min-w-0 rounded-3xl border border-slate-200 bg-white p-4 shadow-sm sm:p-6">
                    <div class="flex min-w-0 flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                        <div class="min-w-0">
                            <p class="text-xs font-bold uppercase tracking-[0.16em] text-slate-500">4 · Review</p>
                            <h3 class="mt-2 break-words text-lg font-semibold text-slate-950">Confirm before going live</h3>
                        </div>
                        <span class="w-fit shrink-0 rounded-full px-3 py-1 text-xs font-bold {{ $campaign->isActive() ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-600' }}">
                            {{ $campaign->isActive() ? 'Active' : 'Off' }}
                        </span>
                    </div>
                    <p class="mt-3 break-words text-sm leading-6 text-slate-600">
                        {{ $workspace['active_enrollment_count'] }} current {{ \Illuminate\Support\Str::plural('participant', $workspace['active_enrollment_count']) }} · {{ $workspace['pending_message_count'] }} pending {{ \Illuminate\Support\Str::plural('message', $workspace['pending_message_count']) }}
                    </p>

                    @if($campaign->status === \App\Modules\Campaigns\Models\Campaign::STATUS_INACTIVE)
                        <form method="POST" action="{{ route('crm.campaigns.activate', $campaign) }}" class="mt-5">
                            @csrf
                            @method('PATCH')
                            <input type="hidden" name="return_to" value="{{ $reviewReturnPath }}">
                            <button
                                type="submit"
                                data-campaign-lifecycle-action="activate"
                                class="inline-flex min-h-11 w-full items-center justify-center rounded-full bg-emerald-700 px-5 text-sm font-bold text-white transition hover:bg-emerald-800 sm:w-auto"
                            >
                                Activate campaign
                            </button>
                        </form>
                    @elseif($campaign->status === \App\Modules\Campaigns\Models\Campaign::STATUS_ACTIVE)
                        <form
                            method="POST"
                            action="{{ route('crm.campaigns.deactivate', $campaign) }}"
                            class="mt-5"
                            onsubmit="return confirm('Turn off this Campaign, cancel active enrollments, and skip pending Campaign messages?');"
                        >
                            @csrf
                            @method('PATCH')
                            <input type="hidden" name="return_to" value="{{ $reviewReturnPath }}">
                            <button
                                type="submit"
                                data-campaign-lifecycle-action="deactivate"
                                class="inline-flex min-h-11 w-full items-center justify-center rounded-full border border-red-200 bg-white px-5 text-sm font-bold text-red-700 transition hover:bg-red-50 sm:w-auto"
                            >
                                Turn off campaign
                            </button>
                        </form>
                    @else
                        <p class="mt-5 rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-semibold text-amber-900">
                            Archived Campaigns cannot be activated from this screen.
                        </p>
                    @endif
                </section>
            </div>
        </x-campaigns.builder-shell>

        <section
            id="campaign-start-editor"
            data-campaign-start-editor
            x-show="startOpen"
            x-cloak
            class="scroll-mt-6 rounded-3xl border border-rose-200 bg-white p-4 shadow-sm sm:p-6"
        >
            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <p class="text-xs font-bold uppercase tracking-[0.16em] text-rose-700">Campaign Start</p>
                    <h2 class="mt-2 text-xl font-semibold text-slate-950">Choose who becomes eligible</h2>
                    <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-600">
                        Different condition types must all match. Multiple selected values inside one condition type match any selected value.
                    </p>
                </div>
                <button
                    type="button"
                    x-on:click="startOpen = false"
                    class="inline-flex min-h-10 items-center justify-center rounded-full border border-slate-300 bg-white px-4 text-sm font-bold text-slate-700 hover:bg-slate-50"
                >
                    Close
                </button>
            </div>

            <form
                method="POST"
                action="{{ route('crm.campaigns.eligibility.update', $campaign) }}"
                x-data="{
                    matchingCount: @js((int) ($eligibility['matching_count'] ?? 0)),
                    previewing: false,
                    previewError: '',
                    async preview() {
                        this.previewing = true;
                        this.previewError = '';
                        const data = new FormData(this.$refs.form);
                        data.delete('_method');

                        try {
                            const response = await fetch(@js(route('crm.campaigns.eligibility.preview', $campaign)), {
                                method: 'POST',
                                body: data,
                                headers: {
                                    'Accept': 'application/json',
                                    'X-Requested-With': 'XMLHttpRequest',
                                },
                            });
                            const payload = await response.json();

                            if (! response.ok) {
                                throw new Error(payload.message || 'Unable to preview eligibility.');
                            }

                            this.matchingCount = payload.matching_count || 0;
                        } catch (error) {
                            this.previewError = error.message || 'Unable to preview eligibility.';
                        } finally {
                            this.previewing = false;
                        }
                    },
                }"
                x-ref="form"
                data-campaign-eligibility-form
                class="mt-6 space-y-6"
            >
                @csrf
                @method('PATCH')

                <div class="grid gap-4 lg:grid-cols-3">
                    <label class="block rounded-2xl border border-slate-200 bg-slate-50 p-4">
                        <span class="text-xs font-bold uppercase tracking-[0.12em] text-slate-500">Enrollment</span>
                        <select name="enrollment_mode" class="mt-2 block min-h-11 w-full rounded-xl border-slate-300 bg-white text-sm font-semibold text-slate-900">
                            @foreach($eligibility['enrollment_modes'] as $value => $label)
                                <option value="{{ $value }}" @selected(old('enrollment_mode', $campaign->enrollment_mode) === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('enrollment_mode')<p class="mt-2 text-sm font-semibold text-red-600">{{ $message }}</p>@enderror
                    </label>

                    <label class="block rounded-2xl border border-slate-200 bg-slate-50 p-4">
                        <span class="text-xs font-bold uppercase tracking-[0.12em] text-slate-500">Re-entry</span>
                        <select name="reentry_policy" class="mt-2 block min-h-11 w-full rounded-xl border-slate-300 bg-white text-sm font-semibold text-slate-900">
                            @foreach($eligibility['reentry_policies'] as $value => $label)
                                <option value="{{ $value }}" @selected(old('reentry_policy', $campaign->reentry_policy) === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('reentry_policy')<p class="mt-2 text-sm font-semibold text-red-600">{{ $message }}</p>@enderror
                    </label>

                    <label class="block rounded-2xl border border-slate-200 bg-slate-50 p-4">
                        <span class="text-xs font-bold uppercase tracking-[0.12em] text-slate-500">If eligibility ends</span>
                        <select name="ineligible_behavior" class="mt-2 block min-h-11 w-full rounded-xl border-slate-300 bg-white text-sm font-semibold text-slate-900">
                            @foreach($eligibility['ineligible_behaviors'] as $value => $label)
                                <option value="{{ $value }}" @selected(old('ineligible_behavior', $campaign->ineligible_behavior) === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('ineligible_behavior')<p class="mt-2 text-sm font-semibold text-red-600">{{ $message }}</p>@enderror
                    </label>
                </div>

                <div class="grid gap-4 lg:grid-cols-2">
                    @foreach($eligibility['criteria'] as $criterion)
                        @php
                            $criterionKey = (string) $criterion['key'];
                            $selectedValues = old(
                                'eligibility_criteria.'.$criterionKey,
                                $selectedCriteria[$criterionKey] ?? [],
                            );
                            $selectedValues = is_array($selectedValues) ? $selectedValues : [];
                        @endphp
                        <fieldset class="rounded-2xl border border-slate-200 bg-white p-4">
                            <legend class="px-1 text-sm font-bold text-slate-950">{{ $criterion['label'] }}</legend>
                            @if(filled($criterion['help'] ?? null))
                                <p class="mt-1 text-xs leading-5 text-slate-500">{{ $criterion['help'] }}</p>
                            @endif
                            <div class="mt-3 grid gap-2 sm:grid-cols-2">
                                @forelse($criterion['options'] as $option)
                                    <label class="flex min-h-11 items-start gap-3 rounded-xl border border-slate-200 px-3 py-2.5 text-sm text-slate-800 hover:bg-slate-50">
                                        <input
                                            type="checkbox"
                                            name="eligibility_criteria[{{ $criterionKey }}][]"
                                            value="{{ $option['value'] }}"
                                            @checked(in_array($option['value'], $selectedValues, true))
                                            class="mt-0.5 rounded border-slate-300 text-rose-700 focus:ring-rose-600"
                                        >
                                        <span>{{ $option['label'] }}</span>
                                    </label>
                                @empty
                                    <p class="text-sm text-slate-500">No available values.</p>
                                @endforelse
                            </div>
                            @error('eligibility_criteria.'.$criterionKey)<p class="mt-2 text-sm font-semibold text-red-600">{{ $message }}</p>@enderror
                        </fieldset>
                    @endforeach
                </div>

                @if(($eligibility['unavailable_criteria'] ?? []) !== [])
                    <div class="rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                        Some saved conditions are owned by features that are not currently available here. They will be preserved when these settings are saved.
                    </div>
                @endif

                @error('eligibility_criteria')
                    <p class="rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-semibold text-red-700">{{ $message }}</p>
                @enderror

                <div class="flex flex-col gap-4 border-t border-slate-200 pt-5 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <p class="text-sm font-semibold text-slate-950">
                            <span x-text="Number(matchingCount).toLocaleString()"></span> matching contacts now
                        </p>
                        <p x-show="previewError" x-text="previewError" class="mt-1 text-xs font-semibold text-red-600"></p>
                    </div>
                    <div class="flex flex-col gap-2 sm:flex-row">
                        <button
                            type="button"
                            x-on:click="preview()"
                            x-bind:disabled="previewing"
                            class="inline-flex min-h-11 items-center justify-center rounded-full border border-slate-300 bg-white px-5 text-sm font-bold text-slate-700 hover:bg-slate-50 disabled:opacity-50"
                        >
                            <span x-show="!previewing">Preview audience</span>
                            <span x-show="previewing">Checking…</span>
                        </button>
                        <button
                            type="submit"
                            class="inline-flex min-h-11 items-center justify-center rounded-full bg-slate-950 px-6 text-sm font-bold text-white hover:bg-slate-800"
                        >
                            Save start settings
                        </button>
                    </div>
                </div>
            </form>
        </section>

        <div
            x-show="activeModal === 'messages'"
            x-cloak
            x-on:click.self="closeModal()"
            data-campaign-panel-modal="messages"
            class="fixed inset-0 z-50 flex items-center justify-center overflow-y-auto bg-slate-950/60 px-3 py-4 sm:px-6"
        >
            <div role="dialog" aria-modal="true" aria-label="Campaign messages" class="max-h-[calc(100vh-2rem)] w-full max-w-6xl overflow-y-auto rounded-3xl bg-white shadow-2xl">
                <header class="sticky top-0 z-30 flex flex-col gap-4 border-b border-slate-200 bg-white/95 px-4 py-4 backdrop-blur sm:flex-row sm:items-start sm:justify-between sm:px-6">
                    <div>
                        <p class="text-xs font-bold uppercase tracking-[0.16em] text-rose-700">Campaign messages</p>
                        <h2 class="mt-1 text-xl font-semibold text-slate-950">{{ $campaign->name }}</h2>
                        <p class="mt-1 text-sm text-slate-600">{{ $messageReviewCount }} selected {{ \Illuminate\Support\Str::plural('message', $messageReviewCount) }}</p>
                    </div>
                    <button type="button" x-on:click="closeModal()" class="inline-flex min-h-10 items-center justify-center rounded-full border border-slate-300 bg-white px-4 text-sm font-bold text-slate-700 hover:bg-slate-50">Close</button>
                </header>

                <div class="p-4 sm:p-6">
                    <x-messaging.message-editor-carousel
                        :presentation="$messagePresentation"
                        :editable="true"
                        empty-message="No selected Messaging templates are available for this Campaign yet."
                        :initial-message-id="$messageReview['initial_message_id'] ?? null"
                        :form-context="['return_to' => $messageReturnPath]"
                    />
                </div>

                <footer class="flex flex-col gap-3 border-t border-slate-200 bg-slate-50 px-4 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-6">
                    <p class="text-xs leading-5 text-slate-500">
                        Saving publishes a new immutable message version. Existing scheduled messages remain pinned to the version they already use.
                    </p>
                    <a
                        href="{{ route('crm.campaigns.message-templates.index', ['campaign' => $campaign->getKey()]) }}"
                        class="inline-flex min-h-10 w-full shrink-0 items-center justify-center rounded-full border border-slate-300 bg-white px-4 text-sm font-bold text-slate-700 hover:bg-slate-50 sm:w-auto"
                    >
                        Advanced message setup
                    </a>
                </footer>
            </div>
        </div>

        <div
            x-show="activeModal === 'schedule'"
            x-cloak
            x-on:click.self="closeModal()"
            data-campaign-panel-modal="schedule"
            class="fixed inset-0 z-50 flex items-center justify-center overflow-y-auto bg-slate-950/60 px-3 py-4 sm:px-6"
        >
            <div
                role="dialog"
                aria-modal="true"
                aria-label="Campaign schedule"
                x-data="{ index: 0, count: @js(count($scheduleSteps)), navigate(delta) { if (this.count > 1) this.index = (this.index + delta + this.count) % this.count; } }"
                class="max-h-[calc(100vh-2rem)] w-full max-w-4xl overflow-y-auto rounded-3xl bg-white shadow-2xl"
            >
                <header class="sticky top-0 z-30 flex flex-col gap-4 border-b border-slate-200 bg-white/95 px-4 py-4 backdrop-blur sm:flex-row sm:items-start sm:justify-between sm:px-6">
                    <div>
                        <p class="text-xs font-bold uppercase tracking-[0.16em] text-rose-700">Current schedule</p>
                        <h2 class="mt-1 text-xl font-semibold text-slate-950">{{ $campaign->name }}</h2>
                        <p class="mt-1 text-sm text-slate-600">Timing and order only. Message copy is reviewed separately.</p>
                    </div>
                    <button type="button" x-on:click="closeModal()" class="inline-flex min-h-10 items-center justify-center rounded-full border border-slate-300 bg-white px-4 text-sm font-bold text-slate-700 hover:bg-slate-50">Close</button>
                </header>

                <div class="p-4 sm:p-6">
                    @if($scheduleSteps === [])
                        <div class="rounded-2xl border border-dashed border-slate-300 bg-slate-50 px-5 py-8 text-center">
                            <p class="font-bold text-slate-900">No active schedule steps are configured.</p>
                        </div>
                    @else
                        <div class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
                            <div class="flex items-center justify-between border-b border-slate-200 bg-slate-50 px-4 py-3 sm:px-6">
                                <span class="text-xs font-bold uppercase tracking-[0.12em] text-slate-500">Message schedule</span>
                                <span class="rounded-full bg-white px-3 py-1 text-xs font-bold text-slate-600 ring-1 ring-slate-200"><span x-text="index + 1"></span> of {{ count($scheduleSteps) }}</span>
                            </div>

                            <div class="relative px-12 py-5 sm:px-20 sm:py-8">
                                @if(count($scheduleSteps) > 1)
                                    <button type="button" aria-label="Previous schedule step" x-on:click="navigate(-1)" class="absolute inset-y-5 left-0 flex w-11 items-center justify-center text-3xl text-slate-400 hover:bg-slate-100 hover:text-slate-950 sm:inset-y-8 sm:w-16">‹</button>
                                    <button type="button" aria-label="Next schedule step" x-on:click="navigate(1)" class="absolute inset-y-5 right-0 flex w-11 items-center justify-center text-3xl text-slate-400 hover:bg-slate-100 hover:text-slate-950 sm:inset-y-8 sm:w-16">›</button>
                                @endif

                                @foreach($scheduleSteps as $scheduleIndex => $step)
                                    <article
                                        x-show="index === {{ $scheduleIndex }}"
                                        x-cloak
                                        data-campaign-schedule-step="{{ $step['step_number'] }}"
                                        class="mx-auto max-w-2xl rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6"
                                    >
                                        <div class="flex items-start gap-4">
                                            <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-slate-950 text-sm font-bold text-white">{{ $step['step_number'] }}</span>
                                            <div class="min-w-0">
                                                <h3 class="break-words text-lg font-semibold text-slate-950">{{ $step['name'] }}</h3>
                                                <p class="mt-2 text-sm font-semibold leading-6 text-slate-700">{{ $step['timing'] }}</p>
                                                <div class="mt-4 flex flex-wrap gap-2">
                                                    @foreach($step['channels'] as $channel)
                                                        <span class="rounded-full bg-rose-50 px-3 py-1 text-xs font-bold text-rose-800 ring-1 ring-inset ring-rose-200">{{ strtoupper($channel) }}</span>
                                                    @endforeach
                                                    <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-bold text-slate-600">{{ $step['message_count'] }} {{ \Illuminate\Support\Str::plural('message', $step['message_count']) }}</span>
                                                </div>
                                            </div>
                                        </div>
                                    </article>
                                @endforeach
                            </div>
                        </div>
                    @endif
                </div>

                <footer class="flex flex-col gap-3 border-t border-slate-200 bg-slate-50 px-4 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-6">
                    <p class="text-xs leading-5 text-slate-500">The schedule view never exposes message payload or implementation metadata.</p>
                    <button type="button" x-on:click="activeModal = 'messages'" @disabled($messageReviewCount < 1) class="inline-flex min-h-10 w-full items-center justify-center rounded-full border border-slate-300 bg-white px-4 text-sm font-bold text-slate-700 hover:bg-slate-50 disabled:opacity-50 sm:w-auto">Review message copy</button>
                </footer>
            </div>
        </div>
    </div>
</x-layouts.crm>