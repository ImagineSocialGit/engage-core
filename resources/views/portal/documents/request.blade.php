<x-layouts.portal :presentation="$presentation" :navigation="$navigation" :portal-user="$portalUser">
    <div class="space-y-6">
        <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <a href="{{ route('portal.documents.index') }}" class="text-sm font-semibold text-slate-600 hover:text-slate-950">Documents</a>
                    <h1 class="mt-2 text-2xl font-bold tracking-tight text-slate-950">{{ $documentRequest->title }}</h1>
                    <p class="mt-2 text-sm text-slate-600">{{ $subject->typeLabel }} · {{ $subject->label }}</p>
                </div>
                <span class="inline-flex w-fit rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-700">
                    {{ str($documentRequest->status)->headline() }}
                </span>
            </div>

            @if($documentRequest->instructions)
                <p class="mt-5 text-sm leading-6 text-slate-700">{{ $documentRequest->instructions }}</p>
            @elseif($documentRequest->requirementDefinition?->instructions)
                <p class="mt-5 text-sm leading-6 text-slate-700">{{ $documentRequest->requirementDefinition->instructions }}</p>
            @endif
        </section>

        @if($canUpload)
            <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
                <h2 class="text-lg font-semibold text-slate-950">Upload document</h2>

            @error('file')
                <p class="mt-3 text-sm font-medium text-red-700">{{ $message }}</p>
            @enderror

            <form
                method="POST"
                action="{{ route('portal.documents.requests.uploads.store', ['documentRequest' => $documentRequest->getKey()]) }}"
                enctype="multipart/form-data"
                class="mt-5 space-y-5"
            >
                @csrf

                <div>
                    <label for="document-file" class="block text-sm font-semibold text-slate-800">File</label>
                    <input
                        id="document-file"
                        type="file"
                        name="file"
                        required
                        class="mt-2 block w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900"
                    >
                    <p class="mt-2 text-xs text-slate-500">Maximum {{ $maximumMb }} MB.</p>
                </div>

                <div>
                    <label for="document-title" class="block text-sm font-semibold text-slate-800">Document name</label>
                    <input
                        id="document-title"
                        type="text"
                        name="title"
                        value="{{ old('title') }}"
                        maxlength="255"
                        class="mt-2 block w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900"
                    >
                </div>

                <div>
                    <label for="document-expires-on" class="block text-sm font-semibold text-slate-800">Valid through</label>
                    <input
                        id="document-expires-on"
                        type="date"
                        name="expires_on"
                        value="{{ old('expires_on') }}"
                        class="mt-2 block w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900"
                    >
                </div>

                <button
                    type="submit"
                    class="inline-flex items-center justify-center rounded-xl bg-slate-950 px-4 py-2.5 text-sm font-semibold text-white hover:bg-slate-800"
                >Upload document</button>
            </form>
            </section>
        @endif

        @if($documentRequest->uploads->isNotEmpty())
            <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
                <h2 class="text-lg font-semibold text-slate-950">Submitted files</h2>
                <div class="mt-4 divide-y divide-slate-200">
                    @foreach($documentRequest->uploads->sortByDesc('id') as $upload)
                        <div class="flex flex-col gap-3 py-4 first:pt-0 last:pb-0 sm:flex-row sm:items-center sm:justify-between">
                            <div>
                                <p class="font-semibold text-slate-950">{{ $upload->title }}</p>
                                <p class="mt-1 text-sm text-slate-600">{{ str($upload->review_status)->headline() }}</p>
                            </div>
                            <a
                                href="{{ route('portal.documents.uploads.download', ['documentUpload' => $upload->getKey()]) }}"
                                class="text-sm font-semibold text-slate-700 hover:text-slate-950"
                            >Download</a>
                        </div>
                    @endforeach
                </div>
            </section>
        @endif
    </div>
</x-layouts.portal>