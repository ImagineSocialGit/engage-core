<x-layouts.crm
    :title="$title"
    :heading="$heading"
    :subheading="$subheading"
    module="media"
>
    <div class="space-y-6" data-media-library>
        @if(session('success'))
            <div class="rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-900">
                {{ session('success') }}
            </div>
        @endif

        <x-ui.card>
            <div class="space-y-4" data-media-upload-workspace>
                <form
                    method="POST"
                    action="{{ route('crm.media.store') }}"
                    enctype="multipart/form-data"
                    class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto] lg:items-end"
                    data-media-upload-form
                    data-media-similarity-preflight-url="{{ route('crm.media.similarity.inspect') }}"
                >
                    @csrf

                    <div>
                        <label for="media-title" class="block text-sm font-semibold text-slate-800">Title</label>
                        <input
                            id="media-title"
                            name="title"
                            type="text"
                            value="{{ old('title') }}"
                            maxlength="255"
                            placeholder="Optional — filename is used when blank"
                            class="mt-1 block w-full rounded-xl border-slate-300 text-sm shadow-sm"
                        >
                        @error('title')
                            <p class="mt-1 text-xs font-medium text-red-700">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="media-file" class="block text-sm font-semibold text-slate-800">File</label>
                        <input
                            id="media-file"
                            name="file"
                            type="file"
                            required
                            class="mt-1 block w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm"
                        >
                        <p class="mt-1 text-xs text-slate-500">Maximum application upload: {{ $maxUploadMegabytes }} MB.</p>
                        @error('file')
                            <p class="mt-1 text-xs font-medium text-red-700">{{ $message }}</p>
                        @enderror
                    </div>

                    <button
                        type="submit"
                        class="inline-flex min-h-11 items-center justify-center rounded-xl bg-slate-950 px-5 py-2.5 text-sm font-semibold text-white hover:bg-slate-800 disabled:cursor-wait disabled:opacity-60"
                        data-media-upload-submit
                    >
                        Upload media
                    </button>
                </form>

                <p
                    class="hidden rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm font-medium text-slate-800"
                    data-media-upload-local-status
                ></p>

                <section
                    class="hidden rounded-2xl border border-amber-300 bg-amber-50 p-4"
                    data-media-similarity-review
                >
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                        <div>
                            <h2 class="text-sm font-bold text-amber-950">Similar Media found</h2>
                            <p class="mt-1 max-w-3xl text-sm leading-6 text-amber-900" data-media-similarity-message>
                                This image looks very similar to existing Media.
                            </p>
                        </div>

                        <button
                            type="button"
                            class="text-sm font-semibold text-amber-950 underline"
                            data-media-cancel-upload
                        >
                            Cancel upload
                        </button>
                    </div>

                    <div class="mt-4 grid gap-3" data-media-similarity-candidates></div>

                    <div class="mt-4 flex flex-wrap items-center gap-3 border-t border-amber-200 pt-4">
                        <button
                            type="button"
                            class="inline-flex min-h-10 items-center justify-center rounded-xl border border-amber-400 bg-white px-4 py-2 text-sm font-semibold text-amber-950 hover:bg-amber-100"
                            data-media-upload-anyway
                        >
                            Upload anyway
                        </button>
                        <p class="text-xs leading-5 text-amber-800">
                            Use this only when the image is intentionally different, such as a distinct crop or edited variant.
                        </p>
                    </div>
                </section>
            </div>
        </x-ui.card>

        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h2 class="text-lg font-semibold text-slate-950">{{ $showArchived ? 'Archived media' : 'Available media' }}</h2>
                <p class="mt-1 text-sm text-slate-500">Archived assets remain stored so existing references do not break.</p>
            </div>

            <a
                href="{{ route('crm.media.index', $showArchived ? [] : ['archived' => 1]) }}"
                class="inline-flex min-h-10 items-center rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50"
            >
                {{ $showArchived ? 'View available media' : 'View archived media' }}
            </a>
        </div>

        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            @forelse($assets as $asset)
                <x-ui.card>
                    <article class="space-y-4" data-media-asset-id="{{ $asset->getKey() }}" data-media-kind="{{ $asset->kind }}">
                        <div class="overflow-hidden rounded-xl bg-slate-100">
                            @if($asset->publicUrl() && $asset->kind === \App\Modules\Media\Models\MediaAsset::KIND_IMAGE)
                                <img src="{{ $asset->publicUrl() }}" alt="{{ $asset->title }}" class="aspect-video w-full object-cover">
                            @elseif($asset->publicUrl() && $asset->kind === \App\Modules\Media\Models\MediaAsset::KIND_VIDEO)
                                <video controls preload="metadata" class="aspect-video w-full bg-black object-contain">
                                    <source src="{{ $asset->publicUrl() }}" type="{{ $asset->mime_type }}">
                                </video>
                            @elseif($asset->publicUrl() && $asset->kind === \App\Modules\Media\Models\MediaAsset::KIND_AUDIO)
                                <div class="p-4">
                                    <audio controls preload="metadata" class="w-full">
                                        <source src="{{ $asset->publicUrl() }}" type="{{ $asset->mime_type }}">
                                    </audio>
                                </div>
                            @else
                                <div class="flex aspect-video items-center justify-center px-6 text-center text-sm font-semibold text-slate-600">
                                    {{ strtoupper($asset->extension ?: $asset->kind) }}
                                </div>
                            @endif
                        </div>

                        <div class="min-w-0">
                            <p class="text-xs font-bold uppercase tracking-[0.12em] text-slate-500">{{ $asset->kind }}</p>
                            <h3 class="mt-1 truncate text-base font-semibold text-slate-950">{{ $asset->title }}</h3>
                            <p class="mt-1 truncate text-xs text-slate-500">{{ $asset->original_filename }}</p>
                        </div>

                        @if($asset->publicUrl())
                            <div class="rounded-xl bg-slate-50 p-3">
                                <p class="break-all text-xs text-slate-600" data-media-public-url>{{ $asset->publicUrl() }}</p>
                                <a href="{{ $asset->publicUrl() }}" target="_blank" rel="noopener" class="mt-2 inline-flex text-xs font-semibold text-slate-900 underline">Open asset</a>
                            </div>
                        @endif

                        @if($asset->archived_at)
                            <form method="POST" action="{{ route('crm.media.restore', $asset) }}">
                                @csrf
                                @method('PATCH')
                                <button type="submit" class="text-sm font-semibold text-slate-700 underline">Restore</button>
                            </form>
                        @else
                            <form method="POST" action="{{ route('crm.media.archive', $asset) }}">
                                @csrf
                                @method('PATCH')
                                <button type="submit" class="text-sm font-semibold text-slate-700 underline">Archive</button>
                            </form>
                        @endif
                    </article>
                </x-ui.card>
            @empty
                <x-ui.card>
                    <p class="text-sm text-slate-500" data-media-empty>No media is in this view yet.</p>
                </x-ui.card>
            @endforelse
        </div>

        {{ $assets->links() }}
    </div>
</x-layouts.crm>