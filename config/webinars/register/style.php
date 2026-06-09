<?php

// Registered style

return [

    'section' => 'bg-white',
    'grid' => 'grid gap-10 lg:grid-cols-[1.05fr_0.95fr] lg:items-start',

    'hero' => [
        'theme' => 'dark',
        'section' => 'bg-secondary text-white',
        'inner' => 'mx-auto grid w-full max-w-7xl lg:gap-10 px-6 pt-14 pb-6 sm:pt-8 lg:pb-20 lg:grid-cols-[1.05fr_0.95fr] lg:items-center',
        'wrapper' => 'max-w-4xl text-left',
        'align' => 'text-left',
        'title' => 'mt-5 flex flex-col gap-4',
        'body' => 'mt-6 max-w-2xl text-lg sm:text-xl',
        'supporting_copy' => 'mt-2 max-w-xl text-white',
    ],

    'urgency_stats' => [
        'wrapper' => 'mt-6 sm:mt-8',
        'stats_wrapper' => 'mt-3 grid gap-2 sm:mt-4 sm:grid-cols-3',
        'intro' => 'text-2xl font-extrabold tracking-wide leading-tight text-white sm:text-3xl',
        'item' => 'border-b border-white/10 py-2 sm:rounded-2xl sm:border sm:border-white/10 sm:bg-white/[0.06] sm:p-5',
        'value' => 'inline text-2xl font-extrabold tracking-[-0.03em] text-primary sm:block sm:text-3xl',
        'label' => 'ml-2 inline text-sm font-bold leading-5 text-white sm:ml-0 sm:mt-1 sm:block',
        'closing_line' => 'mt-5 max-w-2xl text-lg font-bold leading-7 text-white',
    ],

    'webinar_title' => [
        'title' => 'text-4xl font-semibold text-white',
    ],

    'primary_cta' => [
        'wrapper' => 'mt-5 flex flex-col gap-4 text-left sm:mt-9',
        'action_row' => 'flex flex-col-reverse gap-5 sm:max-w-md',
        'pretext' => 'text-sm font-extrabold uppercase tracking-[0.18em] text-primary',
        'helper_text' => 'text-sm font-bold text-white/70',
    ],

    'mobile_after_hero_cta' => [
        'wrapper' => '
            lg:hidden
            sticky bottom-0
            flex flex-col gap-2
            bg-secondary
            px-4 py-4
            z-50
        ',
    ],

    'form_card' => [
        'class' => 'rounded-3xl border border-black/10 bg-white p-4 text-ink shadow-2xl shadow-black/20 sm:p-8',
        'title' => 'text-xl font-extrabold tracking-[-0.03em] text-ink sm:text-2xl',
        'body' => 'mt-2 text-sm font-medium leading-6 text-slate-600',
    ],

    'countdown' => [
        'themes' => [
            'dark' => [
                'wrapper' => 'rounded-2xl border border-white/10 bg-white/[0.07] px-3 py-2 lg:px-5 lg:py-4 text-white',
                'label' => 'mb-3 text-xs font-extrabold uppercase tracking-[0.2em] text-white/65',
                'item' => 'flex lg:flex-col gap-2 items-end lg:items-center min-w-12 rounded-xl bg-black/30 px-1 py-2 lg:px-2 lg:py-3',
                'value' => 'text-2xl font-extrabold leading-none text-white',
                'unit' => 'text-[0.65rem] font-extrabold uppercase tracking-[0.14em] text-primary',
            ],

            'light' => [
                'wrapper' => 'rounded-2xl border border-black/10 bg-soft px-5 py-4 text-ink',
                'label' => 'mb-3 text-xs font-extrabold uppercase tracking-[0.2em] text-ink/55',
                'item' => 'min-w-12 rounded-xl bg-white px-2 py-3 shadow-sm',
                'value' => 'text-2xl font-extrabold leading-none text-ink',
                'unit' => 'mt-1 text-[0.65rem] font-extrabold uppercase tracking-[0.14em] text-primary',
            ],
        ],
    ],

    'event_details' => [
        'wrapper' => '',
        'heading_class' => 'text-left',
        'grid' => 'mt-4 grid grid-cols-2 gap-3 sm:mt-6 md:grid-cols-3',
        'card' => 'rounded-2xl border border-white/10 bg-white/[0.06] p-4 sm:p-5 [&:nth-child(3)]:col-span-2 [&:nth-child(3)]:text-center md:[&:nth-child(3)]:col-span-1 md:[&:nth-child(3)]:text-left',
        'label' => 'text-xs font-extrabold uppercase tracking-[0.2em] text-primary',
        'value' => 'mt-2 text-base font-extrabold tracking-tight text-white sm:text-lg',
        'footnote' => 'mt-5 text-sm font-medium text-white/65',
    ],

    'problem' => [
        'section' => 'bg-white text-ink',
        'inner' => 'mx-auto grid w-full max-w-7xl gap-12 px-6 py-16 sm:py-24 lg:grid-cols-[0.95fr_1.05fr] lg:items-center',
        'content_wrapper' => 'space-y-6',
        'block' => 'space-y-3',
        'paragraph' => 'max-w-2xl text-lg font-medium leading-8 text-ink',
        'list' => 'space-y-3',
        'list_item' => 'flex gap-3 text-base font-bold leading-6 text-ink',
        'icon' => 'mt-1 inline-flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-primary text-xs font-extrabold text-white',
    ],

    'instructor' => [
        'wrapper' => 'rounded-3xl border border-black/10 bg-soft p-6 shadow-xl shadow-black/10 sm:p-8',
        'image_wrapper' => 'mx-auto max-w-sm',
        'image_class' => 'w-full object-cover',
        'content_wrapper' => 'mt-8 space-y-4',
        'body' => 'space-y-4 text-base font-medium leading-7 text-ink',
        'credibility_list' => 'mt-6 grid gap-3',
        'credibility_item' => 'flex gap-3 text-base font-extrabold text-ink',
    ],

    'form_card' => [
        'class' => 'rounded-3xl border border-black/10 bg-white p-6 text-ink shadow-2xl shadow-black/20 sm:p-8',
        'title' => 'text-2xl font-extrabold tracking-[-0.03em] text-ink',
        'body' => 'mt-2 text-sm font-medium leading-6 text-slate-600',
    ],

    'secondary_cta' => [
        'wrapper' => 'bg-white px-6 pb-16 text-center sm:pb-24',
        'inner' => 'mx-auto max-w-3xl rounded-3xl border border-black/10 bg-soft p-8 shadow-xl shadow-black/10 sm:p-10',
        'headline' => 'text-3xl font-extrabold tracking-[-0.03em] text-ink sm:text-4xl',
        'helper_text' => 'mt-4 text-sm font-bold text-slate-600',
    ],

    'trust' => [
        'wrapper' => 'bg-white px-6 py-16 text-center text-ink sm:py-24',
        'headline' => 'text-3xl font-extrabold tracking-[-0.03em] text-ink sm:text-5xl',
        'body' => 'mx-auto mt-5 max-w-2xl text-lg font-medium leading-8 text-ink/75',
        'review_card' => 'rounded-3xl border border-black/10 bg-soft p-6 text-left shadow-xl shadow-black/10',
        'stars' => 'text-lg font-extrabold tracking-[0.18em] text-primary',
        'review_name' => 'mt-4 text-base font-extrabold text-ink',
        'review_text' => 'mt-2 text-sm font-medium leading-6 text-ink/75',
    ],

    'final_close' => [
        'theme' => 'light',
        'wrapper' => 'bg-white px-6 pb-20 text-ink sm:pb-28',
        'headline' => 'text-4xl font-extrabold tracking-[-0.04em] leading-tight text-ink sm:text-6xl',
        'body' => 'mx-auto mt-6 max-w-2xl text-lg font-medium leading-8 text-ink/75',
        'list_item' => 'flex gap-3 text-base font-bold leading-6 text-ink',
        'helper_text' => 'text-sm font-bold text-ink/65',
    ],

    'sticky_desktop' => [
        'wrapper' => '
            hidden lg:flex fixed bottom-6 right-6 z-50
            w-80 flex-col gap-4 rounded-3xl border border-black/10
            bg-white p-5 shadow-2xl
        ',

        'eyebrow' => '
            text-xs font-extrabold uppercase tracking-[0.18em]
            text-ink/55
        ',

        'countdown_wrapper' => '
            rounded-2xl border border-black/10 bg-soft px-4 py-4
        ',

        'cta' => '
            inline-flex min-h-14 items-center justify-center rounded-full
            bg-primary px-8 text-base text-balance font-extrabold uppercase
            tracking-[0.16em] text-white transition hover:scale-[1.02]
            animate-pulse motion-safe:animate-pulse cursor-pointer
        ',

        'helper_text' => '
            text-center text-xs font-bold text-ink/55
        ',
    ],

    'sticky_mobile' => [
        'wrapper' => 'fixed inset-x-0 bottom-0 z-50 border-t border-white/10 bg-secondary/95 p-4 backdrop-blur md:hidden',
        'button' => 'w-full rounded-2xl bg-primary px-6 py-4 text-center text-sm font-extrabold uppercase tracking-[0.16em] text-white shadow-xl shadow-primary/25 animate-pulse motion-safe:animate-pulse cursor-pointer',
    ],

    'legal_links' => [
        'wrapper' => 'text-xs leading-5 text-slate-600',
        'link' => 'font-extrabold text-primary underline underline-offset-4 hover:text-primary/80',
    ],

    'compliance' => [
        'wrapper' => 'bg-white px-6 pb-10 text-center',
        'text' => 'mx-auto max-w-4xl text-xs leading-6 text-ink/45',
    ],

];
