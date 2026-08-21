<x-layouts.crm
    :title="'Import '.config('contacts.labels.plural')"
    :heading="'Import '.config('contacts.labels.plural')"
    :subheading="'Choose whether this file adds new contacts or updates contacts already in the CRM'"
>
    <div class="max-w-3xl space-y-6">
        <x-ui.card class="space-y-6">
            <form
                method="POST"
                action="{{ route('crm.contacts.import.preview') }}"
                enctype="multipart/form-data"
                class="space-y-6"
            >
                @csrf

                <div class="space-y-3">
                    <div>
                        <h2 class="text-base font-semibold tracking-tight text-slate-950">
                            What should this import do?
                        </h2>

                        <p class="mt-1 text-sm text-slate-500">
                            Add and update imports use the same mapping tools, but they have different safety rules.
                        </p>
                    </div>

                    <div class="grid gap-3 md:grid-cols-2">
                        <label class="cursor-pointer rounded-xl border border-slate-200 p-4 transition hover:border-slate-300 has-[:checked]:border-slate-900 has-[:checked]:bg-slate-50">
                            <div class="flex items-start gap-3">
                                <input
                                    type="radio"
                                    name="mode"
                                    value="add"
                                    class="mt-1"
                                    @checked(old('mode', 'add') === 'add')
                                >

                                <span>
                                    <span class="block font-semibold text-slate-950">
                                        Add Contacts via Import
                                    </span>

                                    <span class="mt-1 block text-sm leading-6 text-slate-600">
                                        Use this for new lists and audiences. New contacts can be created, and exact email matches follow the normal import update rules.
                                    </span>
                                </span>
                            </div>
                        </label>

                        <label class="cursor-pointer rounded-xl border border-slate-200 p-4 transition hover:border-slate-300 has-[:checked]:border-slate-900 has-[:checked]:bg-slate-50">
                            <div class="flex items-start gap-3">
                                <input
                                    type="radio"
                                    name="mode"
                                    value="update"
                                    class="mt-1"
                                    @checked(old('mode') === 'update')
                                >

                                <span>
                                    <span class="block font-semibold text-slate-950">
                                        Update Contacts via Import
                                    </span>

                                    <span class="mt-1 block text-sm leading-6 text-slate-600">
                                        Match existing contacts by exact email and enrich them from the file. Missing contacts are skipped, and profile defaults or automatic post-import launches are not applied.
                                    </span>
                                </span>
                            </div>
                        </label>
                    </div>

                    @error('mode')
                        <p class="text-sm text-red-600">
                            {{ $message }}
                        </p>
                    @enderror
                </div>

                <div class="border-t border-slate-200 pt-6">
                    <x-ui.form.label for="csv">
                        CSV File
                    </x-ui.form.label>

                    <x-ui.form.input
                        id="csv"
                        name="csv"
                        type="file"
                        accept=".csv,text/csv,.txt,text/plain"
                        required
                    />

                    <p class="mt-2 text-xs leading-5 text-slate-500">
                        CSV/TXT only. You will review field mapping and any explicit treatment before anything is imported.
                    </p>

                    @error('csv')
                        <p class="mt-1 text-sm text-red-600">
                            {{ $message }}
                        </p>
                    @enderror
                </div>

                <div class="flex flex-wrap items-center gap-3 border-t border-slate-200 pt-6">
                    <x-ui.button type="submit">
                        Review Import
                    </x-ui.button>

                    <a
                        href="{{ route('crm.contacts.index') }}"
                        class="text-sm font-semibold text-slate-600 hover:underline"
                    >
                        Cancel
                    </a>
                </div>
            </form>
        </x-ui.card>
    </div>
</x-layouts.crm>