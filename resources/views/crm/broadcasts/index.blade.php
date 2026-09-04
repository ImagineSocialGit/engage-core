@php
    $clientTimezone = config('client.timezone', config('app.timezone', 'UTC'));
@endphp
<x-layouts.crm
    :title="$title"
    :heading="$heading"
    subheading="Choose who should receive a message, review what they have already received, then compose and send."
>
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

        <div class="space-y-6">
            <x-ui.card class="space-y-5">
                <div>
                    <div class="inline-flex rounded-full bg-slate-100 px-2 py-1 text-xs font-semibold text-slate-700">
                        Regular Broadcast
                    </div>

                    <h2 class="mt-3 text-lg font-semibold tracking-tight">
                        Send a Broadcast
                    </h2>

                    <p class="mt-1 text-sm text-slate-500">
                        One-time message to selected recipients. Normal Messaging consent, suppression, and revocation gates apply.
                    </p>
                </div>

                <form
                    method="POST"
                    action="{{ route('crm.broadcasts.store') }}"
                    enctype="multipart/form-data"
                    class="space-y-4"
                    x-data="{
                        recipientFilterType: @js(old('recipient_filter_type', 'all')),
                        channel: @js(old('channel', $availableBroadcastChannels[0] ?? 'email')),
                        reusableMessages: @js($reusableMessageTemplates),
                        selectedReusableMessageId: '',
                        subject: @js(old('subject', '')),
                        body: @js(old('body', '')),
                        message: @js(old('message', '')),
                        ctaLabel: @js(old('cta.label', '')),
                        ctaUrl: @js(old('cta.url', '')),
                        availableReusableMessages() {
                            return this.reusableMessages.filter((template) => template.channel === this.channel);
                        },
                        applyReusableMessage() {
                            const selected = this.reusableMessages.find(
                                (template) => String(template.id) === String(this.selectedReusableMessageId),
                            );

                            if (! selected || selected.channel !== this.channel) {
                                return;
                            }

                            const payload = selected.payload || {};
                            const announceTemplate = () => window.dispatchEvent(
                                new CustomEvent('broadcast-message-template-applied', {
                                    detail: { tokenFallbacks: payload.token_fallbacks || [] },
                                }),
                            );

                            if (this.channel === 'sms') {
                                this.message = payload.message || '';
                                this.ctaLabel = '';
                                this.ctaUrl = '';
                                queueMicrotask(announceTemplate);
                                return;
                            }

                            this.subject = payload.subject || '';
                            this.body = payload.body || '';
                            this.ctaLabel = payload.cta?.label || '';
                            this.ctaUrl = payload.cta?.url || '';
                            queueMicrotask(announceTemplate);
                        },
                    }"
                >
                    @csrf

                    <input type="hidden" name="broadcast_type" value="{{ \App\Modules\Broadcasts\Models\Broadcast::BROADCAST_TYPE_REGULAR }}">

                    @include('crm.broadcasts.partials.audience-builder', [
                        'audienceCriteria' => $audienceCriteria,
                        'recipientFilterType' => old('recipient_filter_type', 'criteria'),
                        'recipientCriteria' => old('recipient_criteria', []),
                        'recipientTag' => old('recipient_tag'),
                        'selectedRecipientContacts' => $selectedRecipientContacts,
                        'excludableBroadcasts' => $excludableBroadcasts,
                        'excludeBroadcastIds' => old('exclude_broadcast_ids', []),
                        'excludeBroadcastStatuses' => old('exclude_broadcast_statuses', [
                            \App\Modules\Broadcasts\Models\BroadcastRecipient::STATUS_SCHEDULED,
                            \App\Modules\Broadcasts\Models\BroadcastRecipient::STATUS_SENT,
                        ]),
                    ])

                    <div class="border-t border-slate-200 pt-4">
                        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">2. Message</p>
                    </div>

                    @if(count($availableBroadcastChannels) > 1)
                    <div>
                        <x-ui.form.label for="channel">
                            Channel
                        </x-ui.form.label>

                        <x-ui.form.select
                            id="channel"
                            name="channel"
                            x-model="channel"
                            x-on:change="selectedReusableMessageId = ''; window.dispatchEvent(new CustomEvent('broadcast-message-template-applied', { detail: { tokenFallbacks: [] } }))"
                        >
                            @foreach($availableBroadcastChannels as $availableBroadcastChannel)
                                <option value="{{ $availableBroadcastChannel }}">
                                    {{ strtoupper($availableBroadcastChannel) }}
                                </option>
                            @endforeach
                        </x-ui.form.select>

                        <x-ui.form.error name="channel" />
                    </div>
                @else
                    <input type="hidden" name="channel" value="{{ $availableBroadcastChannels[0] ?? 'email' }}">
                @endif

                    @include('crm.broadcasts.partials.reusable-message-picker', [
                        'reusableMessageTemplates' => $reusableMessageTemplates,
                    ])

                    <div>
                        <x-ui.form.label for="name">
                            Internal Name
                        </x-ui.form.label>

                        <x-ui.form.input
                            id="name"
                            name="name"
                            value="{{ old('name') }}"
                            required
                        />

                        <x-ui.form.error name="name" />
                    </div>

                    <x-ui.message-editor
                        :subject="[
                            'label' => 'Email Subject',
                            'id' => 'subject',
                            'name' => 'subject',
                            'value' => old('subject'),
                            'model' => 'subject',
                            'required_bind' => 'channel === \'email\'',
                            'visible_bind' => 'channel === \'email\'',
                            'error' => $errors->first('subject'),
                        ]"
                        :body="[
                            'label' => 'Email Body',
                            'id' => 'body',
                            'name' => 'body',
                            'rows' => 8,
                            'value' => old('body'),
                            'model' => 'body',
                            'required_bind' => 'channel === \'email\'',
                            'visible_bind' => 'channel === \'email\'',
                            'error' => $errors->first('body'),
                        ]"
                        :sms="[
                            'label' => 'SMS Message',
                            'id' => 'message',
                            'name' => 'message',
                            'rows' => 5,
                            'value' => old('message'),
                            'model' => 'message',
                            'required_bind' => 'channel === \'sms\'',
                            'visible_bind' => 'channel === \'sms\'',
                            'help' => 'Keep SMS copy short (ideally <160 characters). Normal Messaging SMS consent, suppression, revocation, and send guards still apply.',
                            'error' => $errors->first('message'),
                        ]"
                    />

                    <x-messaging.message-media-authoring
                        visible-bind="channel === 'email'"
                        :failed="$errors->any() && old('broadcast_type', \App\Modules\Broadcasts\Models\Broadcast::BROADCAST_TYPE_REGULAR) === \App\Modules\Broadcasts\Models\Broadcast::BROADCAST_TYPE_REGULAR"
                    />

                    @include('crm.broadcasts.partials.cta-editor')

                    @include('crm.broadcasts.partials.message-personalization', [
                        'broadcastMessageFields' => $broadcastMessageFields,
                        'initialTokenFallbacks' => old('token_fallbacks', []),
                    ])

                    <div class="border-t border-slate-200 pt-4">
                        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">3. Review & send</p>
                    </div>

                    <div>
                        <x-ui.form.label for="send_at">
                            Send Time
                        </x-ui.form.label>

                        <x-ui.form.input
                            id="send_at"
                            name="send_at"
                            type="datetime-local"
                            value="{{ old('send_at') }}"
                        />

                        <p class="mt-2 text-xs text-slate-500">
                            Leave blank to send after the 5-minute safety buffer.
                        </p>

                        <x-ui.form.error name="send_at" />
                    </div>

                    <div class="grid gap-3 sm:flex sm:flex-wrap">
                        <x-ui.button
                            class="w-full justify-center sm:w-auto"
                            type="submit"
                            name="intent"
                            value="draft"
                            variant="secondary"
                        >
                            Save Broadcast Draft
                        </x-ui.button>

                        <x-ui.button
                            type="submit"
                            name="intent"
                            value="schedule"
                            class="w-full justify-center sm:w-auto"
                        >
                            Schedule Broadcast
                        </x-ui.button>
                    </div>
                </form>
            </x-ui.card>

            @if(($permissionInvitationPreview['eligible_contacts_count'] ?? 0) > 0)
            <details class="rounded-2xl border border-amber-200 bg-amber-50/40 p-5">
                <summary class="cursor-pointer text-sm font-semibold text-amber-950">
                    Some imported contacts can be asked for permission
                </summary>
                <p class="mt-2 text-sm text-amber-900/80">
                    Use this only for imported contacts who do not already have consent on record and have not already received the one-time invitation.
                </p>
                <div class="mt-5">

                <div>
                    <div class="inline-flex rounded-full bg-amber-100 px-2 py-1 text-xs font-semibold text-amber-800">
                        Imported Contacts
                    </div>

                    <h2 class="mt-3 text-lg font-semibold tracking-tight">
                        Send Opt-In Invitation
                    </h2>

                    <p class="mt-1 text-sm text-slate-600">
                        Email-only one-time invitation asking imported contacts, or a selected import batch, to confirm future email or SMS preferences.
                    </p>
                </div>

                <div class="rounded-xl border border-amber-200 bg-white p-3 text-sm text-amber-900">
                    This is not a normal marketing broadcast. Messaging owns the one-time invitation enforcement, token behavior, public preference page, and consent recording.
                </div>

                @if($permissionInvitationPreview)
                    <div class="rounded-xl border border-amber-200 bg-white p-4">
                        <h3 class="text-sm font-semibold text-slate-900">
                            Invitation Eligibility Preview
                        </h3>

                        <dl class="mt-3 grid gap-3 sm:grid-cols-2">
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
                                    Already consented
                                </dt>
                                <dd class="mt-1 text-2xl font-semibold text-slate-900">
                                    {{ $permissionInvitationPreview['already_consented_count'] }}
                                </dd>
                            </div>

                            <div class="rounded-lg bg-slate-50 p-3">
                                <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">
                                    Already invited
                                </dt>
                                <dd class="mt-1 text-2xl font-semibold text-slate-900">
                                    {{ $permissionInvitationPreview['already_invited_count'] }}
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

                        @if(($permissionInvitationPreview['eligible_contacts_count'] ?? 0) < 1)
                            <p class="mt-3 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs font-semibold text-amber-900">
                                No imported contacts are currently eligible. You can save a draft, but scheduling will be blocked until at least one contact is eligible.
                            </p>
                        @endif
                    </div>
                @endif

                <form
                    method="POST"
                    action="{{ route('crm.broadcasts.store') }}"
                    enctype="multipart/form-data"
                    class="space-y-4"
                >
                    @csrf

                    <input type="hidden" name="broadcast_type" value="{{ \App\Modules\Broadcasts\Models\Broadcast::BROADCAST_TYPE_PERMISSION_INVITATION }}">
                    @php
                        $permissionInvitationRecipientFilterType = old('recipient_filter_type', 'imported');
                    @endphp

                    <div>
                        <x-ui.form.label for="permission_invitation_recipient_filter_type">
                            Imported Contact Target
                        </x-ui.form.label>

                        <x-ui.form.select
                            id="permission_invitation_recipient_filter_type"
                            name="recipient_filter_type"
                        >
                            <option value="imported" @selected($permissionInvitationRecipientFilterType === 'imported')>
                                All imported contacts
                            </option>
                            <option value="import_batch" @selected($permissionInvitationRecipientFilterType === 'import_batch')>
                                Selected import batches
                            </option>
                        </x-ui.form.select>

                        <x-ui.form.error name="recipient_filter_type" />
                    </div>

                    <div>
                        <x-ui.form.label for="permission_invitation_import_batch_ids">
                            Import Batches
                        </x-ui.form.label>

                        <select
                            id="permission_invitation_import_batch_ids"
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
                            Leave this unselected when targeting all imported contacts. On desktop, hold Ctrl/Cmd to choose multiple batches.
                        </p>

                        <x-ui.form.error name="import_batch_ids" />
                        <x-ui.form.error name="import_batch_ids.*" />
                    </div>

                    <div>
                        <x-ui.form.label for="permission_invitation_name">
                            Internal Name
                        </x-ui.form.label>

                        <x-ui.form.input
                            id="permission_invitation_name"
                            name="name"
                            value="{{ old('name', 'Imported contact opt-in invitation') }}"
                            required
                        />

                        <x-ui.form.error name="name" />
                    </div>

                    <x-ui.message-editor
                        :subject="[
                            'label' => 'Email Subject',
                            'id' => 'permission_invitation_subject',
                            'name' => 'subject',
                            'value' => old('subject', 'Please confirm how you want to hear from us'),
                            'required' => true,
                            'error' => $errors->first('subject'),
                        ]"
                        :body="[
                            'label' => 'Email Body',
                            'id' => 'permission_invitation_body',
                            'name' => 'body',
                            'rows' => 8,
                            'value' => old('body', 'Hi,'.PHP_EOL.PHP_EOL.'We recently moved to a new communication system. Please confirm how you want to hear from us going forward.'.PHP_EOL.PHP_EOL.'{cta}'.PHP_EOL.PHP_EOL.'The link above lets you choose email, SMS, or both when available.'),
                            'required' => true,
                            'error' => $errors->first('body'),
                        ]"
                    />

                    <x-messaging.message-media-authoring
                        :failed="$errors->any() && old('broadcast_type') === \App\Modules\Broadcasts\Models\Broadcast::BROADCAST_TYPE_PERMISSION_INVITATION"
                    />

                    <p class="text-xs text-slate-600">
                        Include <span class="font-mono">{cta}</span> on its own line where the button should render. The public preference URL is injected by Messaging at send time.
                    </p>

                    <div>
                        <x-ui.form.label for="permission_invitation_send_at">
                            Send Time
                        </x-ui.form.label>

                        <x-ui.form.input
                            id="permission_invitation_send_at"
                            name="send_at"
                            type="datetime-local"
                            value="{{ old('send_at') }}"
                        />

                        <p class="mt-2 text-xs text-slate-600">
                            Leave blank to send after the 5-minute safety buffer.
                        </p>

                        <x-ui.form.error name="send_at" />
                    </div>

                    <div class="grid gap-3 sm:flex sm:flex-wrap">
                        <x-ui.button
                            type="submit"
                            name="intent"
                            value="draft"
                            variant="secondary"
                            class="w-full justify-center sm:w-auto"
                        >
                            Save Invitation Draft
                        </x-ui.button>

                        <x-ui.button
                            type="submit"
                            name="intent"
                            value="schedule"
                            :disabled="($permissionInvitationPreview['eligible_contacts_count'] ?? 0) < 1"
                            class="w-full justify-center sm:w-auto"
                        >
                            Schedule Opt-In Invitation
                        </x-ui.button>
                    </div>
                </form>
                </div>
            </details>
            @endif
        </div>

        <div class="grid gap-6 xl:grid-cols-2">
            <x-ui.card class="overflow-hidden p-0">
                <div class="border-b border-slate-200 px-4 py-4 sm:px-6">
                    <h2 class="text-lg font-semibold tracking-tight">
                        Recent Broadcasts
                    </h2>

                    <p class="mt-1 text-sm text-slate-500">
                        Regular one-time sends. These remain normal Messaging-consent-gated broadcasts.
                    </p>
                </div>

                <div class="divide-y divide-slate-200 md:hidden">
                    @forelse($regularBroadcasts as $broadcast)
                        <a
                            href="{{ route('crm.broadcasts.show', $broadcast) }}"
                            class="block p-4 transition hover:bg-slate-50"
                        >
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <div class="break-words font-semibold text-slate-950">
                                        {{ $broadcast->name }}
                                    </div>
                                    <div class="mt-1 break-words text-xs text-slate-500">
                                        @if($broadcast->channel === 'sms')
                                            {{ str($broadcast->messagePayload()['message'] ?? 'No message')->limit(80) }}
                                        @else
                                            {{ $broadcast->messagePayload()['subject'] ?? 'No subject' }}
                                        @endif
                                    </div>
                                </div>

                                <span class="shrink-0 rounded-full bg-slate-100 px-2 py-1 text-xs font-semibold text-slate-700">
                                    {{ str_replace('_', ' ', $broadcast->status) }}
                                </span>
                            </div>

                            <dl class="mt-3 grid grid-cols-2 gap-3 text-xs">
                                <div>
                                    <dt class="font-semibold uppercase tracking-wide text-slate-400">Recipients</dt>
                                    <dd class="mt-1 text-sm font-semibold text-slate-700">{{ $broadcast->recipient_count }}</dd>
                                </div>
                                <div>
                                    <dt class="font-semibold uppercase tracking-wide text-slate-400">Send time</dt>
                                    <dd class="mt-1 text-sm text-slate-700">{{ $broadcast->send_at?->setTimezone($clientTimezone)->format('M j, Y g:i A') ?? 'Not scheduled' }}</dd>
                                </div>
                            </dl>
                        </a>
                    @empty
                        <div class="p-4 text-sm text-slate-600">
                            No regular broadcasts yet.
                        </div>
                    @endforelse
                </div>

                <div class="hidden overflow-x-auto md:block">
                    <table class="min-w-full text-sm">
                        <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                            <tr>
                                <th class="px-6 py-3">Name</th>
                                <th class="px-6 py-3">Status</th>
                                <th class="px-6 py-3">Recipients</th>
                                <th class="px-6 py-3">Send Time</th>
                            </tr>
                        </thead>

                        <tbody class="divide-y divide-slate-200">
                            @forelse($regularBroadcasts as $broadcast)
                                <tr class="hover:bg-slate-50">
                                    <td class="px-6 py-4">
                                        <a
                                            href="{{ route('crm.broadcasts.show', $broadcast) }}"
                                            class="font-medium text-slate-900 underline decoration-slate-300 underline-offset-4 hover:decoration-slate-900"
                                        >
                                            {{ $broadcast->name }}
                                        </a>

                                        <div class="mt-1 text-xs text-slate-500">
                                            @if($broadcast->channel === 'sms')
                                                {{ str($broadcast->messagePayload()['message'] ?? 'No message')->limit(80) }}
                                            @else
                                                {{ $broadcast->messagePayload()['subject'] ?? 'No subject' }}
                                            @endif
                                        </div>
                                    </td>

                                    <td class="px-6 py-4">
                                        <span class="inline-flex rounded-full bg-slate-100 px-2 py-1 text-xs font-semibold text-slate-700">
                                            {{ str_replace('_', ' ', $broadcast->status) }}
                                        </span>
                                    </td>

                                    <td class="px-6 py-4 text-slate-600">
                                        {{ $broadcast->recipient_count }}
                                    </td>

                                    <td class="px-6 py-4 text-slate-600">
                                        {{ $broadcast->send_at?->setTimezone($clientTimezone)->format('M j, Y g:i A') ?? 'Not scheduled' }}
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="px-6 py-8 text-sm text-slate-600">
                                        No regular broadcasts yet.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </x-ui.card>

            <x-ui.card class="overflow-hidden p-0">
                <div class="border-b border-amber-200 bg-amber-50 px-4 py-4 sm:px-6">
                    <h2 class="text-lg font-semibold tracking-tight">
                        Opt-In Invitations
                    </h2>

                    <p class="mt-1 text-sm text-amber-900">
                        Imported-contact invitations. Email-only, one-time, and enforced by Messaging.
                    </p>
                </div>

                <div class="divide-y divide-amber-100 md:hidden">
                    @forelse($permissionInvitationBroadcasts as $broadcast)
                        <a
                            href="{{ route('crm.broadcasts.show', $broadcast) }}"
                            class="block p-4 transition hover:bg-amber-50/50"
                        >
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <div class="break-words font-semibold text-slate-950">
                                        {{ $broadcast->name }}
                                    </div>
                                    <div class="mt-1 break-words text-xs text-slate-500">
                                        {{ $broadcast->messagePayload()['subject'] ?? 'No subject' }}
                                    </div>
                                </div>

                                <span class="shrink-0 rounded-full bg-amber-100 px-2 py-1 text-xs font-semibold text-amber-800">
                                    {{ str_replace('_', ' ', $broadcast->status) }}
                                </span>
                            </div>

                            <dl class="mt-3 grid grid-cols-2 gap-3 text-xs">
                                <div>
                                    <dt class="font-semibold uppercase tracking-wide text-slate-400">Recipients</dt>
                                    <dd class="mt-1 text-sm font-semibold text-slate-700">{{ $broadcast->recipient_count }}</dd>
                                </div>
                                <div>
                                    <dt class="font-semibold uppercase tracking-wide text-slate-400">Send time</dt>
                                    <dd class="mt-1 text-sm text-slate-700">{{ $broadcast->send_at?->setTimezone($clientTimezone)->format('M j, Y g:i A') ?? 'Not scheduled' }}</dd>
                                </div>
                            </dl>
                        </a>
                    @empty
                        <div class="p-4 text-sm text-slate-600">
                            No opt-in invitations yet.
                        </div>
                    @endforelse
                </div>

                <div class="hidden overflow-x-auto md:block">
                    <table class="min-w-full text-sm">
                        <thead class="bg-amber-50/60 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                            <tr>
                                <th class="px-6 py-3">Name</th>
                                <th class="px-6 py-3">Status</th>
                                <th class="px-6 py-3">Recipients</th>
                                <th class="px-6 py-3">Send Time</th>
                            </tr>
                        </thead>

                        <tbody class="divide-y divide-slate-200">
                            @forelse($permissionInvitationBroadcasts as $broadcast)
                                <tr class="hover:bg-amber-50/50">
                                    <td class="px-6 py-4">
                                        <a
                                            href="{{ route('crm.broadcasts.show', $broadcast) }}"
                                            class="font-medium text-slate-900 underline decoration-slate-300 underline-offset-4 hover:decoration-slate-900"
                                        >
                                            {{ $broadcast->name }}
                                        </a>

                                        <div class="mt-1 text-xs text-slate-500">
                                            {{ $broadcast->messagePayload()['subject'] ?? 'No subject' }}
                                        </div>
                                    </td>

                                    <td class="px-6 py-4">
                                        <span class="inline-flex rounded-full bg-amber-100 px-2 py-1 text-xs font-semibold text-amber-800">
                                            {{ str_replace('_', ' ', $broadcast->status) }}
                                        </span>
                                    </td>

                                    <td class="px-6 py-4 text-slate-600">
                                        {{ $broadcast->recipient_count }}
                                    </td>

                                    <td class="px-6 py-4 text-slate-600">
                                        {{ $broadcast->send_at?->setTimezone($clientTimezone)->format('M j, Y g:i A') ?? 'Not scheduled' }}
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="px-6 py-8 text-sm text-slate-600">
                                        No opt-in invitations yet.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </x-ui.card>
        </div>
    </div>
</x-layouts.crm>