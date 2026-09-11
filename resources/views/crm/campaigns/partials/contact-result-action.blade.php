<div class="space-y-3">
    <div>
        <p class="text-sm font-semibold text-slate-900">{{ $contactResultAction->label }}</p>
        <p class="mt-1 text-xs leading-5 text-slate-500">{{ $contactResultAction->description }}</p>
    </div>

    @if(($contactResultAction->data['campaigns'] ?? []) === [])
        <p class="rounded-xl border border-dashed border-slate-300 bg-slate-50 px-3 py-3 text-sm text-slate-500">
            No active Campaigns are available.
        </p>
    @else
        <form method="POST" action="{{ route('crm.campaigns.contact-results.store') }}" class="space-y-3">
            @csrf
            <x-crm.contact-result-filter-fields :payload="$contactResultPayload" />

            <div>
                <x-ui.form.label for="contact_result_campaign_key">Campaign</x-ui.form.label>
                <x-ui.form.select
                    id="contact_result_campaign_key"
                    name="campaign_key"
                    required
                >
                    <option value="">Choose a Campaign</option>
                    @foreach($contactResultAction->data['campaigns'] as $campaignOption)
                        <option value="{{ $campaignOption['value'] }}">
                            {{ $campaignOption['label'] }}
                        </option>
                    @endforeach
                </x-ui.form.select>
            </div>

            <p class="text-xs leading-5 text-slate-500">
                Enrollment uses each Campaign's normal eligibility and duplicate-enrollment safeguards.
            </p>

            <x-ui.button type="submit" class="w-full">
                Enroll {{ number_format($contactResultCount) }} {{ $leadPlural }}
            </x-ui.button>
        </form>
    @endif
</div>