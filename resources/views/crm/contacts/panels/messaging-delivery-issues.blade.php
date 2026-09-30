<x-ui.card class="space-y-5" data-module-panel="messaging" data-messaging-delivery-issues>
    <div>
        <p class="text-xs font-bold uppercase tracking-[0.14em] text-red-700">Needs attention</p>
        <h3 class="mt-1 text-lg font-semibold tracking-tight text-slate-950">Message delivery problem</h3>
    </div>

    <div class="space-y-4">
        @foreach($deliveryIssues as $issue)

            <div class="rounded-2xl border border-red-200 bg-red-50/70 p-4">
                <p class="text-sm font-semibold text-red-950">
                    {{ $issue['problem_label'] }}
                </p>
                <p class="mt-1 break-all text-sm text-red-900">{{ $issue['suppression']->destination }}</p>
                <p class="mt-3 text-sm text-slate-700">
                    Check the {{ $issue['edit_field'] }} and correct it if it is wrong.
                </p>

                <a
                    href="{{ route('crm.contacts.show', $issue['contact']) }}?contact_edit={{ $issue['edit_field'] }}"
                    class="mt-4 inline-flex items-center justify-center rounded-xl bg-slate-950 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-slate-800"
                    data-delivery-issue-edit-destination
                >
                    Update {{ $issue['edit_field'] === 'email' ? 'email address' : 'phone number' }}
                </a>
            </div>
        @endforeach
    </div>

    <div class="border-t border-slate-200 pt-4">
        <a
            href="{{ route('crm.messaging.delivery-issues.index') }}"
            class="text-sm font-semibold text-slate-600 underline decoration-slate-300 underline-offset-4 hover:text-slate-950"
        >
            Review all delivery problems
        </a>
    </div>
</x-ui.card>