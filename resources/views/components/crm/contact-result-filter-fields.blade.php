@props([
    'payload',
])

<input type="hidden" name="contact_result[search]" value="{{ $payload['search'] ?? '' }}">

@foreach(($payload['criteria'] ?? []) as $criterionKey => $criterionValues)
    @foreach($criterionValues as $criterionValue)
        <input
            type="hidden"
            name="contact_result[criteria][{{ $criterionKey }}][]"
            value="{{ $criterionValue }}"
        >
    @endforeach
@endforeach