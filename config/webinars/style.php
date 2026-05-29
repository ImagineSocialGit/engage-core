<?php

// Shared content

return [
    'layout' => [
        'body' => 'min-h-screen flex flex-col text-white font-sans',
        'main' => 'flex-1',
        'container' => 'mx-auto w-full max-w-7xl px-6',
        'content_width' => 'mx-auto w-full max-w-4xl',
        'card' => 'rounded-3xl border border-black/10 bg-white shadow-2xl shadow-black/15',
        'card_padding' => 'p-8 sm:p-10',

        'header' => [
            'wrap' => 'sticky top-0 z-40 border-b border-white/10 bg-secondary/95 backdrop-blur',
            'inner' => 'mx-auto flex w-full max-w-7xl items-center justify-between px-6 py-4',
            'brand' => 'text-lg font-extrabold tracking-tight text-white',
            'brand_link' => 'max-w-24 max-h-24',
            'brand_link_compact' => 'max-w-16 max-h-16',
            'brand_image' => 'w-full h-full',
            'nav' => 'hidden items-center gap-6 text-sm font-bold uppercase tracking-[0.12em] text-white/75 md:flex',
            'nav_link' => 'transition hover:text-primary',
            'primary_link' => [
                'class' => 'rounded-full bg-primary px-5 py-2.5 text-xs font-extrabold uppercase tracking-[0.14em] text-white shadow-lg shadow-primary/25 transition hover:scale-[1.03] hover:brightness-110',
            ],
        ],

        'footer' => [
            'wrap' => 'border-t border-white/10 bg-secondary',
            'inner' => 'mx-auto w-full max-w-7xl px-6 py-8 text-sm text-white/60',
        ],
    ],

    'components' => [
        'button' => [
            'base' => 'inline-flex items-center justify-center rounded-2xl font-extrabold uppercase tracking-[0.14em] transition duration-200 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-offset-secondary disabled:pointer-events-none disabled:opacity-60',
            'sizes' => [
                'sm' => 'min-h-10 px-4 py-2 text-xs',
                'md' => 'min-h-12 px-5 py-3 text-sm',
                'lg' => 'min-h-14 px-7 py-3.5 text-base',
                'xl' => 'min-h-16 px-8 py-4 text-lg',
            ],
            'variants' => [
                'primary' => 'bg-primary text-white shadow-xl shadow-primary/25 hover:scale-[1.02] hover:brightness-110 focus:ring-primary',
                'secondary' => 'border border-black bg-black text-white hover:bg-primary hover:border-primary focus:ring-primary',
                'outline' => 'border-2 border-primary bg-transparent text-primary hover:bg-primary hover:text-white focus:ring-primary',
                'ghost' => 'bg-transparent text-white hover:bg-white/10 focus:ring-white/40',
            ],
        ],

        'card' => [
            'base' => 'rounded-3xl border border-black/10 bg-white text-ink shadow-2xl shadow-black/15',
            'padding' => [
                'none' => '',
                'sm' => 'p-4 sm:p-5',
                'md' => 'p-6 sm:p-8',
                'lg' => 'p-8 sm:p-10',
            ],
        ],

        'label' => [
            'base' => 'mb-2 block text-sm font-extrabold tracking-tight text-ink',
        ],

        'checkbox' => [
            'wrapper' => 'flex items-start gap-3',
            'input' => 'mt-1 rounded border-slate-300 accent-primary text-primary focus:ring-primary',
            'label' => 'text-xs leading-4 text-slate-700',
        ],

        'input' => [
            'base' => 'block w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-ink shadow-sm outline-none transition placeholder:text-slate-400 focus:border-primary focus:ring-2 focus:ring-primary/20',
        ],
    ],

    'footer' => [
        'compliance_identity' => [
            'wrapper' => 'mt-6 text-center',
            'line' => 'block text-xs font-medium leading-4 text-white/90',
        ],
    ],

    'tokens' => [
        'eyebrow' => 'text-sm font-extrabold uppercase tracking-[0.24em] text-primary',
        'hero_title' => 'text-4xl font-extrabold tracking-[-0.04em] leading-[0.95] text-white sm:text-5xl',
        'section_title' => 'text-3xl font-extrabold tracking-[-0.03em] leading-tight text-ink sm:text-4xl',
        'dark_section_title' => 'text-3xl font-extrabold tracking-[-0.03em] leading-tight text-white sm:text-5xl',
        'body' => 'text-base font-medium leading-7 text-white/88 sm:text-lg',
        'emphasize' => 'text-xl font-bold leading-7 tracking-wide text-primary-light sm:text-2xl',
        'emphasize_dark' => 'text-base font-medium leading-7 text-ink sm:text-lg',
        'body_dark' => 'text-base font-medium leading-7 text-ink sm:text-lg',
        'muted' => 'text-sm font-medium text-white/75',
        'muted_dark' => 'text-sm font-medium text-slate-500',
        'pink' => 'text-primary',
        'list_link' => 'text-base font-extrabold text-primary underline decoration-primary underline-offset-4 transition hover:brightness-110',
        'primary_button' => 'w-full animate-[pulse_2.8s_ease-in-out_infinite] cursor-pointer',
        'secondary_button' => 'w-fit animate-[pulse_2.8s_ease-in-out_infinite] cursor-pointer',
        'form_grid' => 'space-y-4',
        'field_error' => 'mt-1 text-sm text-red-600',
    ],

];
