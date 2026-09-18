@props([
    'options' => [],
    'selected' => [],
    'fieldPrefix' => '',
    'namePrefixBind' => null,
    'visibleBind' => null,
    'selectionModel' => null,
    'uploadSources' => [],
])

<section class="mt-4 rounded-xl border border-slate-200 bg-white p-4" @if(filled($visibleBind)) x-show="{{ $visibleBind }}" x-cloak @endif>
    <input type="hidden"
        @if(filled($namePrefixBind)) x-bind:name="{{ $namePrefixBind }} + '[attachments_present]'"
        @else name="{{ $fieldPrefix !== '' ? $fieldPrefix.'[attachments_present]' : 'attachments_present' }}" @endif
        value="1">
    <h4 class="text-sm font-bold text-slate-900">Attach files</h4>
    <p class="mt-1 text-xs text-slate-600">Choose up to five stored files. Attachments are sent as email files; large videos belong in the message body as hosted links.</p>
    @if($options === [])
        <p class="mt-3 text-sm text-slate-500">No eligible stored files yet. Upload a new file below or choose one from the library later.</p>
    @else
        <div class="mt-3 max-h-56 space-y-2 overflow-y-auto">
            @foreach($options as $option)
                <label class="flex items-center gap-3 rounded-lg border border-slate-200 px-3 py-2 text-sm">
                    <input type="checkbox"
                        @if(filled($namePrefixBind)) x-bind:name="{{ $namePrefixBind }} + '[attachment_refs][]'"
                        @else name="{{ $fieldPrefix !== '' ? $fieldPrefix.'[attachment_refs][]' : 'attachment_refs[]' }}" @endif
                        value="{{ $option['source'] }}:{{ $option['id'] }}"
                        @if(filled($selectionModel)) x-model="{{ $selectionModel }}"
                        @else @checked(in_array($option['source'].':'.$option['id'], $selected, true)) @endif
                        class="rounded border-slate-300 text-violet-700">
                    <span class="min-w-0 truncate">{{ ucfirst($option['source']) }} · {{ $option['filename'] }} @if(($option['size_bytes'] ?? 0) > 0) ({{ number_format($option['size_bytes'] / 1024, 0) }} KB) @endif</span>
                </label>
            @endforeach
        </div>
    @endif
    @if($uploadSources !== [])
        <div class="mt-4 grid gap-3 sm:grid-cols-2">
            <div>
                <label class="block text-xs font-semibold text-slate-700">Store in</label>
                <select
                    @if(filled($namePrefixBind)) x-bind:name="{{ $namePrefixBind }} + '[attachment_upload_source]'"
                    @else name="{{ $fieldPrefix !== '' ? $fieldPrefix.'[attachment_upload_source]' : 'attachment_upload_source' }}" @endif
                    class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
                >
                    @foreach($uploadSources as $source)
                        <option value="{{ $source['key'] }}">{{ $source['label'] }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-xs font-semibold text-slate-700">Upload and attach a new file</label>
                <input type="file"
                    @if(filled($namePrefixBind)) x-bind:name="{{ $namePrefixBind }} + '[attachment_upload]'"
                    @else name="{{ $fieldPrefix !== '' ? $fieldPrefix.'[attachment_upload]' : 'attachment_upload' }}" @endif
                    class="mt-1 block w-full rounded-lg border border-slate-300 p-2 text-sm"
                >
            </div>
        </div>
        @error($fieldPrefix !== '' ? $fieldPrefix.'.attachment_upload' : 'attachment_upload')
            <p class="mt-2 text-sm font-semibold text-red-700">{{ $message }}</p>
        @enderror
    @endif
    @error($fieldPrefix !== '' ? $fieldPrefix.'.attachment_refs' : 'attachment_refs')
        <p class="mt-2 text-sm font-semibold text-red-700">{{ $message }}</p>
    @enderror
</section>