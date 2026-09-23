<x-layouts.crm
    :title="$title"
    :heading="$heading"
    :subheading="$subheading"
    module="reporting"
>
    <div class="w-full max-w-5xl space-y-6">
        <section class="grid gap-3 md:grid-cols-3">
            <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <div class="text-xs font-bold uppercase tracking-wide text-slate-500">1 · Export</div>
                <div class="mt-2 font-semibold text-slate-950">Use the raw Meta Ads CSV</div>
                <p class="mt-2 text-sm leading-6 text-slate-600">Keep Meta’s original headers and reporting dates intact. Do not rename or clean columns first.</p>
            </div>
            <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <div class="text-xs font-bold uppercase tracking-wide text-slate-500">2 · Review</div>
                <div class="mt-2 font-semibold text-slate-950">Check identity and reporting scope</div>
                <p class="mt-2 text-sm leading-6 text-slate-600">Reporting previews the recognized period, spend/click metrics, identity quality, warnings, and skipped rows before anything is stored.</p>
            </div>
            <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <div class="text-xs font-bold uppercase tracking-wide text-slate-500">3 · Import</div>
                <div class="mt-2 font-semibold text-slate-950">Add external measurement evidence</div>
                <p class="mt-2 text-sm leading-6 text-slate-600">Imported rows remain ad-platform measurements that Reporting can compare with first-party traffic and conversion evidence.</p>
            </div>
        </section>

        <section class="rounded-3xl border border-slate-200 bg-white/90 p-5 shadow-sm sm:p-8">
            <div>
                <p class="text-sm font-semibold uppercase tracking-[0.16em] text-slate-600">Meta Ads · CSV import</p>
                <h2 class="mt-2 text-xl font-semibold tracking-tight text-slate-950">Upload the Ads Manager export</h2>
                <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-700">
                    The parser accepts Meta reporting dates plus campaign, ad-set/group, or ad identity. Spend, impressions, reach, link clicks, outbound clicks, landing-page views, delivery status, and attribution settings are retained when present.
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
                    <p class="mt-1 text-xs leading-5 text-slate-500">Maximum 10 MB and 5,000 data rows. Reporting starts, Day, or Date must be present.</p>
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
                        <p class="mt-1 text-xs leading-5 text-slate-500">Use this only when the CSV does not already identify the ad account.</p>
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

        <section class="grid gap-4 lg:grid-cols-2">
            <div class="rounded-2xl border border-slate-200 bg-slate-50 p-5 text-sm leading-6 text-slate-700">
                <h2 class="font-semibold text-slate-950">Stable IDs vs. name fallback</h2>
                <p class="mt-1">
                    Campaign, ad-set/group, and ad IDs provide the strongest external identity. Rows without those IDs can still use names as historical fallback identity, but Reporting will not claim an exact automatic match to first-party traffic.
                </p>
            </div>
            <div class="rounded-2xl border border-slate-200 bg-slate-50 p-5 text-sm leading-6 text-slate-700">
                <h2 class="font-semibold text-slate-950">What this import does not do</h2>
                <p class="mt-1">
                    Importing a Meta report does not replace first-party browser observations, infer missing attribution, or prove that an ad-platform result and a CRM conversion belong to the same person.
                </p>
            </div>
        </section>
    </div>
</x-layouts.crm>