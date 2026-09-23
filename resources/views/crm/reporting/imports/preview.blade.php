<x-layouts.crm
    :title="$title"
    :heading="$heading"
    :subheading="$subheading"
    module="reporting"
>
    <div class="w-full max-w-6xl space-y-6">
        <section class="rounded-3xl border border-slate-200 bg-white/90 shadow-sm">
            <div class="border-b border-slate-100 p-5 sm:p-8">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <h2 class="text-xl font-semibold tracking-tight text-slate-950">Import readiness</h2>
                        <p class="mt-1 text-sm leading-6 text-slate-700">Nothing has been stored yet. Review the recognized scope and identity quality before importing.</p>
                    </div>
                    <a href="{{ route('crm.reporting.imports.create') }}" class="text-sm font-semibold text-slate-600 hover:underline">Upload a different CSV</a>
                </div>
            </div>

            <div class="grid gap-4 p-5 sm:grid-cols-2 sm:p-8 lg:grid-cols-4">
                <div class="rounded-2xl bg-slate-50 p-4 ring-1 ring-slate-200">
                    <div class="text-xs font-bold uppercase tracking-wide text-slate-500">Importable rows</div>
                    <div class="mt-1 text-2xl font-bold text-slate-950">{{ number_format((int) $preview['valid_count']) }}</div>
                </div>
                <div class="rounded-2xl bg-slate-50 p-4 ring-1 ring-slate-200">
                    <div class="text-xs font-bold uppercase tracking-wide text-slate-500">Stable-ID rows</div>
                    <div class="mt-1 text-2xl font-bold text-slate-950">{{ number_format((int) $preview['identity_counts']['stable_ids']) }}</div>
                </div>
                <div class="rounded-2xl bg-slate-50 p-4 ring-1 ring-slate-200">
                    <div class="text-xs font-bold uppercase tracking-wide text-slate-500">Name fallback</div>
                    <div class="mt-1 text-2xl font-bold text-slate-950">{{ number_format((int) $preview['identity_counts']['name_fallback']) }}</div>
                </div>
                <div class="rounded-2xl bg-slate-50 p-4 ring-1 ring-slate-200">
                    <div class="text-xs font-bold uppercase tracking-wide text-slate-500">Skipped</div>
                    <div class="mt-1 text-2xl font-bold text-slate-950">{{ number_format((int) $preview['skipped_count']) }}</div>
                </div>
            </div>

            @if($preview['warnings'] !== [])
                <div class="space-y-2 border-t border-slate-100 p-5 sm:p-8">
                    <div class="text-xs font-bold uppercase tracking-wide text-amber-800">Review before importing</div>
                    @foreach($preview['warnings'] as $warning)
                        <div class="rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm leading-6 text-amber-950">{{ $warning }}</div>
                    @endforeach
                </div>
            @endif
        </section>

        <section class="rounded-3xl border border-slate-200 bg-white/90 shadow-sm">
            <div class="border-b border-slate-100 p-5 sm:p-8">
                <h2 class="text-xl font-semibold tracking-tight text-slate-950">Detected reporting scope</h2>
                <div class="mt-3 grid min-w-0 gap-2 text-sm text-slate-700 sm:grid-cols-2">
                    <div class="min-w-0 break-words"><span class="font-semibold text-slate-950">Period:</span> {{ implode(', ', $preview['periods']) }}</div>
                    <div class="min-w-0 break-words"><span class="font-semibold text-slate-950">Currency:</span> {{ $preview['currencies'] !== [] ? implode(', ', $preview['currencies']) : 'Not supplied' }}</div>
                    <div class="min-w-0 break-all"><span class="font-semibold text-slate-950">Account ID:</span> {{ filled($accountId) ? $accountId : 'Not supplied' }}</div>
                    <div class="min-w-0 break-words"><span class="font-semibold text-slate-950">Account timezone:</span> {{ filled($accountTimezone) ? $accountTimezone : 'Not supplied' }}</div>
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-[66rem] divide-y divide-slate-200 text-sm">
                    <thead class="bg-slate-50 text-left text-xs font-bold uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-5 py-3">Ad / creative</th>
                            <th class="px-5 py-3">Ad set / group</th>
                            <th class="px-5 py-3">Identity</th>
                            <th class="px-5 py-3 text-right">Spend</th>
                            <th class="px-5 py-3 text-right">Impressions</th>
                            <th class="px-5 py-3 text-right">Link clicks</th>
                            <th class="px-5 py-3 text-right">Landing views</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 bg-white">
                        @foreach($preview['preview_rows'] as $row)
                            <tr>
                                <td class="px-5 py-4 font-medium text-slate-950">{{ $row['creative'] ?? '—' }}</td>
                                <td class="px-5 py-4 text-slate-700">{{ $row['group'] ?? '—' }}</td>
                                <td class="px-5 py-4 text-slate-700">{{ $row['identity_quality'] === 'stable_ids' ? 'Stable IDs' : 'Name fallback' }}</td>
                                <td class="px-5 py-4 text-right text-slate-700">{{ $row['spend'] !== null ? ($row['currency'] ? $row['currency'].' ' : '').number_format((float) $row['spend'], 2) : '—' }}</td>
                                <td class="px-5 py-4 text-right text-slate-700">{{ $row['impressions'] !== null ? number_format((int) $row['impressions']) : '—' }}</td>
                                <td class="px-5 py-4 text-right text-slate-700">{{ $row['link_clicks'] !== null ? number_format((int) $row['link_clicks']) : '—' }}</td>
                                <td class="px-5 py-4 text-right text-slate-700">{{ $row['landing_page_views'] !== null ? number_format((int) $row['landing_page_views']) : '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="border-t border-slate-100 p-5 sm:p-8">
                <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4 text-sm leading-6 text-slate-700">
                    Importing these rows adds external ad-platform measurement evidence. Stable IDs improve later reconciliation; name-fallback rows remain useful historical measurements without being treated as exact first-party matches.
                </div>

                <form method="POST" action="{{ route('crm.reporting.imports.store') }}" class="mt-4 grid gap-3 sm:flex sm:flex-wrap sm:items-center">
                    @csrf
                    <input type="hidden" name="import_token" value="{{ $importToken }}">
                    <input type="hidden" name="account_id" value="{{ $accountId }}">
                    <input type="hidden" name="account_timezone" value="{{ $accountTimezone }}">
                    <x-ui.button type="submit" class="w-full sm:w-auto">Import {{ number_format((int) $preview['valid_count']) }} row(s)</x-ui.button>
                    <a href="{{ route('crm.reporting.imports.create') }}" class="text-center text-sm font-semibold text-slate-600 hover:underline sm:text-left">Cancel and upload another file</a>
                </form>
            </div>
        </section>

        @if($preview['errors'] !== [])
            <details class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <summary class="cursor-pointer font-semibold text-slate-950">Skipped-row details</summary>
                <ul class="mt-3 space-y-1 text-sm leading-6 text-slate-700">
                    @foreach($preview['errors'] as $error)
                        <li class="break-words">{{ $error }}</li>
                    @endforeach
                </ul>
            </details>
        @endif
    </div>
</x-layouts.crm>