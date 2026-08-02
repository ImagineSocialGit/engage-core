<x-layouts.crm
    :title="$title"
    :heading="$heading"
    subheading="Download the currently supported project state or validate and apply a current-format JSON file after a clean rebuild."
>
    <div class="space-y-6">
        <section class="rounded-3xl border border-amber-300 bg-amber-50 p-6 shadow-sm sm:p-8">
            <p class="text-xs font-extrabold uppercase tracking-[0.18em] text-amber-800">
                Owner-only maintenance
            </p>

            <h2 class="mt-2 text-2xl font-extrabold tracking-tight text-amber-950">
                This changes production data directly
            </h2>

            <p class="mt-3 max-w-4xl text-sm leading-6 text-amber-950">
                Export before rebuilding the database. Import only after the new code is deployed and
                <code class="rounded bg-white/80 px-1.5 py-0.5 font-mono text-xs">migrate:fresh</code>,
                <code class="rounded bg-white/80 px-1.5 py-0.5 font-mono text-xs">presets:sync</code>, and
                <code class="rounded bg-white/80 px-1.5 py-0.5 font-mono text-xs">setup:validate</code>
                have completed successfully.
            </p>

            <dl class="mt-5 grid gap-3 text-sm sm:grid-cols-2">
                <div class="rounded-2xl border border-amber-200 bg-white/80 p-4">
                    <dt class="font-semibold text-slate-950">Current format</dt>
                    <dd class="mt-1 font-mono text-xs text-slate-700">{{ $format }} v{{ $formatVersion }}</dd>
                </div>
                <div class="rounded-2xl border border-amber-200 bg-white/80 p-4">
                    <dt class="font-semibold text-slate-950">Upload limit</dt>
                    <dd class="mt-1 text-slate-700">{{ $maxUploadMegabytes }} MB</dd>
                </div>
            </dl>
        </section>

        @if($errors->any())
            <section class="rounded-3xl border border-red-300 bg-red-50 p-6 shadow-sm">
                <h2 class="text-lg font-bold text-red-950">The request could not be completed</h2>
                <ul class="mt-3 space-y-1 text-sm text-red-900">
                    @foreach($errors->all() as $error)
                        <li>• {{ $error }}</li>
                    @endforeach
                </ul>
            </section>
        @endif

        @if(is_array($report))
            <section class="rounded-3xl border {{ $report['valid'] ? 'border-emerald-300 bg-emerald-50' : 'border-red-300 bg-red-50' }} p-6 shadow-sm sm:p-8">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <p class="text-xs font-extrabold uppercase tracking-[0.18em] {{ $report['valid'] ? 'text-emerald-800' : 'text-red-800' }}">
                            {{ $report['applied'] ? 'Import applied' : 'Validation report' }}
                        </p>
                        <h2 class="mt-2 text-2xl font-extrabold tracking-tight {{ $report['valid'] ? 'text-emerald-950' : 'text-red-950' }}">
                            {{ $report['valid'] ? 'The file matches the current contract' : 'The file cannot be imported' }}
                        </h2>
                    </div>

                    <span class="inline-flex w-fit rounded-full px-3 py-1 text-xs font-bold {{ $report['valid'] ? 'bg-emerald-200 text-emerald-950' : 'bg-red-200 text-red-950' }}">
                        {{ $report['valid'] ? 'VALID' : 'INVALID' }}
                    </span>
                </div>

                @if(!empty($report['errors']))
                    <ul class="mt-5 space-y-2 text-sm text-red-950">
                        @foreach($report['errors'] as $error)
                            <li>• {{ $error }}</li>
                        @endforeach
                    </ul>
                @endif

                @if(!empty($report['warnings']))
                    <div class="mt-5 rounded-2xl border border-amber-300 bg-amber-50 p-4">
                        <p class="font-semibold text-amber-950">Warnings</p>
                        <ul class="mt-2 space-y-1 text-sm text-amber-900">
                            @foreach($report['warnings'] as $warning)
                                <li>• {{ $warning }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                @if(!empty($report['counts']))
                    <div class="mt-6 overflow-hidden rounded-2xl border border-slate-200 bg-white">
                        <table class="min-w-full divide-y divide-slate-200 text-sm">
                            <thead class="bg-slate-50 text-left text-xs font-bold uppercase tracking-wide text-slate-600">
                                <tr>
                                    <th class="px-4 py-3">Table</th>
                                    <th class="px-4 py-3 text-right">File rows</th>
                                    @if($report['applied'])
                                        <th class="px-4 py-3 text-right">Applied</th>
                                    @endif
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                @foreach($report['counts'] as $table => $count)
                                    <tr>
                                        <td class="px-4 py-3 font-mono text-xs text-slate-800">{{ $table }}</td>
                                        <td class="px-4 py-3 text-right text-slate-700">{{ number_format($count) }}</td>
                                        @if($report['applied'])
                                            <td class="px-4 py-3 text-right font-semibold text-slate-950">
                                                {{ number_format($report['applied_counts'][$table] ?? 0) }}
                                            </td>
                                        @endif
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>
        @endif

        @if(is_array($resumeReport))
            <section class="rounded-3xl border border-emerald-300 bg-emerald-50 p-6 shadow-sm sm:p-8">
                <p class="text-xs font-extrabold uppercase tracking-[0.18em] text-emerald-800">
                    Resume completed
                </p>
                <h2 class="mt-2 text-2xl font-extrabold tracking-tight text-emerald-950">
                    {{ $resumeReport['label'] }}
                </h2>
                <p class="mt-3 text-sm leading-6 text-emerald-950">
                    Processed {{ number_format($resumeReport['processed']) }} imported work item(s).
                    {{ number_format($resumeReport['pending_count']) }} remain in this category.
                </p>

                @if(!empty($resumeReport['outcomes']))
                    <dl class="mt-5 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                        @foreach($resumeReport['outcomes'] as $outcome => $count)
                            <div class="rounded-2xl border border-emerald-200 bg-white/80 p-4">
                                <dt class="font-mono text-xs text-slate-600">{{ $outcome }}</dt>
                                <dd class="mt-1 text-2xl font-extrabold text-slate-950">{{ number_format($count) }}</dd>
                            </div>
                        @endforeach
                    </dl>
                @endif
            </section>
        @endif

        <div class="grid gap-6 xl:grid-cols-2">
            <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
                <p class="text-xs font-extrabold uppercase tracking-[0.18em] text-slate-500">Export</p>
                <h2 class="mt-2 text-2xl font-extrabold tracking-tight text-slate-950">Download current state</h2>
                <p class="mt-3 text-sm leading-6 text-slate-600">
                    Generates the supported JSON contract from one consistent database snapshot and downloads it without leaving a public server-side copy. Export is blocked when schema changes are unclassified, unsupported durable tables contain rows, operational receipts are not terminal, or polymorphic references cannot be restored safely.
                </p>

                <form method="POST" action="{{ route('crm.project-state.export') }}" class="mt-6 space-y-4">
                    @csrf

                    <div>
                        <label for="export-current-password" class="text-sm font-semibold text-slate-900">Current password</label>
                        <input
                            id="export-current-password"
                            name="current_password"
                            type="password"
                            autocomplete="current-password"
                            required
                            class="mt-1 block w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-950 shadow-sm focus:border-orange-500 focus:outline-none focus:ring-2 focus:ring-orange-200"
                        >
                    </div>

                    <button type="submit" class="inline-flex items-center justify-center rounded-xl bg-slate-950 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-slate-800">
                        Download Project State
                    </button>
                </form>
            </section>

            <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
                <p class="text-xs font-extrabold uppercase tracking-[0.18em] text-slate-500">Import</p>
                <h2 class="mt-2 text-2xl font-extrabold tracking-tight text-slate-950">Validate or apply current-format state</h2>
                <p class="mt-3 text-sm leading-6 text-slate-600">
                    Validation never changes the database. Apply revalidates the same contract and imports it in one transaction without firing Eloquent model events.
                </p>

                <form method="POST" action="{{ route('crm.project-state.import') }}" enctype="multipart/form-data" class="mt-6 space-y-4">
                    @csrf

                    <div>
                        <label for="state-file" class="text-sm font-semibold text-slate-900">Project-state JSON file</label>
                        <input
                            id="state-file"
                            name="state_file"
                            type="file"
                            accept="application/json,.json"
                            required
                            class="mt-1 block w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-700 shadow-sm file:mr-4 file:rounded-lg file:border-0 file:bg-slate-100 file:px-3 file:py-2 file:text-sm file:font-semibold file:text-slate-800"
                        >
                    </div>

                    <div>
                        <label for="import-current-password" class="text-sm font-semibold text-slate-900">Current password <span class="font-normal text-slate-500">(required only when applying)</span></label>
                        <input
                            id="import-current-password"
                            name="current_password"
                            type="password"
                            autocomplete="current-password"
                            class="mt-1 block w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-950 shadow-sm focus:border-orange-500 focus:outline-none focus:ring-2 focus:ring-orange-200"
                        >
                    </div>

                    <div>
                        <label for="confirmation" class="text-sm font-semibold text-slate-900">Apply confirmation <span class="font-normal text-slate-500">(type IMPORT only when applying)</span></label>
                        <input
                            id="confirmation"
                            name="confirmation"
                            type="text"
                            autocomplete="off"
                            class="mt-1 block w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-950 shadow-sm focus:border-orange-500 focus:outline-none focus:ring-2 focus:ring-orange-200"
                        >
                    </div>

                    <div class="flex flex-wrap gap-3">
                        <button name="operation" value="validate" type="submit" class="inline-flex items-center justify-center rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-800 shadow-sm transition hover:bg-slate-50">
                            Validate Only
                        </button>

                        <button name="operation" value="apply" type="submit" class="inline-flex items-center justify-center rounded-xl bg-red-700 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-red-800">
                            Apply Import
                        </button>
                    </div>
                </form>
            </section>
        </div>

        <section class="rounded-3xl border border-orange-300 bg-orange-50 p-6 shadow-sm sm:p-8">
            <p class="text-xs font-extrabold uppercase tracking-[0.18em] text-orange-800">
                Explicit post-import resume
            </p>
            <h2 class="mt-2 text-2xl font-extrabold tracking-tight text-orange-950">
                Resume imported activity one category at a time
            </h2>
            <p class="mt-3 max-w-4xl text-sm leading-6 text-orange-950">
                Import restores runnable work in inert states and records exactly what was paused.
                Resume the categories below in dependency order only after the application, workers,
                providers, and client configuration are ready. Each submission processes up to
                {{ number_format($resumeBatchSize) }} items; repeat a category while its pending count remains above zero.
            </p>

            <div class="mt-6 grid gap-4 lg:grid-cols-2">
                @foreach($resumeSummary as $category)
                    <article class="rounded-2xl border {{ $category['pending_count'] > 0 ? 'border-orange-300 bg-white' : 'border-slate-200 bg-white/70' }} p-5">
                        <div class="flex items-start justify-between gap-4">
                            <div>
                                <h3 class="font-bold text-slate-950">{{ $category['label'] }}</h3>
                                <p class="mt-1 text-sm leading-5 text-slate-600">{{ $category['description'] }}</p>
                            </div>
                            <span class="inline-flex min-w-10 justify-center rounded-full px-3 py-1 text-xs font-extrabold {{ $category['pending_count'] > 0 ? 'bg-orange-200 text-orange-950' : 'bg-slate-200 text-slate-700' }}">
                                {{ number_format($category['pending_count']) }}
                            </span>
                        </div>

                        @if(!empty($category['blocked_by']))
                            <p class="mt-3 text-xs font-semibold text-amber-800">
                                Complete first:
                                @foreach($category['blocked_by'] as $dependency)
                                    {{ $resumeSummary[$dependency]['label'] ?? $dependency }}{{ $loop->last ? '' : ', ' }}
                                @endforeach
                            </p>
                        @elseif($category['pending_count'] > 0)
                            <p class="mt-3 text-xs font-semibold text-emerald-800">Ready for explicit resume.</p>
                        @else
                            <p class="mt-3 text-xs font-semibold text-slate-500">No pending imported work.</p>
                        @endif
                    </article>
                @endforeach
            </div>

            @php
                $resumableCategories = collect($resumeSummary)
                    ->filter(fn (array $category): bool =>
                        $category['pending_count'] > 0
                        && $category['blocked_by'] === []
                    );
            @endphp

            @if($resumableCategories->isNotEmpty())
                <form method="POST" action="{{ route('crm.project-state.resume') }}" class="mt-6 grid gap-4 rounded-2xl border border-orange-300 bg-white p-5 lg:grid-cols-3">
                    @csrf

                    <div>
                        <label for="resume-category" class="text-sm font-semibold text-slate-900">Category</label>
                        <select
                            id="resume-category"
                            name="category"
                            required
                            class="mt-1 block w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-950 shadow-sm focus:border-orange-500 focus:outline-none focus:ring-2 focus:ring-orange-200"
                        >
                            @foreach($resumableCategories as $category)
                                <option value="{{ $category['key'] }}">
                                    {{ $category['label'] }} — {{ number_format($category['pending_count']) }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label for="resume-current-password" class="text-sm font-semibold text-slate-900">Current password</label>
                        <input
                            id="resume-current-password"
                            name="current_password"
                            type="password"
                            autocomplete="current-password"
                            required
                            class="mt-1 block w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-950 shadow-sm focus:border-orange-500 focus:outline-none focus:ring-2 focus:ring-orange-200"
                        >
                    </div>

                    <div>
                        <label for="resume-confirmation" class="text-sm font-semibold text-slate-900">Type RESUME</label>
                        <input
                            id="resume-confirmation"
                            name="confirmation"
                            type="text"
                            autocomplete="off"
                            required
                            class="mt-1 block w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-950 shadow-sm focus:border-orange-500 focus:outline-none focus:ring-2 focus:ring-orange-200"
                        >
                    </div>

                    <div class="lg:col-span-3">
                        <button type="submit" class="inline-flex items-center justify-center rounded-xl bg-orange-700 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-orange-800">
                            Resume Selected Activity
                        </button>
                    </div>
                </form>
            @else
                <p class="mt-6 rounded-2xl border border-slate-200 bg-white/70 p-4 text-sm text-slate-700">
                    No category is currently ready for resume. Complete any listed prerequisites first, or there is no imported activity waiting.
                </p>
            @endif
        </section>
    </div>
</x-layouts.crm>