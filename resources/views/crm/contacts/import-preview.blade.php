<x-layouts.crm
    :title="'Map CSV Fields'"
    :heading="'Map CSV Fields'"
    :subheading="'Review source fields, then choose how imported rows should be treated'"
>
    <div
        class="max-w-6xl space-y-6"
        x-data="{
            columnProfiles: @js($columnProfiles),
            valuesFor(column) {
                return this.columnProfiles[column]?.values || [];
            },
            blankCount(column) {
                return this.columnProfiles[column]?.blank_count || 0;
            },
            isTruncated(column) {
                return Boolean(this.columnProfiles[column]?.truncated);
            },
            otherCount(column) {
                return this.columnProfiles[column]?.other_count || 0;
            },
        }"
    >
        <x-ui.card class="space-y-6">
            <div>
                <h2 class="text-lg font-semibold tracking-tight">
                    Import Fields
                </h2>

                <p class="mt-1 text-sm text-slate-500">
                    Select the CSV column for each field. Required fields are marked.
                </p>

                @if ($importProfile)
                    <div class="mt-4 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3">
                        <p class="text-sm font-semibold text-slate-900">
                            Import profile: {{ $importProfile->label }}
                        </p>

                        @if ($importProfile->description)
                            <p class="mt-1 text-xs text-slate-600">
                                {{ $importProfile->description }}
                            </p>
                        @endif

                        <p class="mt-1 text-xs text-slate-500">
                            Known columns were preselected where recognized. Review the mappings below before importing.
                        </p>
                    </div>
                @endif
            </div>

            <form
                method="POST"
                action="{{ route('crm.contacts.import.process') }}"
                class="space-y-6"
            >
                @csrf

                <input
                    type="hidden"
                    name="csv_path"
                    value="{{ $csvPath }}"
                >

                @foreach ($importSections as $section)
                    <div class="@if (! $loop->first) border-t border-slate-200 pt-6 @endif space-y-4">
                        <div>
                            <h3 class="text-base font-semibold tracking-tight">
                                {{ $section['label'] }}
                            </h3>
                        </div>

                        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                            @foreach ($section['fields'] as $field)
                                <div>
                                    <x-ui.form.label for="mapping_{{ $field->key }}">
                                        {{ $field->label }}

                                        @if ($field->required)
                                            <span class="text-red-600">*</span>
                                        @endif
                                    </x-ui.form.label>

                                    @if ($field->description)
                                        <p class="mt-1 text-xs text-slate-500">
                                            {{ $field->description }}
                                        </p>
                                    @endif

                                    <select
                                        id="mapping_{{ $field->key }}"
                                        name="mapping[{{ $field->key }}]"
                                        @required($field->required)
                                        class="mt-1 block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                    >
                                        <option value="">Do not import</option>

                                        @foreach ($headers as $header)
                                            <option
                                                value="{{ $header }}"
                                                @selected(old("mapping.{$field->key}", $suggestedMapping[$field->key] ?? null) === $header)
                                            >
                                                {{ $header }}
                                            </option>
                                        @endforeach
                                    </select>

                                    @error("mapping.{$field->key}")
                                        <p class="mt-1 text-sm text-red-600">
                                            {{ $message }}
                                        </p>
                                    @enderror
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endforeach

                @if ($treatmentDefinitions->isNotEmpty())
                    <div class="space-y-5 border-t border-slate-200 pt-6">
                        <div>
                            <h3 class="text-base font-semibold tracking-tight">
                                Import Treatment
                            </h3>

                            <p class="mt-1 text-sm text-slate-500">
                                Decide what CRM treatment should be applied after identity and field mapping. An explicit treatment overrides the mapped/profile value for the treatment it controls, while the original CSV value remains in import provenance.
                            </p>
                        </div>

                        @error('treatments')
                            <p class="text-sm text-red-600">
                                {{ $message }}
                            </p>
                        @enderror

                        @foreach ($treatmentDefinitions->groupBy('section') as $section => $definitions)
                            <div class="space-y-3">
                                <h4 class="text-sm font-semibold uppercase tracking-wide text-slate-500">
                                    {{ $section }}
                                </h4>

                                <div class="grid gap-4 lg:grid-cols-2">
                                    @foreach ($definitions as $treatment)
                                        <div
                                            class="rounded-xl border border-slate-200 p-4"
                                            x-data="{
                                                targetKey: @js($treatment->key),
                                                mode: @js(old("treatments.{$treatment->key}.mode", 'none')),
                                                sourceColumn: @js(old("treatments.{$treatment->key}.source_column", '')),
                                            }"
                                        >
                                            <div>
                                                <p class="font-semibold text-slate-900">
                                                    {{ $treatment->label }}
                                                </p>

                                                @if ($treatment->description)
                                                    <p class="mt-1 text-xs text-slate-500">
                                                        {{ $treatment->description }}
                                                    </p>
                                                @endif
                                            </div>

                                            <div class="mt-4">
                                                <x-ui.form.label for="treatment_mode_{{ $treatment->key }}">
                                                    Treatment
                                                </x-ui.form.label>

                                                <select
                                                    id="treatment_mode_{{ $treatment->key }}"
                                                    name="treatments[{{ $treatment->key }}][mode]"
                                                    x-model="mode"
                                                    class="mt-1 block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                                >
                                                    <option value="none">Leave unchanged</option>
                                                    <option value="fixed">Apply fixed value(s) to all rows</option>
                                                    <option value="column">Apply based on a CSV field</option>
                                                </select>
                                            </div>

                                            <div x-show="mode === 'fixed'" x-cloak class="mt-4">
                                                <x-ui.form.label>
                                                    Apply to all imported rows
                                                </x-ui.form.label>

                                                @if ($treatment->allowCustom)
                                                    <input
                                                        type="text"
                                                        name="treatments[{{ $treatment->key }}][fixed_custom]"
                                                        value="{{ old("treatments.{$treatment->key}.fixed_custom") }}"
                                                        placeholder="{{ $treatment->multiple ? 'Enter values separated by commas' : 'Enter value' }}"
                                                        class="mt-1 block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                                    >

                                                    @if ($treatment->options !== [])
                                                        <p class="mt-1 text-xs text-slate-500">
                                                            Existing values include: {{ collect($treatment->options)->pluck('label')->take(8)->implode(', ') }}{{ count($treatment->options) > 8 ? '…' : '' }}
                                                        </p>
                                                    @endif
                                                @elseif ($treatment->multiple)
                                                    <select
                                                        name="treatments[{{ $treatment->key }}][fixed_values][]"
                                                        multiple
                                                        size="{{ min(max(count($treatment->options), 3), 8) }}"
                                                        class="mt-1 block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                                    >
                                                        @foreach ($treatment->options as $option)
                                                            <option value="{{ $option['value'] }}">
                                                                {{ $option['label'] }}
                                                            </option>
                                                        @endforeach
                                                    </select>
                                                @else
                                                    <select
                                                        name="treatments[{{ $treatment->key }}][fixed_values][]"
                                                        class="mt-1 block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                                    >
                                                        <option value="">Choose value</option>

                                                        @foreach ($treatment->options as $option)
                                                            <option value="{{ $option['value'] }}">
                                                                {{ $option['label'] }}
                                                            </option>
                                                        @endforeach
                                                    </select>
                                                @endif
                                            </div>

                                            <div x-show="mode === 'column'" x-cloak class="mt-4 space-y-4">
                                                <div>
                                                    <x-ui.form.label>
                                                        Source CSV field
                                                    </x-ui.form.label>

                                                    <select
                                                        name="treatments[{{ $treatment->key }}][source_column]"
                                                        x-model="sourceColumn"
                                                        class="mt-1 block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                                    >
                                                        <option value="">Choose CSV field</option>

                                                        @foreach ($headers as $header)
                                                            <option value="{{ $header }}">
                                                                {{ $header }}
                                                            </option>
                                                        @endforeach
                                                    </select>
                                                </div>

                                                <div x-show="sourceColumn && valuesFor(sourceColumn).length > 0" class="space-y-2">
                                                    <div class="flex flex-wrap gap-x-4 gap-y-1 text-xs text-slate-500">
                                                        <span>
                                                            <strong class="font-semibold text-slate-700" x-text="valuesFor(sourceColumn).length"></strong>
                                                            source values shown
                                                        </span>
                                                        <span x-show="blankCount(sourceColumn) > 0">
                                                            <strong class="font-semibold text-slate-700" x-text="blankCount(sourceColumn)"></strong>
                                                            blank rows
                                                        </span>
                                                        <span x-show="isTruncated(sourceColumn)" class="text-amber-700">
                                                            More than 100 distinct values; additional values remain unchanged.
                                                        </span>
                                                    </div>

                                                    <div class="max-h-96 overflow-auto rounded-xl border border-slate-200">
                                                        <table class="min-w-full divide-y divide-slate-200 text-sm">
                                                            <thead class="sticky top-0 bg-slate-50">
                                                                <tr>
                                                                    <th class="px-3 py-2 text-left font-semibold text-slate-700">
                                                                        CSV value
                                                                    </th>
                                                                    <th class="px-3 py-2 text-right font-semibold text-slate-700">
                                                                        Rows
                                                                    </th>
                                                                    <th class="px-3 py-2 text-left font-semibold text-slate-700">
                                                                        Apply
                                                                    </th>
                                                                </tr>
                                                            </thead>

                                                            <tbody class="divide-y divide-slate-200 bg-white">
                                                                <template x-for="item in valuesFor(sourceColumn)" :key="item.token">
                                                                    <tr>
                                                                        <td class="px-3 py-2 font-medium text-slate-900">
                                                                            <span x-text="item.value"></span>
                                                                            <input
                                                                                type="hidden"
                                                                                x-bind:name="`treatments[${targetKey}][value_map][${item.token}][source]`"
                                                                                x-bind:value="item.value"
                                                                            >
                                                                        </td>
                                                                        <td class="px-3 py-2 text-right text-slate-500" x-text="item.count"></td>
                                                                        <td class="min-w-56 px-3 py-2">
                                                                            @if ($treatment->allowCustom)
                                                                                <input
                                                                                    type="text"
                                                                                    x-bind:name="`treatments[${targetKey}][value_map][${item.token}][custom]`"
                                                                                    placeholder="{{ $treatment->multiple ? 'Comma-separated values; blank = unchanged' : 'Value; blank = unchanged' }}"
                                                                                    class="block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                                                                >
                                                                            @elseif ($treatment->multiple)
                                                                                <select
                                                                                    multiple
                                                                                    x-bind:name="`treatments[${targetKey}][value_map][${item.token}][values][]`"
                                                                                    class="block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                                                                >
                                                                                    @foreach ($treatment->options as $option)
                                                                                        <option value="{{ $option['value'] }}">
                                                                                            {{ $option['label'] }}
                                                                                        </option>
                                                                                    @endforeach
                                                                                </select>
                                                                            @else
                                                                                <select
                                                                                    x-bind:name="`treatments[${targetKey}][value_map][${item.token}][values][]`"
                                                                                    class="block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                                                                >
                                                                                    <option value="">Leave unchanged</option>

                                                                                    @foreach ($treatment->options as $option)
                                                                                        <option value="{{ $option['value'] }}">
                                                                                            {{ $option['label'] }}
                                                                                        </option>
                                                                                    @endforeach
                                                                                </select>
                                                                            @endif
                                                                        </td>
                                                                    </tr>
                                                                </template>
                                                            </tbody>
                                                        </table>
                                                    </div>
                                                </div>

                                                <p
                                                    x-show="sourceColumn && valuesFor(sourceColumn).length === 0"
                                                    class="text-xs text-slate-500"
                                                >
                                                    No nonblank values were found in this column.
                                                </p>
                                            </div>

                                            @error("treatments.{$treatment->key}")
                                                <p class="mt-2 text-sm text-red-600">
                                                    {{ $message }}
                                                </p>
                                            @enderror
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif

                @if (! empty($rows))
                    <div class="space-y-3 border-t border-slate-200 pt-6">
                        <div>
                            <h3 class="text-base font-semibold tracking-tight">
                                CSV Preview
                            </h3>

                            <p class="mt-1 text-sm text-slate-500">
                                Showing the first {{ count($rows) }} rows. Treatment value counts above are calculated from the staged CSV, not only these preview rows.
                            </p>
                        </div>

                        <div class="overflow-x-auto rounded-xl border border-slate-200">
                            <table class="min-w-full divide-y divide-slate-200 text-sm">
                                <thead class="bg-slate-50">
                                    <tr>
                                        @foreach ($headers as $header)
                                            <th class="whitespace-nowrap px-4 py-3 text-left font-semibold text-slate-700">
                                                {{ $header }}
                                            </th>
                                        @endforeach
                                    </tr>
                                </thead>

                                <tbody class="divide-y divide-slate-200 bg-white">
                                    @foreach ($rows as $row)
                                        <tr>
                                            @foreach ($headers as $header)
                                                <td class="whitespace-nowrap px-4 py-3 text-slate-600">
                                                    {{ $row[$header] ?? '—' }}
                                                </td>
                                            @endforeach
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                @endif

                <div class="flex items-center gap-3 border-t border-slate-200 pt-6">
                    <x-ui.button type="submit">
                        Continue Import
                    </x-ui.button>

                    <a
                        href="{{ route('crm.contacts.import') }}"
                        class="text-sm font-semibold text-slate-600 hover:underline"
                    >
                        Upload a different CSV
                    </a>
                </div>
            </form>
        </x-ui.card>
    </div>
</x-layouts.crm>