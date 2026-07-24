<x-layouts.crm
    title="Campaigns"
    heading="Campaigns"
    subheading="Manage Campaign availability and reusable message selections."
>
    <div class="space-y-6">
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

        <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                <div>
                    <p class="text-xs font-extrabold uppercase tracking-[0.18em] text-slate-500">
                        Campaigns
                    </p>
                    <h2 class="mt-2 text-2xl font-extrabold tracking-tight text-slate-950">
                        Lifecycle and message selection
                    </h2>
                    <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-600">
                        Campaign status controls whether new enrollments may begin. Deactivation also cancels active enrollments and skips pending Campaign messages. Messaging continues to own reusable copy.
                    </p>
                </div>

                <a
                    href="{{ route('crm.messaging.message-templates.index', ['module' => 'campaigns']) }}"
                    class="inline-flex min-h-11 items-center justify-center rounded-full border border-slate-300 px-5 text-sm font-extrabold text-slate-700 transition hover:bg-slate-50"
                >
                    Edit message copy
                </a>
            </div>
        </section>

        @if($campaigns->isEmpty())
            <section class="rounded-3xl border border-slate-200 bg-white p-8 text-center shadow-sm">
                <h2 class="text-xl font-extrabold tracking-tight text-slate-950">
                    No campaigns are available yet.
                </h2>
                <p class="mt-2 text-sm leading-6 text-slate-600">
                    Run campaign preset sync and message template sync before choosing campaign message templates.
                </p>
            </section>
        @else
            <div class="grid gap-6 xl:grid-cols-[minmax(16rem,0.45fr)_minmax(0,1fr)]">
                <section class="rounded-3xl border border-slate-200 bg-white shadow-sm">
                    <div class="border-b border-slate-200 px-5 py-4">
                        <h2 class="text-base font-extrabold tracking-tight text-slate-950">
                            Campaigns
                        </h2>
                        <p class="mt-1 text-sm text-slate-500">
                            Choose a Campaign to review its status and message steps.
                        </p>
                    </div>

                    <div class="divide-y divide-slate-100">
                        @foreach($campaigns as $campaign)
                            @php
                                $variantCount = $campaign->steps->sum(fn ($step) => $step->variants->count());
                            @endphp
                            <a
                                href="{{ route('crm.campaigns.message-templates.index', ['campaign' => $campaign->getKey()]) }}"
                                class="block px-5 py-4 transition hover:bg-slate-50 {{ $selectedCampaign && $selectedCampaign->is($campaign) ? 'bg-indigo-50/70' : '' }}"
                            >
                                <div class="font-extrabold text-slate-950">
                                    {{ $campaign->name }}
                                </div>
                                <div class="mt-1 text-sm text-slate-500">
                                    {{ $campaign->steps->count() }} message {{ Str::plural('step', $campaign->steps->count()) }} · {{ $variantCount }} delivery {{ Str::plural('option', $variantCount) }}
                                </div>
                                <div class="mt-3 flex flex-wrap gap-2">
                                    <span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-bold uppercase tracking-wide text-slate-600">
                                        {{ $campaign->channel }} default
                                    </span>
                                    <span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-bold text-slate-600">
                                        {{ str_replace('_', ' ', $campaign->scope) }}
                                    </span>
                                    <span class="rounded-full px-2.5 py-1 text-xs font-bold uppercase tracking-wide {{ $campaign->status === \App\Modules\Campaigns\Models\Campaign::STATUS_ACTIVE ? 'bg-emerald-100 text-emerald-800' : ($campaign->status === \App\Modules\Campaigns\Models\Campaign::STATUS_ARCHIVED ? 'bg-slate-200 text-slate-700' : 'bg-amber-100 text-amber-800') }}">
                                        {{ $campaign->status }}
                                    </span>
                                </div>
                            </a>
                        @endforeach
                    </div>
                </section>

                <section class="rounded-3xl border border-slate-200 bg-white shadow-sm">
                    @if($selectedCampaign)
                        <div class="border-b border-slate-200 px-6 py-5">
                            <div class="flex flex-col gap-5 lg:flex-row lg:items-start lg:justify-between">
                                <div>
                                    <p class="text-xs font-extrabold uppercase tracking-[0.18em] text-slate-500">
                                        Selected Campaign
                                    </p>
                                    <h2 class="mt-2 text-xl font-extrabold tracking-tight text-slate-950">
                                        {{ $selectedCampaign->name }}
                                    </h2>
                                    <p class="mt-2 text-sm leading-6 text-slate-600">
                                        {{ $selectedCampaign->description ?: 'Select the active Messaging template for each Campaign delivery option.' }}
                                    </p>

                                    <div class="mt-4 flex flex-wrap gap-2">
                                        <span class="rounded-full px-3 py-1 text-xs font-bold uppercase tracking-wide {{ $selectedCampaign->status === \App\Modules\Campaigns\Models\Campaign::STATUS_ACTIVE ? 'bg-emerald-100 text-emerald-800' : ($selectedCampaign->status === \App\Modules\Campaigns\Models\Campaign::STATUS_ARCHIVED ? 'bg-slate-200 text-slate-700' : 'bg-amber-100 text-amber-800') }}">
                                            {{ $selectedCampaign->status }}
                                        </span>
                                        <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-bold uppercase tracking-wide text-slate-600">
                                            {{ $selectedCampaign->channel }} default
                                        </span>
                                        <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-bold text-slate-600">
                                            {{ str_replace('_', ' ', $selectedCampaign->purpose) }}
                                        </span>
                                        <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-bold text-slate-600">
                                            {{ str_replace('_', ' ', $selectedCampaign->scope) }}
                                        </span>
                                    </div>
                                </div>

                                <div class="w-full max-w-sm rounded-2xl border border-slate-200 bg-slate-50 p-4 lg:w-auto">
                                    <dl class="grid grid-cols-2 gap-3 text-sm">
                                        <div>
                                            <dt class="text-xs font-bold uppercase tracking-wide text-slate-500">Active enrollments</dt>
                                            <dd class="mt-1 text-lg font-extrabold text-slate-950">{{ $activeEnrollmentCount }}</dd>
                                        </div>
                                        <div>
                                            <dt class="text-xs font-bold uppercase tracking-wide text-slate-500">Pending messages</dt>
                                            <dd class="mt-1 text-lg font-extrabold text-slate-950">{{ $pendingScheduledMessageCount }}</dd>
                                        </div>
                                    </dl>

                                    @if($selectedCampaign->status === \App\Modules\Campaigns\Models\Campaign::STATUS_ACTIVE)
                                        <form
                                            method="POST"
                                            action="{{ route('crm.campaigns.message-templates.deactivate', $selectedCampaign) }}"
                                            class="mt-4"
                                            onsubmit="return confirm('Deactivate this Campaign, cancel active enrollments, and skip pending Campaign messages?');"
                                        >
                                            @csrf
                                            @method('PATCH')
                                            <button
                                                type="submit"
                                                class="inline-flex min-h-10 w-full items-center justify-center rounded-full bg-red-700 px-4 text-sm font-extrabold text-white transition hover:bg-red-800"
                                            >
                                                Deactivate Campaign
                                            </button>
                                        </form>
                                    @elseif($selectedCampaign->status === \App\Modules\Campaigns\Models\Campaign::STATUS_INACTIVE)
                                        <form
                                            method="POST"
                                            action="{{ route('crm.campaigns.message-templates.activate', $selectedCampaign) }}"
                                            class="mt-4"
                                        >
                                            @csrf
                                            @method('PATCH')
                                            <button
                                                type="submit"
                                                class="inline-flex min-h-10 w-full items-center justify-center rounded-full bg-emerald-700 px-4 text-sm font-extrabold text-white transition hover:bg-emerald-800"
                                            >
                                                Activate Campaign
                                            </button>
                                        </form>
                                        <p class="mt-3 text-xs leading-5 text-slate-500">
                                            Activation allows new enrollments. It does not restart cancelled enrollments or skipped messages.
                                        </p>
                                    @else
                                        <p class="mt-4 text-xs font-semibold leading-5 text-slate-600">
                                            Archived Campaigns cannot be activated from this surface.
                                        </p>
                                    @endif
                                </div>
                            </div>
                        </div>
                        <div class="divide-y divide-slate-100">
                            @foreach($selectedCampaign->steps as $step)
                                <div id="step-{{ $step->id }}" class="p-6 {{ $selectedStep && $selectedStep->is($step) ? 'bg-indigo-50/40' : '' }}">
                                    <div class="space-y-5">
                                        <div class="grid gap-5 lg:grid-cols-[minmax(0,0.7fr)_minmax(20rem,1fr)] lg:items-start">
                                            <div>
                                                <div class="flex items-center gap-2">
                                                    <span class="inline-flex h-8 w-8 items-center justify-center rounded-full bg-slate-950 text-sm font-extrabold text-white">
                                                        {{ $step->step_number }}
                                                    </span>
                                                    <h3 class="text-base font-extrabold text-slate-950">
                                                        {{ $step->name }}
                                                    </h3>
                                                </div>

                                                <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-2">
                                                    <div class="rounded-2xl border border-slate-200 bg-white p-3">
                                                        <dt class="text-xs font-bold uppercase tracking-wide text-slate-500">Timing</dt>
                                                        <dd class="mt-1 font-semibold text-slate-900">
                                                            @php
                                                                $timing = data_get($step->criteria, 'timing') ?: data_get($step->criteria, 'schedule');
                                                            @endphp
                                                            @if(is_array($timing))
                                                                {{ str_replace('_', ' ', $timing['type'] ?? 'delay') }}
                                                                @if(isset($timing['days']))
                                                                    · {{ $timing['days'] }} {{ Str::plural('day', (int) $timing['days']) }}
                                                                @elseif(isset($timing['hours']))
                                                                    · {{ $timing['hours'] }} {{ Str::plural('hour', (int) $timing['hours']) }}
                                                                @elseif(isset($timing['minutes']))
                                                                    · {{ $timing['minutes'] }} {{ Str::plural('minute', (int) $timing['minutes']) }}
                                                                @endif
                                                            @else
                                                                Immediate
                                                            @endif
                                                        </dd>
                                                    </div>
                                                    <div class="rounded-2xl border border-slate-200 bg-white p-3">
                                                        <dt class="text-xs font-bold uppercase tracking-wide text-slate-500">Strategy</dt>
                                                        <dd class="mt-1 font-semibold text-slate-900">
                                                            {{ str_replace('_', ' ', $step->variant_strategy ?: 'first_available') }}
                                                        </dd>
                                                    </div>
                                                </dl>
                                            </div>

                                            <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                                                <h4 class="text-sm font-extrabold text-slate-950">
                                                    Delivery options
                                                </h4>
                                                <p class="mt-1 text-sm text-slate-500">
                                                    Each option can use its own Messaging template without changing the campaign step timing.
                                                </p>

                                                @if($step->variants->isEmpty())
                                                    <div class="mt-4 rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                                                        No campaign step variants exist for this step. Run campaign preset sync after confirming the variant config exists.
                                                    </div>
                                                @endif
                                            </div>
                                        </div>

                                        @foreach($step->variants as $variant)
                                            @php
                                                $variantKey = implode(':', [
                                                    $variant->channel,
                                                    $variant->purpose,
                                                    $variant->scope,
                                                    $step->step_number,
                                                    $variant->key,
                                                ]);
                                                $options = $templateOptionsByVariant->get($variantKey, collect());
                                                $assignment = $currentAssignments->get($variantKey);
                                                $selectedPreset = $assignment?->messageTemplatePreset;
                                                $selectedCatalogEntry = $selectedPreset?->catalogEntries?->first();
                                            @endphp

                                            <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                                                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                                    <div>
                                                        <div class="flex flex-wrap items-center gap-2">
                                                            <h4 class="text-sm font-extrabold text-slate-950">
                                                                {{ $variant->name ?: Str::headline(str_replace('_', ' ', $variant->key)) }}
                                                            </h4>
                                                            <span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-bold uppercase tracking-wide text-slate-600">
                                                                {{ $variant->channel }}
                                                            </span>
                                                            <span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-bold text-slate-600">
                                                                {{ str_replace('_', ' ', $variant->scope) }}
                                                            </span>
                                                        </div>
                                                        <p class="mt-1 text-sm text-slate-500">
                                                            Active template: {{ $selectedPreset?->name ?: 'No template selected for this delivery option.' }}
                                                        </p>
                                                    </div>

                                                    @if($selectedPreset)
                                                        <a
                                                            href="{{ route('crm.messaging.message-templates.index', ['channel' => $selectedPreset->channel, 'purpose' => $selectedPreset->purpose, 'module' => $selectedCatalogEntry?->module_key, 'preset' => $selectedPreset->getKey()]) }}"
                                                            class="inline-flex min-h-9 items-center justify-center rounded-full border border-slate-300 px-4 text-xs font-extrabold text-slate-700 transition hover:bg-slate-50"
                                                        >
                                                            Edit copy
                                                        </a>
                                                    @endif
                                                </div>

                                                @if($options->isEmpty())
                                                    <div class="mt-4 rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                                                        No compatible Messaging template is cataloged for this campaign delivery option. Run the Messaging template preset sync after confirming the variant template path exists.
                                                    </div>
                                                @else
                                                    <form
                                                        method="POST"
                                                        action="{{ route('crm.campaigns.message-templates.update', $step) }}"
                                                        class="mt-4 space-y-3"
                                                    >
                                                        @csrf
                                                        @method('PATCH')

                                                        <input type="hidden" name="campaign_step_variant_id" value="{{ $variant->id }}">

                                                        <label for="message_template_preset_id_{{ $step->id }}_{{ $variant->id }}" class="block text-sm font-bold text-slate-900">
                                                            Template for this delivery option
                                                        </label>
                                                        <select
                                                            id="message_template_preset_id_{{ $step->id }}_{{ $variant->id }}"
                                                            name="message_template_preset_id"
                                                            class="block w-full rounded-xl border border-slate-300 px-4 py-2.5 text-sm text-slate-900 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500"
                                                        >
                                                            @foreach($options as $option)
                                                                <option
                                                                    value="{{ $option->messageTemplatePreset->getKey() }}"
                                                                    @selected($selectedPreset && $selectedPreset->is($option->messageTemplatePreset))
                                                                >
                                                                    {{ $option->item_label }} — {{ $option->messageTemplatePreset->name }}
                                                                </option>
                                                            @endforeach
                                                        </select>

                                                        @error('campaign_step_variant_id')
                                                            <p class="text-sm font-semibold text-red-600">{{ $message }}</p>
                                                        @enderror
                                                        @error('message_template_preset_id')
                                                            <p class="text-sm font-semibold text-red-600">{{ $message }}</p>
                                                        @enderror

                                                        <div class="flex items-center justify-between gap-4 pt-1">
                                                            <p class="text-xs leading-5 text-slate-500">
                                                                This changes the selected template only. It does not edit campaign timing or reusable message copy.
                                                            </p>
                                                            <button
                                                                type="submit"
                                                                class="inline-flex min-h-10 items-center justify-center rounded-full bg-slate-950 px-5 text-sm font-extrabold text-white transition hover:bg-slate-800"
                                                            >
                                                                Save selection
                                                            </button>
                                                        </div>
                                                    </form>
                                                @endif
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </section>
            </div>
        @endif
    </div>
</x-layouts.crm>