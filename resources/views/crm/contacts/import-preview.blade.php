<x-layouts.crm
    :title="'Map CSV Fields'"
    :heading="'Map CSV Fields'"
    :subheading="'Choose which CSV columns map to primary contact fields'"
>
    <div class="max-w-6xl space-y-6">
        <x-ui.card class="space-y-6">
            <div>
                <h2 class="text-lg font-semibold tracking-tight">
                    Contact Fields
                </h2>

                <p class="mt-1 text-sm text-slate-500">
                    Select the CSV column for each contact field. Email is required for import.
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

                @php
                    $fields = [
                        'first_name' => 'First Name',
                        'last_name' => 'Last Name',
                        'name' => 'Full Name',
                        'email' => 'Email',
                        'phone' => 'Phone',
                        'source' => 'Source',
                        'subsource' => 'Subsource',
                        'last_contacted_at' => 'Last Contacted At',
                        'last_activity_at' => 'Last Activity At',
                    ];
                @endphp

                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($fields as $field => $label)
                        <div>
                            <x-ui.form.label for="mapping_{{ $field }}">
                                {{ $label }}

                                @if ($field === 'email')
                                    <span class="text-red-600">*</span>
                                @endif
                            </x-ui.form.label>

                            <select
                                id="mapping_{{ $field }}"
                                name="mapping[{{ $field }}]"
                                @required($field === 'email')
                                class="mt-1 block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                            >
                                <option value="">Do not import</option>

                                @foreach ($headers as $header)
                                    <option
                                        value="{{ $header }}"
                                        @selected(old("mapping.$field") === $header)
                                    >
                                        {{ $header }}
                                    </option>
                                @endforeach
                            </select>

                            @error("mapping.$field")
                                <p class="mt-1 text-sm text-red-600">
                                    {{ $message }}
                                </p>
                            @enderror
                        </div>
                    @endforeach
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