<x-layouts.crm :title="$title" :heading="$heading" module="commerce">
    <div class="space-y-6">
        <div class="rounded-2xl border border-amber-200 bg-amber-50 p-5 text-sm text-amber-950">
            These are authenticated privacy requests awaiting merchant review. Receipt is not fulfillment.
            Check applicable legal retention obligations before exporting or deleting personal data.
            Shopify generally requires completion within 30 days. No automatic deletion is performed here.
        </div>

        <div class="overflow-x-auto rounded-2xl border border-slate-200 bg-white">
            <table class="w-full text-left text-sm">
                <thead class="bg-slate-50 text-slate-600">
                    <tr>
                        <th class="px-4 py-3">Received</th>
                        <th class="px-4 py-3">Provider / request</th>
                        <th class="px-4 py-3">Customer identity</th>
                        <th class="px-4 py-3">Orders referenced</th>
                        <th class="px-4 py-3">Status</th>
                        <th class="px-4 py-3">Operator action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse($requests as $entry)
                        <tr>
                            <td class="px-4 py-3 whitespace-nowrap">{{ $entry->created_at?->format('Y-m-d H:i') }}</td>
                            <td class="px-4 py-3">
                                <div class="font-semibold">{{ $entry->provider }}</div>
                                <div class="text-slate-600">{{ $entry->event_type }}</div>
                            </td>
                            <td class="px-4 py-3">
                                @if(data_get($entry->payload, 'customer_id'))
                                    Provider ID {{ data_get($entry->payload, 'customer_id') }}
                                @elseif(data_get($entry->payload, 'has_customer_email'))
                                    Email-only request: requires secure merchant follow-up
                                @else
                                    Whole-shop request
                                @endif
                            </td>
                            <td class="px-4 py-3">{{ count(data_get($entry->payload, 'order_ids', [])) }}</td>
                            <td class="px-4 py-3 font-semibold text-amber-900">{{ str($entry->status)->replace('_', ' ')->headline() }}</td>
                            <td class="px-4 py-3">
                                @if($entry->event_type === 'privacy.customers/redact' && data_get($entry->payload, 'customer_id'))
                                    @if(data_get($entry->outcome, 'commerce_scoped_reviewed_at'))
                                        <span class="text-xs font-semibold text-slate-700">
                                            @if(data_get($entry->outcome, 'customer_found'))
                                                Commerce pseudonymized; shared Contact still requires review
                                            @else
                                                No matching Commerce customer; other data still requires review
                                            @endif
                                        </span>
                                    @else
                                        <form method="POST" action="{{ route('crm.commerce.privacy.redact-customer', $entry) }}" onsubmit="return confirm('Pseudonymize the Shopify customer in Commerce? This does not delete shared CRM contacts or complete the privacy request.');">
                                            @csrf
                                            <input type="hidden" name="acknowledge_partial" value="1">
                                            <button type="submit" class="rounded-lg border border-slate-300 px-3 py-2 text-xs font-semibold text-slate-800">Redact Commerce customer</button>
                                        </form>
                                    @endif
                                @else
                                    <span class="text-xs text-slate-500">Manual merchant review required</span>
                                @endif
                                @if($entry->event_type !== 'privacy.customers/redact'
                                    || !data_get($entry->payload, 'customer_id')
                                    || data_get($entry->outcome, 'commerce_scoped_reviewed_at'))
                                    <form method="POST" action="{{ route('crm.commerce.privacy.complete', $entry) }}" class="mt-3 space-y-2" onsubmit="return confirm('Confirm merchant review, related data, legal retention and external evidence are complete? This closes the privacy request.');">
                                        @csrf
                                        <label class="block text-xs font-medium text-slate-700" for="privacy-case-{{ $entry->id }}">Case reference (no personal information)</label>
                                        <input id="privacy-case-{{ $entry->id }}" name="case_reference" required maxlength="120" class="w-full rounded-lg border border-slate-300 px-2 py-2 text-xs" placeholder="Merchant case / ticket ID">
                                        <label class="block text-xs font-medium text-slate-700" for="privacy-resolution-{{ $entry->id }}">Resolution</label>
                                        <select id="privacy-resolution-{{ $entry->id }}" name="disposition" required class="w-full rounded-lg border border-slate-300 px-2 py-2 text-xs">
                                            <option value="fulfilled">Fulfilled after external review</option>
                                            <option value="legal_retention">Data lawfully retained (redaction requests only)</option>
                                            <option value="not_applicable">No applicable personal data / action</option>
                                        </select>
                                        <label class="block text-xs"><input type="checkbox" name="merchant_reviewed" value="1" required> Merchant review completed</label>
                                        <label class="block text-xs"><input type="checkbox" name="related_data_reviewed" value="1" required> Related CRM data and external records reviewed</label>
                                        <label class="block text-xs"><input type="checkbox" name="retention_reviewed" value="1" required> Retention, backups and legal holds reviewed</label>
                                        <button type="submit" class="rounded-lg border border-slate-300 px-3 py-2 text-xs font-semibold text-slate-800">Record final resolution</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-4 py-6 text-slate-500">No pending privacy requests.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{ $requests->links() }}
    </div>
</x-layouts.crm>
