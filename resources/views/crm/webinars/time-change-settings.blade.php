<x-layouts.crm :title="$series->title.' · Time-change messages'" :heading="'Time-change messages · '.$series->title">
    <div class="mx-auto max-w-4xl space-y-6 px-4 py-6">
        <a href="{{ route('crm.webinar-series.show', $series) }}" class="text-sm font-semibold text-slate-700 underline">Back to webinar type</a>

        @if(session('status'))
            <p class="rounded-xl bg-emerald-50 p-4 text-sm text-emerald-900">{{ session('status') }}</p>
        @endif
        @if($errors->any())
            <p class="rounded-xl bg-red-50 p-4 text-sm text-red-900">{{ $errors->first() }}</p>
        @endif

        <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
            <h1 class="text-xl font-semibold text-slate-950">When the webinar time changes</h1>
            <p class="mt-2 text-sm text-slate-600">These messages are triggered by a provider resync that changes an existing session's time or displayed timezone. They are separate from reminders timed relative to the webinar start. Only people registered before the change and eligible for a selected channel receive a notice.</p>
            <p class="mt-2 text-sm text-slate-600">Publish a message for each channel you want to use, then enable automatic sending. Existing change records keep the policy and published versions they had when created.</p>
        </section>

        @foreach(['email' => 'Email', 'sms' => 'SMS'] as $channel => $label)
            <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                <h2 class="text-lg font-semibold text-slate-950">{{ $label }} time-change message</h2>
                <p class="mt-1 text-sm text-slate-600">{{ $published[$channel] ? 'Published version '.$published[$channel]->version : 'No message published yet.' }}</p>
                <form method="POST" action="{{ route('crm.webinar-series.time-change-settings.template', $series) }}" class="mt-4 space-y-4">
                    @csrf
                    <input type="hidden" name="channel" value="{{ $channel }}">
                    @if($channel === 'email')
                        <label class="block text-sm font-semibold text-slate-800">Subject
                            <input name="subject" required maxlength="255" value="{{ old('channel') === 'email' ? old('subject', $copy['email']['subject']) : $copy['email']['subject'] }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 font-normal">
                        </label>
                        <label class="block text-sm font-semibold text-slate-800">Body
                            <textarea name="body" required rows="6" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 font-normal">{{ old('channel') === 'email' ? old('body', $copy['email']['body']) : $copy['email']['body'] }}</textarea>
                        </label>
                    @else
                        <label class="block text-sm font-semibold text-slate-800">Text
                            <textarea name="message" required rows="5" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 font-normal">{{ old('channel') === 'sms' ? old('message', $copy['sms']['message']) : $copy['sms']['message'] }}</textarea>
                        </label>
                    @endif
                    <p class="text-xs text-slate-500">Available tokens: @foreach($tokens as $token)<code>{<span>{{ $token }}</span>}</code>{{ ! $loop->last ? ', ' : '' }}@endforeach. Previous and current webinar times are required in the body.</p>
                    <button type="submit" class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white">Publish {{ $label }} message</button>
                </form>
            </section>
        @endforeach

        <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
            <h2 class="text-lg font-semibold text-slate-950">Delivery</h2>
            <form method="POST" action="{{ route('crm.webinar-series.time-change-settings.policy', $series) }}" class="mt-4 space-y-4">
                @csrf
                @method('PATCH')
                <input type="hidden" name="auto_send" value="0">
                <label class="flex items-center gap-2 text-sm font-semibold text-slate-800"><input type="checkbox" name="auto_send" value="1" @checked(old('auto_send', $autoSend))> Automatically send after a provider resync changes the time</label>
                <fieldset>
                    <legend class="text-sm font-semibold text-slate-800">Send by</legend>
                    <div class="mt-2 flex flex-wrap gap-5">
                        @foreach(['email' => 'Email', 'sms' => 'SMS'] as $channel => $label)
                            <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="channels[]" value="{{ $channel }}" @checked(in_array($channel, old('channels', $selectedChannels)))> {{ $label }}{{ ! in_array($channel, $availableChannels) ? ' (provider unavailable)' : '' }}{{ ! $published[$channel] ? ' (publish message first)' : '' }}</label>
                        @endforeach
                    </div>
                </fieldset>
                <button type="submit" class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white">Save delivery settings</button>
            </form>
        </section>
    </div>
</x-layouts.crm>