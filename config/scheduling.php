<?php

$publicUrl = env('SCHEDULING_APP_URL');
$publicUrlConfigured = $publicUrl !== null && $publicUrl !== '';
$normalizedPublicUrl = null;
$publicHost = null;
$publicScheme = null;

if ($publicUrlConfigured && is_string($publicUrl)) {
    $candidate = trim($publicUrl);
    $parts = parse_url($candidate);

    $scheme = is_array($parts) && is_string($parts['scheme'] ?? null)
        ? strtolower(trim($parts['scheme']))
        : null;
    $host = is_array($parts) && is_string($parts['host'] ?? null)
        ? strtolower(trim($parts['host']))
        : null;
    $path = is_array($parts) && is_string($parts['path'] ?? null)
        ? trim($parts['path'])
        : '';
    $port = is_array($parts) && is_int($parts['port'] ?? null)
        ? $parts['port']
        : null;

    $hasUnsupportedParts = is_array($parts) && (
        array_key_exists('user', $parts)
        || array_key_exists('pass', $parts)
        || array_key_exists('query', $parts)
        || array_key_exists('fragment', $parts)
    );

    if (in_array($scheme, ['http', 'https'], true)
        && is_string($host)
        && $host !== ''
        && in_array($path, ['', '/'], true)
        && ! $hasUnsupportedParts
    ) {
        $publicScheme = $scheme;
        $publicHost = $host;
        $normalizedPublicUrl = $scheme.'://'.$host.($port !== null ? ':'.$port : '');
    }
}

$publicPresentationStyleDefaults = [
                'header_inner' => 'mx-auto flex w-full max-w-6xl items-center justify-between px-4 py-4 sm:px-6',
                'brand_link' => 'inline-flex min-h-12 items-center text-slate-950 no-underline',
                'brand_logo' => 'max-h-12 max-w-52 object-contain',
                'brand_text' => 'text-base font-extrabold tracking-tight sm:text-lg',
                'footer_inner' => 'mx-auto w-full max-w-6xl px-4 py-6 text-center text-xs leading-5 text-slate-500 sm:px-6',
                'page' => 'mx-auto w-full max-w-6xl px-4 py-8 sm:px-6 sm:py-12 lg:py-16',
                'state_width' => 'mx-auto max-w-3xl',
                'catalog_width' => 'mx-auto max-w-4xl',
                'service_width' => 'mx-auto max-w-4xl',
                'catalog_intro' => 'mx-auto max-w-3xl text-center',
                'catalog_title' => 'text-4xl font-black tracking-tight text-slate-950 sm:text-5xl',
                'catalog_body' => 'mx-auto mt-4 max-w-2xl text-lg leading-8 text-slate-600',
                'catalog_grid' => 'mt-10 grid gap-4 sm:grid-cols-2',
                'service_card' => 'group block rounded-2xl border border-slate-200 bg-gradient-to-br from-white to-slate-50 p-6 text-slate-950 shadow-sm transition duration-200 hover:-translate-y-1 hover:border-slate-300 hover:shadow-lg',
                'service_card_title' => 'block text-lg font-extrabold tracking-tight',
                'service_card_body' => 'mt-2 block text-sm leading-6 text-slate-600',
                'service_card_cta' => 'mt-5 inline-flex items-center text-sm font-extrabold text-[var(--public-primary)]',
                'back_link' => 'mb-4 inline-flex items-center gap-2 text-sm font-bold text-slate-600 transition hover:text-[var(--public-primary)]',
                'service_header' => 'max-w-3xl',
                'service_title' => 'text-3xl font-black tracking-tight text-slate-950 sm:text-4xl',
                'service_description' => 'mt-3 text-base leading-7 text-slate-600',
                'section' => 'mt-7 border-t border-slate-200 pt-7',
                'section_title' => 'text-xl font-black tracking-tight text-slate-950',
                'section_body' => 'mt-2 text-sm leading-6 text-slate-600',
                'field_label' => 'grid gap-2 text-sm font-bold text-slate-800',
                'input' => 'min-h-12 w-full rounded-xl border border-slate-300 bg-white px-4 py-3 font-medium text-slate-950 outline-none transition focus:border-[var(--public-primary)] focus:ring-2 focus:ring-[var(--public-accent)]/30',
                'helper_text' => 'text-sm leading-6 text-slate-500',
                'day_period_tabs' => 'grid grid-cols-2 gap-2 sm:inline-grid sm:grid-flow-col sm:auto-cols-fr',
                'day_period_tab' => 'min-h-11 rounded-full border border-slate-300 bg-white px-5 py-2.5 text-sm font-extrabold text-slate-700 transition hover:border-slate-400 hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--public-accent)] aria-selected:border-[var(--public-primary)] aria-selected:bg-[var(--public-primary)] aria-selected:text-white',
                'time_panel' => 'mt-5',
                'time_grid' => 'grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-4',
                'time_option' => 'flex min-h-12 cursor-pointer items-center justify-center rounded-xl border border-slate-300 bg-white px-3 py-3 text-center text-sm font-extrabold text-[var(--public-primary)] transition hover:border-[var(--public-primary)] hover:bg-slate-50 peer-focus-visible:outline-none peer-focus-visible:ring-2 peer-focus-visible:ring-[var(--public-accent)] data-[selected=true]:border-[var(--public-primary)] data-[selected=true]:bg-[var(--public-primary)] data-[selected=true]:text-white',
                'continue_row' => 'mt-6 flex justify-end border-t border-slate-200 pt-5',
                'empty_state' => 'mt-6 rounded-2xl border border-dashed border-slate-300 bg-slate-50 p-5 text-sm leading-6 text-slate-600',
                'meeting_card' => 'grid gap-4 rounded-2xl border border-slate-200 bg-slate-50/80 p-4 sm:p-5',
                'eyebrow' => 'text-xs font-extrabold uppercase tracking-[0.16em] text-slate-500',
                'meeting_title' => 'mt-2 text-base font-extrabold text-slate-950',
                'meeting_body' => 'mt-1 text-sm leading-6 text-slate-600',
                'preparation' => 'border-t border-slate-200 pt-4',
                'summary_grid' => 'mt-6 grid gap-3 sm:grid-cols-2',
                'summary_tile' => 'rounded-2xl bg-slate-50 p-4',
                'summary_label' => 'text-xs font-extrabold uppercase tracking-[0.14em] text-slate-500',
                'summary_value' => 'mt-1 text-sm font-bold leading-6 text-slate-900',
                'state_badge' => 'inline-flex rounded-full bg-[var(--public-primary)]/10 px-3 py-1 text-xs font-extrabold uppercase tracking-[0.14em] text-[var(--public-primary)]',
                'state_title' => 'mt-4 text-3xl font-black tracking-tight text-slate-950 sm:text-4xl',
                'state_body' => 'mt-3 max-w-2xl text-base leading-7 text-slate-600',
                'countdown' => 'mt-5 text-sm font-bold text-slate-600',
                'error_banner' => 'mx-auto mb-6 max-w-3xl rounded-2xl border border-red-200 bg-red-50 px-5 py-4 text-sm text-red-900 shadow-sm',
            ];

