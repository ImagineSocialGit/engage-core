<form
    class="mt-6 grid gap-5"
    method="POST"
    action="{{ route('scheduling.public.offers.verification.issue', ['offerId' => $offerSummary['offer_id']], false) }}"
    data-verification-form
>
    @csrf

    @if($destinationVerification['single_channel'])
        <input type="hidden" name="channel" value="{{ $destinationVerification['single_channel'] }}">
    @else
        <label class="grid gap-2 text-sm font-bold text-slate-800" for="verification_channel{{ $suffix }}">
            How would you like to receive your code?
            <select
                class="min-h-12 w-full rounded-xl border border-slate-300 bg-white px-4 py-3 font-medium text-slate-950 outline-none transition focus:border-[var(--public-primary)] focus:ring-2 focus:ring-[var(--public-accent)]/30"
                id="verification_channel{{ $suffix }}"
                name="channel"
                required
            >
                @foreach($destinationVerification['available_channels'] as $channel)
                    <option
                        value="{{ $channel }}"
                        @selected(old('channel', $destinationVerification['default_channel']) === $channel)
                    >
                        {{ $channel === 'sms' ? 'Text message' : 'Email' }}
                    </option>
                @endforeach
            </select>
        </label>
    @endif

    <label class="grid gap-2 text-sm font-bold text-slate-800" for="verification_destination{{ $suffix }}">
        <span data-verification-input-label>
            {{ old('channel', $destinationVerification['default_channel']) === 'sms' ? 'Mobile phone number' : 'Email address' }}
        </span>
        <input
            class="min-h-12 w-full rounded-xl border border-slate-300 bg-white px-4 py-3 font-medium text-slate-950 outline-none transition focus:border-[var(--public-primary)] focus:ring-2 focus:ring-[var(--public-accent)]/30"
            id="verification_destination{{ $suffix }}"
            name="destination"
            type="{{ old('channel', $destinationVerification['default_channel']) === 'sms' ? 'tel' : 'email' }}"
            value="{{ old('destination') }}"
            autocomplete="{{ old('channel', $destinationVerification['default_channel']) === 'sms' ? 'tel' : 'email' }}"
            inputmode="{{ old('channel', $destinationVerification['default_channel']) === 'sms' ? 'tel' : 'email' }}"
            placeholder="{{ old('channel', $destinationVerification['default_channel']) === 'sms' ? '(555) 555-0123' : 'you@example.com' }}"
            required
        >
        @error('destination')
            <span class="text-sm font-semibold text-red-700">{{ $message }}</span>
        @enderror
    </label>

    <div class="flex justify-end">
        <x-public-surface.button type="submit">{{ $buttonLabel }}</x-public-surface.button>
    </div>
</form>