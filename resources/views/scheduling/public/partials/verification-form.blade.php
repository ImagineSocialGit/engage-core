@php
    $singleChannel = count($destinationVerification['available_channels']) === 1
        ? $destinationVerification['available_channels'][0]
        : null;
    $defaultChannel = old('channel', $singleChannel ?: 'email');
@endphp
<form class="form" method="POST" action="{{ route('scheduling.public.offers.verification.issue', ['offerId' => $offerSummary['offer_id']], false) }}" data-verification-form style="margin-top:1rem">
    @csrf
    @if($singleChannel)
        <input type="hidden" name="channel" value="{{ $singleChannel }}">
    @else
        <label class="field" for="verification_channel{{ $suffix }}">
            How would you like to receive your code?
            <select id="verification_channel{{ $suffix }}" name="channel" required>
                @foreach($destinationVerification['available_channels'] as $channel)
                    <option value="{{ $channel }}" @selected($defaultChannel === $channel)>{{ $channel === 'sms' ? 'Text message' : 'Email' }}</option>
                @endforeach
            </select>
        </label>
    @endif
    <label class="field" for="verification_destination{{ $suffix }}">
        <span data-verification-input-label>{{ $defaultChannel === 'sms' ? 'Mobile phone number' : 'Email address' }}</span>
        <input id="verification_destination{{ $suffix }}" name="destination" type="{{ $defaultChannel === 'sms' ? 'tel' : 'email' }}" value="{{ old('destination') }}" autocomplete="{{ $defaultChannel === 'sms' ? 'tel' : 'email' }}" inputmode="{{ $defaultChannel === 'sms' ? 'tel' : 'email' }}" placeholder="{{ $defaultChannel === 'sms' ? '(555) 555-0123' : 'you@example.com' }}" required>
        @error('destination')<span class="error">{{ $message }}</span>@enderror
    </label>
    <div class="actions"><button type="submit">{{ $buttonLabel }}</button></div>
</form>