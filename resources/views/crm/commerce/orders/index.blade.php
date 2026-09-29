<x-layouts.crm
    :title="$title"
    :heading="$heading"
    subheading="Inspect normalized order history, customer linkage, and purchase reconciliation evidence."
    module="commerce"
>
    <div class="space-y-6" data-commerce-orders-workspace>
        <div class="flex flex-wrap items-center justify-between gap-3">
            <a href="{{ route('crm.commerce.index') }}" class="text-sm font-semibold text-slate-600 hover:text-slate-950">← Catalog</a>
            <p class="text-sm text-slate-500">{{ number_format($orders->total()) }} matching</p>
        </div>

        <section class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4" data-commerce-order-summary>
            <div class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Visible orders</p>
                <p class="mt-2 text-2xl font-semibold text-slate-950">{{ number_format($summary['total']) }}</p>
            </div>
            <div class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Paid</p>
                <p class="mt-2 text-2xl font-semibold text-slate-950">{{ number_format($summary['paid']) }}</p>
            </div>
            <div class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Refunded / partial</p>
                <p class="mt-2 text-2xl font-semibold text-slate-950">{{ number_format($summary['refunded']) }}</p>
            </div>
            <div class="rounded-2xl px-4 py-4 shadow-sm ring-1 {{ module_tone('commerce', 'item') }}">
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Purchase confirmed</p>
                <p class="mt-2 text-2xl font-semibold text-slate-950">{{ number_format($summary['purchase_confirmed']) }}</p>
            </div>
        </section>

        <section class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6" data-commerce-order-filters>
            <form method="GET" action="{{ route('crm.commerce.orders.index') }}" class="grid gap-3 xl:grid-cols-[minmax(14rem,1.5fr)_repeat(4,minmax(9rem,0.8fr))_auto] xl:items-end">
                <label class="block">
                    <span class="text-xs font-semibold uppercase tracking-wide text-slate-500">Search</span>
                    <input type="search" name="q" value="{{ $filters['q'] }}" placeholder="Order, customer, email, provider ID" class="mt-2 block w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-slate-500 focus:outline-none focus:ring-1 focus:ring-slate-500">
                </label>

                <label class="block">
                    <span class="text-xs font-semibold uppercase tracking-wide text-slate-500">Financial</span>
                    <select name="financial_status" class="mt-2 block w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-slate-500 focus:outline-none focus:ring-1 focus:ring-slate-500">
                        <option value="">All</option>
                        @foreach($filterOptions['financial_statuses'] as $status)
                            <option value="{{ $status }}" @selected($filters['financial_status'] === $status)>{{ str($status)->replace('_', ' ')->title() }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="block">
                    <span class="text-xs font-semibold uppercase tracking-wide text-slate-500">Fulfillment</span>
                    <select name="fulfillment_status" class="mt-2 block w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-slate-500 focus:outline-none focus:ring-1 focus:ring-slate-500">
                        <option value="">All</option>
                        @foreach($filterOptions['fulfillment_statuses'] as $status)
                            <option value="{{ $status }}" @selected($filters['fulfillment_status'] === $status)>{{ str($status)->replace('_', ' ')->title() }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="block">
                    <span class="text-xs font-semibold uppercase tracking-wide text-slate-500">Provider</span>
                    <select name="provider" class="mt-2 block w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-slate-500 focus:outline-none focus:ring-1 focus:ring-slate-500">
                        <option value="">All</option>
                        @foreach($filterOptions['providers'] as $provider)
                            <option value="{{ $provider }}" @selected($filters['provider'] === $provider)>{{ $provider }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="block">
                    <span class="text-xs font-semibold uppercase tracking-wide text-slate-500">Confirmation</span>
                    <select name="confirmation" class="mt-2 block w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-slate-500 focus:outline-none focus:ring-1 focus:ring-slate-500">
                        <option value="">All</option>
                        <option value="confirmed" @selected($filters['confirmation'] === 'confirmed')>Confirmed</option>
                        <option value="unconfirmed" @selected($filters['confirmation'] === 'unconfirmed')>Not confirmed</option>
                    </select>
                </label>

                <div class="flex gap-2">
                    <button type="submit" class="inline-flex items-center justify-center rounded-xl bg-slate-950 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">Filter</button>
                    <a href="{{ route('crm.commerce.orders.index') }}" class="inline-flex items-center justify-center rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">Clear</a>
                </div>
            </form>
        </section>

        <section class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm" data-commerce-orders-list>
            @if($orders->isEmpty())
                <div class="p-8 text-sm text-slate-500">No visible Commerce orders match the current filters.</div>
            @else
                <div class="divide-y divide-slate-100">
                    @foreach($orders as $order)
                        <a href="{{ route('crm.commerce.orders.show', $order) }}" class="grid gap-4 p-5 transition hover:bg-slate-50 lg:grid-cols-[minmax(0,1fr)_auto] lg:items-center sm:p-6" data-commerce-order-id="{{ $order->getKey() }}">
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <h2 class="font-semibold text-slate-950">{{ $order->order_name ?: $order->order_number ?: 'Order #'.$order->getKey() }}</h2>
                                    <span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-600 ring-1 ring-slate-200">{{ str($order->financial_status)->replace('_', ' ')->title() }}</span>
                                    @if($order->fulfillment_status)
                                        <span class="rounded-full bg-slate-50 px-2.5 py-1 text-xs font-semibold text-slate-500 ring-1 ring-slate-200">{{ str($order->fulfillment_status)->replace('_', ' ')->title() }}</span>
                                    @endif
                                    @if((int) $order->purchase_confirmed_count > 0)
                                        <span class="rounded-full px-2.5 py-1 text-xs font-semibold ring-1 {{ module_tone('commerce', 'badge') }}">Purchase confirmed</span>
                                    @endif
                                </div>

                                <p class="mt-1 text-sm text-slate-500">
                                    {{ $order->provider ?: 'Unknown provider' }}
                                    @if($order->ordered_at) · {{ $order->ordered_at->setTimezone(config('client.timezone', config('app.timezone', 'UTC')))->format('M j, Y g:i A') }} @endif
                                    · {{ $order->items_count }} {{ \Illuminate\Support\Str::plural('item', $order->items_count) }}
                                </p>

                                <p class="mt-2 text-sm text-slate-700">
                                    @if($order->contact)
                                        {{ $order->contact->name ?: $order->contact->email ?: 'Contact #'.$order->contact->getKey() }}
                                    @elseif($order->commerceCustomer)
                                        {{ $order->commerceCustomer->name ?: $order->commerceCustomer->email ?: 'Commerce customer #'.$order->commerceCustomer->getKey() }}
                                    @else
                                        Unlinked customer
                                    @endif
                                </p>
                            </div>

                            <div class="text-left lg:text-right">
                                <p class="font-semibold text-slate-950">{{ $order->currency ?: 'USD' }} {{ number_format(((int) $order->total_cents) / 100, 2) }}</p>
                                <p class="mt-1 text-xs font-semibold text-slate-500">Open →</p>
                            </div>
                        </a>
                    @endforeach
                </div>
            @endif
        </section>

        @if($orders->hasPages())
            <div>{{ $orders->links() }}</div>
        @endif
    </div>
</x-layouts.crm>