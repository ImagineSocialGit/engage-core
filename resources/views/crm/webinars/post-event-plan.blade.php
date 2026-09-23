<x-layouts.crm :title="$series->title.' · Follow-up messages'" :heading="'Follow-up messages · '.$series->title">
    <div class="mx-auto max-w-5xl space-y-6 px-4 py-6">
        <a href="{{ route('crm.webinar-series.show', $series) }}" class="text-sm font-semibold text-slate-700 underline">Back to webinar type</a>
        @if(session('status'))
            <p class="rounded-xl bg-emerald-50 p-4 text-sm text-emerald-900">{{ session('status') }}</p>
        @endif
        @if($errors->any())
            <p class="rounded-xl bg-red-50 p-4 text-sm text-red-900">{{ $errors->first() }}</p>
        @endif

        <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
            <h1 class="text-xl font-semibold text-slate-950">Follow-up messages</h1>
            <p class="mt-2 text-sm text-slate-600">Each rule chooses one message for its registrants. An unmet send condition can suppress it or send an alternate message. Delivery waits for authoritative attendance.</p>
            <p class="mt-3 text-sm font-medium text-slate-800">{{ $plan['enabled'] ?? false ? 'Enabled for future sessions' : 'Draft · no messages scheduled' }}</p>
        </section>

        @foreach($cards as $card)
            <section id="rule-{{ $card['id'] }}" class="scroll-mt-6 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 class="text-lg font-semibold text-slate-950">{{ $card['primary']['preset']?->name ?: 'Follow-up message' }}</h2>
                        <p class="text-sm text-slate-600">{{ strtoupper($card['rule']['channel']) }} · {{ $card['rule']['enabled'] ? 'Enabled when the plan is active' : 'Disabled' }}</p>
                    </div>
                    <form method="POST" action="{{ route('crm.webinar-series.post-event-plan.rules.destroy', [$series, $card['id']]) }}">
                        @csrf
                        @method('DELETE')
                        <button class="text-sm font-semibold text-red-700 underline" onclick="return confirm('Remove this follow-up message?')">Remove</button>
                    </form>
                </div>
                <form method="POST" action="{{ route('crm.webinar-series.post-event-plan.rules.update', [$series, $card['id']]) }}" class="mt-5 space-y-4">
                    @csrf
                    @method('PATCH')
                    <input type="hidden" name="rules[{{ $card['id'] }}][enabled]" value="0">
                    <label class="flex items-center gap-2 text-sm font-semibold text-slate-900"><input type="checkbox" name="rules[{{ $card['id'] }}][enabled]" value="1" @checked(old('rules.'.$card['id'].'.enabled', $card['rule']['enabled']))> Send this message</label>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <label class="block text-sm font-semibold text-slate-900">Message template
                            <select name="rules[{{ $card['id'] }}][template_key]" required class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 font-normal">
                                @foreach($templateOptions as $option)
                                    <option value="{{ $option->key }}" @selected(old('rules.'.$card['id'].'.template_key', $card['rule']['template_key']) === $option->key)>{{ strtoupper($option->channel) }} · {{ $option->name }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="block text-sm font-semibold text-slate-900">Registrant condition
                            <select name="rules[{{ $card['id'] }}][outcome]" required class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 font-normal">
                                @foreach($outcomes as $value => $label)
                                    <option value="{{ $value }}" @selected(old('rules.'.$card['id'].'.outcome', $card['rule']['outcome']) === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="block text-sm font-semibold text-slate-900">Message trigger
                            <select name="rules[{{ $card['id'] }}][trigger]" required class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 font-normal">
                                @foreach($triggers as $value => $label)
                                    <option value="{{ $value }}" @selected(old('rules.'.$card['id'].'.trigger', $card['rule']['trigger']) === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="block text-sm font-semibold text-slate-900">Send condition
                            <select name="rules[{{ $card['id'] }}][send_condition]" required class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 font-normal">
                                @foreach($sendConditions as $value => $label)
                                    <option value="{{ $value }}" @selected(old('rules.'.$card['id'].'.send_condition', $card['rule']['send_condition']) === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </label>
                    </div>
                    <fieldset class="rounded-xl border border-slate-200 p-4">
                        <legend class="px-1 text-sm font-semibold text-slate-900">Timing from the trigger</legend>
                        <div class="grid gap-4 sm:grid-cols-3">
                            <label class="block text-sm font-semibold text-slate-900">Amount
                                <input type="number" min="0" max="1440" name="rules[{{ $card['id'] }}][delay_value]" value="{{ old('rules.'.$card['id'].'.delay_value', $card['rule']['delay_value']) }}" required class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 font-normal">
                            </label>
                            <label class="block text-sm font-semibold text-slate-900">Unit
                                <select name="rules[{{ $card['id'] }}][delay_unit]" required class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 font-normal">
                                    <option value="minutes" @selected(old('rules.'.$card['id'].'.delay_unit', $card['rule']['delay_unit']) === 'minutes')>Minutes</option>
                                    <option value="days" @selected(old('rules.'.$card['id'].'.delay_unit', $card['rule']['delay_unit']) === 'days')>Days</option>
                                </select>
                            </label>
                            <label class="block text-sm font-semibold text-slate-900">Optional local time for day delays
                                <input type="time" name="rules[{{ $card['id'] }}][send_time]" value="{{ old('rules.'.$card['id'].'.send_time', $card['rule']['send_time'] ?? '') }}" class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 font-normal">
                            </label>
                        </div>
                        <p class="mt-2 text-xs text-slate-600">Up to 1,440 minutes or 365 days. The optional time uses the client timezone.</p>
                    </fieldset>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <label class="block text-sm font-semibold text-slate-900">If the send condition is not met
                            <select name="rules[{{ $card['id'] }}][on_condition_failure]" required class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 font-normal">
                                @foreach($conditionFailureActions as $value => $label)
                                    <option value="{{ $value }}" @selected(old('rules.'.$card['id'].'.on_condition_failure', $card['rule']['on_condition_failure']) === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="block text-sm font-semibold text-slate-900">Alternate template (if selected)
                            <select name="rules[{{ $card['id'] }}][alternate_template_key]" class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 font-normal">
                                <option value="">Choose a template</option>
                                @foreach($templateOptions as $option)
                                    <option value="{{ $option->key }}" @selected(old('rules.'.$card['id'].'.alternate_template_key', $card['rule']['alternate_template_key'] ?? '') === $option->key)>{{ strtoupper($option->channel) }} · {{ $option->name }}</option>
                                @endforeach
                            </select>
                        </label>
                    </div>
                    <button class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white">Save message rule</button>
                </form>

                @foreach(['primary' => 'Message content', 'alternate' => 'Alternate message content'] as $branch => $heading)
                    @if($branch === 'primary' || ($card['rule']['on_condition_failure'] === 'alternate' && $card['rule']['send_condition'] !== 'always'))
                        <div class="mt-6 border-t border-slate-200 pt-5">
                            <h3 class="text-base font-semibold text-slate-950">{{ $heading }}</h3>
                            @if($card[$branch]['preset'])
                                <p class="mt-1 text-sm text-slate-600">{{ $card[$branch]['preset']->name }}</p>
                                <form method="POST" action="{{ route('crm.webinar-series.post-event-plan.rules.copy', [$series, $card['id']]) }}" class="mt-3 space-y-3">
                                    @csrf
                                    @method('PATCH')
                                    <input type="hidden" name="branch" value="{{ $branch }}">
                                    @if($card['rule']['channel'] === 'email')
                                        <label class="block text-sm font-semibold text-slate-900">Subject
                                            <input type="text" maxlength="255" name="copy[{{ $card['id'] }}][{{ $branch }}][subject]" value="{{ old('copy.'.$card['id'].'.'.$branch.'.subject', $card[$branch]['subject']) }}" required class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 font-normal">
                                        </label>
                                    @endif
                                    <label class="block text-sm font-semibold text-slate-900">{{ $card['rule']['channel'] === 'sms' ? 'Text message' : 'Email body' }}
                                        <textarea name="copy[{{ $card['id'] }}][{{ $branch }}][{{ $card['rule']['channel'] === 'sms' ? 'message' : 'body' }}]" rows="8" required class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 font-normal">{{ old('copy.'.$card['id'].'.'.$branch.'.'.($card['rule']['channel'] === 'sms' ? 'message' : 'body'), $card[$branch]['copy']) }}</textarea>
                                    </label>
                                    <div class="flex flex-wrap items-center gap-4">
                                        <button class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-900">Publish copy</button>
                                        @if($card[$branch]['advanced_url'])
                                            <a href="{{ $card[$branch]['advanced_url'] }}" class="text-sm font-semibold text-slate-700 underline">Full message editor (media, CTA and more)</a>
                                        @endif
                                    </div>
                                </form>
                            @else
                                <p class="mt-2 text-sm text-amber-800">Choose an active template above to edit its copy.</p>
                            @endif
                        </div>
                    @endif
                @endforeach
            </section>
        @endforeach

        <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
            <h2 class="text-lg font-semibold text-slate-950">Add a follow-up message</h2>
            <p class="mt-2 text-sm text-slate-700">Need new copy? <a href="{{ route('crm.messaging.message-templates.create', ['use' => 'webinars.post_event.sms']) }}" class="font-semibold underline">Create text template</a> or <a href="{{ route('crm.messaging.message-templates.create', ['use' => 'webinars.post_event.email']) }}" class="font-semibold underline">create email template</a>.</p>
            @if($templateOptions->isEmpty())
                <p class="mt-2 text-sm text-slate-700">Create and publish a webinar follow-up template in <a href="{{ route('crm.messaging.message-templates.index', ['module' => 'webinars']) }}" class="font-semibold underline">Message Templates</a> first.</p>
            @else
                <form method="POST" action="{{ route('crm.webinar-series.post-event-plan.rules.store', $series) }}" class="mt-3 flex flex-wrap items-end gap-3">
                    @csrf
                    <label class="block min-w-64 flex-1 text-sm font-semibold text-slate-900">Message template
                        <select name="template_key" required class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 font-normal">
                            @foreach($templateOptions as $option)
                                <option value="{{ $option->key }}">{{ strtoupper($option->channel) }} · {{ $option->name }}</option>
                            @endforeach
                        </select>
                    </label>
                    <button class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-900">Add disabled rule</button>
                </form>
            @endif
        </section>

        <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
            <form method="POST" action="{{ route('crm.webinar-series.post-event-plan.update', $series) }}" class="space-y-4">
                @csrf
                @method('PATCH')
                <input type="hidden" name="enabled" value="0">
                <label class="flex items-center gap-2 text-sm font-semibold text-slate-900"><input type="checkbox" name="enabled" value="1" @checked(old('enabled', $plan['enabled'] ?? false))> Enable this plan for future sessions</label>
                <p class="text-sm text-slate-600">Edits to active rules apply to future sessions; older queued revisions are skipped. Already scheduled messages keep their published copy.</p>
                <button class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white">Save plan status</button>
            </form>
        </section>
    </div>
</x-layouts.crm>