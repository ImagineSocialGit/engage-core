<dl class="mt-6 grid gap-3 sm:grid-cols-2" data-appointment-summary-grid>
    <div class="rounded-2xl bg-slate-50 p-4 sm:col-span-2">
        <dt class="text-xs font-extrabold uppercase tracking-[0.14em] text-slate-500">Appointment</dt>
        <dd class="mt-1 text-base font-extrabold text-slate-950">{{ $summary['service_name'] }}</dd>
    </div>

    <div class="rounded-2xl bg-slate-50 p-4">
        <dt class="text-xs font-extrabold uppercase tracking-[0.14em] text-slate-500">
            {{ $summary['is_range'] ? 'Dates' : 'Date' }}
        </dt>
        <dd class="mt-1 text-sm font-bold leading-6 text-slate-900">
            {{ $summary['is_range'] ? $summary['interval_label'] : $summary['date_label'] }}
        </dd>
    </div>

    @unless($summary['is_range'])
        <div class="rounded-2xl bg-slate-50 p-4">
            <dt class="text-xs font-extrabold uppercase tracking-[0.14em] text-slate-500">Time</dt>
            <dd class="mt-1 text-sm font-bold leading-6 text-slate-900">{{ $summary['time_label'] }}</dd>
        </div>
    @endunless

    <div class="rounded-2xl bg-slate-50 p-4 {{ $summary['is_range'] ? '' : 'sm:col-span-2' }}">
        <dt class="text-xs font-extrabold uppercase tracking-[0.14em] text-slate-500">Time zone</dt>
        <dd
            class="mt-1 text-sm font-bold leading-6 text-slate-900"
            data-appointment-timezone="{{ $summary['timezone'] }}"
        >
            {{ $summary['timezone_label'] ?? str_replace('_', ' ', $summary['timezone']) }}
        </dd>
    </div>

    @if($summary['location_presentation'] ?? null)
        <div
            class="sm:col-span-2"
            data-appointment-summary="meeting"
            data-appointment-meeting-method="{{ in_array($summary['location_type'] ?? null, ['fixed', 'customer_site'], true) ? 'in_person' : ($summary['location_type'] ?? 'unknown') }}"
        >
            @include('scheduling.public.partials.location-details', [
                'location' => $summary['location_presentation'],
            ])
        </div>
    @endif
</dl>