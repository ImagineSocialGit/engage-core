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
        <dd data-appointment-timezone="{{ $summary['timezone'] }}">{{ $summary['timezone_label'] ?? str_replace('_', ' ', $summary['timezone']) }}</dd>
    </div>

    @if(in_array($summary['location_type'] ?? null, ['fixed', 'customer_site'], true))
        <div class="wide" data-appointment-summary="meeting" data-appointment-meeting-method="in_person">
            <dt>Where you’ll meet</dt>
            <dd data-appointment-location-address>
                {{ $summary['location_address'] ?? 'Location details will be provided by the team.' }}
            </dd>
        </div>
    @else
        <div class="wide" data-appointment-summary="meeting" data-appointment-meeting-method="{{ $summary['location_type'] ?? 'unknown' }}">
            <dt>How you’ll meet</dt>
            <dd>
                {{ $summary['appointment_method_label'] ?? 'Details provided after booking' }}
                @if(($summary['location_type'] ?? null) === 'phone')
                    <br><span class="muted">At the scheduled time, the team will call the phone number you provide.</span>
                @elseif(($summary['location_type'] ?? null) === 'virtual')
                    <br><span class="muted">Online meeting details will be provided by the team.</span>
                @endif
            </dd>
        </div>
    @endif

    @if($summary['location_instructions'] ?? null)
        <div class="wide" data-appointment-summary="preparation">
            <dt>Before your appointment</dt>
            <dd>{{ $summary['location_instructions'] }}</dd>
        </div>
    @endif
</dl>