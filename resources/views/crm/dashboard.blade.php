<x-layouts.crm :title="$title" :heading="$heading" :subheading="$subheading">
    @php
        $attentionCount = (int) ($summary['attention_count'] ?? 0);
        $coreTone = module_tone('core');
        $jumpCardBaseClass = 'w-full rounded-xl p-3 text-left ring-1 transition focus:outline-none focus:ring-2';
    @endphp

    <div
        class="space-y-6"
        x-data="{
            focusedPanel: null,
            jumpTo(panel) {
                if (! panel) return;

                const target = this.$refs[panel];
                if (! target) return;

                target.scrollIntoView({ behavior: 'smooth', block: 'start' });
                this.focusedPanel = panel;

                window.setTimeout(() => {
                    if (this.focusedPanel === panel) {
                        this.focusedPanel = null;
                    }
                }, 1600);
            },
        }"
    >
        @if (session('success'))
            <div class="rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800">
                {{ session('success') }}
            </div>
        @endif

        @if (session('error'))
            <div class="rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-medium text-red-800">
                {{ session('error') }}
            </div>
        @endif

        <section class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
            <div class="grid gap-6 p-6 lg:grid-cols-[minmax(0,1fr)_22rem] lg:p-8">
                <div>
                    <div class="text-xs font-bold uppercase tracking-[0.24em] text-slate-500">
                        Today
                    </div>

                    <h2 class="mt-3 max-w-3xl text-3xl font-semibold tracking-tight text-slate-950">
                        You have a clear place to start.
                    </h2>

                    <p class="mt-3 max-w-2xl text-sm leading-6 text-slate-600">
                        The dashboard adapts to the modules enabled for this client, keeping the most actionable work first and context underneath.
                    </p>

                    <div class="mt-6 flex flex-wrap items-center gap-3">
                        @if(filled($primaryAction['href'] ?? null))
                            <a
                                href="{{ $primaryAction['href'] }}"
                                class="inline-flex items-center justify-center rounded-xl bg-slate-950 px-4 py-2.5 text-sm font-bold text-white shadow-sm transition hover:bg-slate-800"
                            >
                                {{ $primaryAction['label'] ?? 'Start here' }}
                            </a>
                        @endif

                        @if(filled($primaryAction['summary'] ?? null))
                            <p class="text-sm font-medium text-slate-500">
                                {{ $primaryAction['summary'] }}
                            </p>
                        @endif
                    </div>
                </div>

                <div class="rounded-2xl p-4 ring-1 {{ $coreTone['item'] ?? 'bg-slate-50 ring-slate-200' }}">
                    <p class="text-sm font-semibold text-slate-900">
                        Right now
                    </p>

                    <div class="mt-4 grid grid-cols-2 gap-3 text-sm">
                        @forelse($rightNowCards as $card)
                            @php
                                $cardTone = module_tone($card['module'] ?? 'core');
                                $targetRef = (string) ($card['target_ref'] ?? '');
                            @endphp

                            <button
                                type="button"
                                class="{{ $jumpCardBaseClass }} {{ $cardTone['jump'] ?? 'bg-white ring-slate-200 hover:bg-slate-50 hover:ring-slate-300 focus:ring-slate-300' }}"
                                @click="jumpTo(@js($targetRef))"
                            >
                                <div class="text-2xl font-semibold text-slate-950">{{ (int) ($card['count'] ?? 0) }}</div>
                                <div class="mt-1 text-xs font-medium text-slate-500">{{ $card['label'] }}</div>
                            </button>
                        @empty
                            <div class="col-span-2 rounded-xl bg-white p-3 ring-1 ring-slate-200">
                                <div class="text-2xl font-semibold text-slate-950">0</div>
                                <div class="mt-1 text-xs font-medium text-slate-500">need attention</div>
                            </div>
                        @endforelse
                    </div>
                </div>
            </div>
        </section>

        @if($workPanels->isNotEmpty())
            <section class="grid gap-6 @if($workPanels->count() > 1) lg:grid-cols-2 @else lg:grid-cols-1 @endif">
                @foreach($workPanels as $panel)
                    <x-crm.dashboard.panel :panel="$panel" layout="work" />
                @endforeach
            </section>
        @endif

        @foreach($contextPanels as $panel)
            <x-crm.dashboard.panel :panel="$panel" layout="context" />
        @endforeach
    </div>
</x-layouts.crm>


