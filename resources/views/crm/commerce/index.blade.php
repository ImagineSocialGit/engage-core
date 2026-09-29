<x-layouts.crm
    :title="$title"
    :heading="$heading"
    subheading="Review canonical products, provider coverage, mapping gaps, and reconciliation state."
    module="commerce"
>
    <div class="space-y-6" data-commerce-workspace>
        <div class="flex flex-wrap items-center justify-between gap-3">
            <p class="text-sm text-slate-600">Normalized Commerce state. Provider-specific store administration stays with the provider.</p>
            <a
                href="{{ route('crm.commerce.orders.index') }}"
                class="inline-flex items-center rounded-xl px-4 py-2 text-sm font-semibold ring-1 transition {{ module_tone('commerce', 'badge') }} hover:brightness-95"
                data-commerce-orders-link
            >
                View orders
            </a>
        </div>

        <section class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <x-ui.card>
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Products</p>
                <p class="mt-2 text-2xl font-semibold text-slate-950">{{ number_format($overview['product_count']) }}</p>
                <p class="mt-1 text-sm text-slate-500">{{ number_format($overview['active_product_count']) }} active</p>
            </x-ui.card>

            <x-ui.card>
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Variants</p>
                <p class="mt-2 text-2xl font-semibold text-slate-950">{{ number_format($overview['variant_count']) }}</p>
                <p class="mt-1 text-sm text-slate-500">{{ number_format($overview['active_variant_count']) }} active</p>
            </x-ui.card>

            <x-ui.card>
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Providers represented</p>
                <p class="mt-2 text-2xl font-semibold text-slate-950">{{ number_format(count($overview['provider_keys'])) }}</p>
                <p class="mt-1 text-sm text-slate-500">Across product and variant mappings</p>
            </x-ui.card>

            <x-ui.card>
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Catalog authority</p>
                <p class="mt-2 truncate text-base font-semibold text-slate-950">
                    {{ $overview['role_bindings']['catalog']['default'] ?? 'Not configured' }}
                </p>
                <p class="mt-1 text-sm text-slate-500">Configured provider</p>
            </x-ui.card>
        </section>

        <section class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6" data-commerce-product-filters>
            <form method="GET" action="{{ route('crm.commerce.index') }}" class="grid gap-3 lg:grid-cols-[minmax(14rem,1.6fr)_repeat(3,minmax(10rem,0.8fr))_auto] lg:items-end">
                <label class="block">
                    <span class="text-xs font-semibold uppercase tracking-wide text-slate-500">Search</span>
                    <input
                        type="search"
                        name="q"
                        value="{{ $filters['q'] }}"
                        placeholder="Product, variant, SKU, vendor"
                        class="mt-2 block w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-slate-500 focus:outline-none focus:ring-1 focus:ring-slate-500"
                    >
                </label>

                <label class="block">
                    <span class="text-xs font-semibold uppercase tracking-wide text-slate-500">Status</span>
                    <select name="status" class="mt-2 block w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-slate-500 focus:outline-none focus:ring-1 focus:ring-slate-500">
                        <option value="">All statuses</option>
                        @foreach($filterOptions['statuses'] as $status)
                            <option value="{{ $status }}" @selected($filters['status'] === $status)>{{ str($status)->replace('_', ' ')->title() }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="block">
                    <span class="text-xs font-semibold uppercase tracking-wide text-slate-500">Provider</span>
                    <select name="provider" class="mt-2 block w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-slate-500 focus:outline-none focus:ring-1 focus:ring-slate-500">
                        <option value="">All providers</option>
                        @foreach($filterOptions['providers'] as $provider)
                            <option value="{{ $provider }}" @selected($filters['provider'] === $provider)>{{ $provider }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="block">
                    <span class="text-xs font-semibold uppercase tracking-wide text-slate-500">Inventory mapping</span>
                    <select name="mapping" class="mt-2 block w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-slate-500 focus:outline-none focus:ring-1 focus:ring-slate-500" @disabled(!$filterOptions['inventory_provider_key'])>
                        <option value="">All mappings</option>
                        <option value="inventory_mapped" @selected($filters['mapping'] === 'inventory_mapped')>Mapped to inventory authority</option>
                        <option value="inventory_gap" @selected($filters['mapping'] === 'inventory_gap')>Has inventory mapping gap</option>
                    </select>
                </label>

                <div class="flex gap-2">
                    <button type="submit" class="inline-flex items-center justify-center rounded-xl bg-slate-950 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">Filter</button>
                    <a href="{{ route('crm.commerce.index') }}" class="inline-flex items-center justify-center rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">Clear</a>
                </div>
            </form>
        </section>

        <section class="grid gap-4 lg:grid-cols-2">
            <x-ui.card>
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Provider roles</p>
                <h2 class="mt-1 text-lg font-semibold text-slate-950">Current authority</h2>
                <div class="mt-4 divide-y divide-slate-100">
                    @foreach($overview['role_bindings'] as $binding)
                        @if($binding['default'] !== null || $binding['scopes'] !== [])
                            <div class="flex flex-col gap-1 py-3 sm:flex-row sm:items-center sm:justify-between">
                                <span class="text-sm font-semibold text-slate-700">{{ $binding['label'] }}</span>
                                <div class="text-sm text-slate-600 sm:text-right">
                                    <span class="font-medium text-slate-950">{{ $binding['default'] ?? 'Scoped only' }}</span>
                                    @if($binding['scopes'] !== [])
                                        <span class="ml-2 text-xs text-slate-500">{{ count($binding['scopes']) }} scoped</span>
                                    @endif
                                </div>
                            </div>
                        @endif
                    @endforeach
                </div>
            </x-ui.card>

            <x-ui.card>
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Mapping coverage</p>
                <h2 class="mt-1 text-lg font-semibold text-slate-950">Active variants mapped to authority providers</h2>
                <div class="mt-4 space-y-3">
                    @forelse($overview['mapping_coverage'] as $roleKey => $coverage)
                        <div class="rounded-2xl bg-slate-50 p-4 ring-1 ring-slate-200">
                            <div class="flex items-center justify-between gap-4">
                                <div>
                                    <p class="text-sm font-semibold text-slate-950">{{ $overview['role_bindings'][$roleKey]['label'] }}</p>
                                    <p class="mt-1 text-xs text-slate-500">{{ $coverage['provider_key'] }}</p>
                                </div>
                                <div class="text-right">
                                    <p class="text-lg font-semibold text-slate-950">{{ $coverage['mapped_variant_count'] }} / {{ $coverage['active_variant_count'] }}</p>
                                    <p class="text-xs text-slate-500">active variants mapped</p>
                                </div>
                            </div>
                            @if($coverage['unmapped_variant_count'] > 0)
                                <p class="mt-3 text-xs font-semibold text-amber-800">{{ $coverage['unmapped_variant_count'] }} active {{ \Illuminate\Support\Str::plural('variant', $coverage['unmapped_variant_count']) }} need a mapping.</p>
                            @endif
                        </div>
                    @empty
                        <p class="text-sm leading-6 text-slate-500">No catalog or inventory authority is configured yet.</p>
                    @endforelse
                </div>
            </x-ui.card>
        </section>

        <section class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm" data-commerce-products>
            <div class="flex flex-wrap items-end justify-between gap-3 border-b border-slate-200 px-5 py-5 sm:px-6">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Catalog</p>
                    <h2 class="mt-1 text-xl font-semibold text-slate-950">Products and variants</h2>
                </div>
                <p class="text-sm text-slate-500">{{ number_format($products->total()) }} matching</p>
            </div>

            @if($products->isEmpty())
                <div class="p-8 text-sm text-slate-500" data-commerce-products-empty>
                    No canonical products match the current filters.
                </div>
            @else
                <div class="divide-y divide-slate-100">
                    @foreach($products as $product)
                        <a href="{{ route('crm.commerce.products.show', $product) }}" class="grid gap-3 p-5 transition hover:bg-slate-50 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-center sm:px-6" data-commerce-product-id="{{ $product->getKey() }}">
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <p class="truncate font-semibold text-slate-950">{{ $product->name }}</p>
                                    <span class="rounded-full px-2.5 py-1 text-xs font-semibold ring-1 {{ module_tone('commerce', 'badge') }}">{{ ucfirst($product->status) }}</span>
                                </div>
                                <p class="mt-1 text-sm text-slate-500">
                                    {{ $product->variants_count }} {{ \Illuminate\Support\Str::plural('variant', $product->variants_count) }}
                                    @if($product->vendor) · {{ $product->vendor }} @endif
                                    @if($product->product_type) · {{ $product->product_type }} @endif
                                </p>
                                @if($product->providerMappings->isNotEmpty())
                                    <div class="mt-2 flex flex-wrap gap-2">
                                        @foreach($product->providerMappings->pluck('provider_key')->unique()->values() as $providerKey)
                                            <span class="rounded-full bg-slate-100 px-2 py-1 text-xs font-medium text-slate-600 ring-1 ring-slate-200">{{ $providerKey }}</span>
                                        @endforeach
                                    </div>
                                @endif
                            </div>
                            <span class="text-sm font-semibold text-slate-600">Open →</span>
                        </a>
                    @endforeach
                </div>
            @endif
        </section>

        @if($products->hasPages())
            <div>{{ $products->links() }}</div>
        @endif
    </div>
</x-layouts.crm>