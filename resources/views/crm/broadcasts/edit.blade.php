<x-layouts.crm
    :title="$title"
    :heading="$heading"
    :subheading="$broadcast->isPermissionInvitation() ? 'Update imported-contact opt-in invitation draft' : 'Update regular broadcast draft'"
>
    @php
        $broadcastChannel = old('channel', $broadcast->channel ?? ($availableBroadcastChannels[0] ?? 'email'));
        $recipientFilter = $broadcast->recipient_filter ?? ['type' => 'all'];
        $recipientFilterType = old('recipient_filter_type', $recipientFilter['type'] ?? 'all');
        $recipientTag = old('recipient_tag', $recipientFilter['tags'][0] ?? '');

        $emailFieldVisibility = $broadcast->isPermissionInvitation()
            ? 'true'
            : "channel === 'email'";

        $excludeBroadcastIds = collect(old('exclude_broadcast_ids', data_get($recipientFilter, 'exclude.broadcast_ids', [])))
            ->map(fn ($id) => (int) $id)
            ->all();

        $excludeBroadcastStatuses = old('exclude_broadcast_statuses', data_get($recipientFilter, 'exclude.statuses', [
            \App\Modules\Broadcasts\Models\BroadcastRecipient::STATUS_SCHEDULED,
            \App\Modules\Broadcasts\Models\BroadcastRecipient::STATUS_SENT,
        ]));
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
                Back to {{ $broadcast->typeLabel() }}
            </a>
        </div>

        <x-ui.card class="max-w-3xl {{ $broadcast->isPermissionInvitation() ? 'border-amber-200 bg-amber-50/30' : '' }}">
            <div>
                <div class="{{ $broadcast->isPermissionInvitation() ? 'inline-flex rounded-full bg-amber-100 px-2 py-1 text-xs font-semibold text-amber-800' : 'inline-flex rounded-full bg-slate-100 px-2 py-1 text-xs font-semibold text-slate-700' }}">
                    {{ $broadcast->typeLabel() }}
                </div>

                <h2 class="mt-3 text-lg font-semibold tracking-tight">
                    {{ $broadcast->isPermissionInvitation() ? 'Edit Opt-In Invitation Draft' : 'Edit Broadcast Draft' }}
                </h2>

                <p class="mt-1 text-sm text-slate-500">
                    {{ $broadcast->typeDescription() }}
                </p>
            </div>

            @if($broadcast->isPermissionInvitation())
                <div class="mt-5 rounded-xl border border-amber-200 bg-white p-3 text-sm text-amber-900">
                    Recipients are restricted to imported contacts. You can target all imported contacts or selected import batches.
                </div>

            @if($permissionInvitationPreview)
                <div class="mt-5 rounded-xl border border-amber-200 bg-white p-4">
                    <h3 class="text-sm font-semibold text-slate-900">
                        Invitation Eligibility Preview
                    </h3>

                    <dl class="mt-3 grid gap-3 sm:grid-cols-3">
                        <div class="rounded-lg bg-slate-50 p-3">
                            <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">
                                Imported contacts found
                            </dt>
                            <dd class="mt-1 text-2xl font-semibold text-slate-900">
                                {{ $permissionInvitationPreview['imported_contacts_count'] }}
                            </dd>
                        </div>

                        <div class="rounded-lg bg-slate-50 p-3">
                            <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">
                                Already consented / ineligible
                            </dt>
                            <dd class="mt-1 text-2xl font-semibold text-slate-900">
                                {{ $permissionInvitationPreview['ineligible_contacts_count'] }}
                            </dd>
                        </div>

                        <div class="rounded-lg bg-slate-50 p-3">
                            <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">
                                Eligible for invitation
                            </dt>
                            <dd class="mt-1 text-2xl font-semibold text-slate-900">
                                {{ $permissionInvitationPreview['eligible_contacts_count'] }}
                            </dd>
                        </div>
                    </dl>

                    @if(($permissionInvitationPreview['excluded_by_prior_broadcast_count'] ?? 0) > 0)
                        <p class="mt-3 text-xs text-slate-600">
                            {{ $permissionInvitationPreview['excluded_by_prior_broadcast_count'] }} imported contacts are excluded by prior-Broadcast duplicate-send rules.
                        </p>
                    @endif
                </div>
            @endif
            @endif

            <form
                method="POST"
                action="{{ route('crm.broadcasts.update', $broadcast) }}"
                class="mt-5 space-y-4"
                x-data="{
                    recipientFilterType: @js($recipientFilterType),
                    channel: @js($broadcastChannel),
                }"
            >
                @csrf
                @method('PATCH')

                @if(! $broadcast->isPermissionInvitation())
                    @if(count($availableBroadcastChannels) > 1)
                        <div>
                            <x-ui.form.label for="channel">
                                Channel
                            </x-ui.form.label>

                            <x-ui.form.select
                                id="channel"
                                name="channel"
                                x-model="channel"
                            >
                                @foreach($availableBroadcastChannels as $availableBroadcastChannel)
                                    <option
                                        value="{{ $availableBroadcastChannel }}"
                                        @selected($broadcastChannel === $availableBroadcastChannel)
                                    >
                                        {{ strtoupper($availableBroadcastChannel) }}
                                    </option>
                                @endforeach
                            </x-ui.form.select>

                            <x-ui.form.error name="channel" />
                        </div>
                    @else
                        <input type="hidden" name="channel" value="{{ $availableBroadcastChannels[0] ?? 'email' }}">
                    @endif
                @endif

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

                <div x-show="{{ $emailFieldVisibility }}">
                    <x-ui.form.label for="subject">
                        Email Subject
                    </x-ui.form.label>

                    <x-ui.form.input
                        id="subject"
                        name="subject"
                        value="{{ old('subject', $broadcast->payload['subject'] ?? '') }}"
                        x-bind:required="{{ $emailFieldVisibility }}"
                    />

                    <x-ui.form.error name="subject" />
                </div>

                <div x-show="{{ $emailFieldVisibility }}">
                    <x-ui.form.label for="body">
                        Email Body
                    </x-ui.form.label>

                    <x-ui.form.textarea
                        id="body"
                        name="body"
                        rows="10"
                        x-bind:required="{{ $emailFieldVisibility }}"
                    >{{ old('body', $broadcast->payload['body'] ?? '') }}</x-ui.form.textarea>

                    @if($broadcast->isPermissionInvitation())
                        <p class="mt-2 text-xs text-slate-600">
                            Use <span class="font-mono">{cta}</span> on its own line to render the opt-in button. Messaging injects the public preference URL at send time.
                        </p>
                    @endif

                    <x-ui.form.error name="body" />
                </div>

                @if(! $broadcast->isPermissionInvitation())
                    <div x-show="channel === 'sms'">
                        <x-ui.form.label for="message">
                            SMS Message
                        </x-ui.form.label>

                        <x-ui.form.textarea
                            id="message"
                            name="message"
                            rows="5"
                            x-bind:required="channel === 'sms'"
                        >{{ old('message', $broadcast->payload['message'] ?? '') }}</x-ui.form.textarea>

                        <p class="mt-2 text-xs text-slate-500">
                            Keep SMS copy short. Normal Messaging SMS consent, suppression, revocation, and send guards still apply.
                        </p>

                        <x-ui.form.error name="message" />
                    </div>
                @endif

                @if($broadcast->isPermissionInvitation())
                    <div>
                        <x-ui.form.label for="recipient_filter_type">
                            Imported Contact Target
                        </x-ui.form.label>

                        <x-ui.form.select
                            id="recipient_filter_type"
                            name="recipient_filter_type"
                            x-model="recipientFilterType"
                        >
                            <option value="imported">All imported contacts</option>
                            <option value="import_batch">Selected import batches</option>
                        </x-ui.form.select>

                        <x-ui.form.error name="recipient_filter_type" />
                    </div>

                    <div x-show="recipientFilterType === 'import_batch'">
                        <x-ui.form.label for="import_batch_ids">
                            Import Batches
                        </x-ui.form.label>

                        <select
                            id="import_batch_ids"
                            name="import_batch_ids[]"
                            multiple
                            class="mt-1 block min-h-32 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-slate-500 focus:outline-none focus:ring-1 focus:ring-slate-500"
                        >
                            @foreach($importBatches as $importBatch)
                                <option
                                    value="{{ $importBatch->id }}"
                                    @selected(in_array($importBatch->id, $selectedImportBatchIds, true))
                                >
                                    {{ $importBatch->name ?? 'Import #'.$importBatch->id }}
                                    — {{ $importBatch->imported_at?->format('M j, Y') ?? 'No import date' }}
                                    — {{ $importBatch->successful_count }} contacts
                                </option>
                            @endforeach
                        </select>

                        <p class="mt-2 text-xs text-slate-600">
                            Hold Ctrl/Cmd to select multiple batches.
                        </p>

                        <x-ui.form.error name="import_batch_ids" />
                        <x-ui.form.error name="import_batch_ids.*" />
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

                <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                    <div>
                        <h3 class="text-sm font-semibold text-slate-900">
                            Avoid Duplicate Sends
                        </h3>

                        <p class="mt-1 text-xs text-slate-600">
                            Exclude contacts who were already scheduled or sent one of these previous broadcasts.
                        </p>
                    </div>

                    <div class="mt-4">
                        <x-ui.form.label for="exclude_broadcast_ids">
                            Previous Broadcasts to Exclude
                        </x-ui.form.label>

                        <select
                            id="exclude_broadcast_ids"
                            name="exclude_broadcast_ids[]"
                            multiple
                            class="mt-1 block min-h-32 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-slate-500 focus:outline-none focus:ring-1 focus:ring-slate-500"
                        >
                            @foreach($excludableBroadcasts as $excludableBroadcast)
                                <option
                                    value="{{ $excludableBroadcast->id }}"
                                    @selected(in_array($excludableBroadcast->id, $excludeBroadcastIds, true))
                                >
                                    {{ $excludableBroadcast->name }}
                                    — {{ strtoupper($excludableBroadcast->channel) }}
                                    — {{ str_replace('_', ' ', $excludableBroadcast->status) }}
                                </option>
                            @endforeach
                        </select>

                        <p class="mt-2 text-xs text-slate-500">
                            Hold Ctrl/Cmd to select multiple broadcasts.
                        </p>

                        <x-ui.form.error name="exclude_broadcast_ids" />
                        <x-ui.form.error name="exclude_broadcast_ids.*" />
                    </div>

                    <div class="mt-4 space-y-2">
                        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">
                            Exclude contacts with prior status
                        </p>

                        <label class="flex items-center gap-2 text-sm text-slate-700">
                            <input
                                type="checkbox"
                                name="exclude_broadcast_statuses[]"
                                value="{{ \App\Modules\Broadcasts\Models\BroadcastRecipient::STATUS_SCHEDULED }}"
                                @checked(in_array(\App\Modules\Broadcasts\Models\BroadcastRecipient::STATUS_SCHEDULED, $excludeBroadcastStatuses, true))
                                class="rounded border-slate-300"
                            >
                            Scheduled
                        </label>

                        <label class="flex items-center gap-2 text-sm text-slate-700">
                            <input
                                type="checkbox"
                                name="exclude_broadcast_statuses[]"
                                value="{{ \App\Modules\Broadcasts\Models\BroadcastRecipient::STATUS_SENT }}"
                                @checked(in_array(\App\Modules\Broadcasts\Models\BroadcastRecipient::STATUS_SENT, $excludeBroadcastStatuses, true))
                                class="rounded border-slate-300"
                            >
                            Sent
                        </label>

                        <x-ui.form.error name="exclude_broadcast_statuses" />
                        <x-ui.form.error name="exclude_broadcast_statuses.*" />
                    </div>
                </div>

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
