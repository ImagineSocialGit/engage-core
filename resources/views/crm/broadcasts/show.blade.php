<x-layouts.crm
    :title="$title"
    :heading="$heading"
    :subheading="$broadcast->typeDescription()"
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

        <div class="flex flex-col items-stretch gap-4 sm:flex-row sm:items-center sm:justify-between">
            <a
                href="{{ route('crm.broadcasts.index') }}"
                class="text-sm font-semibold text-slate-600 underline hover:text-slate-900"
            >
                Back to Broadcasts
            </a>

            <div class="grid w-full gap-3 sm:flex sm:w-auto sm:flex-wrap sm:items-center">
                @if($broadcast->status === \App\Modules\Broadcasts\Models\Broadcast::STATUS_DRAFT)
                    <x-ui.button
                        href="{{ route('crm.broadcasts.edit', $broadcast) }}"
                        variant="secondary"
                        class="w-full justify-center sm:w-auto"
                    >
                        Edit Draft
                    </x-ui.button>

                    <form method="POST" action="{{ route('crm.broadcasts.schedule', $broadcast) }}" class="grid w-full gap-2 sm:flex sm:w-auto sm:flex-wrap sm:items-center">
                        @csrf
                        @method('PATCH')

                        <x-ui.form.input
                            name="send_at"
                            type="datetime-local"
                            class="sm:w-56"
                        />

                        <x-ui.button type="submit" class="w-full justify-center sm:w-auto">
                            {{ $broadcast->isPermissionInvitation() ? 'Schedule Invitation' : 'Schedule Broadcast' }}
                        </x-ui.button>
                    </form>
                @endif

                @if($broadcast->isRegularBroadcast())
                    <form method="POST" action="{{ route('crm.broadcasts.duplicate', $broadcast) }}" class="w-full sm:w-auto">
                        @csrf

                        <x-ui.button type="submit" variant="secondary" class="w-full justify-center sm:w-auto">
                            Make a new Broadcast from this
                        </x-ui.button>
                    </form>
                @endif

                @if(! in_array($broadcast->status, [
                    \App\Modules\Broadcasts\Models\Broadcast::STATUS_COMPLETED,
                    \App\Modules\Broadcasts\Models\Broadcast::STATUS_CANCELLED,
                ], true))
                    <form method="POST" action="{{ route('crm.broadcasts.cancel', $broadcast) }}" class="w-full sm:w-auto">
                        @csrf
                        @method('PATCH')

                        <x-ui.button type="submit" variant="danger" class="w-full justify-center sm:w-auto">
                            Cancel
                        </x-ui.button>
                    </form>
                @endif
            </div>
        </div>

        @if($broadcast->isPermissionInvitation())
            <x-ui.card class="border-amber-200 bg-amber-50 text-sm text-amber-950">
                <div class="font-semibold">
                    Imported-contact opt-in invitation
                </div>

                <p class="mt-1">
                    This is an email-only, one-time invitation flow. Messaging owns the invitation token, public preference page, consent recording, and repeat-send enforcement.
                </p>

                @if($permissionInvitationPreview)
                    <dl class="mt-4 grid gap-3 md:grid-cols-3">
                        <div class="rounded-lg bg-white/70 p-3">
                            <dt class="text-xs font-semibold uppercase tracking-wide text-amber-800">
                                Imported contacts found
                            </dt>
                            <dd class="mt-1 text-2xl font-semibold text-amber-950">
                                {{ $permissionInvitationPreview['imported_contacts_count'] }}
                            </dd>
                        </div>

                        <div class="rounded-lg bg-white/70 p-3">
                            <dt class="text-xs font-semibold uppercase tracking-wide text-amber-800">
                                Already consented / ineligible
                            </dt>
                            <dd class="mt-1 text-2xl font-semibold text-amber-950">
                                {{ $permissionInvitationPreview['ineligible_contacts_count'] }}
                            </dd>
                        </div>

                        <div class="rounded-lg bg-white/70 p-3">
                            <dt class="text-xs font-semibold uppercase tracking-wide text-amber-800">
                                Eligible for invitation
                            </dt>
                            <dd class="mt-1 text-2xl font-semibold text-amber-950">
                                {{ $permissionInvitationPreview['eligible_contacts_count'] }}
                            </dd>
                        </div>
                    </dl>

                    @if(($permissionInvitationPreview['excluded_by_prior_broadcast_count'] ?? 0) > 0)
                        <p class="mt-3 text-xs">
                            {{ $permissionInvitationPreview['excluded_by_prior_broadcast_count'] }} imported contacts are excluded by prior-Broadcast duplicate-send rules.
                        </p>
                    @endif
                @endif
            </x-ui.card>
        @endif

        <div class="grid gap-6 xl:grid-cols-[1fr_380px]">
            <div class="space-y-6">
                <x-ui.card class="space-y-5">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between sm:gap-4">
                        <div>
                            <h2 class="text-lg font-semibold tracking-tight">
                                Message
                            </h2>

                            <p class="mt-1 text-sm text-slate-500">
                                {{ $broadcast->typeDescription() }}
                            </p>
                        </div>

                        <span class="{{ $broadcast->isPermissionInvitation() ? 'inline-flex rounded-full bg-amber-100 px-2 py-1 text-xs font-semibold text-amber-800' : 'inline-flex rounded-full bg-slate-100 px-2 py-1 text-xs font-semibold text-slate-700' }}">
                            {{ $broadcast->typeLabel() }}
                        </span>
                    </div>

                    @if($broadcast->channel === 'sms')
                        <div>
                            <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">
                                SMS Message
                            </div>

                            <div class="mt-2 whitespace-pre-line break-words rounded-xl border border-slate-200 bg-slate-50 p-4 text-sm text-slate-700">
                                {{ $broadcast->payload['message'] ?? '' }}
                            </div>
                        </div>
                    @else
                        <div>
                            <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">
                                Subject
                            </div>

                            <div class="mt-1 text-sm font-medium text-slate-900">
                                {{ $broadcast->payload['subject'] ?? 'No subject' }}
                            </div>
                        </div>

                        <div>
                            <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">
                                Body
                            </div>

                            <div class="mt-2 whitespace-pre-line break-words rounded-xl border border-slate-200 bg-slate-50 p-4 text-sm text-slate-700">
                                {{ $broadcast->payload['body'] ?? '' }}
                            </div>
                        </div>
                    @endif

                    @if($broadcast->isRegularBroadcast())
                        <div class="border-t border-slate-200 pt-5">
                            <div>
                                <h3 class="text-sm font-semibold text-slate-900">
                                    Save this message for reuse
                                </h3>
                                <p class="mt-1 text-xs leading-5 text-slate-500">
                                    Add this copy to Message Templates so it can be loaded into future Broadcasts. The saved template is independent from this Broadcast.
                                </p>
                            </div>

                            <form method="POST" action="{{ route('crm.broadcasts.save-message-template', $broadcast) }}" class="mt-4 grid gap-3 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-end">
                                @csrf

                                <div>
                                    <x-ui.form.label for="reusable_template_name">
                                        Template Name
                                    </x-ui.form.label>

                                    <x-ui.form.input
                                        id="reusable_template_name"
                                        name="name"
                                        value="{{ old('name', $broadcast->name) }}"
                                        maxlength="191"
                                        required
                                    />
                                </div>

                                <x-ui.button type="submit" class="w-full justify-center sm:w-auto">
                                    Save to Message Templates
                                </x-ui.button>
                            </form>

                            <a
                                href="{{ route('crm.messaging.message-templates.index') }}"
                                class="mt-3 inline-flex text-xs font-semibold text-slate-600 underline decoration-slate-300 underline-offset-4 hover:text-slate-900"
                            >
                                Open Message Templates
                            </a>
                        </div>
                    @endif
                </x-ui.card>

                <x-ui.card class="overflow-hidden p-0">
                    <div class="border-b border-slate-200 px-4 py-4 sm:px-6">
                        <h2 class="text-lg font-semibold tracking-tight">
                            Recipients
                        </h2>

                        <p class="mt-1 text-sm text-slate-500">
                            Showing the first 250 broadcast recipient records.
                        </p>

                        @if($recipients->contains(fn ($recipient) => $recipient->terminal_reason === 'not_scheduled_by_messaging'))
                            <p class="mt-2 text-sm leading-6 text-slate-600">
                                A recipient marked “Not eligible to receive this message” did not pass one of the required checks: a usable email or phone for this channel, active permission for this type of message, or delivery suppression.
                            </p>
                        @endif
                    </div>

                    <div class="divide-y divide-slate-200 md:hidden">
                        @forelse($recipients as $recipient)
                            <div class="p-4">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        @if($recipient->contact)
                                            <a
                                                href="{{ route('crm.contacts.show', $recipient->contact) }}"
                                                class="break-words font-semibold text-slate-950 underline decoration-slate-300 underline-offset-4 hover:decoration-slate-900"
                                            >
                                                {{ $recipient->contact->name ?: trim($recipient->contact->first_name.' '.$recipient->contact->last_name) ?: $recipient->contact->email }}
                                            </a>
                                        @else
                                            <span class="text-slate-500">Contact missing</span>
                                        @endif

                                        <div class="mt-1 break-all text-xs text-slate-500">
                                            {{ $broadcast->channel === 'sms'
                                                ? ($recipient->contact?->phone ?? '—')
                                                : ($recipient->contact?->email ?? '—') }}
                                        </div>
                                    </div>

                                    <span class="shrink-0 rounded-full bg-slate-100 px-2 py-1 text-xs font-semibold text-slate-700">
                                        {{ str_replace('_', ' ', $recipient->status) }}
                                    </span>
                                </div>

                                <dl class="mt-3 grid grid-cols-2 gap-3 text-xs">
                                    <div>
                                        <dt class="font-semibold uppercase tracking-wide text-slate-400">Sent</dt>
                                        <dd class="mt-1 text-sm text-slate-700">{{ $recipient->sent_at?->format('M j, Y g:i A') ?? '—' }}</dd>
                                    </div>
                                    <div>
                                        <dt class="font-semibold uppercase tracking-wide text-slate-400">Reason</dt>
                                        <dd class="mt-1 break-words text-sm text-slate-700">
                                            @php($reason = $recipient->terminal_reason)
                                            {{ $reason === 'not_scheduled_by_messaging' ? 'Not eligible to receive this message' : ($reason ? str_replace('_', ' ', $reason) : '—') }}
                                        </dd>
                                    </div>
                                </dl>
                            </div>
                        @empty
                            <div class="p-4 text-sm text-slate-600">
                                No recipients yet.
                            </div>
                        @endforelse
                    </div>

                    <div class="hidden overflow-x-auto md:block">
                        <table class="min-w-full text-sm">
                            <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                                <tr>
                                    <th class="px-6 py-3">Contact</th>
                                    <th class="px-6 py-3">{{ $broadcast->channel === 'sms' ? 'Phone' : 'Email' }}</th>
                                    <th class="px-6 py-3">Status</th>
                                    <th class="px-6 py-3">Sent</th>
                                    <th class="px-6 py-3">Reason</th>
                                </tr>
                            </thead>

                            <tbody class="divide-y divide-slate-200">
                                @forelse($recipients as $recipient)
                                    <tr>
                                        <td class="px-6 py-4">
                                            @if($recipient->contact)
                                                <a
                                                    href="{{ route('crm.contacts.show', $recipient->contact) }}"
                                                    class="font-medium text-slate-900 underline decoration-slate-300 underline-offset-4 hover:decoration-slate-900"
                                                >
                                                    {{ $recipient->contact->name ?: trim($recipient->contact->first_name.' '.$recipient->contact->last_name) ?: $recipient->contact->email }}
                                                </a>
                                            @else
                                                <span class="text-slate-500">Contact missing</span>
                                            @endif
                                        </td>

                                        <td class="px-6 py-4 text-slate-600">
                                            {{ $broadcast->channel === 'sms'
                                                ? ($recipient->contact?->phone ?? '—')
                                                : ($recipient->contact?->email ?? '—') }}
                                        </td>

                                        <td class="px-6 py-4">
                                            <span class="inline-flex rounded-full bg-slate-100 px-2 py-1 text-xs font-semibold text-slate-700">
                                                {{ str_replace('_', ' ', $recipient->status) }}
                                            </span>
                                        </td>

                                        <td class="px-6 py-4 text-slate-600">
                                            {{ $recipient->sent_at?->format('M j, Y g:i A') ?? '—' }}
                                        </td>

                                        <td class="px-6 py-4 text-slate-600">
                                            @php($reason = $recipient->terminal_reason)

                                            {{ $reason === 'not_scheduled_by_messaging' ? 'Not eligible to receive this message' : ($reason ? str_replace('_', ' ', $reason) : '—') }}
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="5" class="px-6 py-6 text-sm text-slate-600">
                                            No recipients yet.
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </x-ui.card>
            </div>

            <div class="space-y-6">
                <x-ui.card>
                    <h2 class="text-lg font-semibold tracking-tight">
                        Summary
                    </h2>

                    <dl class="mt-4 space-y-3 text-sm">
                        <div class="flex flex-col gap-1 sm:flex-row sm:justify-between sm:gap-4">
                            <dt class="text-slate-500">Type</dt>
                            <dd class="break-words font-medium text-slate-900 sm:text-right">
                                {{ $broadcast->typeLabel() }}
                            </dd>
                        </div>

                        <div class="flex flex-col gap-1 sm:flex-row sm:justify-between sm:gap-4">
                            <dt class="text-slate-500">Status</dt>
                            <dd class="break-words font-medium text-slate-900 sm:text-right">{{ str_replace('_', ' ', $broadcast->status) }}</dd>
                        </div>

                        <div class="flex flex-col gap-1 sm:flex-row sm:justify-between sm:gap-4">
                            <dt class="text-slate-500">Channel</dt>
                            <dd class="break-words font-medium text-slate-900 sm:text-right">{{ strtoupper($broadcast->channel) }}</dd>
                        </div>

                        <div class="flex flex-col gap-1 sm:flex-row sm:justify-between sm:gap-4">
                            <dt class="text-slate-500">Purpose / Scope</dt>
                            <dd class="break-words font-medium text-slate-900 sm:text-right">{{ $broadcast->purpose }} / {{ $broadcast->scope }}</dd>
                        </div>

                        <div class="flex flex-col gap-1 sm:flex-row sm:justify-between sm:gap-4">
                            <dt class="text-slate-500">Send At</dt>
                            <dd class="break-words font-medium text-slate-900 sm:text-right">{{ $broadcast->send_at?->format('M j, Y g:i A') ?? 'Not scheduled' }}</dd>
                        </div>

                        <div class="flex flex-col gap-1 sm:flex-row sm:justify-between sm:gap-4">
                            <dt class="text-slate-500">Recipients</dt>
                            <dd class="break-words font-medium text-slate-900 sm:text-right">{{ $broadcast->recipient_count }}</dd>
                        </div>

                        <div class="flex flex-col gap-1 sm:flex-row sm:justify-between sm:gap-4">
                            <dt class="text-slate-500">Scheduled</dt>
                            <dd class="break-words font-medium text-slate-900 sm:text-right">{{ $broadcast->scheduled_count }}</dd>
                        </div>

                        <div class="flex flex-col gap-1 sm:flex-row sm:justify-between sm:gap-4">
                            <dt class="text-slate-500">Sent</dt>
                            <dd class="break-words font-medium text-slate-900 sm:text-right">{{ $broadcast->sent_recipients_count ?? 0 }}</dd>
                        </div>

                        <div class="flex flex-col gap-1 sm:flex-row sm:justify-between sm:gap-4">
                            <dt class="text-slate-500">Skipped</dt>
                            <dd class="break-words font-medium text-slate-900 sm:text-right">{{ $broadcast->skipped_recipients_count ?? 0 }}</dd>
                        </div>

                        <div class="flex flex-col gap-1 sm:flex-row sm:justify-between sm:gap-4">
                            <dt class="text-slate-500">Failed</dt>
                            <dd class="break-words font-medium text-slate-900 sm:text-right">{{ $broadcast->failed_recipients_count ?? 0 }}</dd>
                        </div>

                        <div class="flex flex-col gap-1 sm:flex-row sm:justify-between sm:gap-4">
                            <dt class="text-slate-500">Completed</dt>
                            <dd class="break-words font-medium text-slate-900 sm:text-right">{{ $broadcast->completed_at?->format('M j, Y g:i A') ?? '—' }}</dd>
                        </div>
                    </dl>
                </x-ui.card>

                <x-ui.card>
                    <h2 class="text-lg font-semibold tracking-tight">
                        Recipient Filter
                    </h2>

                    @php($recipientFilter = $broadcast->recipient_filter ?? [])

                    <div class="mt-4 text-sm text-slate-700">
                        @if(($recipientFilter['type'] ?? 'all') === 'imported')
                            Imported contacts only.
                        @elseif(($recipientFilter['type'] ?? 'all') === 'import_batch')
                            <div class="space-y-3">
                                <p>
                                    Imported contacts from selected batches:
                                    <span class="font-semibold">
                                        {{ count($recipientFilter['import_batch_ids'] ?? []) }}
                                    </span>
                                </p>

                                <div class="space-y-2">
                                    @forelse($selectedImportBatches as $importBatch)
                                        <div class="rounded-xl border border-slate-200 p-3">
                                            <a
                                                href="{{ route('crm.contacts.import-batches.show', $importBatch) }}"
                                                class="font-medium text-slate-900 underline decoration-slate-300 underline-offset-4 hover:decoration-slate-900"
                                            >
                                                {{ $importBatch->name ?? 'Import #'.$importBatch->id }}
                                            </a>

                                            <div class="mt-1 text-xs text-slate-500">
                                                {{ $importBatch->original_filename ?? 'No filename' }}
                                            </div>

                                            <div class="mt-1 text-xs text-slate-500">
                                                {{ $importBatch->imported_at?->format('M j, Y g:i A') ?? 'No import date' }}
                                                · {{ $importBatch->successful_count }} successful
                                                · {{ $importBatch->failed_count }} failed
                                            </div>
                                        </div>
                                    @empty
                                        <p class="text-sm text-slate-500">
                                            No selected import batches found.
                                        </p>
                                    @endforelse
                                </div>
                            </div>
                        @elseif(($recipientFilter['type'] ?? 'all') === 'tag')
                            Contacts tagged:
                            <span class="font-semibold">
                                {{ implode(', ', $recipientFilter['tags'] ?? []) }}
                            </span>
                        @elseif(($recipientFilter['type'] ?? 'all') === 'contact_ids')
                            <div class="space-y-3">
                                <p>
                                    Selected contacts:
                                    <span class="font-semibold">
                                        {{ count($recipientFilter['contact_ids'] ?? []) }}
                                    </span>
                                </p>

                                <div class="space-y-2">
                                    @forelse($recipientFilterContacts as $contact)
                                        <div class="rounded-xl border border-slate-200 p-3">
                                            <a
                                                href="{{ route('crm.contacts.show', $contact) }}"
                                                class="font-medium text-slate-900 underline decoration-slate-300 underline-offset-4 hover:decoration-slate-900"
                                            >
                                                {{ $contact->name ?: trim($contact->first_name.' '.$contact->last_name) ?: $contact->email }}
                                            </a>

                                            <div class="mt-1 text-xs text-slate-500">
                                                {{ $contact->email }}
                                            </div>
                                        </div>
                                    @empty
                                        <p class="text-sm text-slate-500">
                                            No selected contacts found.
                                        </p>
                                    @endforelse
                                </div>
                            </div>
                        @else
                            All contacts.
                        @endif
                    </div>
                </x-ui.card>

                <x-ui.card>
                    <h2 class="text-lg font-semibold tracking-tight">
                        Scheduled Messages
                    </h2>

                    <div class="mt-4 space-y-3">
                        @forelse($scheduledMessages as $scheduledMessage)
                            <div class="rounded-xl border border-slate-200 p-3 text-sm">
                                <div class="flex flex-wrap justify-between gap-3">
                                    <span class="font-medium text-slate-900">
                                        #{{ $scheduledMessage->id }}
                                    </span>

                                    <span class="text-slate-500">
                                        {{ $scheduledMessage->status }}
                                    </span>
                                </div>

                                <div class="mt-1 text-xs text-slate-500">
                                    {{ $scheduledMessage->send_at?->format('M j, Y g:i A') }}
                                </div>
                            </div>
                        @empty
                            <p class="text-sm text-slate-500">
                                No scheduled messages yet.
                            </p>
                        @endforelse
                    </div>
                </x-ui.card>
            </div>
        </div>
    </div>
</x-layouts.crm>