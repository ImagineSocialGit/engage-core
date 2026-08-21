@if (! empty($postImportInputs))
    <div class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-4">
        <div>
            <h3 class="text-base font-semibold tracking-tight text-slate-900">
                Launch options
            </h3>
            <p class="mt-1 text-xs text-slate-600">
                These values apply to the whole import batch and come from the detected server-side profile.
            </p>
        </div>

        <div class="mt-4 grid gap-4 md:grid-cols-2">
            @foreach ($postImportInputs as $behavior)
                @foreach ($behavior['inputs'] as $input)
                    @php
                        $fieldName = "post_import_inputs[{$behavior['key']}][{$input['key']}]";
                        $oldKey = "post_import_inputs.{$behavior['key']}.{$input['key']}";
                        $fieldId = 'post-import-input-'.str_replace('_', '-', $behavior['key']).'-'.str_replace('_', '-', $input['key']);
                    @endphp

                    <div>
                        <label
                            for="{{ $fieldId }}"
                            class="block text-sm font-medium text-slate-800"
                        >
                            {{ $input['label'] }}
                            @if (($input['required'] ?? false) === true)
                                <span class="text-rose-600">*</span>
                            @endif
                        </label>

                        <input
                            id="{{ $fieldId }}"
                            type="{{ $input['type'] }}"
                            name="{{ $fieldName }}"
                            value="{{ old($oldKey) }}"
                            @if (($input['required'] ?? false) === true) required @endif
                            class="mt-2 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-slate-500 focus:outline-none focus:ring-1 focus:ring-slate-500"
                        >

                        @if (! empty($input['description']))
                            <p class="mt-2 text-xs text-slate-600">
                                {{ $input['description'] }}
                            </p>
                        @endif

                        @error($oldKey)
                            <p class="mt-2 text-xs font-medium text-rose-700">
                                {{ $message }}
                            </p>
                        @enderror
                    </div>
                @endforeach
            @endforeach
        </div>
    </div>
@endif