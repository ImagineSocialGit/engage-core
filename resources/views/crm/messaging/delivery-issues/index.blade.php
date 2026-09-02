<x-layouts.crm
    :title="$title"
    :heading="$heading"
    subheading="Review bounced or suppressed destinations that still match current Contact information."
>
    <div class="max-w-5xl space-y-6">
        @if(session('success'))
            <div class="rounded-2xl border border-emerald-200 bg-emerald-50 p-4 text-sm font-medium text-emerald-900">
                {{ session('success') }}
            </div>
        @endif

        @if($errors->any())
            <div class="rounded-2xl border border-red-200 bg-red-50 p-4 text-sm text-red-900">
                {{ $errors->first() }}
            </div>
        @endif

        <x-ui.card class="space-y-4">
            <div>
                <h2 class="text-base font-semibold tracking-tight text-slate-950">
                    Review rules
                </h2>
                <p class="mt-1 text-sm leading-6 text-slate-600">
                    Correct bad Contact information instead of releasing its old suppression. Release a
                    suppression only when the current destination has been verified or the provider problem
                    has actually been resolved. Complaint suppressions cannot be released here.
                </p>
            </div>
        </x-ui.card>

        @if($deliveryIssues->isEmpty())
            <x-ui.card>
                <p class="text-sm font-medium text-slate-700">
                    No current Contact destinations need delivery review.
                </p>
            </x-ui.card>
        @else
            <div class="space-y-4">
                @foreach($deliveryIssues as $issue)
                    @php
                        $suppression = $issue['suppression'];
                        $fieldId = 'delivery-issue-resolution-'.$suppression->id;
                    @endphp

                    <x-ui.card class="space-y-4" data-delivery-issue-id="{{ $suppression->id }}">
                        <div class="flex flex-wrap items-start justify-between gap-4">
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="rounded-full bg-amber-100 px-2.5 py-1 text-xs font-bold text-amber-900">
                                        {{ $issue['reason_label'] }}
                                    </span>
                                    <span class="text-xs font-semibold uppercase tracking-wide text-slate-500">
                                        {{ $suppression->channel }}
                                    </span>
                                </div>

                                <p class="mt-3 break-all text-base font-semibold text-slate-950">
                                    {{ $suppression->destination }}
                                </p>

                                @if($issue['contacts']->isNotEmpty())
                                    <div class="mt-2 flex flex-wrap gap-x-3 gap-y-1 text-sm text-slate-600">
                                        @foreach($issue['contacts'] as $contact)
                                            <a
                                                href="{{ route('crm.contacts.show', $contact) }}"
                                                class="font-semibold text-slate-800 underline decoration-slate-300 underline-offset-4 hover:text-slate-950"
                                            >
                                                {{ $contact->name ?: $contact->email }}
                                            </a>
                                        @endforeach
                                    </div>
                                @endif
                            </div>

                            <div class="text-right text-xs text-slate-500">
                                @if(filled($suppression->provider))
                                    <div>{{ strtoupper($suppression->provider) }}</div>
                                @endif
                                @if($suppression->suppressed_at)
                                    <div class="mt-1">
                                        {{ $suppression->suppressed_at->timezone(config('client.timezone', config('app.timezone', 'UTC')))->format('M j, Y g:i A') }}
                                    </div>
                                @endif
                            </div>
                        </div>

                        @if($issue['can_release'])
                            <form
                                method="POST"
                                action="{{ route('crm.messaging.delivery-issues.release', $suppression) }}"
                                class="grid gap-3 border-t border-slate-200 pt-4 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-end"
                            >
                                @csrf
                                <input type="hidden" name="return_to" value="{{ request()->getRequestUri() }}">

                                <div>
                                    <x-ui.form.label :for="$fieldId">
                                        Resolution
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
                            <div class="border-t border-slate-200 pt-4 text-sm font-medium text-amber-900">
                                Complaint suppressions require a separate consent/provider remediation path and cannot be reopened here.
                            </div>
                        @endif
                    </x-ui.card>
                @endforeach
            </div>

            <div>
                {{ $suppressions->links() }}
            </div>
        @endif
    </div>
</x-layouts.crm>