<x-layouts.crm
    :title="$title"
    :heading="$heading"
    subheading="Fix contact information that is preventing messages from being delivered."
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

        @if($deliveryIssues->isEmpty())
            <x-ui.card>
                <p class="text-sm font-medium text-slate-700">
                    There are no current delivery problems to fix.
                </p>
            </x-ui.card>
        @else
            <div class="space-y-4">
                @foreach($deliveryIssues as $issue)

                    <x-ui.card class="space-y-5" data-delivery-issue-id="{{ $issue['suppression']->id }}">
                        <div class="flex flex-wrap items-start justify-between gap-4">
                            <div class="min-w-0">
                                <p class="text-sm font-semibold text-red-700">
                                    {{ $issue['problem_label'] }}
                                </p>
                                @if($issue['contact'])
                                    <a
                                        href="{{ route('crm.contacts.show', $issue['contact']) }}"
                                        class="mt-1 inline-block text-lg font-semibold text-slate-950 hover:underline"
                                    >
                                        {{ $issue['contact']->display_name ?: $issue['contact']->name ?: $issue['contact']->email ?: $issue['contact']->phone }}
                                    </a>
                                @endif
                            </div>

                            <span class="rounded-full bg-red-50 px-3 py-1 text-xs font-semibold text-red-700">
                                Needs attention
                            </span>
                        </div>

                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">What happened</p>
                                <p class="mt-1 text-sm text-slate-800">{{ $issue['reason_label'] }}</p>
                                <p class="mt-1 break-all text-sm text-slate-600">{{ $issue['suppression']->destination }}</p>
                            </div>

                            <div>
                                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">What to do</p>
                                <p class="mt-1 text-sm text-slate-700">
                                    {{ $issue['action_guidance'] }}
                                </p>
                            </div>
                        </div>

                        @if($issue['contact'])
                            <div class="border-t border-slate-200 pt-4">
                                <a
                                    href="{{ route('crm.contacts.show', $issue['contact']) }}?contact_edit={{ $issue['edit_field'] }}"
                                    class="inline-flex items-center justify-center rounded-xl bg-slate-950 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-slate-800"
                                    data-delivery-issue-edit-destination
                                >
                                    Update {{ $issue['edit_field'] === 'email' ? 'email address' : 'phone number' }}
                                </a>
                            </div>
                        @endif

                        <details class="border-t border-slate-200 pt-4">
                            <summary class="cursor-pointer text-sm font-semibold text-slate-600 hover:text-slate-950">
                                More options
                            </summary>

                            <div class="mt-4 space-y-4">
                                @if($issue['provider_detail'])
                                    <div class="rounded-xl bg-slate-50 p-3">
                                        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Delivery detail</p>
                                        <p class="mt-1 text-sm leading-6 text-slate-700">{{ $issue['provider_detail'] }}</p>
                                    </div>
                                @endif
                                @if($issue['can_release'])
                                    <form
                                        method="POST"
                                        action="{{ route('crm.messaging.delivery-issues.release', $issue['suppression']) }}"
                                        class="grid gap-3 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-end"
                                    >
                                        @csrf
                                        <input type="hidden" name="return_to" value="{{ request()->getRequestUri() }}">

                                        <div>
                                            <x-ui.form.label :for="'delivery-issue-resolution-'.$issue['suppression']->id">
                                                Reopen delivery to this address or number
                                            </x-ui.form.label>
                                            <x-ui.form.select :id="'delivery-issue-resolution-'.$issue['suppression']->id" name="resolution_reason" required>
                                                <option value="">Choose why it is safe to retry</option>
                                                <option value="destination_verified">Contact information was verified</option>
                                                <option value="provider_issue_resolved">Delivery problem was resolved</option>
                                                <option value="manual_review_resolved">Reviewed and safe to retry</option>
                                            </x-ui.form.select>
                                        </div>

                                        <x-ui.button type="submit" variant="secondary">
                                            Allow messages again
                                        </x-ui.button>
                                    </form>
                                @else
                                    <p class="text-sm text-slate-700">
                                        This issue cannot be reopened from the general delivery review screen.
                                    </p>
                                @endif

                                <form
                                    method="POST"
                                    action="{{ route('crm.messaging.delivery-issues.dismiss', $issue['suppression']) }}"
                                    class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-200 pt-4"
                                >
                                    @csrf
                                    <input type="hidden" name="return_to" value="{{ request()->getRequestUri() }}">
                                    <p class="text-sm text-slate-600">Hide this item from the review list without changing delivery safety.</p>
                                    <x-ui.button type="submit" variant="secondary">Dismiss</x-ui.button>
                                </form>
                            </div>
                        </details>
                    </x-ui.card>
                @endforeach
            </div>

            <div>{{ $suppressions->links() }}</div>
        @endif
    </div>
</x-layouts.crm>