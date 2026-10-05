<x-layouts.portal :presentation="$presentation" :navigation="$navigation" :portal-user="$portalUser">
    <div class="space-y-6">
        <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
            <h1 class="text-2xl font-bold tracking-tight text-slate-950">Documents</h1>
        </section>

        <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
            <h2 class="text-lg font-semibold text-slate-950">Requested documents</h2>

            @if($requests->isEmpty())
                <p class="mt-3 text-sm text-slate-600">No document requests are waiting for you.</p>
            @else
                <div class="mt-4 divide-y divide-slate-200">
                    @foreach($requests as $documentRequest)
                        <a
                            href="{{ route('portal.documents.requests.show', ['documentRequest' => $documentRequest->getKey()]) }}"
                            class="flex items-center justify-between gap-4 py-4 first:pt-0 last:pb-0"
                        >
                            <div>
                                <p class="font-semibold text-slate-950">{{ $documentRequest->title }}</p>
                                <p class="mt-1 text-sm text-slate-600">
                                    {{ $documentRequest->requirementDefinition?->name ?? 'Document' }}
                                    @if(isset($requestSubjects[(int) $documentRequest->getKey()]))
                                        · {{ $requestSubjects[(int) $documentRequest->getKey()]->label }}
                                    @endif
                                </p>
                            </div>
                            <span class="text-sm font-semibold text-slate-700">{{ str($documentRequest->status)->headline() }}</span>
                        </a>
                    @endforeach
                </div>
            @endif
        </section>

        <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
            <h2 class="text-lg font-semibold text-slate-950">Uploaded documents</h2>

            @if($uploads->isEmpty())
                <p class="mt-3 text-sm text-slate-600">No documents have been uploaded yet.</p>
            @else
                <div class="mt-4 divide-y divide-slate-200">
                    @foreach($uploads as $upload)
                        <div class="flex flex-col gap-3 py-4 first:pt-0 last:pb-0 sm:flex-row sm:items-center sm:justify-between">
                            <div>
                                <p class="font-semibold text-slate-950">{{ $upload->title }}</p>
                                <p class="mt-1 text-sm text-slate-600">
                                    {{ $upload->requirementDefinition?->name ?? $upload->original_filename }}
                                    @if(isset($uploadSubjects[(int) $upload->getKey()]))
                                        · {{ $uploadSubjects[(int) $upload->getKey()]->label }}
                                    @endif
                                    · {{ str($upload->review_status)->headline() }}
                                    @if($upload->expires_at)
                                        · Valid through {{ $upload->expires_at->timezone(config('client.timezone', config('app.timezone', 'UTC')))->format('M j, Y') }}
                                    @endif
                                </p>
                            </div>
                            <a
                                href="{{ route('portal.documents.uploads.download', ['documentUpload' => $upload->getKey()]) }}"
                                class="text-sm font-semibold text-slate-700 hover:text-slate-950"
                            >Download</a>
                        </div>
                    @endforeach
                </div>
            @endif
        </section>
    </div>
</x-layouts.portal>