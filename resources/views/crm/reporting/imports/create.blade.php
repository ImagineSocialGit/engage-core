<x-layouts.crm
    :title="$title"
    :heading="$heading"
    :subheading="$subheading"
    module="reporting"
>
    <div class="w-full max-w-3xl space-y-6">
        <section class="rounded-3xl border border-slate-200 bg-white/90 p-5 shadow-sm sm:p-8">
            <div>
                <p class="text-sm font-semibold uppercase tracking-[0.16em] text-slate-600">Meta Ads · CSV first</p>
                <h2 class="mt-2 text-xl font-semibold tracking-tight text-slate-950">Upload the raw Ads Manager export</h2>
                <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-700">
                    Reporting recognizes Meta’s exported column names automatically. Do not rename or clean the spreadsheet before uploading it.
                </p>
            </div>

            <form
                method="POST"
                action="{{ route('crm.reporting.imports.preview') }}"
                enctype="multipart/form-data"
                class="mt-6 space-y-5"
            >
                @csrf

                <div>
                    <x-ui.form.label for="csv">Meta Ads CSV</x-ui.form.label>
                    <x-ui.form.input
                        id="csv"
                        name="csv"
                        type="file"
                        accept=".csv,text/csv"
                        required
                    />
                    @error('csv')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div class="grid gap-5 sm:grid-cols-2">
                    <div>
                        <x-ui.form.label for="account_id">Ad account ID <span class="font-normal text-slate-500">(optional)</span></x-ui.form.label>
                        <x-ui.form.input
                            id="account_id"
                            name="account_id"
                            type="text"
                            :value="old('account_id')"
                            placeholder="If the export does not include it"
                        />
                        @error('account_id')
                            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <x-ui.form.label for="account_timezone">Ad account timezone <span class="font-normal text-slate-500">(optional)</span></x-ui.form.label>
                        <x-ui.form.input
                            id="account_timezone"
                            name="account_timezone"
                            type="text"
                            :value="old('account_timezone')"
                            :placeholder="$defaultTimezone"
                        />
                        <p class="mt-1 text-xs leading-5 text-slate-500">Leave blank if unknown. IANA timezone preferred; Eastern/Central/Mountain/Pacific Time are also accepted.</p>
                        @error('account_timezone')
                            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>
                </div>

                <div class="grid gap-3 border-t border-slate-200 pt-5 sm:flex sm:flex-wrap sm:items-center">
                    <x-ui.button type="submit" class="w-full sm:w-auto">Review import</x-ui.button>
                    <a href="{{ route('crm.reporting.index') }}" class="text-center text-sm font-semibold text-slate-600 hover:underline sm:text-left">Back to Reporting</a>
                </div>
            </form>
        </section>

        <section class="rounded-2xl border border-slate-200 bg-slate-50 p-5 text-sm leading-6 text-slate-700">
            <h2 class="font-semibold text-slate-950">What happens if Meta omitted IDs?</h2>
            <p class="mt-1">
                The report can still be imported using the ad/ad-set names as fallback identity. Reporting will label those rows as historical/name-based data and will not claim an exact automatic match to Engage traffic.
            </p>
        </section>
    </div>
</x-layouts.crm>