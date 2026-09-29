<x-layouts.crm
    title="Create Campaign"
    heading="Create Campaign"
    subheading="Choose what you want the campaign to do, write the first message, and finish the details before anything sends."
    module="campaigns"
>
    <div class="min-w-0 space-y-6" data-campaign-creation>
        <div>
            <a
                href="{{ route('crm.campaigns.index') }}"
                class="inline-flex min-h-10 items-center justify-center rounded-full border border-slate-300 bg-white px-4 text-sm font-extrabold text-slate-700 hover:bg-slate-50"
            >
                Back to Campaigns
            </a>
        </div>

        @if($errors->any())
            <x-ui.feedback.alert type="error">
                <ul class="list-disc space-y-1 pl-5">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </x-ui.feedback.alert>
        @endif

        <div class="grid min-w-0 gap-6 xl:grid-cols-[minmax(18rem,0.42fr)_minmax(0,1fr)] xl:items-start">
            <x-ui.card class="space-y-4">
                <div>
                    <p class="text-xs font-extrabold uppercase tracking-[0.16em] text-slate-500">1 · Choose the goal</p>
                    <h2 class="mt-1 text-lg font-extrabold text-slate-950">What are you trying to accomplish?</h2>
                    <p class="mt-1 text-sm leading-6 text-slate-600">
                        Pick the closest fit. You can still change the audience, messages, and timing afterward.
                    </p>
                </div>

                <div class="space-y-2">
                    @foreach($options as $option)
                        <a
                            href="{{ route('crm.campaigns.create', ['use' => $option->key]) }}"
                            class="block rounded-2xl border p-4 transition {{ $selectedOption?->key === $option->key ? 'border-rose-300 bg-rose-50' : 'border-slate-200 bg-white hover:bg-slate-50' }}"
                            data-campaign-creation-option="{{ $option->key }}"
                        >
                            <div class="text-sm font-extrabold text-slate-950">{{ $option->label }}</div>
                            <p class="mt-2 text-sm leading-5 text-slate-600">{{ $option->description }}</p>
                        </a>
                    @endforeach
                </div>
            </x-ui.card>

            @if($selectedOption)
                <x-campaigns.builder-shell :stages="$builderStages" mode="create" class="min-w-0">
                    <x-ui.card class="space-y-5">
                        <div>
                            <p class="text-xs font-extrabold uppercase tracking-[0.16em] text-slate-500">2 · Build the campaign</p>
                            <h2 class="mt-1 break-words text-xl font-extrabold text-slate-950">{{ $selectedOption->label }}</h2>
                            <p class="mt-2 text-sm leading-6 text-slate-600">
                                Give it a name, choose how it should work, and write the first message. Nothing sends until you finish setup and turn it on.
                            </p>
                        </div>

                        <form
                            method="POST"
                            action="{{ route('crm.campaigns.store') }}"
                            enctype="multipart/form-data"
                            class="space-y-5"
                            x-data="{ channel: @js(old('channel', 'email')), executionStrategy: @js(old('execution_strategy', 'sequence')) }"
                        >
                            @csrf
                            <input type="hidden" name="creation_intent" value="{{ $selectedOption->key }}">

                            <div>
                                <label for="campaign-name" class="mb-1.5 block text-sm font-extrabold text-slate-800">Campaign name</label>
                                <input
                                    id="campaign-name"
                                    name="name"
                                    value="{{ old('name') }}"
                                    maxlength="191"
                                    required
                                    placeholder="{{ $selectedOption->namePlaceholder }}"
                                    class="block w-full rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm text-slate-900 shadow-sm focus:border-slate-500 focus:outline-none focus:ring-0"
                                >
                            </div>

                            <div>
                                <label for="campaign-description" class="mb-1.5 block text-sm font-extrabold text-slate-800">Description <span class="font-semibold text-slate-500">(optional)</span></label>
                                <textarea
                                    id="campaign-description"
                                    name="description"
                                    rows="3"
                                    maxlength="4000"
                                    class="block w-full rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm text-slate-900 shadow-sm focus:border-slate-500 focus:outline-none focus:ring-0"
                                >{{ old('description') }}</textarea>
                            </div>

                            <fieldset class="space-y-2">
                                <legend class="text-sm font-extrabold text-slate-800">How should this campaign work?</legend>
                                <div class="grid gap-2 lg:grid-cols-2">
                                    <label class="flex cursor-pointer items-start gap-3 rounded-2xl border border-slate-200 bg-white p-4">
                                        <input
                                            type="radio"
                                            name="execution_strategy"
                                            value="sequence"
                                            x-model="executionStrategy"
                                            class="mt-1 size-4 border-slate-300 text-slate-950 focus:ring-slate-500"
                                        >
                                        <span>
                                            <span class="block text-sm font-extrabold text-slate-950">Follow-up series</span>
                                            <span class="mt-1 block text-xs leading-5 text-slate-500">Each lead receives the messages in order over time. Use this for a normal nurture or follow-up journey.</span>
                                        </span>
                                    </label>
                                    <label class="flex cursor-pointer items-start gap-3 rounded-2xl border border-slate-200 bg-white p-4">
                                        <input
                                            type="radio"
                                            name="execution_strategy"
                                            value="recurring_allocation"
                                            x-model="executionStrategy"
                                            class="mt-1 size-4 border-slate-300 text-slate-950 focus:ring-slate-500"
                                        >
                                        <span>
                                            <span class="block text-sm font-extrabold text-slate-950">Ongoing outreach</span>
                                            <span class="mt-1 block text-xs leading-5 text-slate-500">On a repeating schedule, choose fresh eligible leads for each message. Use this when you want steady outreach from a larger lead pool.</span>
                                        </span>
                                    </label>
                                </div>
                                @error('execution_strategy')<p class="mt-2 text-sm font-semibold text-red-600">{{ $message }}</p>@enderror
                            </fieldset>

                            <fieldset class="space-y-2">
                                <legend class="text-sm font-extrabold text-slate-800">First message channel</legend>

                                <div class="grid gap-2 sm:grid-cols-2">
                                    <label class="flex cursor-pointer items-center gap-3 rounded-2xl border border-slate-200 bg-white p-4">
                                        <input
                                            type="radio"
                                            name="channel"
                                            value="email"
                                            x-model="channel"
                                            class="size-4 border-slate-300 text-slate-950 focus:ring-slate-500"
                                        >
                                        <span>
                                            <span class="block text-sm font-extrabold text-slate-950">Email</span>
                                            <span class="mt-0.5 block text-xs text-slate-500">Write a subject and email body, with optional images, files, and personalization.</span>
                                        </span>
                                    </label>

                                    <label class="flex cursor-pointer items-center gap-3 rounded-2xl border border-slate-200 bg-white p-4">
                                        <input
                                            type="radio"
                                            name="channel"
                                            value="sms"
                                            x-model="channel"
                                            class="size-4 border-slate-300 text-slate-950 focus:ring-slate-500"
                                        >
                                        <span>
                                            <span class="block text-sm font-extrabold text-slate-950">SMS</span>
                                            <span class="mt-0.5 block text-xs text-slate-500">Start with a text message. Normal SMS permission rules still apply.</span>
                                        </span>
                                    </label>
                                </div>
                            </fieldset>

                            <div x-show="channel === 'email'" x-cloak class="space-y-5">
                                <x-ui.message-editor
                                    :subject="[
                                        'id' => 'campaign-first-subject',
                                        'name' => 'subject',
                                        'value' => old('subject'),
                                        'label' => 'Email subject',
                                        'maxlength' => 255,
                                    ]"
                                    :body="[
                                        'id' => 'campaign-first-body',
                                        'name' => 'body',
                                        'value' => old('body'),
                                        'label' => 'Email body',
                                        'maxlength' => 10000,
                                        'rows' => 9,
                                    ]"
                                />

                                <x-messaging.message-media-authoring :failed="$errors->any()" />
                            </div>

                            <div x-show="channel === 'sms'" x-cloak>
                                <x-ui.message-editor
                                    :sms="[
                                        'id' => 'campaign-first-message',
                                        'name' => 'message',
                                        'value' => old('message'),
                                        'label' => 'SMS message',
                                        'maxlength' => 1600,
                                        'rows' => 7,
                                    ]"
                                />
                            </div>

                            <div class="rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-950">
                                <div class="font-extrabold">Nothing sends yet</div>
                                <p class="mt-1 leading-6">
                                    The new campaign starts Off. After you create it, finish who gets it, review the messages, set the timing, and turn it on only when you are ready.
                                </p>
                            </div>

                            <div class="flex flex-wrap items-center gap-3">
                                <button
                                    type="submit"
                                    class="inline-flex min-h-11 items-center justify-center rounded-full bg-slate-950 px-6 text-sm font-extrabold text-white hover:bg-slate-800"
                                    data-create-campaign-submit
                                >
                                    Create campaign
                                </button>
                                <a href="{{ route('crm.campaigns.index') }}" class="text-sm font-bold text-slate-600 underline">Cancel</a>
                            </div>
                        </form>
                    </x-ui.card>

                    @if($availableFields !== [])
                        <x-ui.card>
                            <details>
                                <summary class="cursor-pointer text-sm font-extrabold text-slate-950">Personalization fields</summary>
                                <p class="mt-2 text-sm leading-6 text-slate-600">
                                    Use these only when you want the message to automatically insert lead or campaign information.
                                </p>

                                <div class="mt-4 space-y-4">
                                    @foreach($availableFields as $group)
                                        <div>
                                            <h3 class="text-xs font-extrabold uppercase tracking-wide text-slate-500">{{ $group['label'] }}</h3>
                                            <div class="mt-2 flex flex-wrap gap-2">
                                                @foreach($group['fields'] as $field)
                                                    <code class="rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-xs font-bold text-slate-800" title="{{ $field['description'] }}">{{ $field['syntax'] }}</code>
                                                @endforeach
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            </details>
                        </x-ui.card>
                    @endif
                </x-campaigns.builder-shell>
            @endif
        </div>
    </div>
</x-layouts.crm>