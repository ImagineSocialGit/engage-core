<x-layouts.crm
    :title="$importBatch->name ?? 'Import #'.$importBatch->id"
    :heading="$importBatch->name ?? 'Import #'.$importBatch->id"
    subheading="Import batch detail"
>
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

                <span class="inline-flex rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-700">
                    {{ str($importBatch->status)->replace('_', ' ')->title() }}
                </span>
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
                            <th class="px-6 py-3">Created</th>
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-slate-200">
                        @forelse($contacts as $contact)
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
                                    {{ $contact->created_at?->format('M j, Y g:i A') ?? '—' }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="px-6 py-6 text-sm text-slate-500">
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