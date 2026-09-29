<x-layouts.crm
    :title="$title"
    :heading="$heading"
    subheading="Inspect normalized order history and open a purchase for reconciliation evidence."
    module="commerce"
>
    <div class="space-y-6" data-commerce-orders-workspace>
        <div class="flex flex-wrap items-center justify-between gap-3">
            <a href="{{ route('crm.commerce.index') }}" class="text-sm font-semibold text-slate-600 hover:text-slate-950">← Catalog</a>
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

        <section class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm" data-commerce-orders-list>
            @if($orders->isEmpty())
                <div class="p-8 text-sm text-slate-500">No visible Commerce orders have been reconciled yet.</div>
            @else
                <div class="divide-y divide-slate-100">
                    @foreach($orders as $order)
                        <a
                            href="{{ route('crm.commerce.orders.show', $order) }}"
                            class="grid gap-4 p-5 transition hover:bg-slate-50 lg:grid-cols-[minmax(0,1fr)_auto] lg:items-center sm:p-6"
                            data-commerce-order-id="{{ $order->getKey() }}"
                        >
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <h2 class="font-semibold text-slate-950">
                                        {{ $order->order_name ?: $order->order_number ?: 'Order #'.$order->getKey() }}
                                    </h2>
                                    <span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-600 ring-1 ring-slate-200">
                                        {{ str($order->financial_status)->replace('_', ' ')->title() }}
                                    </span>
                                    @if((int) $order->purchase_confirmed_count > 0)
                                        <span class="rounded-full px-2.5 py-1 text-xs font-semibold ring-1 {{ module_tone('commerce', 'badge') }}">
                                            Purchase confirmed
                                        </span>
                                    @endif
                                </div>

                                <p class="mt-1 text-sm text-slate-500">
                                    {{ $order->provider ?: 'Unknown provider' }}
                                    @if($order->ordered_at)
                                        · {{ $order->ordered_at->setTimezone(config('client.timezone', config('app.timezone', 'UTC')))->format('M j, Y g:i A') }}
                                    @endif
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
                                <p class="font-semibold text-slate-950">
                                    {{ $order->currency ?: 'USD' }} {{ number_format(((int) $order->total_cents) / 100, 2) }}
                                </p>
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