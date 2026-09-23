<section
    data-reporting-health
    data-reporting-health-status="{{ $collectionHealth['overall_status'] }}"
    class="rounded-3xl border border-slate-200 bg-white/90 shadow-sm"
>
    <div class="border-b border-slate-100 p-5 sm:p-8">
        <h2 class="text-xl font-semibold tracking-tight text-slate-950">Measurement health</h2>
        <p class="mt-1 max-w-3xl text-sm leading-6 text-slate-700">
            Server-observable checks for browser collection, attribution, projection freshness, and optional Meta Pixel configuration.
        </p>
    </div>

    <div class="grid gap-3 p-5 sm:grid-cols-2 sm:p-8 xl:grid-cols-4">
        @foreach($collectionHealth['cards'] as $card)
            <article
                data-reporting-health-card="{{ $card['key'] }}"
                data-reporting-health-tone="{{ $card['tone'] }}"
                class="rounded-2xl border border-slate-200 bg-slate-50 p-4"
            >
                <div class="text-xs font-bold uppercase tracking-wide text-slate-500">{{ $card['label'] }}</div>
                <div class="mt-2 text-xl font-bold text-slate-950">{{ $card['value'] }}</div>
                <p class="mt-2 text-xs leading-5 text-slate-600">{{ $card['detail'] }}</p>
            </article>
        @endforeach
    </div>
</section>