@if (! empty($postImportInputs))
    <div class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-4">
        <div>
            <h3 class="text-base font-semibold tracking-tight text-slate-900">
                Import options
            </h3>
            <p class="mt-1 text-xs text-slate-600">
                Confirm any batch-wide choices before the import begins.
            </p>
        </div>

        <div class="mt-4 space-y-5">
            @foreach ($postImportInputs as $behavior)
                @php
                    $behaviorOld = old("post_import_inputs.{$behavior['key']}", []);
                    $behaviorOld = is_array($behaviorOld) ? $behaviorOld : [];
                @endphp

                <div
                    class="rounded-xl border border-slate-200 bg-white p-4"
                    x-data="{ values: @js($behaviorOld) }"
                >
                    <h4 class="text-sm font-semibold text-slate-900">
                        {{ $behavior['label'] }}
                    </h4>

                    <div class="mt-4 grid gap-4 md:grid-cols-2">
                        @foreach ($behavior['inputs'] as $input)
                            @php
                                $fieldName = "post_import_inputs[{$behavior['key']}][{$input['key']}]";
                                $oldKey = "post_import_inputs.{$behavior['key']}.{$input['key']}";
                                $fieldId = 'post-import-input-'.str_replace('_', '-', $behavior['key']).'-'.str_replace('_', '-', $input['key']);
                                $type = $input['type'] ?? 'text';
                                $showWhen = is_array($input['show_when'] ?? null) ? $input['show_when'] : null;
                                $showField = is_string($showWhen['field'] ?? null) ? $showWhen['field'] : null;
                                $showEquals = $showWhen['equals'] ?? null;
                            @endphp

                            <div
                                @if ($showField !== null)
                                    x-show="values[@js($showField)] === @js($showEquals)"
                                    x-cloak
                                @endif
                                class="{{ ($input['full_width'] ?? false) ? 'md:col-span-2' : '' }}"
                            >
                                @if ($type === 'select')
                                    <label for="{{ $fieldId }}" class="block text-sm font-medium text-slate-800">
                                        {{ $input['label'] }}
                                        @if (($input['required'] ?? false) === true)
                                            <span class="text-rose-600">*</span>
                                        @endif
                                    </label>

                                    <select
                                        id="{{ $fieldId }}"
                                        name="{{ $fieldName }}"
                                        x-model="values[@js($input['key'])]"
                                        @if (($input['required'] ?? false) === true) required @endif
                                        class="mt-2 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-slate-500 focus:outline-none focus:ring-1 focus:ring-slate-500"
                                    >
                                        <option value="">Choose one</option>
                                        @foreach (($input['options'] ?? []) as $option)
                                            <option
                                                value="{{ $option['value'] }}"
                                                @selected(old($oldKey) === $option['value'])
                                            >
                                                {{ $option['label'] }}
                                            </option>
                                        @endforeach
                                    </select>
                                @elseif ($type === 'checkbox_group')
                                    <fieldset>
                                        <legend class="block text-sm font-medium text-slate-800">
                                            {{ $input['label'] }}
                                            @if (($input['required'] ?? false) === true)
                                                <span class="text-rose-600">*</span>
                                            @endif
                                        </legend>

                                        @php
                                            $selectedValues = old($oldKey, []);
                                            $selectedValues = is_array($selectedValues) ? $selectedValues : [];
                                        @endphp

                                        <div class="mt-2 space-y-2">
                                            @foreach (($input['options'] ?? []) as $option)
                                                <label class="flex items-start gap-3 rounded-lg border border-slate-200 px-3 py-2">
                                                    <input
                                                        type="checkbox"
                                                        name="{{ $fieldName }}[]"
                                                        value="{{ $option['value'] }}"
                                                        @checked(in_array($option['value'], $selectedValues, true))
                                                        class="mt-1 rounded border-slate-300 text-slate-900 focus:ring-slate-500"
                                                    >
                                                    <span class="text-sm text-slate-700">{{ $option['label'] }}</span>
                                                </label>
                                            @endforeach
                                        </div>
                                    </fieldset>
                                @elseif ($type === 'checkbox')
                                    <label class="flex items-start gap-3 rounded-lg border border-slate-200 px-3 py-3">
                                        <input
                                            id="{{ $fieldId }}"
                                            type="checkbox"
                                            name="{{ $fieldName }}"
                                            value="1"
                                            @checked((bool) old($oldKey, false))
                                            class="mt-1 rounded border-slate-300 text-slate-900 focus:ring-slate-500"
                                        >
                                        <span>
                                            <span class="block text-sm font-medium text-slate-800">
                                                {{ $input['label'] }}
                                                @if (($input['required'] ?? false) === true)
                                                    <span class="text-rose-600">*</span>
                                                @endif
                                            </span>
                                            @if (! empty($input['description']))
                                                <span class="mt-1 block text-xs text-slate-600">
                                                    {{ $input['description'] }}
                                                </span>
                                            @endif
                                        </span>
                                    </label>
                                @else
                                    <label for="{{ $fieldId }}" class="block text-sm font-medium text-slate-800">
                                        {{ $input['label'] }}
                                        @if (($input['required'] ?? false) === true)
                                            <span class="text-rose-600">*</span>
                                        @endif
                                    </label>

                                    <input
                                        id="{{ $fieldId }}"
                                        type="{{ $type }}"
                                        name="{{ $fieldName }}"
                                        value="{{ old($oldKey) }}"
                                        @if (($input['required'] ?? false) === true) required @endif
                                        class="mt-2 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-slate-500 focus:outline-none focus:ring-1 focus:ring-slate-500"
                                    >
                                @endif

                                @if ($type !== 'checkbox' && ! empty($input['description']))
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
                    </div>
                </div>
            @endforeach
        </div>
    </div>
@endif