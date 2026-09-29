<x-ui.card
    class="space-y-5 {{ module_tone('commerce', 'panel') }}"
    data-module-panel="commerce"
    data-commerce-purchase-history
>
    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <h3 class="text-lg font-semibold tracking-tight">
                {{ $contactPanel->title }}
            </h3>
            <p class="mt-1 text-sm text-slate-600">
                {{ $purchaseHistory['description'] }}
            </p>
        </div>

        <div class="flex flex-wrap gap-2 text-xs font-semibold">
            <span class="rounded-full px-2.5 py-1 ring-1 {{ module_tone('commerce', 'badge') }}" data-commerce-order-count="{{ $purchaseHistory['order_count'] }}">
                {{ $purchaseHistory['order_count_label'] }}
            </span>
            <span class="rounded-full bg-white/70 px-2.5 py-1 text-slate-700 ring-1 ring-slate-200" data-commerce-confirmed-count="{{ $purchaseHistory['confirmed_purchase_count'] }}">
                {{ $purchaseHistory['confirmed_count_label'] }}
            </span>
        </div>
    </div>

    @if($purchaseHistory['confirmed_values']->isNotEmpty())
        <div class="flex flex-wrap items-center gap-2 text-sm" data-commerce-confirmed-values>
            <span class="font-medium text-slate-600">Confirmed order value:</span>
            @foreach($purchaseHistory['confirmed_values'] as $value)
                <span class="rounded-lg bg-white/70 px-2.5 py-1 font-semibold text-slate-900 ring-1 ring-slate-200">
                    {{ $value['label'] }}
                </span>
            @endforeach
        </div>
    @endif

    <div class="divide-y divide-slate-200/80 overflow-hidden rounded-2xl border border-slate-200 bg-white/70">
        @foreach($purchaseHistory['recent_orders'] as $order)
            <a
                href="{{ $order['url'] }}"
                class="block p-4 transition hover:bg-white"
                data-commerce-order-id="{{ $order['id'] }}"
            >
                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="font-semibold text-slate-950">
                                {{ $order['label'] }}
                            </span>

                            @if($order['purchase_confirmed'])
                                <span class="rounded-full bg-emerald-100 px-2 py-0.5 text-[11px] font-bold text-emerald-800 ring-1 ring-emerald-200">
                                    Purchase confirmed
                                </span>
                            @endif
                        </div>

                        <p class="mt-1 text-sm text-slate-500">
                            {{ $order['ordered_at_label'] }}
                            @if($order['provider'] !== null)
                                · {{ $order['provider'] }}
                            @endif
                        </p>

                        @if($order['item_summary'] !== null)
                            <p class="mt-2 text-sm leading-5 text-slate-700">
                                {{ $order['item_summary'] }}
                            </p>
                        @endif
                    </div>

                    <div class="shrink-0 text-left sm:text-right">
                        <div class="font-semibold text-slate-950">
                            {{ $order['total_label'] }}
                        </div>
                        <div class="mt-1 text-xs font-medium text-slate-500">
                            {{ $order['financial_status_label'] }}
                            @if($order['fulfillment_status_label'] !== null)
                                · {{ $order['fulfillment_status_label'] }}
                            @endif
                        </div>
                    </div>
                </div>
            </a>
        @endforeach
    </div>

    @if($purchaseHistory['truncation_note'] !== null)
        <p class="text-xs leading-5 text-slate-500">
            {{ $purchaseHistory['truncation_note'] }}
        </p>
    @endif
</x-ui.card>