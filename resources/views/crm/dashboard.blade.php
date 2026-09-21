<x-layouts.crm :title="$title" :heading="$heading" :subheading="$subheading">
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

        @if(count($rightNowCards) > 0)
            <section class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4" aria-label="Right now">
                @foreach($rightNowCards as $card)
                    <button
                        type="button"
                        class="w-full rounded-xl p-3 text-left ring-1 transition focus:outline-none focus:ring-2 {{ module_tone($card['module'] ?? 'core')['jump'] ?? 'bg-white ring-slate-200 hover:bg-slate-50 hover:ring-slate-300 focus:ring-slate-300' }}"
                        @click="jumpTo(@js($card['target_ref'] ?? ''))"
                    >
                        <div class="flex items-baseline justify-between gap-3">
                            <div class="text-2xl font-semibold text-slate-950">{{ (int) ($card['count'] ?? 0) }}</div>
                            <div class="text-xs font-semibold text-slate-500">{{ $card['label'] }}</div>
                        </div>
                    </button>
                @endforeach
            </section>
        @endif

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