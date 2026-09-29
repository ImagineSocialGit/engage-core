@php
    $selectedCriteria = is_array($eligibility['selected'] ?? null)
        ? $eligibility['selected']
        : [];
    $excludedCriteria = is_array($eligibility['excluded'] ?? null)
        ? $eligibility['excluded']
        : [];
    $messagePresentation = is_array($messageReview['presentation'] ?? null)
        ? $messageReview['presentation']
        : [];
    $messageReviewCount = (int) ($messageReview['message_count'] ?? 0);
    $scheduleEditable = (bool) ($scheduleAuthoring['editable'] ?? false);
    $scheduleSteps = $scheduleEditable
        ? (is_array($scheduleAuthoring['steps'] ?? null)
            ? array_values($scheduleAuthoring['steps'])
            : [])
        : (is_array($workspace['schedule_steps'] ?? null)
            ? array_values($workspace['schedule_steps'])
            : []);
    $scheduleMessageOptions = is_array($scheduleAuthoring['message_options'] ?? null)
        ? array_values($scheduleAuthoring['message_options'])
        : [];
    $messageChainVersionId = (int) ($messageReview['message_chain_version_id'] ?? 0);
    $audienceHasErrors = collect($errors->keys())->contains(
        fn (string $key): bool => in_array($key, [
            'enrollment_mode',
            'reentry_policy',
            'ineligible_behavior',
        ], true)
            || str_starts_with($key, 'eligibility_criteria')
            || str_starts_with($key, 'eligibility_exclusions'),
    );
    $outreachHasErrors = collect($errors->keys())->contains(
        fn (string $key): bool => in_array($key, [
            'execution_strategy',
            'allocation_settings',
        ], true)
            || str_starts_with($key, 'allocation_settings.'),
    );
    $failedCampaignEditor = old('campaign_editor');
    $scheduleHasErrors = $failedCampaignEditor === 'schedule' && collect($errors->keys())->contains(
        fn (string $key): bool => $key === 'message_chain_version_id'
            || $key === 'steps'
            || $key === 'extend_in_progress'
            || str_starts_with($key, 'steps.')
            || str_starts_with($key, 'new_step.'),
    );
    $messageHasErrors = $failedCampaignEditor === 'messages' && collect($errors->keys())->contains(
        fn (string $key): bool => $key === 'payload'
            || str_starts_with($key, 'payload.')
            || $key === '_editing_message_id',
    );
    $initialModal = $scheduleHasErrors
        ? 'schedule'
        : ($messageHasErrors
            ? 'messages'
            : ($audienceHasErrors
                ? 'audience'
                : ($outreachHasErrors
                    ? 'outreach'
                    : (in_array($initialPanel, ['audience', 'outreach', 'schedule', 'messages'], true)
                        ? $initialPanel
                        : null))));
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
    heading="Edit Campaign"
    :subheading="$campaign->name"
    module="campaigns"
