<details>
    <summary
        class="cursor-pointer list-none rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50"
    >
        {{ $contactResultAction->label }}
    </summary>

    <div class="mt-2 w-80 rounded-xl border border-slate-200 bg-white p-4 shadow-lg">
        @if(($contactResultAction->data['campaigns'] ?? []) === [])
            <p class="text-sm text-slate-500">
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
                    Enroll {{ number_format($contactResultCount) }} Contacts
                </x-ui.button>
            </form>
        @endif
    </div>
</details>