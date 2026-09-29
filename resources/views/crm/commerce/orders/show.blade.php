<x-layouts.crm
    :title="$title"
    :heading="$heading"
    subheading="Inspect the normalized purchase, customer linkage, lifecycle history, purchase confirmation, and inventory reconciliation evidence."
    module="commerce"
>
    <div class="space-y-6" data-commerce-order-detail data-commerce-order-id="{{ $detail['order']->getKey() }}">
        <div class="flex flex-wrap items-center gap-4">
            <a href="{{ route('crm.commerce.orders.index') }}" class="text-sm font-semibold text-slate-600 hover:text-slate-950">← Orders</a>
            <a href="{{ route('crm.commerce.index') }}" class="text-sm font-semibold text-slate-600 hover:text-slate-950">Catalog</a>
        </div>

        <section class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
            <div class="grid gap-5 xl:grid-cols-[minmax(0,1fr)_auto] xl:items-start">
                <div>
                    <div class="flex flex-wrap items-center gap-2">
                        <h2 class="text-xl font-semibold text-slate-950">
                            {{ $detail['order']->order_name ?: $detail['order']->order_number ?: 'Order #'.$detail['order']->getKey() }}
                        </h2>
                        <span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-600 ring-1 ring-slate-200">
                            {{ str($detail['order']->status)->replace('_', ' ')->title() }}
                        </span>
                        <span class="rounded-full px-2.5 py-1 text-xs font-semibold ring-1 {{ module_tone('commerce', 'badge') }}">
                            {{ str($detail['order']->financial_status)->replace('_', ' ')->title() }}
                        </span>
                    </div>

                    <p class="mt-2 text-sm text-slate-500">
                        {{ $detail['order']->provider ?: 'Unknown provider' }}
                        @if($detail['order']->external_id)
                            · {{ $detail['order']->external_id }}
                        @endif
                    </p>

                    @if($detail['order']->external_url)
                        <a href="{{ $detail['order']->external_url }}" target="_blank" rel="noopener noreferrer" class="mt-3 inline-flex text-sm font-semibold text-slate-700 underline underline-offset-4">
                            Open provider order
                        </a>
                    @endif
                </div>

                <dl class="grid grid-cols-2 gap-2 text-sm sm:grid-cols-3 xl:grid-cols-5">
                    <div class="rounded-2xl bg-slate-50 px-4 py-3 ring-1 ring-slate-200">
                        <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Subtotal</dt>
                        <dd class="mt-1 font-semibold text-slate-950">{{ $detail['order']->currency ?: 'USD' }} {{ number_format(((int) $detail['order']->subtotal_cents) / 100, 2) }}</dd>
                    </div>
                    <div class="rounded-2xl bg-slate-50 px-4 py-3 ring-1 ring-slate-200">
                        <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Discount</dt>
                        <dd class="mt-1 font-semibold text-slate-950">{{ number_format(((int) $detail['order']->discount_cents) / 100, 2) }}</dd>
                    </div>
                    <div class="rounded-2xl bg-slate-50 px-4 py-3 ring-1 ring-slate-200">
                        <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Tax</dt>
                        <dd class="mt-1 font-semibold text-slate-950">{{ number_format(((int) $detail['order']->tax_cents) / 100, 2) }}</dd>
                    </div>
                    <div class="rounded-2xl bg-slate-50 px-4 py-3 ring-1 ring-slate-200">
                        <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Shipping</dt>
                        <dd class="mt-1 font-semibold text-slate-950">{{ number_format(((int) $detail['order']->shipping_cents) / 100, 2) }}</dd>
                    </div>
                    <div class="rounded-2xl px-4 py-3 ring-1 {{ module_tone('commerce', 'item') }}">
                        <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Total</dt>
                        <dd class="mt-1 font-semibold text-slate-950">{{ $detail['order']->currency ?: 'USD' }} {{ number_format(((int) $detail['order']->total_cents) / 100, 2) }}</dd>
                    </div>
                </dl>
            </div>
        </section>

        <section class="grid gap-5 lg:grid-cols-2" data-commerce-order-linkage>
            <div class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
                <h2 class="text-lg font-semibold text-slate-950">Contact</h2>
                @if($detail['order']->contact)
                    <p class="mt-3 font-semibold text-slate-900">
                        {{ $detail['order']->contact->name ?: $detail['order']->contact->email ?: 'Contact #'.$detail['order']->contact->getKey() }}
                    </p>
                    @if($detail['order']->contact->email)
                        <p class="mt-1 text-sm text-slate-600">{{ $detail['order']->contact->email }}</p>
                    @endif
                    @if($detail['order']->contact->phone)
                        <p class="mt-1 text-sm text-slate-600">{{ $detail['order']->contact->phone }}</p>
                    @endif
                    <a href="{{ route('crm.contacts.show', $detail['order']->contact) }}" class="mt-4 inline-flex text-sm font-semibold text-slate-700 underline underline-offset-4">Open Contact</a>
                @else
                    <p class="mt-3 text-sm text-slate-500">This order is not linked to a Contact.</p>
                @endif
            </div>

            <div class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
                <h2 class="text-lg font-semibold text-slate-950">Commerce customer</h2>
                @if($detail['order']->commerceCustomer)
                    <p class="mt-3 font-semibold text-slate-900">
                        {{ $detail['order']->commerceCustomer->name ?: $detail['order']->commerceCustomer->email ?: 'Customer #'.$detail['order']->commerceCustomer->getKey() }}
                    </p>
                    @if($detail['order']->commerceCustomer->email)
                        <p class="mt-1 text-sm text-slate-600">{{ $detail['order']->commerceCustomer->email }}</p>
                    @endif
                    @if($detail['order']->commerceCustomer->provider)
                        <p class="mt-2 text-xs font-semibold uppercase tracking-wide text-slate-500">
                            {{ $detail['order']->commerceCustomer->provider }} · {{ $detail['order']->commerceCustomer->external_id ?: 'no external id' }}
                        </p>
                    @endif
                    @if($detail['order']->commerceCustomer->external_url)
                        <a href="{{ $detail['order']->commerceCustomer->external_url }}" target="_blank" rel="noopener noreferrer" class="mt-4 inline-flex text-sm font-semibold text-slate-700 underline underline-offset-4">Open provider customer</a>
                    @endif
                @else
                    <p class="mt-3 text-sm text-slate-500">No canonical Commerce customer is linked to this order.</p>
                @endif
            </div>
        </section>

        <section class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6" data-commerce-purchase-confirmation="{{ $detail['purchase_confirmation']['state'] }}">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h2 class="text-lg font-semibold text-slate-950">Purchase confirmation</h2>
                    <p class="mt-1 text-sm text-slate-600">Durable provider-neutral evidence produced after authoritative paid reconciliation.</p>
                </div>
                <span class="rounded-full px-2.5 py-1 text-xs font-semibold ring-1 {{ $detail['purchase_confirmation']['state'] === 'confirmed' ? module_tone('commerce', 'badge') : 'bg-slate-100 text-slate-600 ring-slate-200' }}">
                    {{ str($detail['purchase_confirmation']['state'])->title() }}
                </span>
            </div>

            @if($detail['purchase_confirmation']['confirmation'])
                <dl class="mt-5 grid gap-3 text-sm sm:grid-cols-2 lg:grid-cols-4">
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Outcome event</dt>
                        <dd class="mt-1 font-semibold text-slate-950">#{{ $detail['purchase_confirmation']['event']->getKey() }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Confirmed items</dt>
                        <dd class="mt-1 font-semibold text-slate-950">{{ count($detail['purchase_confirmation']['confirmation']->commerceOrderItemIds) }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Contact at confirmation</dt>
                        <dd class="mt-1 font-semibold text-slate-950">{{ $detail['purchase_confirmation']['confirmation']->contactId ?: '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Occurred</dt>
                        <dd class="mt-1 font-semibold text-slate-950">{{ $detail['purchase_confirmation']['confirmation']->occurredAt->setTimezone(config('client.timezone', config('app.timezone', 'UTC')))->format('M j, Y g:i A') }}</dd>
                    </div>
                </dl>
            @elseif($detail['purchase_confirmation']['state'] === 'invalid')
                <p class="mt-4 rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm leading-6 text-amber-900">
                    The durable purchase-confirmation event exists but failed contract validation: {{ $detail['purchase_confirmation']['error'] }}
                </p>
            @else
                <p class="mt-4 text-sm text-slate-500">No durable purchase-confirmation outcome has been recorded for this order.</p>
            @endif
        </section>

        <section class="space-y-4" data-commerce-order-items>
            <div>
                <h2 class="text-lg font-semibold text-slate-950">Line items</h2>
                <p class="mt-1 text-sm text-slate-600">Purchase-time snapshots remain historical even when current catalog copy or pricing changes.</p>
            </div>

            @forelse($detail['items'] as $item)
                <article class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6" data-commerce-order-item-id="{{ $item->getKey() }}">
                    <div class="grid gap-5 lg:grid-cols-[minmax(0,1fr)_auto] lg:items-start">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <h3 class="font-semibold text-slate-950">{{ $item->title ?: $item->name ?: 'Order item #'.$item->getKey() }}</h3>
                                @if($item->variant_title)
                                    <span class="rounded-full bg-slate-100 px-2 py-1 text-xs font-semibold text-slate-600 ring-1 ring-slate-200">{{ $item->variant_title }}</span>
                                @endif
                            </div>
                            <p class="mt-1 text-sm text-slate-500">
                                Qty {{ $item->quantity }}
                                @if($item->sku)
                                    · SKU {{ $item->sku }}
                                @endif
                                @if($item->fulfillment_status)
                                    · {{ str($item->fulfillment_status)->replace('_', ' ')->title() }}
                                @endif
                            </p>

                            <div class="mt-3 flex flex-wrap gap-3 text-xs font-semibold">
                                @if($item->commerceProduct)
                                    <a href="{{ route('crm.commerce.products.show', $item->commerceProduct) }}" class="text-slate-700 underline underline-offset-4">Open canonical product</a>
                                @endif
                                @if($item->external_url)
                                    <a href="{{ $item->external_url }}" target="_blank" rel="noopener noreferrer" class="text-slate-700 underline underline-offset-4">Open provider item</a>
                                @endif
                            </div>
                        </div>

                        <dl class="grid grid-cols-2 gap-2 text-sm sm:grid-cols-3">
                            <div class="rounded-2xl bg-slate-50 px-3 py-2 ring-1 ring-slate-200">
                                <dt class="text-xs text-slate-500">Unit</dt>
                                <dd class="mt-1 font-semibold text-slate-950">{{ $item->currency ?: $detail['order']->currency ?: 'USD' }} {{ number_format(((int) $item->unit_price_cents) / 100, 2) }}</dd>
                            </div>
                            <div class="rounded-2xl bg-slate-50 px-3 py-2 ring-1 ring-slate-200">
                                <dt class="text-xs text-slate-500">Discount</dt>
                                <dd class="mt-1 font-semibold text-slate-950">{{ number_format(((int) $item->discount_cents) / 100, 2) }}</dd>
                            </div>
                            <div class="rounded-2xl px-3 py-2 ring-1 {{ module_tone('commerce', 'item') }}">
                                <dt class="text-xs text-slate-500">Total</dt>
                                <dd class="mt-1 font-semibold text-slate-950">{{ $item->currency ?: $detail['order']->currency ?: 'USD' }} {{ number_format(((int) $item->total_cents) / 100, 2) }}</dd>
                            </div>
                        </dl>
                    </div>

                    <div class="mt-5 border-t border-slate-100 pt-5">
                        <h4 class="text-xs font-bold uppercase tracking-wide text-slate-500">Inventory reconciliation evidence</h4>
                        <div class="mt-3 space-y-3">
                            @forelse(($detail['inventory_effects_by_item'][$item->getKey()] ?? []) as $effect)
                                <div class="rounded-2xl bg-slate-50 p-4 ring-1 ring-slate-200" data-commerce-inventory-effect-id="{{ $effect->getKey() }}">
                                    <div class="flex flex-wrap items-start justify-between gap-3">
                                        <div>
                                            <p class="font-semibold text-slate-950">{{ $effect->quantity_delta }} · {{ str($effect->status)->replace('_', ' ')->title() }}</p>
                                            <p class="mt-1 text-xs text-slate-500">{{ $effect->reason }} · {{ $effect->authority_mode->value }}</p>
                                        </div>
                                        <span class="rounded-full bg-white px-2 py-1 text-xs font-semibold text-slate-600 ring-1 ring-slate-200">Effect #{{ $effect->getKey() }}</span>
                                    </div>

                                    @if($effect->adjustments->isNotEmpty())
                                        <div class="mt-3 border-t border-slate-200 pt-3">
                                            @foreach($effect->adjustments as $adjustment)
                                                <p class="text-xs leading-5 text-slate-600">
                                                    {{ $adjustment->provider_key }} adjustment · {{ $adjustment->quantity_delta }} · {{ str($adjustment->status)->replace('_', ' ')->title() }}
                                                    @if($adjustment->external_id)
                                                        · {{ $adjustment->external_id }}
                                                    @endif
                                                </p>
                                            @endforeach
                                        </div>
                                    @endif
                                </div>
                            @empty
                                <p class="text-sm text-slate-500">No order-linked inventory effect is recorded for this line item.</p>
                            @endforelse
                        </div>
                    </div>
                </article>
            @empty
                <section class="rounded-3xl border border-slate-200 bg-white p-6 text-sm text-slate-500 shadow-sm">This order has no current normalized line items.</section>
            @endforelse
        </section>

        <section class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm" data-commerce-order-events>
            <div class="border-b border-slate-200 px-5 py-4 sm:px-6">
                <h2 class="text-lg font-semibold text-slate-950">Lifecycle history</h2>
                <p class="mt-1 text-sm text-slate-600">Compact normalized order history. Raw provider webhook payloads are intentionally not shown here.</p>
            </div>

            @if($detail['events']->isEmpty())
                <div class="p-6 text-sm text-slate-500">No normalized order events are recorded.</div>
            @else
                <div class="divide-y divide-slate-100">
                    @foreach($detail['events'] as $event)
                        <div class="grid gap-3 p-5 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-start sm:p-6" data-commerce-order-event-id="{{ $event->getKey() }}">
                            <div>
                                <div class="flex flex-wrap items-center gap-2">
                                    <p class="font-semibold text-slate-950">{{ str($event->event)->replace('_', ' ')->title() }}</p>
                                    <span class="rounded-full bg-slate-100 px-2 py-1 text-xs font-semibold text-slate-600 ring-1 ring-slate-200">{{ $event->source ?: 'unknown source' }}</span>
                                </div>
                                <p class="mt-1 text-sm text-slate-500">
                                    {{ $event->provider ?: $detail['order']->provider ?: 'Unknown provider' }}
                                    @if(data_get($event->meta, 'provider_event_type'))
                                        · {{ data_get($event->meta, 'provider_event_type') }}
                                    @endif
                                </p>
                                @if($event->from_status || $event->to_status)
                                    <p class="mt-2 text-sm text-slate-700">
                                        {{ $event->from_status ?: '—' }} → {{ $event->to_status ?: '—' }}
                                    </p>
                                @endif
                            </div>
                            <div class="text-sm text-slate-500 sm:text-right">
                                {{ $event->occurred_at?->setTimezone(config('client.timezone', config('app.timezone', 'UTC')))->format('M j, Y g:i A') ?: 'No timestamp' }}
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </section>
    </div>
</x-layouts.crm>