>
    <div
        class="min-w-0 space-y-6"
        data-campaign-setup
        x-data="{
            activeModal: @js($initialModal),
            openModal(panel) {
                this.activeModal = panel;
            },
            closeModal() {
                this.activeModal = null;
            },
        }"
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

        @if($completedAppend !== null)
            <section class="rounded-2xl border border-amber-200 bg-amber-50 px-4 py-4 sm:px-6">
                <h2 class="text-base font-semibold text-amber-950">Continue completed leads at the new message?</h2>
                <p class="mt-2 text-sm leading-6 text-amber-900">
                    {{ number_format($completedAppend['count']) }} {{ \Illuminate\Support\Str::plural('lead', $completedAppend['count']) }} finished the previous series. Start leads who still qualify at the new message. Earlier messages will not repeat, and leads who cannot receive the message or no longer belong in this campaign will be skipped.
                </p>
                <form method="POST" action="{{ route('crm.campaigns.completed-append.start', $campaign) }}" class="mt-4" onsubmit="return confirm('Start qualifying completed leads at the new message?');">
                    @csrf
                    <input type="hidden" name="append_id" value="{{ $completedAppend['append_id'] }}">
                    <button type="submit" class="inline-flex min-h-11 items-center justify-center rounded-full bg-amber-900 px-5 text-sm font-bold text-white hover:bg-amber-950">
                        Start qualifying completed leads
                    </button>
                </form>
            </section>
        @endif

        <div>
            <a href="{{ route('crm.campaigns.show', $campaign) }}" class="break-words text-sm font-semibold text-slate-600 hover:text-slate-950">
                &larr; Back to campaign
            </a>
        </div>

        <x-campaigns.builder-shell :stages="$workspace['builder_stages']" mode="edit">
            <div class="grid min-w-0 gap-4 lg:grid-cols-2">
                <section class="min-w-0 rounded-3xl border border-slate-200 bg-white p-4 shadow-sm sm:p-6">
                    <p class="text-xs font-bold uppercase tracking-[0.16em] text-slate-500">Who gets it</p>
                    <h3 class="mt-2 break-words text-lg font-semibold text-slate-950">{{ $eligibility['summary'] }}</h3>
                    <div class="mt-4 flex flex-wrap gap-2 text-xs font-bold text-slate-600">
                        <span class="rounded-full bg-slate-100 px-3 py-1">
                            {{ $campaign->usesAutomaticEnrollment() ? 'Added automatically' : 'Added manually' }}
                        </span>
                        <span class="rounded-full bg-slate-100 px-3 py-1">
                            {{ number_format((int) ($eligibility['matching_count'] ?? 0)) }} {{ \Illuminate\Support\Str::plural('lead', (int) ($eligibility['matching_count'] ?? 0)) }} matching now
                        </span>
                    </div>
                    <button
                        type="button"
                        data-campaign-panel-open="audience"
                        x-on:click="openModal('audience')"
                        class="mt-5 inline-flex min-h-11 w-full items-center justify-center rounded-full border border-slate-300 bg-white px-4 text-sm font-bold text-slate-800 transition hover:bg-slate-50 sm:w-auto"
                    >
                        Edit audience
                    </button>
                </section>

                <section class="min-w-0 rounded-3xl border border-slate-200 bg-white p-4 shadow-sm sm:p-6">
                    <p class="text-xs font-bold uppercase tracking-[0.16em] text-slate-500">Messages</p>
                    <h3 class="mt-2 break-words text-lg font-semibold text-slate-950">
                        {{ $messageReviewCount }} total {{ \Illuminate\Support\Str::plural('message', $messageReviewCount) }}
                    </h3>
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
                        data-campaign-panel-open="messages"
                        x-on:click="openModal('messages')"
                        @disabled($messageReviewCount < 1)
                        class="mt-5 inline-flex min-h-11 w-full items-center justify-center rounded-full bg-slate-950 px-4 text-sm font-bold text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-50 sm:w-auto"
                    >
                        Review now
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

                <section class="min-w-0 rounded-3xl border border-slate-200 bg-white p-4 shadow-sm sm:p-6">
                    @if($campaign->usesRecurringAllocation())
                        <p class="text-xs font-bold uppercase tracking-[0.16em] text-slate-500">Outreach plan</p>
                        <h3 class="mt-2 break-words text-lg font-semibold text-slate-950">{{ $outreachPlan['summary'] }}</h3>
                        <button
                            type="button"
                            data-campaign-panel-open="outreach"
                            x-on:click="openModal('outreach')"
                            class="mt-5 inline-flex min-h-11 w-full items-center justify-center rounded-full border border-slate-300 bg-white px-4 text-sm font-bold text-slate-800 transition hover:bg-slate-50 sm:w-auto"
                        >
                            Edit outreach plan
                        </button>
                    @else
                        <p class="text-xs font-bold uppercase tracking-[0.16em] text-slate-500">Follow-up timing</p>
                        <h3 class="mt-2 break-words text-lg font-semibold text-slate-950">
                            {{ $workspace['message_step_count'] }}-message follow-up series
                        </h3>
                        <button
                            type="button"
                            data-campaign-panel-open="schedule"
                            x-on:click="openModal('schedule')"
                            class="mt-5 inline-flex min-h-11 w-full items-center justify-center rounded-full border border-slate-300 bg-white px-4 text-sm font-bold text-slate-800 transition hover:bg-slate-50 sm:w-auto"
                        >
                            {{ $scheduleEditable ? 'Edit timing' : 'View timing' }}
                        </button>
                    @endif
                </section>

                <section id="campaign-review" class="min-w-0 rounded-3xl border border-slate-200 bg-white p-4 shadow-sm sm:p-6">
                    <p class="text-xs font-bold uppercase tracking-[0.16em] text-slate-500">Campaign on/off</p>
                    <div class="mt-2 flex items-start justify-between gap-3">
                        <h3 class="break-words text-lg font-semibold text-slate-950">
                            {{ $campaign->isActive() ? 'Campaign is on' : 'Campaign is off' }}
                        </h3>
                        <span class="w-fit shrink-0 rounded-full px-3 py-1 text-xs font-bold {{ $campaign->isActive() ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-600' }}">
                            {{ $campaign->isActive() ? 'On' : 'Off' }}
                        </span>
                    </div>
                    <p class="mt-3 break-words text-sm leading-6 text-slate-600">
                        {{ $workspace['active_enrollment_count'] }} active {{ \Illuminate\Support\Str::plural('lead', $workspace['active_enrollment_count']) }} · {{ $workspace['pending_message_count'] }} {{ \Illuminate\Support\Str::plural('message', $workspace['pending_message_count']) }} waiting to send
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
                                Turn campaign on
                            </button>
                        </form>
                    @elseif($campaign->status === \App\Modules\Campaigns\Models\Campaign::STATUS_ACTIVE)
                        <form
                            method="POST"
                            action="{{ route('crm.campaigns.deactivate', $campaign) }}"
                            class="mt-5"
                            onsubmit="return confirm('Turn off this campaign, stop current journeys, and skip pending campaign messages?');"
                        >
                            @csrf
                            @method('PATCH')
                            <input type="hidden" name="return_to" value="{{ $reviewReturnPath }}">
                            <button
                                type="submit"
                                data-campaign-lifecycle-action="deactivate"
                                class="inline-flex min-h-11 w-full items-center justify-center rounded-full bg-red-700 px-5 text-sm font-bold text-white transition hover:bg-red-800 sm:w-auto"
                            >
                                Turn campaign off
                            </button>
                        </form>
                    @endif
                </section>
            </div>
        </x-campaigns.builder-shell>

        <div
            x-show="activeModal === 'audience'"
            x-cloak
            x-on:click.self="closeModal()"
            data-campaign-panel-modal="audience"
            class="fixed inset-0 z-50 flex items-center justify-center overflow-y-auto bg-slate-950/60 px-3 py-4 sm:px-6"
        >
            <div role="dialog" aria-modal="true" aria-label="Campaign audience" class="max-h-[calc(100vh-2rem)] w-full max-w-5xl overflow-y-auto rounded-3xl bg-white shadow-2xl">
                <header class="sticky top-0 z-30 flex flex-col gap-4 border-b border-slate-200 bg-white/95 px-4 py-4 backdrop-blur sm:flex-row sm:items-start sm:justify-between sm:px-6">
                    <div>
                        <p class="text-xs font-bold uppercase tracking-[0.16em] text-rose-700">Campaign audience</p>
                        <h2 class="mt-1 text-xl font-semibold text-slate-950">Who should get this campaign?</h2>
                    </div>
                    <button type="button" x-on:click="closeModal()" class="inline-flex min-h-10 items-center justify-center rounded-full border border-slate-300 bg-white px-4 text-sm font-bold text-slate-700 hover:bg-slate-50">Close</button>
                </header>

                <form
                    method="POST"
                    action="{{ route('crm.campaigns.eligibility.update', $campaign) }}"
                    x-data="{
                        matchingCount: @js((int) ($eligibility['matching_count'] ?? 0)),
                        previewing: false,
                        previewError: '',
                        activeRule: null,
                        chooser: null,
                        showAdvanced: false,
                        openRule(scope, key) {
                            this.activeRule = scope + ':' + key;
                            this.chooser = null;
                        },
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
                                    throw new Error(payload.message || 'Unable to preview matching leads.');
                                }

                                this.matchingCount = payload.matching_count || 0;
                            } catch (error) {
                                this.previewError = error.message || 'Unable to preview matching leads.';
                            } finally {
                                this.previewing = false;
                            }
                        },
                    }"
                    x-ref="form"
                    data-campaign-eligibility-form
                >
                    @csrf
                    @method('PATCH')

                    <div class="space-y-6 p-4 sm:p-6">
                        <div class="grid gap-4 lg:grid-cols-3">
                            <label class="block rounded-2xl border border-slate-200 bg-slate-50 p-4">
                                <span class="text-xs font-bold uppercase tracking-[0.12em] text-slate-500">How leads are added</span>
                                <select name="enrollment_mode" class="mt-2 block min-h-11 w-full rounded-xl border-slate-300 bg-white text-sm font-semibold text-slate-900">
                                    @foreach($eligibility['enrollment_modes'] as $value => $label)
                                        <option value="{{ $value }}" @selected(old('enrollment_mode', $campaign->enrollment_mode) === $value)>{{ $label }}</option>
                                    @endforeach
                                </select>
                                @error('enrollment_mode')<p class="mt-2 text-sm font-semibold text-red-600">{{ $message }}</p>@enderror
                            </label>

                            <label class="block rounded-2xl border border-slate-200 bg-slate-50 p-4">
                                <span class="text-xs font-bold uppercase tracking-[0.12em] text-slate-500">Can a lead come back?</span>
                                <select name="reentry_policy" class="mt-2 block min-h-11 w-full rounded-xl border-slate-300 bg-white text-sm font-semibold text-slate-900">
                                    @foreach($eligibility['reentry_policies'] as $value => $label)
                                        <option value="{{ $value }}" @selected(old('reentry_policy', $campaign->reentry_policy) === $value)>{{ $label }}</option>
                                    @endforeach
                                </select>
                                @error('reentry_policy')<p class="mt-2 text-sm font-semibold text-red-600">{{ $message }}</p>@enderror
                            </label>

                            <label class="block rounded-2xl border border-slate-200 bg-slate-50 p-4">
                                <span class="text-xs font-bold uppercase tracking-[0.12em] text-slate-500">If a lead stops matching</span>
                                <select name="ineligible_behavior" class="mt-2 block min-h-11 w-full rounded-xl border-slate-300 bg-white text-sm font-semibold text-slate-900">
                                    @foreach($eligibility['ineligible_behaviors'] as $value => $label)
                                        <option value="{{ $value }}" @selected(old('ineligible_behavior', $campaign->ineligible_behavior) === $value)>{{ $label }}</option>
                                    @endforeach
                                </select>
                                @error('ineligible_behavior')<p class="mt-2 text-sm font-semibold text-red-600">{{ $message }}</p>@enderror
                            </label>
                        </div>

                        <section class="rounded-3xl border border-slate-200 bg-white p-4 sm:p-5">
                            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                <div>
                                    <h3 class="text-base font-semibold text-slate-950">Included leads</h3>
                                    <p class="mt-1 text-sm text-slate-600">A lead must match every filter type you add here.</p>
                                </div>
                                <button type="button" x-on:click="chooser = chooser === 'include' ? null : 'include'; activeRule = null" class="inline-flex min-h-10 items-center justify-center rounded-full border border-slate-300 bg-white px-4 text-sm font-bold text-slate-800 hover:bg-slate-50">+ Add a filter</button>
                            </div>

                            <div class="mt-4 space-y-2">
                                @forelse($eligibility['criteria'] as $criterion)
                                    @if(($criterion['selected_labels'] ?? []) !== [])
                                        <button type="button" x-on:click="openRule('include', @js($criterion['key']))" class="flex w-full items-center justify-between gap-4 rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-left hover:bg-slate-100">
                                            <span>
                                                <span class="block text-xs font-bold uppercase tracking-[0.12em] text-slate-500">{{ $criterion['label'] }}</span>
                                                <span class="mt-1 block text-sm font-semibold text-slate-900">{{ implode(', ', $criterion['selected_labels']) }}</span>
                                            </span>
                                            <span class="text-sm font-bold text-slate-600">Edit</span>
                                        </button>
                                    @endif
                                @empty
                                @endforelse

                                @if($selectedCriteria === [])
                                    <p class="rounded-2xl border border-dashed border-slate-300 bg-slate-50 px-4 py-4 text-sm text-slate-600">No filters selected yet.</p>
                                @endif
                            </div>

                            <div x-show="chooser === 'include'" x-cloak class="mt-4 rounded-2xl border border-slate-200 bg-slate-50 p-4">
                                <p class="text-sm font-bold text-slate-950">What do you want to filter by?</p>
                                <div class="mt-3 flex flex-wrap gap-2">
                                    @foreach($eligibility['criteria'] as $criterion)
                                        @if(! ($criterion['advanced'] ?? false))
                                            <button type="button" x-on:click="openRule('include', @js($criterion['key']))" class="rounded-full border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-800 hover:bg-slate-100">{{ $criterion['label'] }}</button>
                                        @endif
                                    @endforeach
                                </div>
                                <button type="button" x-on:click="showAdvanced = ! showAdvanced" class="mt-4 text-sm font-semibold text-slate-600 underline decoration-slate-300 underline-offset-4 hover:text-slate-950">Advanced filters</button>
                                <div x-show="showAdvanced" x-cloak class="mt-3 flex flex-wrap gap-2">
                                    @foreach($eligibility['criteria'] as $criterion)
                                        @if($criterion['advanced'] ?? false)
                                            <button type="button" x-on:click="openRule('include', @js($criterion['key']))" class="rounded-full border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-800 hover:bg-slate-100">{{ $criterion['label'] }}</button>
                                        @endif
                                    @endforeach
                                </div>
                            </div>

                            @foreach($eligibility['criteria'] as $criterion)
                                @php
                                    $criterionKey = (string) $criterion['key'];
                                    $selectedValues = old(
                                        'eligibility_criteria.'.$criterionKey,
                                        $selectedCriteria[$criterionKey] ?? [],
                                    );
                                    $selectedValues = is_array($selectedValues) ? $selectedValues : [];
                                @endphp
                                <fieldset x-show="activeRule === @js('include:'.$criterionKey)" x-cloak class="mt-4 rounded-2xl border border-rose-200 bg-rose-50/40 p-4">
                                    <div class="flex items-center justify-between gap-3">
                                        <legend class="text-sm font-bold text-slate-950">{{ $criterion['label'] }}</legend>
                                        <button type="button" x-on:click="activeRule = null" class="text-sm font-semibold text-slate-600">Done</button>
                                    </div>
                                    <div class="mt-3 grid gap-2 sm:grid-cols-2">
                                        @forelse($criterion['options'] as $option)
                                            <label class="flex min-h-11 items-start gap-3 rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm text-slate-800 hover:bg-slate-50">
                                                <input type="checkbox" name="eligibility_criteria[{{ $criterionKey }}][]" value="{{ $option['value'] }}" @checked(in_array($option['value'], $selectedValues, true)) class="mt-0.5 rounded border-slate-300 text-rose-700 focus:ring-rose-600">
                                                <span>{{ $option['label'] }}</span>
                                            </label>
                                        @empty
                                            <p class="text-sm text-slate-500">No available values.</p>
                                        @endforelse
                                    </div>
                                    @error('eligibility_criteria.'.$criterionKey)<p class="mt-2 text-sm font-semibold text-red-600">{{ $message }}</p>@enderror
                                </fieldset>
                            @endforeach
                        </section>

                        <section class="rounded-3xl border border-slate-200 bg-white p-4 sm:p-5">
                            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                <div>
                                    <h3 class="text-base font-semibold text-slate-950">Excluded leads</h3>
                                    <p class="mt-1 text-sm text-slate-600">Anyone matching an exclusion stays out, even if they match the included filters.</p>
                                </div>
                                <button type="button" x-on:click="chooser = chooser === 'exclude' ? null : 'exclude'; activeRule = null" class="inline-flex min-h-10 items-center justify-center rounded-full border border-slate-300 bg-white px-4 text-sm font-bold text-slate-800 hover:bg-slate-50">+ Add an exclusion</button>
                            </div>

                            <div class="mt-4 space-y-2">
                                @foreach($eligibility['criteria'] as $criterion)
                                    @if(($criterion['excluded_labels'] ?? []) !== [])
                                        <button type="button" x-on:click="openRule('exclude', @js($criterion['key']))" class="flex w-full items-center justify-between gap-4 rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-left hover:bg-slate-100">
                                            <span>
                                                <span class="block text-xs font-bold uppercase tracking-[0.12em] text-slate-500">{{ $criterion['label'] }}</span>
                                                <span class="mt-1 block text-sm font-semibold text-slate-900">{{ implode(', ', $criterion['excluded_labels']) }}</span>
                                            </span>
                                            <span class="text-sm font-bold text-slate-600">Edit</span>
                                        </button>
                                    @endif
                                @endforeach

                                @if($excludedCriteria === [])
                                    <p class="rounded-2xl border border-dashed border-slate-300 bg-slate-50 px-4 py-4 text-sm text-slate-600">No exclusions.</p>
                                @endif
                            </div>

                            <div x-show="chooser === 'exclude'" x-cloak class="mt-4 rounded-2xl border border-slate-200 bg-slate-50 p-4">
                                <p class="text-sm font-bold text-slate-950">Who should stay out?</p>
                                <div class="mt-3 flex flex-wrap gap-2">
                                    @foreach($eligibility['criteria'] as $criterion)
                                        @if(! ($criterion['advanced'] ?? false))
                                            <button type="button" x-on:click="openRule('exclude', @js($criterion['key']))" class="rounded-full border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-800 hover:bg-slate-100">{{ $criterion['label'] }}</button>
                                        @endif
                                    @endforeach
                                </div>
                                <button type="button" x-on:click="showAdvanced = ! showAdvanced" class="mt-4 text-sm font-semibold text-slate-600 underline decoration-slate-300 underline-offset-4 hover:text-slate-950">Advanced filters</button>
                                <div x-show="showAdvanced" x-cloak class="mt-3 flex flex-wrap gap-2">
                                    @foreach($eligibility['criteria'] as $criterion)
                                        @if($criterion['advanced'] ?? false)
                                            <button type="button" x-on:click="openRule('exclude', @js($criterion['key']))" class="rounded-full border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-800 hover:bg-slate-100">{{ $criterion['label'] }}</button>
                                        @endif
                                    @endforeach
                                </div>
                            </div>

                            @foreach($eligibility['criteria'] as $criterion)
                                @php
                                    $criterionKey = (string) $criterion['key'];
                                    $excludedValues = old(
                                        'eligibility_exclusions.'.$criterionKey,
                                        $excludedCriteria[$criterionKey] ?? [],
                                    );
                                    $excludedValues = is_array($excludedValues) ? $excludedValues : [];
                                @endphp
                                <fieldset x-show="activeRule === @js('exclude:'.$criterionKey)" x-cloak class="mt-4 rounded-2xl border border-amber-200 bg-amber-50/50 p-4">
                                    <div class="flex items-center justify-between gap-3">
                                        <legend class="text-sm font-bold text-slate-950">Exclude by {{ strtolower($criterion['label']) }}</legend>
                                        <button type="button" x-on:click="activeRule = null" class="text-sm font-semibold text-slate-600">Done</button>
                                    </div>
                                    <div class="mt-3 grid gap-2 sm:grid-cols-2">
                                        @forelse($criterion['options'] as $option)
                                            <label class="flex min-h-11 items-start gap-3 rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm text-slate-800 hover:bg-slate-50">
                                                <input type="checkbox" name="eligibility_exclusions[{{ $criterionKey }}][]" value="{{ $option['value'] }}" @checked(in_array($option['value'], $excludedValues, true)) class="mt-0.5 rounded border-slate-300 text-amber-700 focus:ring-amber-600">
                                                <span>{{ $option['label'] }}</span>
                                            </label>
                                        @empty
                                            <p class="text-sm text-slate-500">No available values.</p>
                                        @endforelse
                                    </div>
                                    @error('eligibility_exclusions.'.$criterionKey)<p class="mt-2 text-sm font-semibold text-red-600">{{ $message }}</p>@enderror
                                </fieldset>
                            @endforeach
                        </section>

                        @if(($eligibility['unavailable_criteria'] ?? []) !== [] || ($eligibility['unavailable_exclusions'] ?? []) !== [])
                            <div class="rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                                Some saved audience rules come from features that are not available here right now. They will stay in place when you save.
                            </div>
                        @endif

                        @error('eligibility_criteria')
                            <p class="rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-semibold text-red-700">{{ $message }}</p>
                        @enderror
                        @error('eligibility_exclusions')
                            <p class="rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-semibold text-red-700">{{ $message }}</p>
                        @enderror
                    </div>

                    <footer class="sticky bottom-0 flex flex-col gap-3 border-t border-slate-200 bg-slate-50 px-4 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-6">
                        <div>
                            <p class="text-sm font-semibold text-slate-950"><span x-text="Number(matchingCount).toLocaleString()"></span> matching leads now</p>
                            <p x-show="previewError" x-text="previewError" class="mt-1 text-xs font-semibold text-red-600"></p>
                        </div>
                        <div class="flex flex-col gap-2 sm:flex-row">
                            <button type="button" x-on:click="preview()" x-bind:disabled="previewing" class="inline-flex min-h-11 items-center justify-center rounded-full border border-slate-300 bg-white px-5 text-sm font-bold text-slate-700 hover:bg-slate-50 disabled:opacity-50">
                                <span x-show="!previewing">Preview matching leads</span>
                                <span x-show="previewing">Checking…</span>
                            </button>
                            <button type="submit" class="inline-flex min-h-11 items-center justify-center rounded-full bg-slate-950 px-6 text-sm font-bold text-white hover:bg-slate-800">Save audience</button>
                        </div>
                    </footer>
                </form>
            </div>
        </div>

        @if($campaign->usesRecurringAllocation())
            <div
                x-show="activeModal === 'outreach'"
                x-cloak
                x-on:click.self="closeModal()"
                data-campaign-panel-modal="outreach"
                class="fixed inset-0 z-50 flex items-center justify-center overflow-y-auto bg-slate-950/60 px-3 py-4 sm:px-6"
            >
                <div role="dialog" aria-modal="true" aria-label="Campaign outreach plan" class="max-h-[calc(100vh-2rem)] w-full max-w-3xl overflow-y-auto rounded-3xl bg-white shadow-2xl">
                    <header class="flex flex-col gap-4 border-b border-slate-200 px-4 py-4 sm:flex-row sm:items-start sm:justify-between sm:px-6">
                        <div>
                            <p class="text-xs font-bold uppercase tracking-[0.16em] text-rose-700">Outreach plan</p>
                            <h2 class="mt-1 text-xl font-semibold text-slate-950">How often should this campaign choose leads?</h2>
                        </div>
                        <button type="button" x-on:click="closeModal()" class="inline-flex min-h-10 items-center justify-center rounded-full border border-slate-300 bg-white px-4 text-sm font-bold text-slate-700 hover:bg-slate-50">Close</button>
                    </header>

                    <form
                        method="POST"
                        action="{{ route('crm.campaigns.execution.update', $campaign) }}"
                        x-data="{
                            runEvery: @js((int) $outreachPlan['run_every_days']),
                            perMessage: @js((int) $outreachPlan['allocation_size_per_message']),
                            cooldown: @js((int) $outreachPlan['recipient_cooldown_days']),
                            example() {
                                const runDays = Number(this.runEvery) || 0;
                                const perMessage = Number(this.perMessage) || 0;
                                const cooldown = Number(this.cooldown) || 0;
                                const runUnit = runDays === 1 ? 'day' : 'days';
                                const leadUnit = perMessage === 1 ? 'lead' : 'leads';
                                const repeat = cooldown === 0
                                    ? 'A lead can be chosen again in the next round.'
                                    : `After a lead is chosen, the campaign waits at least ${cooldown} ${cooldown === 1 ? 'day' : 'days'} before choosing that same lead again.`;
                                return `Every ${runDays} ${runUnit}, this campaign starts a new round of outreach. Each message can choose up to ${perMessage} eligible ${leadUnit}. ${repeat}`;
                            },
                        }"
                    >
                        @csrf
                        @method('PATCH')
                        <input type="hidden" name="execution_strategy" value="{{ $campaign->execution_strategy }}">

                        <div class="space-y-6 p-4 sm:p-6">
                            <div class="rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-4 text-sm font-semibold leading-6 text-emerald-950" x-text="example()"></div>

                            <div class="space-y-3">
                                <label class="block rounded-2xl border border-slate-200 bg-white p-4">
                                    <span class="text-sm font-bold text-slate-950">Start a new outreach round every</span>
                                    <div class="mt-3 flex items-center gap-2">
                                        <input x-model.number="runEvery" type="number" min="1" max="3650" name="allocation_settings[run_every_days]" value="{{ old('allocation_settings.run_every_days', $outreachPlan['run_every_days']) }}" class="min-h-11 w-32 rounded-xl border-slate-300 bg-white text-sm font-semibold text-slate-900">
                                        <span class="text-sm text-slate-600">days</span>
                                    </div>
                                    @error('allocation_settings.run_every_days')<p class="mt-2 text-sm font-semibold text-red-600">{{ $message }}</p>@enderror
                                </label>

                                <label class="block rounded-2xl border border-slate-200 bg-white p-4">
                                    <span class="text-sm font-bold text-slate-950">Choose up to this many leads for each message</span>
                                    <input x-model.number="perMessage" type="number" min="1" max="100000" name="allocation_settings[allocation_size_per_message]" value="{{ old('allocation_settings.allocation_size_per_message', $outreachPlan['allocation_size_per_message']) }}" class="mt-3 min-h-11 w-32 rounded-xl border-slate-300 bg-white text-sm font-semibold text-slate-900">
                                    @error('allocation_settings.allocation_size_per_message')<p class="mt-2 text-sm font-semibold text-red-600">{{ $message }}</p>@enderror
                                </label>

                                <label class="block rounded-2xl border border-slate-200 bg-white p-4">
                                    <span class="text-sm font-bold text-slate-950">Wait this long before choosing the same lead again</span>
                                    <div class="mt-3 flex items-center gap-2">
                                        <input x-model.number="cooldown" type="number" min="0" max="3650" name="allocation_settings[recipient_cooldown_days]" value="{{ old('allocation_settings.recipient_cooldown_days', $outreachPlan['recipient_cooldown_days']) }}" class="min-h-11 w-32 rounded-xl border-slate-300 bg-white text-sm font-semibold text-slate-900">
                                        <span class="text-sm text-slate-600">days</span>
                                    </div>
                                    @error('allocation_settings.recipient_cooldown_days')<p class="mt-2 text-sm font-semibold text-red-600">{{ $message }}</p>@enderror
                                </label>
                            </div>

                            @error('allocation_settings')<p class="text-sm font-semibold text-red-600">{{ $message }}</p>@enderror
                            @error('execution_strategy')<p class="text-sm font-semibold text-red-600">{{ $message }}</p>@enderror

                            <section class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                                <h3 class="text-sm font-bold text-slate-950">Message timing</h3>
                                <p class="mt-1 text-sm leading-6 text-slate-600">Your {{ $workspace['message_step_count'] }} {{ \Illuminate\Support\Str::plural('message', $workspace['message_step_count']) }} can each send at a different time after an outreach round starts.</p>
                                <button type="button" x-on:click="activeModal = 'schedule'" class="mt-3 inline-flex min-h-10 items-center justify-center rounded-full border border-slate-300 bg-white px-4 text-sm font-bold text-slate-800 hover:bg-slate-100">Review message timing</button>
                            </section>
                        </div>

                        <footer class="flex justify-end border-t border-slate-200 bg-slate-50 px-4 py-4 sm:px-6">
                            <button type="submit" class="inline-flex min-h-11 items-center justify-center rounded-full bg-slate-950 px-6 text-sm font-bold text-white hover:bg-slate-800">Save outreach plan</button>
                        </footer>
                    </form>
                </div>
            </div>
        @endif

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
                        :form-context="[
                            'return_to' => $messageReturnPath,
                            'message_chain_version_id' => $messageChainVersionId,
                            'campaign_editor' => 'messages',
                        ]"
                    />
                </div>

                <footer class="flex flex-col gap-3 border-t border-slate-200 bg-slate-50 px-4 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-6">
                    <p class="text-xs leading-5 text-slate-500">
                        Changes apply to future messages. Anything already scheduled keeps the copy it already has.
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
                x-data="{
                    index: 0,
                    count: @js(count($scheduleSteps)),
                    addStep: @js((bool) old('new_step.add', false)),
                    templateMode: @js(old('new_step.template_mode', 'create')),
                    templateChannel: @js(old('new_step.template.channel', 'email')),
                    navigate(delta) {
                        if (this.count > 1) this.index = (this.index + delta + this.count) % this.count;
                    },
                }"
                x-on:campaign-add-step.window="addStep = true; templateMode = 'create'; $nextTick(() => $refs.newMessage?.scrollIntoView({ block: 'start' }))"
                class="max-h-[calc(100vh-2rem)] w-full max-w-4xl overflow-y-auto rounded-3xl bg-white shadow-2xl"
            >
                <header class="sticky top-0 z-30 flex flex-col gap-4 border-b border-slate-200 bg-white/95 px-4 py-4 backdrop-blur sm:flex-row sm:items-start sm:justify-between sm:px-6">
                    <div>
                        <p class="text-xs font-bold uppercase tracking-[0.16em] text-rose-700">Message timing</p>
                        <h2 class="mt-1 text-xl font-semibold text-slate-950">{{ $campaign->name }}</h2>
                        <p class="mt-1 text-sm text-slate-600">{{ $campaign->usesRecurringAllocation() ? 'These messages work independently. Each outreach round chooses a different group of leads for every message. Set when each message may start sending after the outreach begins; email delivery settings may spread those emails out later.' : 'Set the order and wait between messages. Edit the actual message copy from What they receive.' }}</p>
                    </div>
                    <button type="button" x-on:click="closeModal()" class="inline-flex min-h-10 items-center justify-center rounded-full border border-slate-300 bg-white px-4 text-sm font-bold text-slate-700 hover:bg-slate-50">Close</button>
                </header>

                @if($scheduleEditable)
                    <form
                        method="POST"
                        action="{{ route('crm.campaigns.schedule.update', $campaign) }}"
                        enctype="multipart/form-data"
                        data-campaign-schedule-form
                    >
                        @csrf
                        @method('PATCH')
                        <input type="hidden" name="campaign_editor" value="schedule">
                        <input type="hidden" name="message_chain_version_id" value="{{ $scheduleAuthoring['message_chain_version_id'] }}">

                        <div class="space-y-5 p-4 sm:p-6">
                            @if($scheduleHasErrors)
                                <div class="rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-semibold text-red-700">
                                    {{ $errors->first() }}
                                </div>
                            @endif

                            <div class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
                                <div class="flex items-center justify-between border-b border-slate-200 bg-slate-50 px-4 py-3 sm:px-6">
                                    <span class="text-xs font-bold uppercase tracking-[0.12em] text-slate-500">Message timing</span>
                                    @if($campaign->usesRecurringAllocation())
                                        <span class="rounded-full bg-white px-3 py-1 text-xs font-bold text-slate-600">{{ count($scheduleSteps) }} {{ \Illuminate\Support\Str::plural('message', count($scheduleSteps)) }}</span>
                                    @else
                                        <span class="rounded-full bg-white px-3 py-1 text-xs font-bold text-slate-600 ring-1 ring-slate-200"><span x-text="index + 1"></span> of {{ count($scheduleSteps) }}</span>
                                    @endif
                                </div>

                                <div class="{{ $campaign->usesRecurringAllocation() ? 'space-y-4 p-4 sm:p-6' : 'relative px-12 py-5 sm:px-20 sm:py-8' }}">
                                    @if($campaign->usesSequentialExecution() && count($scheduleSteps) > 1)
                                        <button type="button" aria-label="Previous schedule step" x-on:click="navigate(-1)" class="absolute inset-y-5 left-0 flex w-11 items-center justify-center text-3xl text-slate-400 hover:bg-slate-100 hover:text-slate-950 sm:inset-y-8 sm:w-16">‹</button>
                                        <button type="button" aria-label="Next schedule step" x-on:click="navigate(1)" class="absolute inset-y-5 right-0 flex w-11 items-center justify-center text-3xl text-slate-400 hover:bg-slate-100 hover:text-slate-950 sm:inset-y-8 sm:w-16">›</button>
                                    @endif

                                    @foreach($scheduleSteps as $scheduleIndex => $step)
                                        @php
                                            $submittedTimingType = old('steps.'.$scheduleIndex.'.timing_type', $step['timing_type']);
                                        @endphp
                                        <article
                                            @if($campaign->usesSequentialExecution())
                                                x-show="index === {{ $scheduleIndex }}"
                                                x-cloak
                                            @endif
                                            x-data="{ timingType: @js($submittedTimingType) }"
                                            data-campaign-schedule-step="{{ $step['step_number'] }}"
                                            class="mx-auto max-w-2xl rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6"
                                        >
                                            <input type="hidden" name="steps[{{ $scheduleIndex }}][key]" value="{{ $step['key'] }}">

                                            <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                                                <div class="flex min-w-0 items-start gap-3">
                                                    <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-slate-950 text-sm font-bold text-white">{{ $step['step_number'] }}</span>
                                                    <div class="min-w-0">
                                                        <p class="text-sm font-semibold text-slate-700">{{ $step['timing'] }}</p>
                                                        <div class="mt-3 flex flex-wrap gap-2">
                                                            @foreach($step['channels'] as $channel)
                                                                <span class="rounded-full bg-rose-50 px-3 py-1 text-xs font-bold text-rose-800 ring-1 ring-inset ring-rose-200">{{ strtoupper($channel) }}</span>
                                                            @endforeach
                                                            <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-bold text-slate-600">{{ $step['message_count'] }} {{ \Illuminate\Support\Str::plural('message', $step['message_count']) }}</span>
                                                        </div>
                                                    </div>
                                                </div>
                                                <label class="flex items-center gap-2 text-xs font-bold text-red-700">
                                                    <input type="checkbox" name="steps[{{ $scheduleIndex }}][remove]" value="1" @checked(old('steps.'.$scheduleIndex.'.remove')) class="rounded border-slate-300 text-red-600 focus:ring-red-600">
                                                    Remove message
                                                </label>
                                            </div>

                                            <div class="mt-5 grid gap-4 sm:grid-cols-[1fr_7rem]">
                                                <label class="block">
                                                    <span class="text-xs font-bold uppercase tracking-[0.12em] text-slate-500">Message name</span>
                                                    <input name="steps[{{ $scheduleIndex }}][name]" value="{{ old('steps.'.$scheduleIndex.'.name', $step['name']) }}" class="mt-2 block min-h-11 w-full rounded-xl border-slate-300 text-sm font-semibold text-slate-900">
                                                </label>
                                                <label class="block">
                                                    <span class="text-xs font-bold uppercase tracking-[0.12em] text-slate-500">{{ $campaign->usesRecurringAllocation() ? 'Priority when leads are limited' : 'Order' }}</span>
                                                    <input type="number" min="1" name="steps[{{ $scheduleIndex }}][position]" value="{{ old('steps.'.$scheduleIndex.'.position', $step['position']) }}" class="mt-2 block min-h-11 w-full rounded-xl border-slate-300 text-sm font-semibold text-slate-900">
                                                </label>
                                            </div>

                                            @if($step['timing_editable'])
                                                <div class="mt-4 grid gap-4 sm:grid-cols-3">
                                                    <label class="block">
                                                        <span class="text-xs font-bold uppercase tracking-[0.12em] text-slate-500">{{ $campaign->usesRecurringAllocation() ? 'When it can send' : 'Timing' }}</span>
                                                        <select name="steps[{{ $scheduleIndex }}][timing_type]" x-model="timingType" class="mt-2 block min-h-11 w-full rounded-xl border-slate-300 text-sm font-semibold text-slate-900">
                                                            <option value="immediate">{{ $campaign->usesRecurringAllocation() ? 'When outreach begins' : 'Immediately' }}</option>
                                                            <option value="delay">{{ $campaign->usesRecurringAllocation() ? 'After outreach begins' : 'Wait' }}</option>
                                                        </select>
                                                    </label>
                                                    <label x-show="timingType === 'delay'" class="block">
                                                        <span class="text-xs font-bold uppercase tracking-[0.12em] text-slate-500">Wait</span>
                                                        <input type="number" min="0" name="steps[{{ $scheduleIndex }}][delay_value]" value="{{ old('steps.'.$scheduleIndex.'.delay_value', $step['delay_value']) }}" class="mt-2 block min-h-11 w-full rounded-xl border-slate-300 text-sm font-semibold text-slate-900">
                                                    </label>
                                                    <label x-show="timingType === 'delay'" class="block">
                                                        <span class="text-xs font-bold uppercase tracking-[0.12em] text-slate-500">Unit</span>
                                                        <select name="steps[{{ $scheduleIndex }}][delay_unit]" class="mt-2 block min-h-11 w-full rounded-xl border-slate-300 text-sm font-semibold text-slate-900">
                                                            @foreach(['seconds' => 'Seconds', 'minutes' => 'Minutes', 'hours' => 'Hours', 'days' => 'Days'] as $value => $label)
                                                                <option value="{{ $value }}" @selected(old('steps.'.$scheduleIndex.'.delay_unit', $step['delay_unit']) === $value)>{{ $label }}</option>
                                                            @endforeach
                                                        </select>
                                                    </label>
                                                </div>
                                            @else
                                                <input type="hidden" name="steps[{{ $scheduleIndex }}][timing_type]" value="preserve">
                                                <p class="mt-4 rounded-xl bg-amber-50 px-4 py-3 text-xs font-semibold leading-5 text-amber-900">This event-anchored timing is preserved here. Its event contract remains owned by the mechanism that created it.</p>
                                            @endif
                                        </article>
                                    @endforeach
                                </div>
                            </div>

                            <section x-ref="newMessage" class="rounded-3xl border border-slate-200 bg-slate-50 p-4 sm:p-5">
                                <label class="flex items-center gap-3 text-sm font-bold text-slate-900">
                                    <input type="checkbox" name="new_step[add]" value="1" x-model="addStep" class="rounded border-slate-300 text-rose-700 focus:ring-rose-600">
                                    Add another message
                                </label>

                                <div x-show="addStep" x-cloak class="mt-4 grid gap-4 sm:grid-cols-2">
                                    <fieldset class="sm:col-span-2">
                                        <legend class="text-xs font-bold uppercase tracking-[0.12em] text-slate-500">Message</legend>
                                        <div class="mt-2 flex flex-wrap gap-4">
                                            <label class="flex items-center gap-2 text-sm font-semibold text-slate-900">
                                                <input type="radio" name="new_step[template_mode]" value="create" x-model="templateMode" class="border-slate-300 text-rose-700 focus:ring-rose-600">
                                                Write a new message
                                            </label>
                                            <label class="flex items-center gap-2 text-sm font-semibold text-slate-900">
                                                <input type="radio" name="new_step[template_mode]" value="existing" x-model="templateMode" class="border-slate-300 text-rose-700 focus:ring-rose-600">
                                                Choose a saved message
                                            </label>
                                        </div>
                                    </fieldset>

                                    <div x-show="templateMode === 'create'" x-cloak class="space-y-4 sm:col-span-2">
                                        <label class="block">
                                            <span class="text-xs font-bold uppercase tracking-[0.12em] text-slate-500">Message template name</span>
                                            <input name="new_step[template][name]" value="{{ old('new_step.template.name') }}" maxlength="191" placeholder="For example: Realtor follow-up 3" class="mt-2 block min-h-11 w-full rounded-xl border-slate-300 bg-white text-sm font-semibold text-slate-900">
                                        </label>
                                        @error('new_step.template')<p class="text-sm font-semibold text-red-600">{{ $message }}</p>@enderror
                                        @error('new_step.template.name')<p class="text-sm font-semibold text-red-600">{{ $message }}</p>@enderror

                                        <fieldset>
                                            <legend class="text-xs font-bold uppercase tracking-[0.12em] text-slate-500">Channel</legend>
                                            <div class="mt-2 flex gap-4">
                                                <label class="flex items-center gap-2 text-sm font-semibold text-slate-900"><input type="radio" name="new_step[template][channel]" value="email" x-model="templateChannel" class="border-slate-300 text-rose-700 focus:ring-rose-600"> Email</label>
                                                <label class="flex items-center gap-2 text-sm font-semibold text-slate-900"><input type="radio" name="new_step[template][channel]" value="sms" x-model="templateChannel" class="border-slate-300 text-rose-700 focus:ring-rose-600"> SMS</label>
                                            </div>
                                        </fieldset>

                                        <div x-show="templateChannel === 'email'" x-cloak class="space-y-4">
                                            <x-ui.message-editor
                                                :subject="[
                                                    'id' => 'campaign-appended-subject',
                                                    'name' => 'new_step[template][subject]',
                                                    'value' => old('new_step.template.subject'),
                                                    'label' => 'Email subject',
                                                    'maxlength' => 255,
                                                ]"
                                                :body="[
                                                    'id' => 'campaign-appended-body',
                                                    'name' => 'new_step[template][body]',
                                                    'value' => old('new_step.template.body'),
                                                    'label' => 'Email body',
                                                    'maxlength' => 10000,
                                                    'rows' => 9,
                                                ]"
                                            />
                                            <x-messaging.message-media-authoring :failed="$scheduleHasErrors" />
                                        </div>

                                        <div x-show="templateChannel === 'sms'" x-cloak>
                                            <x-ui.message-editor
                                                :sms="[
                                                    'id' => 'campaign-appended-sms',
                                                    'name' => 'new_step[template][message]',
                                                    'value' => old('new_step.template.message'),
                                                    'label' => 'SMS message',
                                                    'maxlength' => 1600,
                                                    'rows' => 7,
                                                ]"
                                            />
                                        </div>
                                    </div>

                                    <label x-show="templateMode === 'existing'" x-cloak class="block sm:col-span-2">
                                        <span class="text-xs font-bold uppercase tracking-[0.12em] text-slate-500">Saved message</span>
                                        <select name="new_step[message_template_preset_id]" class="mt-2 block min-h-11 w-full rounded-xl border-slate-300 bg-white text-sm font-semibold text-slate-900">
                                            <option value="">Choose a message</option>
                                            @foreach($scheduleMessageOptions as $option)
                                                <option value="{{ $option['id'] }}" @selected((int) old('new_step.message_template_preset_id') === (int) $option['id'])>{{ $option['label'] }} · {{ strtoupper($option['channel']) }}</option>
                                            @endforeach
                                        </select>
                                        @error('new_step.message_template_preset_id')<p class="mt-2 text-sm font-semibold text-red-600">{{ $message }}</p>@enderror
                                    </label>
                                    <label class="block">
                                        <span class="text-xs font-bold uppercase tracking-[0.12em] text-slate-500">Message name</span>
                                        <input name="new_step[name]" value="{{ old('new_step.name') }}" class="mt-2 block min-h-11 w-full rounded-xl border-slate-300 bg-white text-sm font-semibold text-slate-900">
                                    </label>
                                    <label class="block">
                                        <span class="text-xs font-bold uppercase tracking-[0.12em] text-slate-500">{{ $campaign->usesRecurringAllocation() ? 'Priority when leads are limited' : 'Order' }}</span>
                                        <input type="number" min="1" name="new_step[position]" value="{{ old('new_step.position', count($scheduleSteps) + 1) }}" class="mt-2 block min-h-11 w-full rounded-xl border-slate-300 bg-white text-sm font-semibold text-slate-900">
                                    </label>
                                    <label class="block">
                                        <span class="text-xs font-bold uppercase tracking-[0.12em] text-slate-500">{{ $campaign->usesRecurringAllocation() ? 'When it can send' : 'Timing' }}</span>
                                        <select name="new_step[timing_type]" class="mt-2 block min-h-11 w-full rounded-xl border-slate-300 bg-white text-sm font-semibold text-slate-900">
                                            <option value="delay" @selected(old('new_step.timing_type', $campaign->usesRecurringAllocation() ? 'immediate' : 'delay') === 'delay')>{{ $campaign->usesRecurringAllocation() ? 'After outreach begins' : 'Wait' }}</option>
                                            <option value="immediate" @selected(old('new_step.timing_type', $campaign->usesRecurringAllocation() ? 'immediate' : 'delay') === 'immediate')>{{ $campaign->usesRecurringAllocation() ? 'When outreach begins' : 'Immediately' }}</option>
                                        </select>
                                    </label>
                                    <div class="grid grid-cols-2 gap-3">
                                        <label class="block">
                                            <span class="text-xs font-bold uppercase tracking-[0.12em] text-slate-500">Wait</span>
                                            <input type="number" min="0" name="new_step[delay_value]" value="{{ old('new_step.delay_value', $campaign->usesRecurringAllocation() ? 0 : 1) }}" class="mt-2 block min-h-11 w-full rounded-xl border-slate-300 bg-white text-sm font-semibold text-slate-900">
                                        </label>
                                        <label class="block">
                                            <span class="text-xs font-bold uppercase tracking-[0.12em] text-slate-500">Unit</span>
                                            <select name="new_step[delay_unit]" class="mt-2 block min-h-11 w-full rounded-xl border-slate-300 bg-white text-sm font-semibold text-slate-900">
                                                @foreach(['seconds' => 'Seconds', 'minutes' => 'Minutes', 'hours' => 'Hours', 'days' => 'Days'] as $value => $label)
                                                    <option value="{{ $value }}" @selected(old('new_step.delay_unit', 'days') === $value)>{{ $label }}</option>
                                                @endforeach
                                            </select>
                                        </label>
                                    </div>
                                </div>
                            </section>
                            @if($campaign->usesSequentialExecution())
                                <section x-show="addStep" x-cloak class="rounded-3xl border border-slate-200 bg-white p-4 sm:p-5">
                                    <label class="flex items-start gap-3 text-sm font-semibold text-slate-900">
                                        <input type="checkbox" name="extend_in_progress" value="1" @checked(old('extend_in_progress')) class="mt-0.5 rounded border-slate-300 text-rose-700 focus:ring-rose-600">
                                        <span>Include leads already in this campaign<br><span class="font-normal text-slate-600">Use this only when adding one final message. Leads already in progress keep their current timing and receive the new message after they finish the existing series. Completed leads are not restarted.</span></span>
                                    </label>
                                    @error('extend_in_progress')<p class="mt-2 text-sm font-semibold text-red-600">{{ $message }}</p>@enderror
                                </section>
                            @endif
                        </div>

                        <footer class="flex flex-col gap-3 border-t border-slate-200 bg-slate-50 px-4 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-6">
                            <p class="text-xs leading-5 text-slate-500">{{ $campaign->usesRecurringAllocation() ? 'Changes apply to future outreach. Leads and messages already scheduled keep the timing they already have.' : 'Changes apply to new journeys. Leads already in progress keep their current timing unless you explicitly add a final message for them.' }}</p>
                            <div class="flex flex-col gap-2 sm:flex-row">
                                <button type="button" x-on:click="activeModal = 'messages'" @disabled($messageReviewCount < 1) class="inline-flex min-h-10 items-center justify-center rounded-full border border-slate-300 bg-white px-4 text-sm font-bold text-slate-700 hover:bg-slate-50 disabled:opacity-50">Review message copy</button>
                                <button type="submit" class="inline-flex min-h-10 items-center justify-center rounded-full bg-slate-950 px-5 text-sm font-bold text-white hover:bg-slate-800">Save timing</button>
                            </div>
                        </footer>
                    </form>
                @else
                    <div class="p-4 sm:p-6">
                        @if($scheduleSteps === [])
                            <div class="rounded-2xl border border-dashed border-slate-300 bg-slate-50 px-5 py-8 text-center">
                                <p class="font-bold text-slate-900">No published message schedule is available.</p>
                            </div>
                        @else
                            <div class="space-y-3">
                                @foreach($scheduleSteps as $step)
                                    <article data-campaign-schedule-step="{{ $step['step_number'] }}" class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                                        <h3 class="text-lg font-semibold text-slate-950">{{ $step['name'] }}</h3>
                                        <p class="mt-2 text-sm font-semibold text-slate-700">{{ $step['timing'] }}</p>
                                    </article>
                                @endforeach
                            </div>
                        @endif
                    </div>
                    <footer class="border-t border-slate-200 bg-slate-50 px-4 py-4 text-xs leading-5 text-slate-500 sm:px-6">Message timing is not editable yet for this campaign.</footer>
                @endif
            </div>
        </div>
    </div>
</x-layouts.crm>