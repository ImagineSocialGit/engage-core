<x-layouts.crm
    :title="$title"
    :heading="$heading"
    subheading="One-time email sends"
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
                <div class="flex items-center justify-between border-b border-slate-200 px-6 py-4">
                    <div>
                        <h2 class="text-lg font-semibold tracking-tight">
                            Recent Broadcasts
                        </h2>

                        <p class="mt-1 text-sm text-slate-500">
                            Regular broadcasts are consent-gated through Messaging.
                        </p>
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                            <tr>
                                <th class="px-6 py-3">Name</th>
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
                                        <span class="inline-flex rounded-full bg-slate-100 px-2 py-1 text-xs font-semibold text-slate-700">
                                            {{ str_replace('_', ' ', $broadcast->status) }}
                                        </span>
                                    </td>

                                    <td class="px-6 py-4 text-slate-600">
                                        @php($recipientFilter = $broadcast->recipient_filter ?? [])

                                        @if(($recipientFilter['type'] ?? 'all') === 'tag')
                                            Tag: {{ implode(', ', $recipientFilter['tags'] ?? []) }}
                                        @elseif(($recipientFilter['type'] ?? 'all') === 'contact_ids')
                                            {{ count($recipientFilter['contact_ids'] ?? []) }} selected contacts
                                        @else
                                            All contacts
                                        @endif
                                    </td>

                                    <td class="px-6 py-4 text-slate-600">
                                        {{ $broadcast->send_at?->format('M j, Y g:i A') ?? 'Not scheduled' }}
                                    </td>

                                    <td class="px-6 py-4 text-right text-slate-700">
                                        {{ $broadcast->scheduled_count }} / {{ $broadcast->recipient_count }}
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="px-6 py-6 text-sm text-slate-600">
                                        No broadcasts found.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </x-ui.card>

            <x-ui.card>
                <div>
                    <h2 class="text-lg font-semibold tracking-tight">
                        New Broadcast
                    </h2>

                    <p class="mt-1 text-sm text-slate-500">
                        Create an email broadcast for selected recipients.
                    </p>
                </div>

                <form method="POST" action="{{ route('crm.broadcasts.store') }}" class="mt-5 space-y-4">
                    @csrf

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

                        <x-ui.form.select id="recipient_filter_type" name="recipient_filter_type" required>
                            <option value="all" @selected(old('recipient_filter_type', 'all') === 'all')>
                                All contacts
                            </option>

                            <option value="tag" @selected(old('recipient_filter_type') === 'tag')>
                                Contacts with tag
                            </option>

                            <option value="contact_ids" @selected(old('recipient_filter_type') === 'contact_ids')>
                                Selected contacts
                            </option>
                        </x-ui.form.select>

                        <x-ui.form.error name="recipient_filter_type" />
                    </div>

                    <div>
                        <x-ui.form.label for="recipient_tag">
                            Recipient Tag
                        </x-ui.form.label>

                        <x-ui.form.input
                            id="recipient_tag"
                            name="recipient_tag"
                            value="{{ old('recipient_tag') }}"
                            placeholder="homebuyer"
                        />

                        <x-ui.form.error name="recipient_tag" />
                    </div>

                    <div>
                        <x-ui.form.label>
                            Selected Contacts
                        </x-ui.form.label>

                        <div class="mt-2 max-h-56 space-y-2 overflow-y-auto rounded-xl border border-slate-200 p-3">
                            @forelse($contactOptions as $contact)
                                <label class="flex items-start gap-3 rounded-lg px-2 py-1.5 hover:bg-slate-50">
                                    <input
                                        type="checkbox"
                                        name="contact_ids[]"
                                        value="{{ $contact->id }}"
                                        @checked(in_array($contact->id, array_map('intval', old('contact_ids', [])), true))
                                        class="mt-1 rounded border-slate-300 text-slate-900 focus:ring-slate-500"
                                    >

                                    <span>
                                        <span class="block text-sm font-medium text-slate-900">
                                            {{ $contact->name ?: trim($contact->first_name.' '.$contact->last_name) ?: $contact->email }}
                                        </span>

                                        <span class="block text-xs text-slate-500">
                                            {{ $contact->email }}
                                        </span>
                                    </span>
                                </label>
                            @empty
                                <p class="text-sm text-slate-500">
                                    No contacts available.
                                </p>
                            @endforelse
                        </div>

                        <p class="mt-2 text-xs text-slate-500">
                            Used only when Recipients is set to Selected contacts.
                        </p>

                        <x-ui.form.error name="contact_ids" />
                        <x-ui.form.error name="contact_ids.*" />
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
        </div>
    </div>
</x-layouts.crm>