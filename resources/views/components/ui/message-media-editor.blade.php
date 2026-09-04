@props([
    'presentation' => [],
    'fieldPrefix' => '',
    'namePrefixBind' => null,
    'visibleBind' => null,
    'selectedAssetUuid' => '',
    'selectedPosterAssetUuid' => '',
    'selectedTitle' => '',
    'assetModel' => null,
    'posterModel' => null,
    'titleModel' => null,
])

@if(($presentation['available'] ?? false) === true)
    <section
        {{ $attributes->class(['rounded-2xl border border-violet-200 bg-violet-50/70 p-4 sm:p-5']) }}
        @if(filled($visibleBind)) x-show="{{ $visibleBind }}" x-cloak @endif
        data-message-media-authoring
    >
        <input
            type="hidden"
            @if(filled($namePrefixBind))
                x-bind:name="{{ $namePrefixBind }} + '[media_present]'"
            @else
                name="{{ $fieldPrefix !== '' ? $fieldPrefix.'[media_present]' : 'media_present' }}"
            @endif
            value="1"
        >

        <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <h4 class="text-sm font-black text-slate-950">Media</h4>
                <p class="mt-1 text-xs leading-5 text-slate-600">
                    Choose a reusable asset or upload a new one. Put <code>{media}</code> in the email body to choose its exact position; otherwise Engage appends it after the body copy.
                </p>
            </div>

            @if(filled($presentation['library_url'] ?? null))
                <a
                    href="{{ $presentation['library_url'] }}"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="shrink-0 text-xs font-extrabold text-violet-800 underline decoration-violet-300 underline-offset-4"
                >
                    Open Media Library
                </a>
            @endif
        </div>

        <div class="mt-4 grid gap-4 lg:grid-cols-2">
            <div>
                <label class="mb-1.5 block text-xs font-extrabold uppercase tracking-wide text-slate-600">Choose existing</label>
                <select
                    @if(filled($namePrefixBind))
                        x-bind:name="{{ $namePrefixBind }} + '[media_asset_uuid]'"
                    @else
                        name="{{ $fieldPrefix !== '' ? $fieldPrefix.'[media_asset_uuid]' : 'media_asset_uuid' }}"
                    @endif
                    @if(filled($assetModel)) x-model="{{ $assetModel }}" @endif
                    class="block w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-900"
                >
                    <option value="">No media</option>
                    @foreach(($presentation['assets'] ?? []) as $asset)
                        <option
                            value="{{ $asset['uuid'] }}"
                            @selected(! filled($assetModel) && (string) $selectedAssetUuid === (string) $asset['uuid'])
                        >
                            {{ ucfirst((string) ($asset['kind'] ?? 'media')) }} — {{ $asset['title'] ?? $asset['uuid'] }}{{ ($asset['archived'] ?? false) ? ' (current archived asset)' : '' }}
                        </option>
                    @endforeach
                </select>
                @error($fieldPrefix !== '' ? $fieldPrefix.'.media_asset_uuid' : 'media_asset_uuid')
                    <p class="mt-2 text-sm font-semibold text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label class="mb-1.5 block text-xs font-extrabold uppercase tracking-wide text-slate-600">Or upload new</label>
                <input
                    type="file"
                    @if(filled($namePrefixBind))
                        x-bind:name="{{ $namePrefixBind }} + '[media_upload]'"
                    @else
                        name="{{ $fieldPrefix !== '' ? $fieldPrefix.'[media_upload]' : 'media_upload' }}"
                    @endif
                    class="block w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm text-slate-700 file:mr-3 file:rounded-full file:border-0 file:bg-slate-900 file:px-3 file:py-1.5 file:text-xs file:font-bold file:text-white"
                >
                <input
                    @if(filled($namePrefixBind))
                        x-bind:name="{{ $namePrefixBind }} + '[media_title]'"
                    @else
                        name="{{ $fieldPrefix !== '' ? $fieldPrefix.'[media_title]' : 'media_title' }}"
                    @endif
                    value="{{ $selectedTitle }}"
                    @if(filled($titleModel)) x-model="{{ $titleModel }}" @endif
                    placeholder="Optional title for new upload"
                    class="mt-2 block w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-900"
                >
                <p class="mt-2 text-xs leading-5 text-slate-500">A new upload takes precedence over the existing-asset selection.</p>
                @error($fieldPrefix !== '' ? $fieldPrefix.'.media_upload' : 'media_upload')
                    <p class="mt-2 text-sm font-semibold text-red-600">{{ $message }}</p>
                @enderror
            </div>
        </div>

        <div class="mt-4">
            <label class="mb-1.5 block text-xs font-extrabold uppercase tracking-wide text-slate-600">
                Video poster image <span class="normal-case font-semibold text-slate-400">(optional)</span>
            </label>
            <select
                @if(filled($namePrefixBind))
                    x-bind:name="{{ $namePrefixBind }} + '[media_poster_asset_uuid]'"
                @else
                    name="{{ $fieldPrefix !== '' ? $fieldPrefix.'[media_poster_asset_uuid]' : 'media_poster_asset_uuid' }}"
                @endif
                @if(filled($posterModel)) x-model="{{ $posterModel }}" @endif
                class="block w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-900"
            >
                <option value="">Use generic video card</option>
                @foreach(($presentation['image_assets'] ?? []) as $asset)
                    <option
                        value="{{ $asset['uuid'] }}"
                        @selected(! filled($posterModel) && (string) $selectedPosterAssetUuid === (string) $asset['uuid'])
                    >
                        {{ $asset['title'] ?? $asset['uuid'] }}{{ ($asset['archived'] ?? false) ? ' (current archived poster)' : '' }}
                    </option>
                @endforeach
            </select>
            <p class="mt-2 text-xs leading-5 text-slate-500">
                Poster images apply only to video. Without one, email rendering uses the safe video-card fallback instead of relying on inconsistent in-inbox playback.
            </p>
            @error($fieldPrefix !== '' ? $fieldPrefix.'.media_poster_asset_uuid' : 'media_poster_asset_uuid')
                <p class="mt-2 text-sm font-semibold text-red-600">{{ $message }}</p>
            @enderror
        </div>
    </section>
@endif