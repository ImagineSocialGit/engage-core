<x-layouts.crm
    :title="'Map CSV Fields'"
    :heading="'Map CSV Fields'"
    :subheading="'Choose which CSV columns map to import fields'"
>
    <div
        class="max-w-6xl space-y-6"
        x-data="{
            rows: @js($rows),
            importStatusColumn: @js(old('mapping.import_status')),
            statusValues() {
                if (! this.importStatusColumn) {
                    return [];
                }

                const values = [];

                this.rows.forEach((row) => {
                    const value = String(row[this.importStatusColumn] || '').trim();

                    if (value && ! values.includes(value)) {
                        values.push(value);
                    }
                });

                return values.sort();
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
            </div>

            <form
                method="POST"
                action="{{ route('crm.contacts.import.process') }}"
                class="space-y-6"
            >
                @csrf

                @foreach ($headers as $header)
                    <input
                        type="hidden"
                        name="headers[]"
                        value="{{ $header }}"
                    >
                @endforeach

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
                                        @if ($field->key === 'import_status')
                                            x-model="importStatusColumn"
                                        @endif
                                        @required($field->required)
                                        class="mt-1 block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                    >
                                        <option value="">Do not import</option>

                                        @foreach ($headers as $header)
                                            <option
                                                value="{{ $header }}"
                                                @selected(old("mapping.{$field->key}") === $header)
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

                <div
                    x-show="statusValues().length > 0"
                    class="space-y-4 border-t border-slate-200 pt-6"
                >
                    <div>
                        <h3 class="text-base font-semibold tracking-tight">
                            Status Mapping
                        </h3>

                        <p class="mt-1 text-sm text-slate-500">
                            Map imported status values to active CRM statuses. Leave values unmapped when they need review.
                        </p>
                    </div>

                    @error('status_mapping')
                        <p class="text-sm text-red-600">
                            {{ $message }}
                        </p>
                    @enderror

                    <div class="overflow-hidden rounded-xl border border-slate-200">
                        <table class="min-w-full divide-y divide-slate-200 text-sm">
                            <thead class="bg-slate-50">
                                <tr>
                                    <th class="px-4 py-3 text-left font-semibold text-slate-700">
                                        Imported Status
                                    </th>
                                    <th class="px-4 py-3 text-left font-semibold text-slate-700">
                                        CRM Status
                                    </th>
                                </tr>
                            </thead>

                            <tbody class="divide-y divide-slate-200 bg-white">
                                <template x-for="value in statusValues()" :key="value">
                                    <tr>
                                        <td class="px-4 py-3 font-medium text-slate-900" x-text="value"></td>

                                        <td class="px-4 py-3">
                                            <select
                                                x-bind:name="`status_mapping[${value}]`"
                                                class="block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                            >
                                                <option value="">Leave unmapped / review later</option>

                                                @foreach ($contactStatuses as $status)
                                                    <option value="{{ $status->id }}">
                                                        {{ $status->name }}
                                                    </option>
                                                @endforeach
                                            </select>
                                        </td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>
                </div>

                @if (! empty($rows))
                    <div class="space-y-3 border-t border-slate-200 pt-6">
                        <div>
                            <h3 class="text-base font-semibold tracking-tight">
                                CSV Preview
                            </h3>

                            <p class="mt-1 text-sm text-slate-500">
                                Showing the first {{ count($rows) }} rows.
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