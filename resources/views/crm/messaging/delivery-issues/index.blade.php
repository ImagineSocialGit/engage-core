<x-layouts.crm
    :title="$title"
    :heading="$heading"
    subheading="Review delivery problems and decide whether to correct the contact, remove it, or safely allow delivery again."
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

        <x-ui.card>
            <form method="GET" action="{{ route('crm.messaging.delivery-issues.index') }}" class="grid gap-4 md:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto] md:items-end">
                <div>
                    <x-ui.form.label for="delivery-issue-channel">Channel</x-ui.form.label>
                    <x-ui.form.select id="delivery-issue-channel" name="channel">
                        <option value="">All channels</option>
                        @foreach($filterOptions['channels'] as $value => $label)
                            <option value="{{ $value }}" @selected($filters['channel'] === $value)>{{ $label }}</option>
                        @endforeach
                    </x-ui.form.select>
                </div>

                <div>
                    <x-ui.form.label for="delivery-issue-reason">Problem</x-ui.form.label>
                    <x-ui.form.select id="delivery-issue-reason" name="reason">
                        <option value="">All problems</option>
                        @foreach($filterOptions['reasons'] as $value => $label)
                            <option value="{{ $value }}" @selected($filters['reason'] === $value)>{{ $label }}</option>
                        @endforeach
                    </x-ui.form.select>
                </div>

                <div class="flex flex-wrap gap-2">
                    <x-ui.button type="submit" variant="secondary">Apply filters</x-ui.button>
                    @if($hasFilters)
                        <x-ui.button href="{{ route('crm.messaging.delivery-issues.index') }}" variant="outline">Clear filters</x-ui.button>
                    @endif
                </div>
            </form>

            <p class="mt-4 text-sm text-slate-500">
                {{ $resultSummary }} in this view.
            </p>
        </x-ui.card>

        @if($deliveryIssues->isEmpty())
            <x-ui.card>
                <p class="text-sm font-medium text-slate-700">
                    {{ $hasFilters ? 'No delivery problems match these filters.' : 'There are no current delivery problems to fix.' }}
                </p>
                @if($hasFilters)
                    <div class="mt-4">
                        <x-ui.button href="{{ route('crm.messaging.delivery-issues.index') }}" variant="secondary">Clear filters</x-ui.button>
                    </div>
                @endif
            </x-ui.card>
        @else
            <div class="space-y-4">
                @foreach($deliveryIssues as $issue)
                    <x-ui.card
                        class="space-y-5"
                        x-data="{ removeContactOpen: false }"
                        data-delivery-issue-id="{{ $issue['suppression']->id }}"
                    >
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

                        <div class="grid gap-5 sm:grid-cols-2">
                            <div>
                                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">What happened</p>
                                <p class="mt-1 text-sm font-medium text-slate-900">{{ $issue['reason_label'] }}</p>
                                <p class="mt-1 break-all text-sm text-slate-600">{{ $issue['suppression']->destination }}</p>

                                <div class="mt-3 flex flex-wrap gap-x-3 gap-y-1 text-xs text-slate-500">
                                    @if($issue['bounce_type_label'])
                                        <span>{{ $issue['bounce_type_label'] }}</span>
                                    @endif
                                    @if($issue['provider_label'])
                                        <span>Reported by {{ $issue['provider_label'] }}</span>
                                    @endif
                                    @if($issue['suppressed_at_label'])
                                        <span>{{ $issue['suppressed_at_label'] }}</span>
                                    @endif
                                </div>

                                @if($issue['provider_detail'])
                                    <div class="mt-3 rounded-xl bg-slate-50 p-3">
                                        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Delivery provider reported</p>
                                        <p class="mt-1 text-sm leading-6 text-slate-700">{{ $issue['provider_detail'] }}</p>
                                    </div>
                                @endif
                            </div>

                            <div>
                                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">What to do</p>
                                <p class="mt-1 text-sm leading-6 text-slate-700">
                                    {{ $issue['action_guidance'] }}
                                </p>
                            </div>
                        </div>

                        @if($issue['contact'])
                            <div class="flex flex-wrap gap-2 border-t border-slate-200 pt-4">
                                <a
                                    href="{{ route('crm.contacts.show', $issue['contact']) }}?contact_edit={{ $issue['edit_field'] }}"
                                    class="inline-flex items-center justify-center rounded-xl bg-slate-950 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-slate-800"
                                    data-delivery-issue-edit-destination
                                >
                                    Update {{ $issue['edit_field'] === 'email' ? 'email address' : 'phone number' }}
                                </a>

                                <x-ui.button
                                    type="button"
                                    variant="outline"
                                    class="border-red-300 text-red-700 hover:bg-red-50"
                                    x-on:click="removeContactOpen = true"
                                >
                                    Dismiss &amp; remove contact
                                </x-ui.button>
                            </div>

                            <div
                                x-show="removeContactOpen"
                                x-cloak
                                class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/50 p-4"
                                role="dialog"
                                aria-modal="true"
                                aria-labelledby="delivery-issue-remove-contact-{{ $issue['suppression']->id }}"
                            >
                                <div
                                    class="w-full max-w-lg rounded-2xl bg-white p-6 shadow-2xl"
                                    x-on:click.outside="removeContactOpen = false"
                                >
                                    <h2 id="delivery-issue-remove-contact-{{ $issue['suppression']->id }}" class="text-lg font-semibold text-slate-950">
                                        Remove this Contact?
                                    </h2>
                                    <p class="mt-2 text-sm leading-6 text-slate-600">
                                        This removes the Contact from active CRM and stops pending Contact messaging. The failed destination and its suppression history stay retained.
                                    </p>

                                    <div class="mt-5 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                                        <x-ui.button type="button" variant="outline" x-on:click="removeContactOpen = false">
                                            Keep Contact
                                        </x-ui.button>

                                        <form
                                            method="POST"
                                            action="{{ route('crm.messaging.delivery-issues.contacts.destroy', [$issue['suppression'], $issue['contact']]) }}"
                                        >
                                            @csrf
                                            @method('DELETE')
                                            <input type="hidden" name="return_to" value="{{ request()->getRequestUri() }}">

                                            <x-ui.button
                                                type="submit"
                                                class="w-full bg-red-700 hover:bg-red-800 sm:w-auto"
                                            >
                                                Remove Contact
                                            </x-ui.button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        @endif

                        <details class="border-t border-slate-200 pt-4">
                            <summary class="cursor-pointer text-sm font-semibold text-slate-600 hover:text-slate-950">
                                More options
                            </summary>

                            <div class="mt-4 space-y-4">
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
                                    <p class="text-sm text-slate-600">Hide this item from the review list without changing delivery safety or removing the Contact.</p>
                                    <x-ui.button type="submit" variant="secondary">Dismiss only</x-ui.button>
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