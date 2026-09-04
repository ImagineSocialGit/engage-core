<x-layouts.crm
    title="Create Campaign"
    heading="Create Campaign"
    subheading="Start with the job this campaign needs to do. Engage will create an inactive campaign with one real message and drop you into the existing setup builder."
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
                    <p class="text-xs font-extrabold uppercase tracking-[0.16em] text-slate-500">1 · Choose the job</p>
                    <h2 class="mt-1 text-lg font-extrabold text-slate-950">What is this campaign for?</h2>
                    <p class="mt-1 text-sm leading-6 text-slate-600">
                        This choice records the campaign's authoring intent. Delivery purpose, scope, queue, and runtime ownership stay server-controlled.
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
                            <p class="text-xs font-extrabold uppercase tracking-[0.16em] text-slate-500">2 · Start the campaign</p>
                            <h2 class="mt-1 break-words text-xl font-extrabold text-slate-950">{{ $selectedOption->label }}</h2>
                            <p class="mt-2 text-sm leading-6 text-slate-600">
                                Give it a name and write the first message. The campaign starts inactive and with manual entry, so nothing sends until you finish setup and turn it on.
                            </p>
                        </div>

                        <form
                            method="POST"
                            action="{{ route('crm.campaigns.store') }}"
                            enctype="multipart/form-data"
                            class="space-y-5"
                            x-data="{ channel: @js(old('channel', 'email')) }"
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
                                            <span class="mt-0.5 block text-xs text-slate-500">Supports subject, body, Media, and dynamic fields.</span>
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
                                            <span class="mt-0.5 block text-xs text-slate-500">Starts with a text message and remains marketing-gated.</span>
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
                                <div class="font-extrabold">Safe starting state</div>
                                <p class="mt-1 leading-6">
                                    Engage creates the campaign as Off, uses manual entry, and creates one immediate first-message step. After creation, use the existing Start and Schedule editors to change audience rules or timing before activation.
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
                        <x-ui.card class="space-y-4">
                            <div>
                                <h2 class="text-base font-extrabold text-slate-950">Available dynamic fields</h2>
                                <p class="mt-1 text-sm text-slate-600">
                                    These are the real Campaign dispatch fields available to the first message and later Campaign messages.
                                </p>
                            </div>

                            <div class="space-y-4">
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
                        </x-ui.card>
                    @endif
                </x-campaigns.builder-shell>
            @endif
        </div>
    </div>
</x-layouts.crm>