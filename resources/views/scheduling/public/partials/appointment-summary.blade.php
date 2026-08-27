<dl class="summary">
    <div class="wide">
        <dt>Appointment</dt>
        <dd>{{ $summary['service_name'] }}</dd>
    </div>
    <div>
        <dt>{{ $summary['is_range'] ? 'Dates' : 'Date' }}</dt>
        <dd>{{ $summary['is_range'] ? $summary['interval_label'] : $summary['date_label'] }}</dd>
    </div>
    @unless($summary['is_range'])
        <div>
            <dt>Time</dt>
            <dd>{{ $summary['time_label'] }}</dd>
        </div>
    @endunless
    <div>
        <dt>Time zone</dt>
        <dd>{{ str_replace('_', ' ', $summary['timezone']) }}</dd>
    </div>
    <div class="wide">
            <dt>{{ ($summary['location_label'] ?? null) ?: match($summary['location_type'] ?? null) { 'phone' => 'Phone appointment', 'virtual' => 'Online appointment', 'fixed' => 'Meeting location', 'customer_site' => 'Your location', default => 'How you’ll meet' } }}</dt>
            <dd>
                @if($summary['location_address']){{ $summary['location_address'] }}
                @elseif($summary['location_type'] === 'phone')At the scheduled time, the team will call the phone number you provide.
                @elseif($summary['location_type'] === 'virtual')Online meeting details will be provided by the team.
                @elseLocation details will be provided by the team.
                @endif
                @if($summary['location_instructions'] ?? null)<br><span class="muted">{{ $summary['location_instructions'] }}</span>@endif
            </dd>
        </div>
</dl>