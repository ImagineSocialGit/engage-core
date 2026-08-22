<x-layouts.crm
    :title="$title"
    :heading="$heading"
    :subheading="$subheading"
    module="core"
>
    <div class="space-y-6">
        <section class="rounded-3xl border border-slate-200 bg-white shadow-sm">
            <div class="p-5 sm:p-8">
                <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <p class="text-sm font-semibold uppercase tracking-[0.16em] text-slate-600">
                            Business processes
                        </p>

                        <h2 class="mt-2 text-2xl font-semibold tracking-tight text-slate-950">
                            See the road without opening every automation screen
                        </h2>

                        <p class="mt-3 max-w-3xl text-sm leading-6 text-slate-600">
                            Process Highway is read-only. It summarizes the processes Core already knows about and leaves editing with the feature that owns each process.
                        </p>
                    </div>

                    @if(($highway['process_count'] ?? 0) > 0)
                        <div class="shrink-0 rounded-2xl bg-slate-50 px-4 py-3 text-center ring-1 ring-slate-200">
                            <div class="text-2xl font-semibold text-slate-950">{{ $highway['process_count'] }}</div>
                            <div class="text-xs font-medium text-slate-500">active processes</div>
                        </div>
                    @endif
                </div>
            </div>
        </section>

        @if(! ($highway['source_available'] ?? false))
            <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
                <h2 class="text-lg font-semibold text-slate-950">No process routes are enabled</h2>
                <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-600">
                    Process Highway still works without automation modules. When Routes are enabled and configured, their process map will appear here automatically.
                </p>
            </section>
        @elseif(($highway['process_count'] ?? 0) === 0)
            <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
                <h2 class="text-lg font-semibold text-slate-950">No active processes yet</h2>
                <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-600">
                    There are no current active Routes to summarize.
                </p>
            </section>
        @else
            @foreach($highway['groups'] as $group)
                <section class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
                    <div class="border-b border-slate-100 px-5 py-4 sm:px-8">
                        <div class="flex items-center justify-between gap-4">
                            <h2 class="text-lg font-semibold tracking-tight text-slate-950">
                                {{ $group['label'] }}
                            </h2>
                            <span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-700">
                                {{ $group['processes']->count() }}
                            </span>
                        </div>
                    </div>

                    <div class="divide-y divide-slate-100">
                        @foreach($group['processes'] as $process)
                            <article class="p-5 sm:p-8">
                                <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                                    <div class="min-w-0 flex-1">
                                        <h3 class="text-lg font-semibold text-slate-950">{{ $process['name'] }}</h3>

                                        @if($process['description'])
                                            <p class="mt-1 max-w-3xl text-sm leading-6 text-slate-600">
                                                {{ $process['description'] }}
                                            </p>
                                        @endif
                                    </div>

                                    @if($process['edit_url'])
                                        <a
                                            href="{{ $process['edit_url'] }}"
                                            class="inline-flex shrink-0 items-center justify-center rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50"
                                        >
                                            Edit Route
                                        </a>
                                    @endif
                                </div>

                                <div class="mt-5 grid gap-4 xl:grid-cols-[minmax(0,0.9fr)_minmax(0,1.4fr)_minmax(0,1fr)]">
                                    <div class="rounded-2xl bg-slate-50 p-4 ring-1 ring-slate-200">
                                        <p class="text-xs font-bold uppercase tracking-[0.14em] text-slate-500">Starts when</p>
                                        <p class="mt-2 text-sm font-semibold leading-6 text-slate-900">{{ $process['starts_when'] }}</p>
                                    </div>

                                    <div class="rounded-2xl bg-slate-50 p-4 ring-1 ring-slate-200">
                                        <p class="text-xs font-bold uppercase tracking-[0.14em] text-slate-500">Then</p>

                                        <ol class="mt-3 space-y-3">
                                            @forelse($process['steps'] as $index => $step)
                                                <li class="flex gap-3">
                                                    <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-white text-xs font-bold text-slate-700 ring-1 ring-slate-300">
                                                        {{ $index + 1 }}
                                                    </span>
                                                    <span class="min-w-0 text-sm leading-5 text-slate-800">
                                                        <span class="font-semibold">{{ $step['name'] }}</span>
                                                        @if($step['detail'])
                                                            <span class="mt-0.5 block text-xs text-slate-500">{{ $step['detail'] }}</span>
                                                        @endif
                                                    </span>
                                                </li>
                                            @empty
                                                <li class="text-sm text-slate-600">No active steps.</li>
                                            @endforelse
                                        </ol>
                                    </div>

                                    <div class="rounded-2xl bg-slate-50 p-4 ring-1 ring-slate-200">
                                        <p class="text-xs font-bold uppercase tracking-[0.14em] text-slate-500">Can lead to</p>

                                        <div class="mt-3 flex flex-wrap gap-2">
                                            @forelse($process['outcomes'] as $outcome)
                                                <span class="rounded-full bg-white px-2.5 py-1 text-xs font-semibold text-slate-700 ring-1 ring-slate-300">
                                                    {{ $outcome }}
                                                </span>
                                            @empty
                                                <span class="text-sm text-slate-600">No downstream action is configured.</span>
                                            @endforelse
                                        </div>
                                    </div>
                                </div>
                            </article>
                        @endforeach
                    </div>
                </section>
            @endforeach
        @endif
    </div>
</x-layouts.crm>