return [

    'public_presentation_style_defaults' => $publicPresentationStyleDefaults,

    'consent_domains' => [
        'scheduling_appointments' => [
            'topic' => 'appointment confirmations, reminders, scheduling updates, and appointment-related follow-up',
            'scopes' => [
                'scheduling_appointments',
            ],
            'scope_prefixes' => [],
            'opt_in' => [],
        ],
    ],

    'communications' => [
        'chain_key' => 'scheduling_appointment_communications',
        'default_subject' => 'Appointment reminder',
        'default_message' => "Hello {first_name}! You have an appointment on:\n\n{appointment_date} at {appointment_time_with_timezone}.\n\n{appointment_location_or_method}\n\nThank you!",
    ],

    'public' => [
        'configured' => $publicUrlConfigured,
        'enabled' => $normalizedPublicUrl !== null,
        'url' => $normalizedPublicUrl,
        'host' => $publicHost,
        'scheme' => $publicScheme,
        'availability_max_days' => 31,
        'reservation_rate_limit_per_minute' => 12,
        'hold_review_rate_limit_per_minute' => 60,
        'reporting_enabled' => true,

        /*
         * Client config may override any presentation value. Null colors fall
         * back to the selected client theme and then the neutral defaults.
         */
        'presentation' => [
            'brand_name' => null,
            /*
             * Generated client image descriptor. Prefer this for committed
             * client branding; logo_url remains available for literal URLs.
             */
            'logo' => null,
            'logo_url' => null,
            'heading' => 'Schedule an appointment',
            'intro' => 'Choose a service and a time that works for you.',
            'primary_color' => null,
            'accent_color' => null,
            'surface_color' => null,
            'background_color' => null,
            'page_revision' => 'scheduling-public-v4',
            'disclosure_version' => '2',
            'consent_text' => 'By providing your email address or phone number, you agree to receive appointment confirmations, reminders, scheduling updates, and other messages related to this appointment. Message and data rates may apply. Reply STOP to opt out of texts or HELP for help.',

            /*
             * Module-owned semantic presentation classes. Client config may
             * sparsely override these without replacing booking behavior.
             *
             * Shared shell/card/button treatment lives in public_surfaces.php.
             */
            'style' => $publicPresentationStyleDefaults,
        ],

        'destination_verification' => [
            'code_digits' => 6,
            'challenge_ttl_seconds' => 300,
            'proof_ttl_seconds' => 300,
            'max_code_attempts' => 5,
            'max_sends_per_challenge' => 3,
            'resend_cooldown_seconds' => 30,
            'rate_limits' => [
                'per_ip_per_hour' => 20,
                'per_destination_per_hour' => 6,
                'per_offer_session_per_hour' => 8,
                'per_challenge_per_hour' => 4,
            ],
        ],
    ],


    'travel' => [
        'maximum_minutes' => 240,
        'conservative_minutes' => 45,
    ],

    'reschedule_suggestions' => [
        'lookahead_days' => 14,
        'limit' => 6,
    ],

    'slot_offers' => [
        'ttl_seconds' => 300,
    ],

    'booking_holds' => [
        'ttl_seconds' => 600,
        'expiration_batch_size' => 500,
    ],

];