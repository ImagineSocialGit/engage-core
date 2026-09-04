<?php

return [
    'theme' => [
        /*
         * Shared public-facing presentation language.
         *
         * Client config may sparsely override this file at:
         *   client/<client>/config/public_surfaces.php
         *
         * Module-specific public surfaces may layer their own semantic classes
         * on top of these shell/card/button primitives.
         */
        'brand' => [
            /*
             * Optional generated-image descriptor consumed by x-ui.image.
             * Individual public modules choose whether to render it.
             */
            'logo' => null,
            'image_sizes' => '(min-width:1024px) 40vw,100vw',
        ],

        'colors' => [
            'primary' => null,
            'accent' => null,
            'surface' => '#ffffff',
            'background' => '#f8fafc',
        ],

        'layout' => [
            'body' => 'bg-slate-50 text-slate-950 font-sans',
            'header' => 'sticky top-0 z-40 border-b border-white/10 bg-secondary/95 backdrop-blur',
            'main' => 'flex-1',
            'footer' => 'border-t border-slate-200 bg-white',
        ],

        'components' => [
            'header' => [
                'inner' => 'mx-auto flex w-full max-w-7xl items-center justify-between px-6 py-4',
                'brand' => 'text-lg font-extrabold tracking-tight text-white',
                'brand_link' => 'max-w-24 max-h-24',
                'brand_link_compact' => 'max-w-16 max-h-16',
                'brand_image' => 'w-full h-full',
                'nav' => 'hidden items-center gap-6 text-sm font-bold uppercase tracking-[0.12em] text-white/75 md:flex',
                'nav_link' => 'transition hover:text-primary',
            ],

            'card' => [
                'base' => 'rounded-[2rem] border border-slate-200/80 bg-[var(--public-surface)] text-slate-950 shadow-xl shadow-slate-200/50',
                'padding' => [
                    'none' => '',
                    'sm' => 'p-4 sm:p-5',
                    'md' => 'p-5 sm:p-7 lg:p-8',
                    'lg' => 'p-6 sm:p-8 lg:p-10',
                ],
            ],

            'button' => [
                'base' => 'inline-flex min-h-11 items-center justify-center rounded-full px-5 py-2.5 text-sm font-extrabold transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-45',
                'variants' => [
                    'primary' => 'bg-[var(--public-primary)] text-white hover:brightness-95 focus-visible:ring-[var(--public-accent)]',
                    'secondary' => 'border border-slate-300 bg-white text-slate-800 hover:bg-slate-50 focus-visible:ring-slate-400',
                    'quiet' => 'text-slate-600 hover:bg-slate-100 hover:text-slate-950 focus-visible:ring-slate-400',
                ],
            ],
        ],
    ],
];