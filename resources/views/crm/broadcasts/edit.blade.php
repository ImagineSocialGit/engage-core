<x-layouts.crm
    :title="$title"
    :heading="$heading"
    :subheading="$broadcast->isPermissionInvitation() ? 'Update draft opt-in invitation' : 'Update draft broadcast'"
>
    @php
        $recipientFilter = $broadcast->recipient_filter ?? ['type' => 'all'];
        $recipientFilterType = $broadcast->isPermissionInvitation()
            ? 'imported'
            : old('recipient_filter_type', $recipientFilter['type'] ?? 'all');
        $recipientTag = old('recipient_tag', $recipientFilter['tags'][0] ?? '');
    @endphp

    <div class="space-y-6">
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

        <div class="flex flex-wrap items-center justify-between gap-4">
            <a
                href="{{ route('crm.broadcasts.show', $broadcast) }}"
                class="text-sm font-semibold text-slate-600 underline hover:text-slate-900"
            >
                Back to Broadcast
            </a>
        </div>

        <x-ui.card class="max-w-3xl">
            <div>
                <h2 class="text-lg font-semibold tracking-tight">
                    {{ $broadcast->isPermissionInvitation() ? 'Edit Opt-In Invitation Draft' : 'Edit Broadcast Draft' }}
                </h2>

                <p class="mt-1 text-sm text-slate-500">
                    @if($broadcast->isPermissionInvitation())
                        This email is limited to imported contacts and each imported contact can receive it only once.
                    @else
                        Draft broadcasts can be edited until they are scheduled.
                    @endif
                </p>
            </div>

            <form
                method="POST"
                action="{{ route('crm.broadcasts.update', $broadcast) }}"
                class="mt-5 space-y-4"
                x-data="{ recipientFilterType: @js($recipientFilterType) }"
            >
                @csrf
                @method('PATCH')

                <div>
                    <x-ui.form.label for="name">
                        Internal Name
                    </x-ui.form.label>

                    <x-ui.form.input
                        id="name"
                        name="name"
                        value="{{ old('name', $broadcast->name) }}"
                        required
                    />

                    <x-ui.form.error name="name" />
                </div>

                <div>
                    <x-ui.form.label for="subject">
                        Email Subject
                    </x-ui.form.label>

                    <x-ui.form.input
                        id="subject"
                        name="subject"
                        value="{{ old('subject', $broadcast->payload['subject'] ?? '') }}"
                        required
                    />

                    <x-ui.form.error name="subject" />
                </div>

                <div>
                    <x-ui.form.label for="body">
                        Email Body
                    </x-ui.form.label>

                    <x-ui.form.textarea
                        id="body"
                        name="body"
                        rows="10"
                        required
                    >{{ old('body', $broadcast->payload['body'] ?? '') }}</x-ui.form.textarea>

                    <x-ui.form.error name="body" />
                </div>

                @if($broadcast->isPermissionInvitation())
                    <input type="hidden" name="recipient_filter_type" value="imported">

                    <div class="rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
                        Recipients are locked to imported contacts. This keeps the opt-in invitation separate from normal marketing broadcasts.
                    </div>
                @else
                    <div>
                        <x-ui.form.label for="recipient_filter_type">
                            Recipients
                        </x-ui.form.label>

                        <x-ui.form.select
                            id="recipient_filter_type"
                            name="recipient_filter_type"
                            x-model="recipientFilterType"
                        >
                            <option value="all">All contacts</option>
                            <option value="tag">Contacts with tag</option>
                            <option value="contact_ids">Selected contacts</option>
                        </x-ui.form.select>

                        <x-ui.form.error name="recipient_filter_type" />
                    </div>

                    <div x-show="recipientFilterType === 'tag'">
                        <x-ui.form.label for="recipient_tag">
                            Contact Tag
                        </x-ui.form.label>

                        <x-ui.form.input
                            id="recipient_tag"
                            name="recipient_tag"
                            value="{{ $recipientTag }}"
                            placeholder="homebuyer"
                        />

                        <x-ui.form.error name="recipient_tag" />
                    </div>

                    <div x-show="recipientFilterType === 'contact_ids'">
                        <x-ui.form.label>
                            Selected Contacts
                        </x-ui.form.label>

                        <div class="mt-2">
                            <x-crm.contact-picker
                                :selected-contacts="$selectedRecipientContacts"
                                input-name="contact_ids[]"
                            />
                        </div>
                    </div>
                @endif

                <div>
                    <x-ui.form.label for="send_at">
                        Send Time
                    </x-ui.form.label>

                    <x-ui.form.input
                        id="send_at"
                        name="send_at"
                        type="datetime-local"
                        value="{{ old('send_at', $broadcast->send_at?->format('Y-m-d\TH:i')) }}"
                    />

                    <p class="mt-2 text-xs text-slate-500">
                        Leave blank to send after the 5-minute safety buffer when scheduled.
                    </p>

                    <x-ui.form.error name="send_at" />
                </div>

                <div class="flex flex-wrap gap-3">
                    <x-ui.button type="submit">
                        Save Changes
                    </x-ui.button>

                    <x-ui.button
                        href="{{ route('crm.broadcasts.show', $broadcast) }}"
                        variant="secondary"
                    >
                        Cancel
                    </x-ui.button>
                </div>
            </form>
        </x-ui.card>
    </div>
</x-layouts.crm>