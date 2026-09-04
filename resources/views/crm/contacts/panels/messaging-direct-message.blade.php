<section
    x-data="{
        open: @js($errors->has('direct_message.*')),
        channels: @js($directMessageComposer['channels'] ?? []),
        purposesByChannel: @js($directMessageComposer['purposes_by_channel'] ?? []),
        templates: @js($directMessageComposer['templates'] ?? []),
        channel: @js(old('direct_message.channel', $directMessageComposer['default_channel'] ?? 'email')),
        purpose: @js(old('direct_message.purpose', $directMessageComposer['default_purpose'] ?? 'transactional')),
        templateId: @js((string) old('direct_message.template_preset_id', '')),
        subject: @js(old('direct_message.subject', '')),
        body: @js(old('direct_message.body', '')),
        message: @js(old('direct_message.message', '')),
        mediaAssetUuid: @js(old('direct_message.media_asset_uuid', '')),
        mediaPosterAssetUuid: @js(old('direct_message.media_poster_asset_uuid', '')),
        mediaTitle: @js(old('direct_message.media_title', '')),
        purposeOptions() {
            return this.purposesByChannel[this.channel] || [];
        },
        channelChanged() {
            const options = this.purposeOptions();
            if (! options.some((option) => option.value === this.purpose)) {
                this.purpose = options[0]?.value || '';
            }
            this.templateId = '';
        },
        purposeChanged() {
            this.templateId = '';
        },
        applyTemplate() {
            const template = this.templates.find((item) => String(item.id) === String(this.templateId));
            if (! template) return;
            this.channel = template.channel;
            this.purpose = template.purpose;
            this.subject = template.subject || '';
            this.body = template.body || '';
            this.message = template.message || '';
            this.mediaAssetUuid = template.media_asset_uuid || '';
            this.mediaPosterAssetUuid = template.media_poster_asset_uuid || '';
            this.mediaTitle = template.media_title || '';
        },
    }"
    class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6"
    data-contact-direct-message-panel
