<?php

return [
    'section' => 'mx-auto max-w-5xl px-6 py-16 sm:py-24',

    'tokens' => [
        'hero_title' => 'mt-4 text-4xl font-bold tracking-tight text-primary sm:text-5xl',
        'section_title' => 'text-2xl font-bold tracking-tight text-primary'
    ],

    'hero' => [
        'align' => 'text-center',
        'wrapper' => 'mx-auto max-w-3xl',
    ],

    'series_list' => [
        'wrapper' => 'mt-14 rounded-[2rem] border border-slate-500 bg-secondary/95 p-8 shadow-xl shadow-slate-500 backdrop-blur',
        'heading_wrapper' => 'mb-8 text-center',
        'list' => 'grid gap-4 sm:grid-cols-2',
    ],

    'series_card' => [
        'wrapper' => 'group block rounded-2xl border border-slate-200 bg-gradient-to-br from-white to-slate-50 p-6 duration-200 hover:-translate-y-1 hover:border-slate-300 hover:shadow-lg',
        'title' => 'text-lg font-semibold tracking-tight text-primary',
        'description' => 'mt-2 text-sm leading-6 text-slate-600',
        'cta' => 'mt-4 inline-flex items-center text-sm font-semibold text-slate-900',
    ],

    'empty_state' => [
        'wrapper' => 'mt-14 rounded-[2rem] border border-slate-200 bg-secondary p-10 text-center shadow-xl shadow-slate-200/60',
    ],
];