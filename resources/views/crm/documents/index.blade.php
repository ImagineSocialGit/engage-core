<x-layouts.crm :title="$title" :heading="$heading" :subheading="$subheading" module="documents">
    <div class="space-y-6">
        @if(session('success'))
            <p class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-900">{{ session('success') }}</p>
        @endif

        <x-ui.card>
            <form action="{{ route('crm.documents.store') }}" method="POST" enctype="multipart/form-data" class="grid gap-4 sm:grid-cols-2">
                @csrf
                @if($contact)
                    <input type="hidden" name="contact_id" value="{{ $contact->getKey() }}">
                    <p class="sm:col-span-2 text-sm text-slate-700">Contact: {{ $contact->name }}</p>
                @endif
                <div>
                    <label for="document-title" class="block text-sm font-semibold">Title</label>
                    <input id="document-title" name="title" value="{{ old('title') }}" maxlength="255" class="mt-1 block w-full rounded-xl border-slate-300" placeholder="Optional">
                    @error('title') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="document-file" class="block text-sm font-semibold">Document</label>
                    <input id="document-file" name="file" type="file" required class="mt-1 block w-full rounded-xl border border-slate-300 bg-white p-2">
                    <p class="mt-1 text-xs text-slate-600">Maximum {{ $maximumMb }} MB. Files are stored privately.</p>
                    @error('file') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                </div>
                <button type="submit" class="rounded-xl bg-slate-950 px-5 py-3 text-sm font-semibold text-white sm:col-span-2 sm:justify-self-start">Upload document</button>
            </form>
        </x-ui.card>

        <x-ui.card>
            <h2 class="mb-4 text-lg font-bold">Stored documents</h2>
            @forelse($uploads as $upload)
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 py-3 last:border-0">
                    <div>
                        <p class="font-semibold text-slate-900">{{ $upload->title ?: $upload->original_filename }}</p>
                        <p class="text-xs text-slate-600">{{ $upload->original_filename }} · {{ $upload->mime_type }} · {{ number_format(($upload->size_bytes ?? 0) / 1024, 1) }} KB</p>
                    </div>
                    <a href="{{ route('crm.documents.download', $upload) }}" class="text-sm font-semibold text-violet-800 underline">Download</a>
                </div>
            @empty
                <p class="text-sm text-slate-600">No documents uploaded yet.</p>
            @endforelse
            <div class="mt-4">{{ $uploads->links() }}</div>
        </x-ui.card>
    </div>
</x-layouts.crm>