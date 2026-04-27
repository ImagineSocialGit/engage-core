<?php

return [

    'section' => 'bg-white',
    'grid' => 'grid gap-10 lg:grid-cols-[1.05fr_0.95fr] lg:items-start',

    'hero' => [
        'theme' => 'dark',
        'section' => 'bg-secondary text-white',
        'inner' => 'mx-auto grid w-full max-w-7xl gap-10 px-6 py-14 sm:py-20 lg:grid-cols-[1.05fr_0.95fr] lg:items-center',
        'wrapper' => 'max-w-4xl text-left',
        'align' => 'text-left',
        'title' => 'mt-5 flex flex-col gap-4',
        'body' => 'mt-6 max-w-2xl',
        'supporting_copy' => 'mt-5 max-w-xl',
    ],

    'urgency_stats' => [
        'wrapper' => 'mt-8 grid gap-3 sm:grid-cols-3',
        'item' => 'rounded-2xl border border-white/10 bg-white/[0.06] p-5',
        'value' => 'block text-3xl font-extrabold tracking-[-0.03em] text-primary',
        'label' => 'mt-1 block text-sm font-bold leading-5 text-white',
        'closing_line' => 'mt-6 max-w-2xl text-lg font-bold leading-7 text-white',
    ],

    'primary_cta' => [
        'wrapper' => 'mt-9 flex flex-col gap-4 text-left',
        'action_row' => 'flex flex-col-reverse gap-5 sm:max-w-md',
        'pretext' => 'text-sm font-extrabold uppercase tracking-[0.18em] text-primary',
        'helper_text' => 'text-sm font-bold text-white/70',
    ],

    'countdown' => [
        'themes' => [
            'dark' => [
                'wrapper' => 'rounded-2xl border border-white/10 bg-white/[0.07] px-5 py-4 text-white',
                'label'   => 'mb-3 text-xs font-extrabold uppercase tracking-[0.2em] text-white/65',
                'item'    => 'min-w-12 rounded-xl bg-black/30 px-2 py-3',
                'value'   => 'text-2xl font-extrabold leading-none text-white',
                'unit'    => 'mt-1 text-[0.65rem] font-extrabold uppercase tracking-[0.14em] text-primary',
            ],

            'light' => [
                'wrapper' => 'rounded-2xl border border-black/10 bg-soft px-5 py-4 text-ink',
                'label'   => 'mb-3 text-xs font-extrabold uppercase tracking-[0.2em] text-ink/55',
                'item'    => 'min-w-12 rounded-xl bg-white px-2 py-3 shadow-sm',
                'value'   => 'text-2xl font-extrabold leading-none text-ink',
                'unit'    => 'mt-1 text-[0.65rem] font-extrabold uppercase tracking-[0.14em] text-primary',
            ],
        ],
    ],

    'event_details' => [
        'wrapper' => 'mt-10',
        'heading_class' => 'text-left',
        'grid' => 'mt-6 grid gap-4 md:grid-cols-3',
        'card' => 'rounded-2xl border border-white/10 bg-white/[0.06] p-5',
        'label' => 'text-xs font-extrabold uppercase tracking-[0.2em] text-primary',
        'value' => 'mt-2 text-lg font-extrabold tracking-tight text-white',
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
        'image_wrapper' => 'mx-auto max-w-md',
        'image_class' => 'w-full rounded-3xl object-cover shadow-2xl shadow-black/20',
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
            bg-primary px-8 text-base font-extrabold uppercase
            tracking-[0.16em] text-white transition hover:scale-[1.02]
        ',

        'helper_text' => '
            text-center text-xs font-bold text-ink/55
        ',
    ],

    'sticky_mobile' => [
        'wrapper' => 'fixed inset-x-0 bottom-0 z-50 border-t border-white/10 bg-secondary/95 p-4 backdrop-blur md:hidden',
        'button' => 'w-full rounded-2xl bg-primary px-6 py-4 text-center text-sm font-extrabold uppercase tracking-[0.16em] text-white shadow-xl shadow-primary/25',
    ],

    'compliance' => [
        'wrapper' => 'bg-white px-6 pb-10 text-center',
        'text' => 'mx-auto max-w-4xl text-xs leading-6 text-ink/45',
    ],

];