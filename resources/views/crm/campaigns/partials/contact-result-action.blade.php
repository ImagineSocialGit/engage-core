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
        <form
            method="POST"
            action="{{ route('crm.campaigns.contact-results.store') }}"
            class="space-y-4"
            x-data="{
                campaigns: @js($contactResultAction->data['campaigns']),
                campaignKey: @js((string) old('campaign_key', '')),
                operation: @js((string) old('operation', 'enroll')),
                messageStepKey: @js((string) old('message_step_key', '')),
                resultLabel: @js(number_format($contactResultCount).' '.$leadPlural),
                get selectedCampaign() {
                    return this.campaigns.find(campaign => campaign.value === this.campaignKey) ?? null;
                },
                get allocation() {
                    return this.selectedCampaign?.strategy === 'recurring_allocation';
                },
                get messageOptions() {
                    return this.selectedCampaign?.messages ?? [];
                },
                get operations() {
                    const choices = [
                        { value: 'enroll', label: 'Enroll normally' },
                        { value: 'reenroll_from_message', label: 'Re-enroll starting at a message' },
                    ];
                    if (this.allocation) {
                        choices.push(
                            { value: 'enroll_with_allocation_message_exclusion', label: 'Enroll and exclude a message' },
                            { value: 'exclude_allocation_message', label: 'Exclude a message' },
                            { value: 'remove_allocation_message_exclusion', label: 'Remove a message exclusion' },
                        );
                    }
                    return choices;
                },
                get requiresMessage() {
                    return this.operation !== 'enroll';
                },
                get excluding() {
                    return this.operation === 'exclude_allocation_message'
                        || this.operation === 'enroll_with_allocation_message_exclusion';
                },
                get buttonLabel() {
                    const action = this.operations.find(choice => choice.value === this.operation);
                    return (action?.label ?? 'Apply Campaign action') + ' to ' + this.resultLabel;
                },
                resetCampaign() {
                    this.operation = 'enroll';
                    this.messageStepKey = '';
                },
                resetOperation() {
                    this.messageStepKey = '';
                },
            }"
            x-on:submit="if (operation === 'reenroll_from_message' && !confirm('Restart the selected leads at this message? Current open Campaign participation will end.')) $event.preventDefault()"
        >
            @csrf
            <x-crm.contact-result-filter-fields :payload="$contactResultPayload" />

            <div>
                <label for="contact_result_campaign_key" class="block text-sm font-semibold text-slate-900">Campaign</label>
                <select
                    id="contact_result_campaign_key"
                    name="campaign_key"
                    x-model="campaignKey"
                    x-on:change="resetCampaign()"
                    required
                    class="mt-2 block min-h-11 w-full rounded-xl border-slate-300 bg-white text-sm text-slate-900"
                >
                    <option value="">Choose a Campaign</option>
                    @foreach($contactResultAction->data['campaigns'] as $campaignOption)
                        <option value="{{ $campaignOption['value'] }}">
                            {{ $campaignOption['label'] }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div x-show="selectedCampaign" x-cloak>
                <label for="contact_result_campaign_operation" class="block text-sm font-semibold text-slate-900">Action</label>
                <select
                    id="contact_result_campaign_operation"
                    name="operation"
                    x-model="operation"
                    x-on:change="resetOperation()"
                    class="mt-2 block min-h-11 w-full rounded-xl border-slate-300 bg-white text-sm text-slate-900"
                >
                    <template x-for="choice in operations" :key="choice.value">
                        <option :value="choice.value" x-text="choice.label"></option>
                    </template>
                </select>
            </div>

            <div x-show="selectedCampaign && requiresMessage" x-cloak>
                <label for="contact_result_campaign_message" class="block text-sm font-semibold text-slate-900">Message</label>
                <select
                    id="contact_result_campaign_message"
                    name="message_step_key"
                    x-model="messageStepKey"
                    :required="requiresMessage"
                    :disabled="!requiresMessage"
                    class="mt-2 block min-h-11 w-full rounded-xl border-slate-300 bg-white text-sm text-slate-900"
                >
                    <option value="">Choose a message</option>
                    <template x-for="message in messageOptions" :key="message.value">
                        <option :value="message.value" x-text="message.label"></option>
                    </template>
                </select>
                <p x-show="requiresMessage && messageOptions.length === 0" class="mt-1 text-xs text-amber-800">This Campaign has no active published messages to select.</p>
            </div>

            <div x-show="allocation && excluding" x-cloak>
                <label for="contact_result_campaign_reason" class="block text-sm font-semibold text-slate-900">Reason (optional)</label>
                <input
                    id="contact_result_campaign_reason"
                    name="reason"
                    maxlength="255"
                    :disabled="!excluding"
                    value="{{ old('reason') }}"
                    class="mt-2 block min-h-11 w-full rounded-xl border-slate-300 bg-white text-sm text-slate-900"
                >
            </div>

            <p x-show="operation === 'enroll'" class="text-xs leading-5 text-slate-500">Enrollment uses the Campaign's normal eligibility and duplicate-enrollment safeguards.</p>
            <p x-show="operation === 'reenroll_from_message'" x-cloak class="text-xs leading-5 text-amber-900">This ends current open participation and starts a new enrollment at the selected message. Historical receipts and allocation cooldowns remain intact.</p>
            <p x-show="allocation && excluding" x-cloak class="text-xs leading-5 text-amber-900">Excluding a message does not mark it as received. A matching pending allocation message is skipped.</p>
            <p x-show="allocation && operation === 'remove_allocation_message_exclusion'" x-cloak class="text-xs leading-5 text-slate-500">Removal restores future eligibility only if no previous assignment or receipt already excludes this message.</p>

            @error('campaign_operation')
                <p class="text-sm font-semibold text-red-700">{{ $message }}</p>
            @enderror

            <button type="submit" class="inline-flex min-h-11 w-full items-center justify-center rounded-xl bg-slate-950 px-4 text-sm font-bold text-white hover:bg-slate-800" x-text="buttonLabel">
                Apply Campaign action
            </button>
        </form>
    @endif
</div>