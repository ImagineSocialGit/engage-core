@php
    $items = [
        [
            'label' => 'Manage Routes',
            'route' => 'crm.flow-routes.index',
            'active' => request()->routeIs('crm.flow-routes.index'),
        ],
        [
            'label' => 'Assignments',
            'route' => 'crm.flow-routes.bindings.index',
            'active' => request()->routeIs('crm.flow-routes.bindings.*'),
        ],
    ];
@endphp

<nav aria-label="Routes" class="grid grid-cols-2 gap-2 rounded-2xl border border-slate-200 bg-white p-2 shadow-sm sm:flex sm:flex-wrap">
    @foreach($items as $item)
        <a
            href="{{ route($item['route']) }}"
            @class([
                'rounded-xl px-3 py-2.5 text-center text-sm font-semibold transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-orange-300 sm:px-4 sm:py-2',
                'bg-orange-50 text-orange-900 ring-1 ring-orange-200' => $item['active'],
                'text-slate-600 hover:bg-slate-50 hover:text-slate-950' => ! $item['active'],
            ])
        >
            {{ $item['label'] }}
        </a>
    @endforeach
</nav>