<x-ui.card class="space-y-5" data-module-panel="messaging" data-messaging-delivery-issues>
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <p class="text-xs font-bold uppercase tracking-[0.14em] text-amber-700">
                Needs review
            </p>
            <h3 class="mt-1 text-lg font-semibold tracking-tight text-slate-950">
                Messaging delivery issue
            </h3>
            <p class="mt-1 text-sm text-slate-600">
                A current email address or phone number is suppressed from messaging.
            </p>
        </div>

        <a
            href="{{ route('crm.messaging.delivery-issues.index') }}"
            class="text-sm font-semibold text-slate-700 underline decoration-slate-300 underline-offset-4 hover:text-slate-950"
        >
            Review all
        </a>
    </div>

    <div class="space-y-4">
        @foreach($deliveryIssues as $issue)
            @php
                $suppression = $issue['suppression'];
                $fieldId = 'delivery-issue-resolution-'.$suppression->id;
            @endphp

            <div class="rounded-2xl border border-amber-200 bg-amber-50/70 p-4">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <p class="text-sm font-semibold text-amber-950">
                            {{ $issue['reason_label'] }}
                        </p>
                        <p class="mt-1 break-all text-sm text-amber-900">
                            {{ strtoupper($suppression->channel) }} · {{ $suppression->destination }}
                        </p>
                    </div>

                    @if($suppression->suppressed_at)
                        <span class="text-xs text-amber-800">
                            {{ $suppression->suppressed_at->timezone(config('client.timezone', config('app.timezone', 'UTC')))->format('M j, Y g:i A') }}
                        </span>
                    @endif
                </div>

                @if(filled($suppression->provider))
                    <p class="mt-2 text-xs text-amber-800">
                        Provider: {{ strtoupper($suppression->provider) }}
                    </p>
                @endif

                @if($issue['can_release'])
                    <form
                        method="POST"
                        action="{{ route('crm.messaging.delivery-issues.release', $suppression) }}"
                        class="mt-4 grid gap-3 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-end"
                    >
                        @csrf
                        <input type="hidden" name="return_to" value="{{ request()->getRequestUri() }}">

                        <div>
                            <x-ui.form.label :for="$fieldId">
                                Release only after verification
                            </x-ui.form.label>
                            <x-ui.form.select :id="$fieldId" name="resolution_reason" required>
                                <option value="">Choose a reason</option>
                                <option value="destination_verified">Verified destination is correct</option>
                                <option value="provider_issue_resolved">Provider issue resolved</option>
                                <option value="manual_review_resolved">Reviewed and safe to retry</option>
                            </x-ui.form.select>
                        </div>

                        <x-ui.button type="submit" variant="secondary">
                            Release suppression
                        </x-ui.button>
                    </form>
                @else
                    <p class="mt-4 text-sm font-medium text-amber-950">
                        Complaint suppressions are intentionally not releasable from this screen.
                    </p>
                @endif
            </div>
        @endforeach
    </div>

    <p class="text-xs leading-5 text-slate-500">
        If the Contact information is wrong, correct the Contact instead of releasing the suppression.
        The old destination remains suppressed as historical delivery evidence and automatically stops
        appearing here once it is no longer current for this Contact.
    </p>
</x-ui.card>