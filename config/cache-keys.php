<?php

return [
    'ttl' => [
        'next_upcoming_webinar_empty_seconds' => env('CACHE_NEXT_UPCOMING_WEBINAR_EMPTY_SECONDS', 300),
        'next_upcoming_webinar_min_seconds' => env('CACHE_NEXT_UPCOMING_WEBINAR_MIN_SECONDS', 60),

        'active_webinar_series_min_seconds' => env('CACHE_ACTIVE_WEBINAR_SERIES_MIN_SECONDS', 300),

        'public_page_config_seconds' => env('CACHE_PUBLIC_PAGE_CONFIG_SECONDS', 3600),
        'webinar_landing_page_seconds' => env('CACHE_WEBINAR_LANDING_PAGE_SECONDS', 300),
        'external_api_response_seconds' => env('CACHE_EXTERNAL_API_RESPONSE_SECONDS', 300),
        'image_manifest_seconds' => env('CACHE_IMAGE_MANIFEST_SECONDS', 3600),
    ],
];