<x-layouts.crm
    :title="$importBatch->name ?? 'Import #'.$importBatch->id"
    :heading="$importBatch->name ?? 'Import #'.$importBatch->id"
    subheading="Import batch detail"
>
    @php
        $statusMapping = data_get($importBatch->meta, 'status_mapping', []);
        $reviewRequired = (bool) data_get($statusMapping, 'review_required', false);
        $mapped = data_get($statusMapping, 'mapped', []);
        $unmapped = data_get($statusMapping, 'unmapped', []);

        $messagingEnabled = module_enabled('messaging');

        $contactsCollection = $contacts->getCollection();

        $missingEmailConsentCount = $messagingEnabled
            ? $contactsCollection->filter(function ($contact): bool {
                $consents = $contact->relationLoaded('messageConsents') ? $contact->messageConsents : collect();

                foreach (['broadcast', 'campaign'] as $scope) {
                    $hasConsent = $consents->contains(fn ($consent): bool =>
                        ($consent->channel?->value ?? $consent->channel) === 'email'
                        && ($consent->purpose?->value ?? $consent->purpose) === 'marketing'
                        && $consent->scope === $scope
                        && $consent->consented_at !== null
                    );

                    if (! $hasConsent) {
                        return true;
                    }
                }

                return false;
            })->count()
            : 0;

        $pendingInvitationCount = $messagingEnabled
            ? $contactsCollection->filter(function ($contact): bool {
                $messages = $contact->relationLoaded('scheduledMessages') ? $contact->scheduledMessages : collect();

                return $messages->contains(fn ($message): bool =>
                    $message->message_type === 'imported_contact_permission_invitation'
                    && $message->status === 'pending'
                );
            })->count()
            : 0;

        $skippedInvitationCount = $messagingEnabled
            ? $contactsCollection->filter(function ($contact): bool {
                $messages = $contact->relationLoaded('scheduledMessages') ? $contact->scheduledMessages : collect();

                return $messages->contains(fn ($message): bool =>
                    $message->message_type === 'imported_contact_permission_invitation'
                    && $message->status === 'skipped'
                );
            })->count()
            : 0;

        $sentInvitationCount = $messagingEnabled
            ? $contactsCollection->filter(function ($contact): bool {
                $invitations = $contact->relationLoaded('permissionInvitations') ? $contact->permissionInvitations : collect();

                return $invitations->contains(fn ($invitation): bool =>
                    $invitation->source === 'imported_contact'
                    && $invitation->channel === 'email'
                    && in_array($invitation->status, ['sent', 'accepted'], true)
                );
            })->count()
            : 0;

        $acceptedInvitationCount = $messagingEnabled
            ? $contactsCollection->filter(function ($contact): bool {
                $invitations = $contact->relationLoaded('permissionInvitations') ? $contact->permissionInvitations : collect();

                return $invitations->contains(fn ($invitation): bool =>
                    $invitation->source === 'imported_contact'
                    && $invitation->channel === 'email'
                    && $invitation->status === 'accepted'
                );
            })->count()
            : 0;
    @endphp

    <div class="space-y-6">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <a
                href="{{ route('crm.contacts.import-batches.index') }}"
                class="text-sm font-semibold text-slate-600 underline hover:text-slate-900"
            >
                Back to Import Batches
            </a>
        </div>

        <x-ui.card>
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h2 class="text-lg font-semibold tracking-tight">
                        Import Summary
                    </h2>

                    <p class="mt-1 text-sm text-slate-500">
                        {{ $importBatch->original_filename ?? 'No filename recorded' }}
                    </p>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    @if ($reviewRequired)
                        <span class="inline-flex rounded-full bg-amber-50 px-2.5 py-1 text-xs font-semibold text-amber-700">
                            Status Review Needed
                        </span>
                    @endif

                    <span class="inline-flex rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-700">
                        {{ str($importBatch->status)->replace('_', ' ')->title() }}
                    </span>
                </div>
            </div>

            <dl class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div class="rounded-xl border border-slate-200 p-4">
                    <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">
                        Source
                    </dt>
                    <dd class="mt-1 font-medium text-slate-900">
                        {{ $importBatch->source ? str($importBatch->source)->replace('_', ' ')->title() : '—' }}
                    </dd>
                </div>

                <div class="rounded-xl border border-slate-200 p-4">
                    <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">
                        Imported
                    </dt>
                    <dd class="mt-1 font-medium text-slate-900">
                        {{ $importBatch->imported_at?->format('M j, Y g:i A') ?? '—' }}
                    </dd>
                </div>

                <div class="rounded-xl border border-slate-200 p-4">
                    <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">
                        Successful
                    </dt>
                    <dd class="mt-1 font-medium text-slate-900">
                        {{ $importBatch->successful_count }}
                    </dd>
                </div>

                <div class="rounded-xl border border-slate-200 p-4">
                    <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">
                        Failed / Skipped
                    </dt>
                    <dd class="mt-1 font-medium text-slate-900">
                        {{ $importBatch->failed_count }}
                    </dd>
                </div>
            </dl>
        </x-ui.card>

        @if ($messagingEnabled)
        <x-ui.card class="space-y-4">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h2 class="text-lg font-semibold tracking-tight">
                        Permission Invitations
                    </h2>

                    <p class="mt-1 text-sm text-slate-500">
                        Review permission invitation status for the contacts shown on this page.
                    </p>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    @if ($missingEmailConsentCount > 0)
                        <form
                            method="POST"
                            action="{{ route('crm.contacts.import-batches.permission-invitations.store', $importBatch) }}"
                        >
                            @csrf

                            <x-ui.button type="submit">
                                Send Permission Invitations
                            </x-ui.button>
                        </form>
                    @endif

                    @if ($pendingInvitationCount > 0)
                        <form
                            method="POST"
                            action="{{ route('crm.contacts.import-batches.permission-invitations.destroy', $importBatch) }}"
                        >
                            @csrf
                            @method('DELETE')

                            <x-ui.button type="submit">
                                Cancel Pending Invitations
                            </x-ui.button>
                        </form>
                    @endif
                </div>
            </div>

            @if (session('success'))
                <div class="rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm font-semibold text-green-800">
                    {{ session('success') }}
                </div>
            @endif

            <dl class="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
                <div class="rounded-xl border border-slate-200 p-4">
                    <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">
                        Missing Consent
                    </dt>
                    <dd class="mt-1 font-medium {{ $missingEmailConsentCount > 0 ? 'text-amber-700' : 'text-slate-900' }}">
                        {{ $missingEmailConsentCount }}
                    </dd>
                </div>

                <div class="rounded-xl border border-slate-200 p-4">
                    <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">
                        Scheduled
                    </dt>
                    <dd class="mt-1 font-medium text-slate-900">
                        {{ $pendingInvitationCount }}
                    </dd>
                </div>

                <div class="rounded-xl border border-slate-200 p-4">
                    <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">
                        Cancelled
                    </dt>
                    <dd class="mt-1 font-medium text-slate-900">
                        {{ $skippedInvitationCount }}
                    </dd>
                </div>

                <div class="rounded-xl border border-slate-200 p-4">
                    <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">
                        Sent
                    </dt>
                    <dd class="mt-1 font-medium text-slate-900">
                        {{ $sentInvitationCount }}
                    </dd>
                </div>

                <div class="rounded-xl border border-slate-200 p-4">
                    <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">
                        Accepted
                    </dt>
                    <dd class="mt-1 font-medium text-slate-900">
                        {{ $acceptedInvitationCount }}
                    </dd>
                </div>
            </dl>
        </x-ui.card>
    @endif

        @if ($statusMapping !== [])
            <x-ui.card class="space-y-4">
                <div>
                    <h2 class="text-lg font-semibold tracking-tight">
                        Status Mapping
                    </h2>

                    <p class="mt-1 text-sm text-slate-500">
                        Original imported status values were preserved. Unmapped values need manual review.
                    </p>
                </div>

                <dl class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <div class="rounded-xl border border-slate-200 p-4">
                        <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">
                            Source Column
                        </dt>
                        <dd class="mt-1 font-medium text-slate-900">
                            {{ data_get($statusMapping, 'source_column') ?: '—' }}
                        </dd>
                    </div>

                    <div class="rounded-xl border border-slate-200 p-4">
                        <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">
                            Mapped Rows
                        </dt>
                        <dd class="mt-1 font-medium text-slate-900">
                            {{ data_get($statusMapping, 'mapped_count', 0) }}
                        </dd>
                    </div>

                    <div class="rounded-xl border border-slate-200 p-4">
                        <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">
                            Unmapped Rows
                        </dt>
                        <dd class="mt-1 font-medium {{ data_get($statusMapping, 'unmapped_count', 0) > 0 ? 'text-amber-700' : 'text-slate-900' }}">
                            {{ data_get($statusMapping, 'unmapped_count', 0) }}
                        </dd>
                    </div>

                    <div class="rounded-xl border border-slate-200 p-4">
                        <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">
                            Missing Rows
                        </dt>
                        <dd class="mt-1 font-medium text-slate-900">
                            {{ data_get($statusMapping, 'missing_count', 0) }}
                        </dd>
                    </div>
                </dl>

                @if ($mapped !== [] || $unmapped !== [])
                    <div class="grid gap-4 lg:grid-cols-2">
                        <div class="rounded-xl border border-slate-200 p-4">
                            <h3 class="text-sm font-semibold text-slate-900">
                                Mapped Values
                            </h3>

                            <div class="mt-3 space-y-2">
                                @forelse ($mapped as $originalStatus => $contactStatusId)
                                    <div class="flex items-center justify-between gap-3 text-sm">
                                        <span class="font-medium text-slate-700">
                                            {{ $originalStatus }}
                                        </span>

                                        <span class="text-slate-500">
                                            Status #{{ $contactStatusId }}
                                        </span>
                                    </div>
                                @empty
                                    <p class="text-sm text-slate-500">
                                        No imported statuses were mapped.
                                    </p>
                                @endforelse
                            </div>
                        </div>

                        <div class="rounded-xl border border-slate-200 p-4">
                            <h3 class="text-sm font-semibold text-slate-900">
                                Unmapped Values
                            </h3>

                            <div class="mt-3 space-y-2">
                                @forelse ($unmapped as $originalStatus)
                                    <div class="rounded-lg bg-amber-50 px-3 py-2 text-sm font-medium text-amber-800">
                                        {{ $originalStatus }}
                                    </div>
                                @empty
                                    <p class="text-sm text-slate-500">
                                        No unmapped imported statuses.
                                    </p>
                                @endforelse
                            </div>
                        </div>
                    </div>
                @endif
            </x-ui.card>
        @endif

        <x-ui.card padding="none" class="overflow-hidden">
            <div class="border-b border-slate-200 px-6 py-4">
                <h2 class="text-lg font-semibold tracking-tight">
                    Imported {{ config('contacts.labels.plural') }}
                </h2>

                <p class="mt-1 text-sm text-slate-500">
                    Showing contacts currently attached to this import batch.
                </p>
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-6 py-3">Name</th>
                            <th class="px-6 py-3">Email</th>
                            <th class="px-6 py-3">Phone</th>
                            <th class="px-6 py-3">Imported Status</th>
                            <th class="px-6 py-3">Mapping</th>
                            @if ($messagingEnabled)
                                <th class="px-6 py-3">Permission</th>
                            @endif
                            <th class="px-6 py-3">Created</th>
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-slate-200">
                        @forelse($contacts as $contact)
                            @php
                                $contactStatusMapping = data_get($contact->meta, 'import.status_mapping', []);
                                $mappingState = data_get($contactStatusMapping, 'state');
                            @endphp

                            <tr>
                                <td class="px-6 py-4">
                                    <a
                                        href="{{ route('crm.contacts.show', $contact) }}"
                                        class="font-medium text-slate-900 underline decoration-slate-300 underline-offset-4 hover:decoration-slate-900"
                                    >
                                        {{ $contact->name ?: trim($contact->first_name.' '.$contact->last_name) ?: $contact->email }}
                                    </a>
                                </td>

                                <td class="px-6 py-4 text-slate-600">
                                    {{ $contact->email }}
                                </td>

                                <td class="px-6 py-4 text-slate-600">
                                    {{ $contact->phone ?: '—' }}
                                </td>

                                <td class="px-6 py-4 text-slate-600">
                                    {{ data_get($contact->meta, 'import.original_status') ?: '—' }}
                                </td>

                                <td class="px-6 py-4">
                                    @if ($mappingState === 'mapped')
                                        <span class="inline-flex rounded-full bg-green-50 px-2.5 py-1 text-xs font-semibold text-green-700">
                                            Mapped
                                        </span>
                                    @elseif ($mappingState === 'unmapped')
                                        <span class="inline-flex rounded-full bg-amber-50 px-2.5 py-1 text-xs font-semibold text-amber-700">
                                            Review
                                        </span>
                                    @elseif ($mappingState === 'missing')
                                        <span class="inline-flex rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-600">
                                            Missing
                                        </span>
                                    @else
                                        <span class="text-slate-400">—</span>
                                    @endif
                                </td>

                                @if ($messagingEnabled)
                                    @php
                                        $contactConsents = $contact->relationLoaded('messageConsents') ? $contact->messageConsents : collect();
                                        $contactInvitations = $contact->relationLoaded('permissionInvitations') ? $contact->permissionInvitations : collect();
                                        $contactScheduledMessages = $contact->relationLoaded('scheduledMessages') ? $contact->scheduledMessages : collect();

                                        $hasBroadcastEmailConsent = $contactConsents->contains(fn ($consent): bool =>
                                            ($consent->channel?->value ?? $consent->channel) === 'email'
                                            && ($consent->purpose?->value ?? $consent->purpose) === 'marketing'
                                            && $consent->scope === 'broadcast'
                                            && $consent->consented_at !== null
                                        );

                                        $hasCampaignEmailConsent = $contactConsents->contains(fn ($consent): bool =>
                                            ($consent->channel?->value ?? $consent->channel) === 'email'
                                            && ($consent->purpose?->value ?? $consent->purpose) === 'marketing'
                                            && $consent->scope === 'campaign'
                                            && $consent->consented_at !== null
                                        );

                                        $acceptedInvitation = $contactInvitations->first(fn ($invitation): bool =>
                                            $invitation->source === 'imported_contact'
                                            && $invitation->channel === 'email'
                                            && $invitation->status === 'accepted'
                                        );

                                        $sentInvitation = $contactInvitations->first(fn ($invitation): bool =>
                                            $invitation->source === 'imported_contact'
                                            && $invitation->channel === 'email'
                                            && $invitation->status === 'sent'
                                        );

                                        $pendingInvitation = $contactScheduledMessages->first(fn ($message): bool =>
                                            $message->message_type === 'imported_contact_permission_invitation'
                                            && $message->status === 'pending'
                                        );

                                        $skippedInvitation = $contactScheduledMessages->first(fn ($message): bool =>
                                                $message->message_type === 'imported_contact_permission_invitation'
                                                && $message->status === 'skipped'
                                            );
                                    @endphp

                                    <td class="px-6 py-4">
                                        @if ($hasBroadcastEmailConsent && $hasCampaignEmailConsent)
                                            <span class="inline-flex rounded-full bg-green-50 px-2.5 py-1 text-xs font-semibold text-green-700">
                                                Consented
                                            </span>
                                        @elseif ($acceptedInvitation)
                                            <span class="inline-flex rounded-full bg-green-50 px-2.5 py-1 text-xs font-semibold text-green-700">
                                                Accepted
                                            </span>
                                        @elseif ($sentInvitation)
                                            <span class="inline-flex rounded-full bg-blue-50 px-2.5 py-1 text-xs font-semibold text-blue-700">
                                                Sent
                                            </span>
                                        @elseif ($pendingInvitation)
                                            <span class="inline-flex rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-700">
                                                Scheduled
                                            </span>
                                        @elseif ($skippedInvitation)
                                            <span
                                                title="{{ $skippedInvitation->skip_reason }}"
                                                class="inline-flex rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-600"
                                            >
                                                Cancelled
                                            </span>
                                        @else
                                            <span class="inline-flex rounded-full bg-amber-50 px-2.5 py-1 text-xs font-semibold text-amber-700">
                                                Missing Consent
                                            </span>
                                        @endif
                                    </td>
                                @endif

                                <td class="px-6 py-4 text-slate-600">
                                    {{ $contact->created_at?->format('M j, Y g:i A') ?? '—' }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="{{ $messagingEnabled ? 7 : 6 }}" class="px-6 py-6 text-sm text-slate-500">
                                    No contacts are attached to this import batch.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-ui.card>

        <div>
            {{ $contacts->links() }}
        </div>
    </div>
</x-layouts.crm>