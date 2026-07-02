<x-layouts.crm
    :title="$title"
    :heading="$heading"
    subheading="Regular broadcasts and imported-contact opt-in invitations are separate send types."
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

        <div class="grid gap-6 xl:grid-cols-2">
            <x-ui.card class="space-y-5">
                <div>
                    <div class="inline-flex rounded-full bg-slate-100 px-2 py-1 text-xs font-semibold text-slate-700">
                        Regular Broadcast
                    </div>

                    <h2 class="mt-3 text-lg font-semibold tracking-tight">
                        Send a Broadcast
                    </h2>

                    <p class="mt-1 text-sm text-slate-500">
                        One-time email to selected recipients. Normal Messaging consent, suppression, and revocation gates apply.
                    </p>
                </div>

                <form
                    method="POST"
                    action="{{ route('crm.broadcasts.store') }}"
                    class="space-y-4"
                    x-data="{ recipientFilterType: @js(old('recipient_filter_type', 'all')) }"
                >
                    @csrf

                    <input type="hidden" name="broadcast_type" value="{{ \App\Modules\Broadcasts\Models\Broadcast::BROADCAST_TYPE_REGULAR }}">

                    @php
                        $excludeBroadcastIds = collect(old('exclude_broadcast_ids', []))
                            ->map(fn ($id) => (int) $id)
                            ->all();

                        $excludeBroadcastStatuses = old('exclude_broadcast_statuses', [
                            \App\Modules\Broadcasts\Models\BroadcastRecipient::STATUS_SCHEDULED,
                            \App\Modules\Broadcasts\Models\BroadcastRecipient::STATUS_SENT,
                        ]);
                    @endphp

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

                    <div>
                        <x-ui.form.label for="subject">
                            Email Subject
                        </x-ui.form.label>

                        <x-ui.form.input
                            id="subject"
                            name="subject"
                            value="{{ old('subject') }}"
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
                            rows="8"
                            required
                        >{{ old('body') }}</x-ui.form.textarea>

                        <x-ui.form.error name="body" />
                    </div>

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
                            value="{{ old('recipient_tag') }}"
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
                            value="{{ old('send_at') }}"
                        />

                        <p class="mt-2 text-xs text-slate-500">
                            Leave blank to send after the 5-minute safety buffer.
                        </p>

                        <x-ui.form.error name="send_at" />
                    </div>

                    <div class="flex flex-wrap gap-3">
                        <x-ui.button
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
                        >
                            Schedule Broadcast
                        </x-ui.button>
                    </div>
                </form>
            </x-ui.card>

            <x-ui.card class="space-y-5 border-amber-200 bg-amber-50/40">
                <div>
                    <div class="inline-flex rounded-full bg-amber-100 px-2 py-1 text-xs font-semibold text-amber-800">
                        Imported Contacts Only
                    </div>

                    <h2 class="mt-3 text-lg font-semibold tracking-tight">
                        Send Opt-In Invitation
                    </h2>

                    <p class="mt-1 text-sm text-slate-600">
                        Email-only one-time invitation asking imported contacts to confirm future email or SMS preferences.
                    </p>
                </div>

                <div class="rounded-xl border border-amber-200 bg-white p-3 text-sm text-amber-900">
                    This is not a normal marketing broadcast. Messaging owns the one-time invitation enforcement, token behavior, public preference page, and consent recording.
                </div>

                <form
                    method="POST"
                    action="{{ route('crm.broadcasts.store') }}"
                    class="space-y-4"
                >
                    @csrf

                    <input type="hidden" name="broadcast_type" value="{{ \App\Modules\Broadcasts\Models\Broadcast::BROADCAST_TYPE_PERMISSION_INVITATION }}">
                    <input type="hidden" name="recipient_filter_type" value="imported">

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

                    <div>
                        <x-ui.form.label for="permission_invitation_subject">
                            Email Subject
                        </x-ui.form.label>

                        <x-ui.form.input
                            id="permission_invitation_subject"
                            name="subject"
                            value="{{ old('subject', 'Please confirm how you want to hear from us') }}"
                            required
                        />

                        <x-ui.form.error name="subject" />
                    </div>

                    <div>
                        <x-ui.form.label for="permission_invitation_body">
                            Email Body
                        </x-ui.form.label>

                        <x-ui.form.textarea
                            id="permission_invitation_body"
                            name="body"
                            rows="8"
                            required
                        >{{ old('body', "Hi,\n\nWe recently moved to a new communication system. Please confirm how you want to hear from us going forward.\n\n{cta}\n\nThe link above lets you choose email, SMS, or both when available.") }}</x-ui.form.textarea>

                        <p class="mt-2 text-xs text-slate-600">
                            Include <span class="font-mono">{cta}</span> on its own line where the button should render. The public preference URL is injected by Messaging at send time.
                        </p>

                        <x-ui.form.error name="body" />
                    </div>

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

                    <div class="flex flex-wrap gap-3">
                        <x-ui.button
                            type="submit"
                            name="intent"
                            value="draft"
                            variant="secondary"
                        >
                            Save Invitation Draft
                        </x-ui.button>

                        <x-ui.button
                            type="submit"
                            name="intent"
                            value="schedule"
                        >
                            Schedule Opt-In Invitation
                        </x-ui.button>
                    </div>
                </form>
            </x-ui.card>
        </div>

        <div class="grid gap-6 xl:grid-cols-2">
            <x-ui.card class="overflow-hidden p-0">
                <div class="border-b border-slate-200 px-6 py-4">
                    <h2 class="text-lg font-semibold tracking-tight">
                        Recent Broadcasts
                    </h2>

                    <p class="mt-1 text-sm text-slate-500">
                        Regular one-time sends. These remain normal Messaging-consent-gated broadcasts.
                    </p>
                </div>

                <div class="overflow-x-auto">
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
                                            {{ $broadcast->payload['subject'] ?? 'No subject' }}
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
                                        {{ $broadcast->send_at?->format('M j, Y g:i A') ?? 'Not scheduled' }}
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
                <div class="border-b border-amber-200 bg-amber-50 px-6 py-4">
                    <h2 class="text-lg font-semibold tracking-tight">
                        Opt-In Invitations
                    </h2>

                    <p class="mt-1 text-sm text-amber-900">
                        Imported-contact invitations. Email-only, one-time, and enforced by Messaging.
                    </p>
                </div>

                <div class="overflow-x-auto">
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
                                            {{ $broadcast->payload['subject'] ?? 'No subject' }}
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
                                        {{ $broadcast->send_at?->format('M j, Y g:i A') ?? 'Not scheduled' }}
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
