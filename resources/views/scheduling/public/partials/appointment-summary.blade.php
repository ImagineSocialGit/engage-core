<dl class="{{ $publicPresentation['style']['summary_grid'] }}" data-appointment-summary-grid>
    <div class="{{ $publicPresentation['style']['summary_tile'] }} sm:col-span-2">
        <dt class="{{ $publicPresentation['style']['summary_label'] }}">Appointment</dt>
        <dd class="{{ $publicPresentation['style']['summary_value'] }} text-base font-extrabold">{{ $summary['service_name'] }}</dd>
    </div>

    <div class="{{ $publicPresentation['style']['summary_tile'] }}">
        <dt class="{{ $publicPresentation['style']['summary_label'] }}">
            {{ $summary['is_range'] ? 'Dates' : 'Date' }}
        </dt>
        <dd class="{{ $publicPresentation['style']['summary_value'] }}">
            {{ $summary['is_range'] ? $summary['interval_label'] : $summary['date_label'] }}
        </dd>
    </div>

    @unless($summary['is_range'])
        <div class="{{ $publicPresentation['style']['summary_tile'] }}">
            <dt class="{{ $publicPresentation['style']['summary_label'] }}">Time</dt>
            <dd class="{{ $publicPresentation['style']['summary_value'] }}">{{ $summary['time_label'] }}</dd>
        </div>
    @endunless

    <div class="{{ $publicPresentation['style']['summary_tile'] }} {{ $summary['is_range'] ? '' : 'sm:col-span-2' }}">
        <dt class="{{ $publicPresentation['style']['summary_label'] }}">Time zone</dt>
        <dd
            class="{{ $publicPresentation['style']['summary_value'] }}"
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