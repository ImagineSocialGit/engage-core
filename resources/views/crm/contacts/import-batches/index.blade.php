<x-layouts.crm
    title="Import Batches"
    heading="Import Batches"
    :subheading="'Review imported '.strtolower(config('contacts.labels.plural')).' by CSV batch'"
>
    <div class="space-y-6">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <a
                href="{{ route('crm.contacts.index') }}"
                class="text-sm font-semibold text-slate-600 underline hover:text-slate-900"
            >
                Back to {{ config('contacts.labels.plural') }}
            </a>
        </div>

        <x-ui.card padding="none" class="overflow-hidden">
            <div class="border-b border-slate-200 px-6 py-4">
                <h2 class="text-lg font-semibold tracking-tight">
                    Recent Imports
                </h2>

                <p class="mt-1 text-sm text-slate-500">
                    Read-only import history for CSV-created contact batches.
                </p>
            </div>

            <div class="divide-y divide-slate-200">
                @forelse($importBatches as $importBatch)
                    <a
                        href="{{ route('crm.contacts.import-batches.show', $importBatch) }}"
                        class="block px-6 py-4 transition hover:bg-slate-50"
                    >
                        <div class="flex flex-wrap items-start justify-between gap-4">
                            <div>
                                <p class="font-semibold text-slate-900">
                                    {{ $importBatch->name ?? 'Import #'.$importBatch->id }}
                                </p>

                                <p class="mt-1 text-sm text-slate-500">
                                    {{ $importBatch->original_filename ?? 'No filename' }}
                                </p>

                                <p class="mt-1 text-xs text-slate-500">
                                    Source:
                                    <span class="font-medium text-slate-700">
                                        {{ $importBatch->source ? str($importBatch->source)->replace('_', ' ')->title() : '—' }}
                                    </span>
                                </p>
                            </div>

                            <div class="grid gap-2 text-right text-sm text-slate-600 sm:grid-cols-4 sm:text-left">
                                <div>
                                    <div class="text-xs font-semibold uppercase tracking-wide text-slate-400">
                                        Status
                                    </div>
                                    <div class="mt-1 font-medium text-slate-900">
                                        {{ str($importBatch->status)->replace('_', ' ')->title() }}
                                    </div>
                                </div>

                                <div>
                                    <div class="text-xs font-semibold uppercase tracking-wide text-slate-400">
                                        Contacts
                                    </div>
                                    <div class="mt-1 font-medium text-slate-900">
                                        {{ $importBatch->contacts_count }}
                                    </div>
                                </div>

                                <div>
                                    <div class="text-xs font-semibold uppercase tracking-wide text-slate-400">
                                        Successful
                                    </div>
                                    <div class="mt-1 font-medium text-slate-900">
                                        {{ $importBatch->successful_count }}
                                    </div>
                                </div>

                                <div>
                                    <div class="text-xs font-semibold uppercase tracking-wide text-slate-400">
                                        Imported
                                    </div>
                                    <div class="mt-1 font-medium text-slate-900">
                                        {{ $importBatch->imported_at?->format('M j, Y') ?? '—' }}
                                    </div>
                                </div>
                            </div>
                        </div>
                    </a>
                @empty
                    <div class="px-6 py-8 text-sm text-slate-500">
                        No import batches yet.
                    </div>
                @endforelse
            </div>
        </x-ui.card>

        <div>
            {{ $importBatches->links() }}
        </div>
    </div>
</x-layouts.crm>