>
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <p class="text-xs font-black uppercase tracking-[0.18em] text-slate-500">Messaging</p>
            <h2 class="mt-1 text-lg font-black text-slate-950">Send a message</h2>
            <p class="mt-1 text-sm leading-6 text-slate-600">
                Send a one-off email or text directly to this contact. It stays in normal message history and does not create a reusable template.
            </p>
        </div>

        @if(($directMessageComposer['available'] ?? false) === true)
            <button
                type="button"
                x-on:click="open = true"
                class="inline-flex shrink-0 items-center justify-center rounded-xl bg-slate-950 px-4 py-2.5 text-sm font-extrabold text-white shadow-sm hover:bg-slate-800"
                data-contact-direct-message-open
            >
                Send message
            </button>
        @else
            <span class="inline-flex shrink-0 items-center rounded-xl bg-slate-100 px-3 py-2 text-xs font-extrabold text-slate-500">
                No sendable channel
            </span>
        @endif
    </div>

    @if(($directMessageComposer['available'] ?? false) !== true)
        <p class="mt-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm leading-6 text-amber-900">
            This contact does not currently have a provider-ready email/SMS channel with the required messaging permission and no active suppression.
        </p>
    @endif

    <div
        x-show="open"
        x-cloak
        x-on:keydown.escape.window="open = false"
        class="fixed inset-0 z-50 overflow-y-auto"
        role="dialog"
        aria-modal="true"
        aria-label="Send message"
        data-contact-direct-message-modal
    >
        <div class="fixed inset-0 bg-slate-950/50" x-on:click="open = false"></div>

        <div class="relative flex min-h-full items-start justify-center p-4 sm:p-8">
            <div
                x-on:click.stop
                class="relative my-auto w-full max-w-3xl rounded-2xl bg-white shadow-2xl"
            >
                <div class="flex items-start justify-between border-b border-slate-200 px-5 py-4 sm:px-6">
                    <div>
                        <p class="text-xs font-black uppercase tracking-[0.18em] text-slate-500">One-off message</p>
                        <h3 class="mt-1 text-xl font-black text-slate-950">Send to {{ $contact->display_name ?: $contact->name ?: $contact->email ?: $contact->phone }}</h3>
                    </div>
                    <button
                        type="button"
                        x-on:click="open = false"
                        class="rounded-lg px-3 py-1.5 text-sm font-bold text-slate-500 hover:bg-slate-100 hover:text-slate-900"
                        aria-label="Close send message modal"
                    >
                        Close
                    </button>
                </div>

                <form
                    method="POST"
                    action="{{ route('crm.messaging.contacts.messages.store', $contact) }}"
                    enctype="multipart/form-data"
                    class="space-y-5 px-5 py-5 sm:px-6 sm:py-6"
                    data-contact-direct-message-form
                >
                    @csrf
                    <input
                        type="hidden"
                        name="direct_message[request_key]"
                        value="{{ old('direct_message.request_key', $directMessageComposer['request_key'] ?? '') }}"
                    >

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label for="contact-direct-message-channel" class="mb-1.5 block text-sm font-extrabold text-slate-800">Channel</label>
                            <select
                                id="contact-direct-message-channel"
                                name="direct_message[channel]"
                                x-model="channel"
                                x-on:change="channelChanged()"
                                class="block w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-900"
                            >
                                @foreach(($directMessageComposer['channels'] ?? []) as $channelOption)
                                    <option value="{{ $channelOption['value'] }}">{{ $channelOption['label'] }}</option>
                                @endforeach
                            </select>
                            @error('direct_message.channel')
                                <p class="mt-2 text-sm font-semibold text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label for="contact-direct-message-purpose" class="mb-1.5 block text-sm font-extrabold text-slate-800">Message type</label>
                            <select
                                id="contact-direct-message-purpose"
                                name="direct_message[purpose]"
                                x-model="purpose"
                                x-on:change="purposeChanged()"
                                class="block w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-900"
                            >
                                <template x-for="option in purposeOptions()" :key="option.value">
                                    <option x-bind:value="option.value" x-text="option.label"></option>
                                </template>
                            </select>
                            <p class="mt-2 text-xs leading-5 text-slate-500">Personal/service and marketing messages use their normal consent and suppression rules.</p>
                            @error('direct_message.purpose')
                                <p class="mt-2 text-sm font-semibold text-red-600">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>

                    @if(($directMessageComposer['templates'] ?? []) !== [])
                        <div>
                            <label for="contact-direct-message-template" class="mb-1.5 block text-sm font-extrabold text-slate-800">
                                Start from a reusable template <span class="font-semibold text-slate-400">(optional)</span>
                            </label>
                            <select
                                id="contact-direct-message-template"
                                name="direct_message[template_preset_id]"
                                x-model="templateId"
                                x-on:change="applyTemplate()"
                                class="block w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-900"
                            >
                                <option value="">Write from scratch</option>
                                @foreach(($directMessageComposer['templates'] ?? []) as $template)
                                    <option value="{{ $template['id'] }}">{{ $template['label'] }}</option>
                                @endforeach
                            </select>
                            <p class="mt-2 text-xs leading-5 text-slate-500">
                                Template copy is only a starting point. Changes here affect this send only and never modify the reusable template.
                            </p>
                            @error('direct_message.template_preset_id')
                                <p class="mt-2 text-sm font-semibold text-red-600">{{ $message }}</p>
                            @enderror
                        </div>
                    @endif

                    <x-ui.message-editor
                        :subject="[
                            'id' => 'contact-direct-message-subject',
                            'name' => 'direct_message[subject]',
                            'model' => 'subject',
                            'visible_bind' => 'channel === \'email\'',
                            'required_bind' => 'channel === \'email\'',
                            'maxlength' => 255,
                            'label' => 'Email subject',
                            'error' => $errors->first('direct_message.subject'),
                        ]"
                        :body="[
                            'id' => 'contact-direct-message-body',
                            'name' => 'direct_message[body]',
                            'model' => 'body',
                            'visible_bind' => 'channel === \'email\'',
                            'required_bind' => 'channel === \'email\'',
                            'rows' => 9,
                            'maxlength' => 10000,
                            'label' => 'Email message',
                            'help' => 'Contact fields such as {first_name} can use the normal Messaging token resolution path.',
                            'error' => $errors->first('direct_message.body'),
                        ]"
                        :sms="[
                            'id' => 'contact-direct-message-sms',
                            'name' => 'direct_message[message]',
                            'model' => 'message',
                            'visible_bind' => 'channel === \'sms\'',
                            'required_bind' => 'channel === \'sms\'',
                            'rows' => 7,
                            'maxlength' => 1600,
                            'label' => 'SMS message',
                            'error' => $errors->first('direct_message.message'),
                        ]"
                    />

                    <x-ui.message-media-editor
                        :presentation="$directMessageComposer['media'] ?? []"
                        field-prefix="direct_message"
                        visible-bind="channel === 'email'"
                        asset-model="mediaAssetUuid"
                        poster-model="mediaPosterAssetUuid"
                        title-model="mediaTitle"
                        :selected-asset-uuid="old('direct_message.media_asset_uuid', '')"
                        :selected-poster-asset-uuid="old('direct_message.media_poster_asset_uuid', '')"
                        :selected-title="old('direct_message.media_title', '')"
                    />

                    <div class="flex flex-col-reverse gap-3 border-t border-slate-200 pt-5 sm:flex-row sm:items-center sm:justify-end">
                        <button
                            type="button"
                            x-on:click="open = false"
                            class="inline-flex items-center justify-center rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-extrabold text-slate-700 hover:bg-slate-50"
                        >
                            Cancel
                        </button>
                        <button
                            type="submit"
                            class="inline-flex items-center justify-center rounded-xl bg-slate-950 px-5 py-2.5 text-sm font-extrabold text-white shadow-sm hover:bg-slate-800"
                            data-contact-direct-message-submit
                        >
                            Send message
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</section>