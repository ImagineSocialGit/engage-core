<x-layouts.crm title="Leads" heading="Leads" subheading="Lead list">
    <div class="space-y-6">
        <div class="flex items-center justify-between">
            <div>
                <h2 class="text-xl font-semibold tracking-tight">All Leads</h2>
            </div>
        </div>

        <x-ui.card padding="none" class="overflow-hidden">
            <div class="divide-y divide-slate-200">
                @forelse ($leads as $lead)
                    <a href="/leads/{{ $lead->id }}" class="block px-6 py-4 transition hover:bg-slate-50">
                        <div class="flex items-center justify-between gap-4">
                            <div>
                                <p class="font-semibold text-slate-900">{{ $lead->name }}</p>
                                <p class="text-sm text-slate-500">{{ $lead->email }}</p>
                            </div>
                            <div class="text-sm text-slate-500">{{ ucfirst($lead->status) }}</div>
                        </div>
                    </a>
                @empty
                    <div class="px-6 py-8 text-sm text-slate-500">
                        No leads yet.
                    </div>
                @endforelse
            </div>
        </x-ui.card>

        <div>
            {{ $leads->links() }}
        </div>
    </div>
</x-layouts.crm>