{{-- FAVICONS --}}
@php
    $baseUrl = rtrim(config('brand.favicons.base_url') ?? '', '/');
@endphp

@foreach (config('brand.favicons.files', []) as $favicon)
    <link
        rel="{{ $favicon['rel'] }}"
        @isset($favicon['type']) type="{{ $favicon['type'] }}" @endisset
        @isset($favicon['sizes']) sizes="{{ $favicon['sizes'] }}" @endisset
        href="{{ $baseUrl.$favicon['href'] }}"
    />
@endforeach