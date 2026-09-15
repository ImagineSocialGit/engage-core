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

                <div class="mt-4 grid gap-3 lg:grid-cols-2" data-delivery-issue-resolution-options>
                    @if($issue['can_release'])
                        <div class="rounded-xl border border-slate-200 bg-white p-3">
                            <p class="text-xs font-semibold text-slate-950">Keep this Contact</p>
                            <p class="mt-1 text-xs leading-5 text-slate-500">
                                Release only when this destination is genuinely correct and safe to retry.
                            </p>

                            <form
                                method="POST"
                                action="{{ route('crm.messaging.delivery-issues.release', $suppression) }}"
                                class="mt-3 space-y-2"
                            >
                                @csrf
                                <input type="hidden" name="return_to" value="{{ request()->getRequestUri() }}">

                                <div>
                                    <x-ui.form.label :for="$fieldId">
                                        Release reason
                                    </x-ui.form.label>
                                    <x-ui.form.select :id="$fieldId" name="resolution_reason" required>
                                        <option value="">Choose a reason</option>
                                        <option value="destination_verified">Verified destination is correct</option>
                                        <option value="provider_issue_resolved">Provider issue resolved</option>
                                        <option value="manual_review_resolved">Reviewed and safe to retry</option>
                                    </x-ui.form.select>
                                </div>

                                <x-ui.button type="submit" variant="secondary" class="w-full justify-center">
                                    Release suppression
                                </x-ui.button>
                            </form>
                        </div>
                    @else
                        <div class="rounded-xl border border-slate-200 bg-white p-3">
                            <p class="text-xs font-semibold text-slate-950">Keep this Contact</p>
                            <p class="mt-1 text-xs leading-5 text-slate-500">
                                Complaint suppressions are intentionally not releasable from this screen.
                            </p>
                        </div>
                    @endif

                    <div class="rounded-xl border border-red-200 bg-red-50 p-3">
                        <p class="text-xs font-semibold text-red-900">Delete this Contact</p>
                        <p class="mt-1 text-xs leading-5 text-red-800/80">
                            Use this when the Contact itself is invalid or should no longer exist in the active CRM.
                            The suppression remains historical delivery evidence.
                        </p>

                        <form
                            method="POST"
                            action="{{ route('crm.contacts.destroy', $contact) }}"
                            class="mt-3"
                            x-on:submit="if (! window.confirm('Delete this Contact? Historical delivery and suppression evidence will be retained.')) $event.preventDefault()"
                            data-delivery-issue-delete-contact
                        >
                            @csrf
                            @method('DELETE')

                            <button
                                type="submit"
                                class="w-full rounded-xl border border-red-300 bg-white px-4 py-2.5 text-xs font-semibold uppercase tracking-[0.14em] text-red-700 hover:bg-red-100"
                            >
                                Delete Contact
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    <p class="text-xs leading-5 text-slate-500">
        If only the destination is wrong, correct the Contact instead of releasing the suppression.
        If the Contact itself is invalid, delete the Contact. In either case, the old destination remains
        suppressed as historical delivery evidence unless an operator explicitly releases it.
    </p>
</x-ui.card>