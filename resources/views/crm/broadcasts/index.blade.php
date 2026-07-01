<x-layouts.crm
    :title="$title"
    :heading="$heading"
    subheading="One-time email sends and imported-contact opt-in invitations"
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

        <div class="grid gap-6 xl:grid-cols-[1fr_420px]">
            <x-ui.card class="overflow-hidden p-0">
                <div class="border-b border-slate-200 px-6 py-4">
                    <h2 class="text-lg font-semibold tracking-tight">
                        Recent Broadcasts
                    </h2>

                    <p class="mt-1 text-sm text-slate-500">
                        Regular broadcasts are consent-gated. Imported-contact opt-in invitations are one-time only.
                    </p>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                            <tr>
                                <th class="px-6 py-3">Name</th>
                                <th class="px-6 py-3">Type</th>
                                <th class="px-6 py-3">Status</th>
                                <th class="px-6 py-3">Recipients</th>
                                <th class="px-6 py-3">Send Time</th>
                                <th class="px-6 py-3 text-right">Scheduled</th>
                            </tr>
                        </thead>

                        <tbody class="divide-y divide-slate-200">
                            @forelse($broadcasts as $broadcast)
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
                                        @if($broadcast->isPermissionInvitation())
                                            <span class="inline-flex rounded-full bg-blue-50 px-2 py-1 text-xs font-semibold text-blue-700">
                                                Opt-in invitation
                                            </span>
                                        @else
                                            <span class="inline-flex rounded-full bg-slate-100 px-2 py-1 text-xs font-semibold text-slate-700">
                                                Broadcast
                                            </span>
                                        @endif
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

                                    <td class="px-6 py-4 text-right font-medium text-slate-900">
                                        {{ $broadcast->scheduled_count }}
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="px-6 py-8 text-sm text-slate-600">
                                        No broadcasts yet.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </x-ui.card>

            <div class="space-y-6">
                <x-ui.card>
                    <div>
                        <h2 class="text-lg font-semibold tracking-tight">
                            New Broadcast
                        </h2>

                        <p class="mt-1 text-sm text-slate-500">
                            Regular marketing broadcasts require existing consent.
                        </p>
                    </div>

                    <form
                        method="POST"
                        action="{{ route('crm.broadcasts.store') }}"
                        class="mt-5 space-y-4"
                        x-data="{ recipientFilterType: @js(old('recipient_filter_type', 'all')) }"
                    >
                        @csrf

                        <input type="hidden" name="broadcast_type" value="{{ \App\Modules\Broadcasts\Models\Broadcast::BROADCAST_TYPE_REGULAR }}">

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
                                Save Draft
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

                <x-ui.card>
                    <div>
                        <h2 class="text-lg font-semibold tracking-tight">
                            Send Opt-In Invitation
                        </h2>

                        <p class="mt-1 text-sm text-slate-500">
                            Sends one email to imported contacts so they can opt into the new system. Each imported contact can receive this invitation only once.
                        </p>
                    </div>

                    <form
                        method="POST"
                        action="{{ route('crm.broadcasts.store') }}"
                        class="mt-5 space-y-4"
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
                                rows="7"
                                required
                            >{{ old('body', "Hi,\n\nWe recently moved to a new communication system. Please confirm how you want to hear from us going forward.\n\nClick the button below to choose email, SMS, or both.") }}</x-ui.form.textarea>

                            <p class="mt-2 text-xs text-slate-500">
                                The public opt-in link/button flow comes next. For now, this creates the correctly scoped one-time invitation broadcast.
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

                            <p class="mt-2 text-xs text-slate-500">
                                Leave blank to send after the 5-minute safety buffer.
                            </p>

                            <x-ui.form.error name="send_at" />
                        </div>

                        <div class="rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
                            This is not a normal marketing broadcast. It is an email-only, one-time opt-in invitation for imported contacts.
                        </div>

                        <div class="flex flex-wrap gap-3">
                            <x-ui.button
                                type="submit"
                                name="intent"
                                value="draft"
                                variant="secondary"
                            >
                                Save Draft
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
        </div>
    </div>
</x-layouts.crm>