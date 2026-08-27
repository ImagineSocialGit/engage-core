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
    <div>
        <dt>Format</dt>
        <dd>{{ $summary['appointment_format_label'] ?? 'Appointment' }}</dd>
    </div>
    <div>
        <dt>{{ ($summary['appointment_format'] ?? null) === 'in_person' ? 'Where you’ll meet' : 'How you’ll meet' }}</dt>
        <dd>{{ $summary['appointment_method_label'] ?? 'Details provided after booking' }}</dd>
    </div>
    <div class="wide">
        <dt>{{ ($summary['location_label'] ?? null) ?: 'What to expect' }}</dt>
        <dd>
            @if($summary['location_address'])
                {{ $summary['location_address'] }}
            @elseif($summary['location_type'] === 'phone')
                At the scheduled time, the team will call the phone number you provide.
            @elseif($summary['location_type'] === 'virtual')
                Online meeting details will be provided by the team.
            @elseif($summary['location_type'] === 'customer_site')
                The appointment will take place at the address you provided.
            @else
                Location details will be provided by the team.
            @endif
            @if($summary['location_instructions'] ?? null)
                <br><span class="muted">{{ $summary['location_instructions'] }}</span>
            @endif
        </dd>
    </div>
</dl>