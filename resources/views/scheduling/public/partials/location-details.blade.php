<div
    class="grid gap-4 rounded-2xl border border-slate-200 bg-slate-50/80 p-4 sm:p-5"
    data-booking-location
>
    <div class="min-w-0">
        <p class="text-xs font-extrabold uppercase tracking-[0.16em] text-slate-500">
            {{ ($location['address_lines'] ?? []) !== [] ? 'Where you’ll meet' : 'How you’ll meet' }}
        </p>
        <p class="mt-2 text-base font-extrabold text-slate-950">
            {{ $location['name'] ?? $location['method_label'] ?? 'Appointment details' }}
        </p>

        @if(($location['address_lines'] ?? []) !== [])
            <address class="mt-1 not-italic text-sm leading-6 text-slate-600" data-booking-location-address data-appointment-location-address>
                @foreach($location['address_lines'] as $line)
                    <span class="block">{{ $line }}</span>
                @endforeach
            </address>
        @elseif($location['method_detail'] ?? null)
            <p class="mt-1 text-sm leading-6 text-slate-600">
                {{ $location['method_detail'] }}
            </p>
        @endif
    </div>

    @if($location['instructions'] ?? null)
        <div class="border-t border-slate-200 pt-4" data-booking-preparation data-appointment-summary="preparation">
            <p class="text-xs font-extrabold uppercase tracking-[0.16em] text-slate-500">Before your appointment</p>
            <p class="mt-2 text-sm leading-6 text-slate-700">{{ $location['instructions'] }}</p>
        </div>
    @endif
</div>