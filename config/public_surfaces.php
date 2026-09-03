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
        ],

        'colors' => [
            'primary' => null,
            'accent' => null,
            'surface' => '#ffffff',
            'background' => '#f8fafc',
        ],

        'layout' => [
            'body' => 'bg-slate-50 text-slate-950 font-sans',
            'header' => 'border-b border-slate-200/80 bg-white/95 backdrop-blur',
            'main' => 'flex-1',
            'footer' => 'border-t border-slate-200 bg-white',
        ],

        'components' => [
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