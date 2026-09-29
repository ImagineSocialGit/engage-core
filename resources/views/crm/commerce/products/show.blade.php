<x-layouts.crm
    :title="$title"
    :heading="$heading"
    subheading="Inspect canonical product identity, variants, provider mappings, and local inventory reconciliation evidence."
    module="commerce"
>
    <div class="space-y-6" data-commerce-product-detail data-commerce-product-id="{{ $detail['product']->getKey() }}">
        <div>
            <a href="{{ route('crm.commerce.index') }}" class="text-sm font-semibold text-slate-600 hover:text-slate-950">← Commerce</a>
        </div>

        @if(session('commerce_action_error'))
            <div class="rounded-2xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-900" data-commerce-action-error>
                {{ session('commerce_action_error') }}
            </div>
        @endif

        @if(session('commerce_inventory_read'))
            <div class="rounded-2xl border border-emerald-200 bg-emerald-50 p-4" data-commerce-inventory-read>
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wide text-emerald-700">Authoritative inventory check</p>
                        <p class="mt-1 font-semibold text-emerald-950">
                            Variant #{{ session('commerce_inventory_read.variant_id') }} · {{ session('commerce_inventory_read.provider_key') }}
                        </p>
                        <p class="mt-1 text-sm text-emerald-900">
                            @if(session('commerce_inventory_read.tracked'))
                                {{ number_format((int) session('commerce_inventory_read.available_quantity')) }} available
                            @else
                                Inventory is not tracked for this provider item.
                            @endif
                        </p>
                    </div>
                    <div class="text-xs text-emerald-800 sm:text-right">
                        <p>Item {{ session('commerce_inventory_read.external_inventory_item_id') }}</p>
                        <p class="mt-1">Location {{ session('commerce_inventory_read.external_location_id') }}</p>
                    </div>
                </div>
            </div>
        @endif

        <section class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
            <div class="grid gap-5 lg:grid-cols-[minmax(0,1fr)_auto] lg:items-start">
                <div>
                    <div class="flex flex-wrap items-center gap-2">
                        <h2 class="text-xl font-semibold text-slate-950">{{ $detail['product']->name }}</h2>
                        <span class="rounded-full px-2.5 py-1 text-xs font-semibold ring-1 {{ module_tone('commerce', 'badge') }}">
                            {{ ucfirst($detail['product']->status) }}
                        </span>
                    </div>
                    @if($detail['product']->description)
                        <p class="mt-3 max-w-3xl text-sm leading-6 text-slate-600">{{ $detail['product']->description }}</p>
                    @endif
                </div>

                <dl class="grid grid-cols-2 gap-2 text-sm sm:grid-cols-3">
                    <div class="rounded-2xl bg-slate-50 px-4 py-3 ring-1 ring-slate-200">
                        <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">SKU</dt>
                        <dd class="mt-1 font-semibold text-slate-950">{{ $detail['product']->sku ?: '—' }}</dd>
                    </div>
                    <div class="rounded-2xl bg-slate-50 px-4 py-3 ring-1 ring-slate-200">
                        <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Vendor</dt>
                        <dd class="mt-1 font-semibold text-slate-950">{{ $detail['product']->vendor ?: '—' }}</dd>
                    </div>
                    <div class="rounded-2xl bg-slate-50 px-4 py-3 ring-1 ring-slate-200">
                        <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Variants</dt>
                        <dd class="mt-1 font-semibold text-slate-950">{{ $detail['variants']->count() }}</dd>
                    </div>
                </dl>
            </div>

            @if($detail['product']->providerMappings->isNotEmpty())
                <div class="mt-6 border-t border-slate-100 pt-5">
                    <h3 class="text-xs font-bold uppercase tracking-wide text-slate-500">Product provider identities</h3>
                    <div class="mt-3 flex flex-wrap gap-2">
                        @foreach($detail['product']->providerMappings as $mapping)
                            @if($mapping->external_url)
                                <a href="{{ $mapping->external_url }}" target="_blank" rel="noopener noreferrer" class="rounded-full bg-slate-100 px-3 py-1.5 text-xs font-semibold text-slate-700 ring-1 ring-slate-200 hover:bg-slate-200">
                                    {{ $mapping->provider_key }} · {{ $mapping->reference_type }} · {{ $mapping->external_id }}
                                </a>
                            @else
                                <span class="rounded-full bg-slate-100 px-3 py-1.5 text-xs font-semibold text-slate-700 ring-1 ring-slate-200">
                                    {{ $mapping->provider_key }} · {{ $mapping->reference_type }} · {{ $mapping->external_id }}
                                </span>
                            @endif
                        @endforeach
                    </div>
                </div>
            @endif
        </section>

        <section class="space-y-4" data-commerce-variants>
            @forelse($detail['variants'] as $variant)
                <article class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6" data-commerce-variant-id="{{ $variant->getKey() }}">
                    <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <h2 class="text-lg font-semibold text-slate-950">{{ $variant->title ?: 'Default variant' }}</h2>
                                <span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-600 ring-1 ring-slate-200">{{ ucfirst($variant->status) }}</span>
                            </div>
                            <p class="mt-1 text-sm text-slate-500">
                                SKU {{ $variant->sku ?: '—' }}
                                @if($variant->barcode)
                                    · Barcode {{ $variant->barcode }}
                                @endif
                            </p>
                        </div>

                        <div class="flex flex-col items-start gap-3 lg:items-end">
                            @if(isset($detail['latest_inventory_effects'][$variant->getKey()]))
                                <div class="rounded-2xl px-4 py-3 ring-1 {{ module_tone('commerce', 'item') }}" data-commerce-inventory-effect>
                                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Latest local inventory evidence</p>
                                    <p class="mt-1 text-sm font-semibold text-slate-950">
                                        {{ $detail['latest_inventory_effects'][$variant->getKey()]->quantity_delta }} · {{ $detail['latest_inventory_effects'][$variant->getKey()]->status }}
                                    </p>
                                    <p class="mt-1 text-xs text-slate-500">
                                        {{ $detail['latest_inventory_effects'][$variant->getKey()]->authority_mode->value }}
                                    </p>
                                </div>
                            @endif

                            @if($canOperate && $detail['inventory_provider_key'])
                                <form method="POST" action="{{ route('crm.commerce.products.variants.inventory', [$detail['product'], $variant]) }}">
                                    @csrf
                                    <button type="submit" class="inline-flex items-center rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50" data-commerce-inventory-check="{{ $variant->getKey() }}">
                                        Check authoritative inventory
                                    </button>
                                </form>
                            @endif
                        </div>
                    </div>

                    <div class="mt-5 border-t border-slate-100 pt-5">
                        <h3 class="text-xs font-bold uppercase tracking-wide text-slate-500">Provider mappings</h3>
                        <div class="mt-3 grid gap-3 lg:grid-cols-2">
                            @forelse($variant->providerMappings as $mapping)
                                <div class="rounded-2xl bg-slate-50 p-4 ring-1 ring-slate-200">
                                    <div class="flex items-start justify-between gap-3">
                                        <div class="min-w-0">
                                            <p class="font-semibold text-slate-950">{{ $mapping->provider_key }}</p>
                                            <p class="mt-1 break-all text-xs text-slate-500">{{ $mapping->reference_type }} · {{ $mapping->external_id }}</p>
                                        </div>
                                        <span class="rounded-full bg-white px-2 py-1 text-xs font-semibold text-slate-600 ring-1 ring-slate-200">{{ ucfirst($mapping->status) }}</span>
                                    </div>
                                    @if($mapping->external_url)
                                        <a href="{{ $mapping->external_url }}" target="_blank" rel="noopener noreferrer" class="mt-3 inline-flex text-xs font-semibold text-slate-700 underline underline-offset-4">Open provider record</a>
                                    @endif
                                </div>
                            @empty
                                <p class="text-sm text-slate-500">No provider mapping is recorded for this variant.</p>
                            @endforelse
                        </div>
                    </div>
                </article>
            @empty
                <section class="rounded-3xl border border-slate-200 bg-white p-6 text-sm text-slate-500 shadow-sm">
                    This product has no canonical variants.
                </section>
            @endforelse
        </section>
    </div>
</x-layouts.crm>