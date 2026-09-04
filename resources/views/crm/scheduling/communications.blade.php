
<x-layouts.crm
    :title="$title"
    :heading="$heading"
    subheading="Choose when appointment confirmations, reminders, and follow-up messages go out, and what they say."
>
    @php
        $configured = (bool) ($plan['configured'] ?? false);
        $available = (bool) ($plan['available'] ?? false);
        $steps = old('steps', $plan['steps'] ?? []);
        $channels = collect($plan['channels'] ?? []);
        $inputClass = 'mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-200';
        $labelClass = 'block text-sm font-medium text-slate-700';
    @endphp

    <div class="space-y-6" data-scheduling-appointment-communications>
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <a
                href="{{ route('crm.scheduling.configuration.index') }}"
                class="inline-flex w-full items-center justify-center rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50 sm:w-auto"
            >
                Back to Scheduling Setup
            </a>

            @if($configured)
                <div class="flex flex-wrap gap-2 text-xs font-semibold">
                    @foreach($channels as $channel)
                        <span class="rounded-full px-3 py-1 {{ $channel['provider_ready'] ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-900' }}">
                            {{ $channel['label'] }} · {{ $channel['provider_ready'] ? 'ready' : 'provider not ready' }}
                        </span>
                    @endforeach
                </div>
            @endif
        </div>

        @if (session('success'))
            <x-ui.feedback.alert type="success">
                {{ session('success') }}
            </x-ui.feedback.alert>
        @endif

        @if (session('error'))
            <x-ui.feedback.alert type="error">
                {{ session('error') }}
            </x-ui.feedback.alert>
        @endif

        @if ($errors->any())
            <x-ui.feedback.alert type="error">
                <ul class="list-disc space-y-1 pl-5">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </x-ui.feedback.alert>
        @endif

        @if(! $available)
            <x-ui.card class="space-y-3">
                <h2 class="text-lg font-semibold text-slate-900">Messaging is not available</h2>
                <p class="text-sm text-slate-600">
                    Enable Messaging with Scheduling to configure appointment confirmations and reminders.
                </p>
            </x-ui.card>
        @elseif(! $configured)
            <x-ui.card
                class="space-y-5"
                data-appointment-communications-empty
            >
                <div>
                    <div class="inline-flex rounded-full px-2 py-1 text-xs font-semibold {{ module_tone('scheduling', 'badge') }}">
                        Recommended starting point
                    </div>
                    <h2 class="mt-3 text-xl font-semibold tracking-tight text-slate-900">
                        Generate your appointment message schedule
                    </h2>
                    <p class="mt-2 max-w-2xl text-sm text-slate-600">
                        Start with an immediate confirmation plus reminders 3 days, 24 hours, and 1 hour before the appointment. You can change the timing, channels, or message immediately after generating it.
                    </p>
                </div>

                <form method="POST" action="{{ route('crm.scheduling.configuration.communications.generate') }}">
                    @csrf
                    <x-ui.button type="submit">
                        Generate schedule
                    </x-ui.button>
                </form>
            </x-ui.card>
        @else
            <form
                method="POST"
                action="{{ route('crm.scheduling.configuration.communications.update') }}"
                data-appointment-communications-editor
                class="space-y-6"
                x-data="{
                    steps: @js($steps),
                    newStep() {
                        return {
                            key: '',
                            name: 'New reminder',
                            timing: 'before',
                            offset_value: 1,
                            offset_unit: 'days',
                            channels: @js($channels->where('provider_ready', true)->pluck('key')->values()->all() ?: $channels->take(1)->pluck('key')->values()->all()),
                            subject: 'Appointment reminder',
                            message: @js(config('scheduling.communications.default_message')),
                        };
                    },
                    addStep() {
                        this.steps.push(this.newStep());
                    },
                    removeStep(index) {
                        this.steps.splice(index, 1);
                    }
                }"
            >
                @csrf
                @method('PUT')

                <x-ui.card class="space-y-4">
                    <div>
                        <h2 class="text-lg font-semibold text-slate-900">Schedule</h2>
                        <p class="mt-1 text-sm text-slate-500">
                            Reminders whose scheduled time has already passed are skipped automatically. Rescheduling recalculates future reminders; cancellation stops the remaining appointment messages.
                        </p>
                    </div>

                    <template x-for="(step, index) in steps" :key="step.key || `new-${index}`">
                        <div class="space-y-4 rounded-xl border border-slate-200 p-4" data-appointment-communication-step>
                            <input type="hidden" x-bind:name="`steps[${index}][key]`" x-model="step.key">

                            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                <label class="{{ $labelClass }} flex-1">
                                    Name
                                    <input
                                        class="{{ $inputClass }}"
                                        x-bind:name="`steps[${index}][name]`"
                                        x-model="step.name"
                                        required
                                    >
                                </label>

                                <button
                                    type="button"
                                    class="text-sm font-semibold text-red-600 hover:text-red-800 sm:mt-8"
                                    x-on:click="removeStep(index)"
                                >
                                    Remove
                                </button>
                            </div>

                            <div class="grid gap-4 md:grid-cols-[minmax(0,1fr)_minmax(0,8rem)_minmax(0,9rem)]">
                                <label class="{{ $labelClass }}">
                                    When
                                    <select
                                        class="{{ $inputClass }}"
                                        x-bind:name="`steps[${index}][timing]`"
                                        x-model="step.timing"
                                    >
                                        <option value="immediate">Immediately after booking</option>
                                        <option value="before">Before the appointment</option>
                                        <option value="after">After the appointment</option>
                                    </select>
                                </label>

                                <label class="{{ $labelClass }}" x-show="step.timing !== 'immediate'" x-cloak>
                                    Amount
                                    <input
                                        class="{{ $inputClass }}"
                                        type="number"
                                        min="1"
                                        max="525600"
                                        x-bind:name="`steps[${index}][offset_value]`"
                                        x-model.number="step.offset_value"
                                        x-bind:disabled="step.timing === 'immediate'"
                                    >
                                </label>

                                <label class="{{ $labelClass }}" x-show="step.timing !== 'immediate'" x-cloak>
                                    Unit
                                    <select
                                        class="{{ $inputClass }}"
                                        x-bind:name="`steps[${index}][offset_unit]`"
                                        x-model="step.offset_unit"
                                        x-bind:disabled="step.timing === 'immediate'"
                                    >
                                        <option value="minutes">Minutes</option>
                                        <option value="hours">Hours</option>
                                        <option value="days">Days</option>
                                    </select>
                                </label>
                            </div>

                            <fieldset>
                                <legend class="{{ $labelClass }}">Send by</legend>
                                <div class="mt-2 flex flex-wrap gap-3">
                                    @foreach($channels as $channel)
                                        <label class="inline-flex items-center gap-2 rounded-lg border border-slate-200 px-3 py-2 text-sm text-slate-700">
                                            <input
                                                type="checkbox"
                                                x-bind:name="`steps[${index}][channels][]`"
                                                value="{{ $channel['key'] }}"
                                                x-bind:checked="step.channels.includes('{{ $channel['key'] }}')"
                                                x-on:change="
                                                    if ($event.target.checked && ! step.channels.includes('{{ $channel['key'] }}')) step.channels.push('{{ $channel['key'] }}');
                                                    if (! $event.target.checked) step.channels = step.channels.filter(channel => channel !== '{{ $channel['key'] }}');
                                                "
                                            >
                                            <span>{{ $channel['label'] }}</span>
                                            @unless($channel['provider_ready'])
                                                <span class="text-xs text-amber-700">(provider not ready)</span>
                                            @endunless
                                        </label>
                                    @endforeach
                                </div>
                            </fieldset>

                            <x-ui.message-editor
                                :subject="[
                                    'label' => 'Email subject',
                                    'name_bind' => '`steps[${index}][subject]`',
                                    'model' => 'step.subject',
                                    'maxlength' => 255,
                                    'visible_bind' => 'step.channels.includes(\'email\')',
                                    'label_class' => $labelClass,
                                    'input_class' => $inputClass,
                                ]"
                                :body="[
                                    'label' => 'Message',
                                    'name_bind' => '`steps[${index}][message]`',
                                    'model' => 'step.message',
                                    'rows' => 7,
                                    'maxlength' => 5000,
                                    'required' => true,
                                    'label_class' => $labelClass,
                                    'input_class' => $inputClass,
                                ]"
                            />
                        </div>
                    </template>

                    <button
                        type="button"
                        class="inline-flex items-center rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50"
                        x-on:click="addStep()"
                    >
                        Add message
                    </button>
                </x-ui.card>

                <x-ui.card class="space-y-3">
                    <h2 class="text-lg font-semibold text-slate-900">Available appointment fields</h2>
                    <p class="text-sm text-slate-600">
                        Use these fields anywhere in the message:
                    </p>
                    <div class="flex flex-wrap gap-2">
                        @foreach($plan['tokens'] ?? [] as $token)
                            <code class="rounded bg-slate-100 px-2 py-1 text-xs text-slate-800">{{ $token }}</code>
                        @endforeach
                    </div>
                    <p class="text-xs text-slate-500">
                        Appointment-related confirmations, reminders, scheduling updates, and practical follow-up are transactional. Promotional or nurture messaging still requires marketing permission.
                    </p>
                </x-ui.card>

                <div class="flex flex-wrap items-center gap-3">
                    <x-ui.button type="submit">
                        Save schedule
                    </x-ui.button>

                    <a
                        href="{{ route('crm.scheduling.configuration.index') }}"
                        class="text-sm font-semibold text-slate-600 hover:text-slate-900"
                    >
                        Back to Scheduling Setup
                    </a>
                </div>
            </form>
        @endif
    </div>
</x-layouts.crm>