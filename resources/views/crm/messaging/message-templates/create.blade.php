<x-layouts.crm
    :title="$title"
    :heading="$heading"
    subheading="Start with what the message is for. Engage will keep the channel, purpose, scope, delivery context, and reusable-library placement aligned for you."
>
    <div class="space-y-6" data-guided-reusable-message-authoring>
        <div class="flex flex-wrap items-center justify-between gap-3">
            <a
                href="{{ route('crm.messaging.message-templates.index') }}"
                class="inline-flex min-h-10 items-center justify-center rounded-full border border-slate-300 bg-white px-4 text-sm font-extrabold text-slate-700 hover:bg-slate-50"
            >
                Back to Message Templates
            </a>

            @if($selectedOption)
                <span class="rounded-full bg-slate-100 px-3 py-1.5 text-xs font-extrabold text-slate-700">
                    {{ strtoupper($selectedOption->channel) }} · {{ \Illuminate\Support\Str::headline($selectedOption->context->purpose) }}
                </span>
            @endif
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

        @if($options === [])
            <x-ui.card class="space-y-3">
                <h2 class="text-lg font-extrabold text-slate-950">No reusable message uses are available.</h2>
                <p class="text-sm leading-6 text-slate-600">
                    Enable a module that contributes reusable message authoring before creating a standalone template here.
                </p>
            </x-ui.card>
        @else
            <div class="grid gap-6 xl:grid-cols-[minmax(18rem,0.42fr)_minmax(0,1fr)] xl:items-start">
                <x-ui.card class="space-y-4">
                    <div>
                        <p class="text-xs font-extrabold uppercase tracking-[0.16em] text-slate-500">1 · Choose the use</p>
                        <h2 class="mt-1 text-lg font-extrabold text-slate-950">What will this message do?</h2>
                        <p class="mt-1 text-sm leading-6 text-slate-600">
                            These choices come from the modules that actually know how the reusable message will be selected later.
                        </p>
                    </div>

                    <div class="space-y-2">
                        @foreach($options as $option)
                            <a
                                href="{{ route('crm.messaging.message-templates.create', ['use' => $option->key]) }}"
                                class="block rounded-2xl border p-4 transition {{ $selectedOption?->key === $option->key ? 'border-indigo-300 bg-indigo-50' : 'border-slate-200 bg-white hover:bg-slate-50' }}"
                                data-reusable-message-authoring-option="{{ $option->key }}"
                            >
                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <div class="text-sm font-extrabold text-slate-950">{{ $option->label }}</div>
                                        <div class="mt-1 text-xs font-bold uppercase tracking-wide text-slate-500">{{ $option->context->moduleLabel }}</div>
                                    </div>
                                    <span class="rounded-full bg-white px-2.5 py-1 text-[0.7rem] font-extrabold text-slate-700 shadow-sm">{{ strtoupper($option->channel) }}</span>
                                </div>
                                <p class="mt-2 text-sm leading-5 text-slate-600">{{ $option->description }}</p>
                            </a>
                        @endforeach
                    </div>
                </x-ui.card>

                @if($selectedOption)
                    <div class="space-y-6">
                        <x-ui.card class="space-y-5">
                            <div>
                                <p class="text-xs font-extrabold uppercase tracking-[0.16em] text-slate-500">2 · Write the message</p>
                                <h2 class="mt-1 text-xl font-extrabold text-slate-950">{{ $selectedOption->label }}</h2>
                                <p class="mt-2 text-sm leading-6 text-slate-600">{{ $selectedOption->description }}</p>
                            </div>

                            <form method="POST" action="{{ route('crm.messaging.message-templates.store') }}" enctype="multipart/form-data" class="space-y-5">
                                @csrf
                                <input type="hidden" name="authoring_option" value="{{ $selectedOption->key }}">

                                <div>
                                    <label for="template-name" class="mb-1.5 block text-sm font-extrabold text-slate-800">Template name</label>
                                    <input
                                        id="template-name"
                                        name="name"
                                        value="{{ old('name') }}"
                                        maxlength="191"
                                        required
                                        placeholder="{{ $selectedOption->namePlaceholder }}"
                                        class="block w-full rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm text-slate-900 shadow-sm focus:border-slate-500 focus:outline-none focus:ring-0"
                                    >
                                </div>

                                @if($selectedOption->channel === 'sms')
                                    <x-ui.message-editor
                                        :sms="[
                                            'id' => 'template-message',
                                            'name' => 'message',
                                            'value' => old('message'),
                                            'label' => 'SMS message',
                                            'required' => true,
                                            'maxlength' => 1600,
                                            'rows' => 7,
                                        ]"
                                    />
                                @else
                                    <x-ui.message-editor
                                        :subject="[
                                            'id' => 'template-subject',
                                            'name' => 'subject',
                                            'value' => old('subject'),
                                            'label' => 'Email subject',
                                            'required' => true,
                                            'maxlength' => 255,
                                        ]"
                                        :body="[
                                            'id' => 'template-body',
                                            'name' => 'body',
                                            'value' => old('body'),
                                            'label' => 'Email body',
                                            'required' => true,
                                            'maxlength' => 10000,
                                            'rows' => 9,
                                        ]"
                                    />

                                    <x-messaging.message-media-authoring :failed="$errors->any()" />
                                @endif

                                <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4 text-sm text-slate-600">
                                    <div class="font-extrabold text-slate-900">Engage will set the delivery identity</div>
                                    <p class="mt-1 leading-6">
                                        Purpose, scope, dispatch context, queue, catalog placement, and selection eligibility come from the chosen business use rather than editable hidden fields.
                                    </p>
                                </div>

                                <div class="flex flex-wrap items-center gap-3">
                                    <button type="submit" class="inline-flex min-h-11 items-center justify-center rounded-full bg-slate-950 px-6 text-sm font-extrabold text-white hover:bg-slate-800" data-create-reusable-message-template-submit>
                                        Create message template
                                    </button>
                                    <a href="{{ route('crm.messaging.message-templates.index') }}" class="text-sm font-bold text-slate-600 underline">Cancel</a>
                                </div>
                            </form>
                        </x-ui.card>

                        @if($availableFields !== [])
                            <x-ui.card class="space-y-4">
                                <div>
                                    <h2 class="text-base font-extrabold text-slate-950">Available dynamic fields</h2>
                                    <p class="mt-1 text-sm text-slate-600">Use the exact token syntax shown below. The list is derived from this message's real dispatch context.</p>
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
                    </div>
                @endif
            </div>
        @endif
    </div>
</x-layouts.crm>