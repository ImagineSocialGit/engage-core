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

<nav aria-label="Routes" class="flex flex-wrap gap-2 rounded-2xl border border-slate-200 bg-white p-2 shadow-sm">
    @foreach($items as $item)
        <a
            href="{{ route($item['route']) }}"
            @class([
                'rounded-xl px-4 py-2 text-sm font-semibold transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-orange-300',
                'bg-orange-50 text-orange-900 ring-1 ring-orange-200' => $item['active'],
                'text-slate-600 hover:bg-slate-50 hover:text-slate-950' => ! $item['active'],
            ])
        >
            {{ $item['label'] }}
        </a>
    @endforeach
</nav>
