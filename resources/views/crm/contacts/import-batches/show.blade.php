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

                                <td class="px-6 py-4 text-slate-600">
                                    {{ $contact->created_at?->format('M j, Y g:i A') ?? '—' }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-6 py-6 text-sm text-slate-500">
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