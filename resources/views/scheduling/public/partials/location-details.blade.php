<div
    class="{{ $publicPresentation['style']['meeting_card'] }}"
    data-booking-location
>
    <div class="min-w-0">
        <p class="{{ $publicPresentation['style']['eyebrow'] }}">
            {{ ($location['address_lines'] ?? []) !== [] ? 'Where you’ll meet' : 'How you’ll meet' }}
        </p>
        <p class="{{ $publicPresentation['style']['meeting_title'] }}">
            {{ $location['name'] ?? $location['method_label'] ?? 'Appointment details' }}
        </p>

        @if(($location['address_lines'] ?? []) !== [])
            <address class="{{ $publicPresentation['style']['meeting_body'] }} not-italic" data-booking-location-address data-appointment-location-address>
                @foreach($location['address_lines'] as $line)
                    <span class="block">{{ $line }}</span>
                @endforeach
            </address>
        @elseif($location['method_detail'] ?? null)
            <p class="{{ $publicPresentation['style']['meeting_body'] }}">
                {{ $location['method_detail'] }}
            </p>
        @endif
    </div>

    @if($location['instructions'] ?? null)
        <div class="{{ $publicPresentation['style']['preparation'] }}" data-booking-preparation data-appointment-summary="preparation">
            <p class="{{ $publicPresentation['style']['eyebrow'] }}">Before your appointment</p>
            <p class="mt-2 {{ $publicPresentation['style']['meeting_body'] }}">{{ $location['instructions'] }}</p>
        </div>
    @endif
</div>