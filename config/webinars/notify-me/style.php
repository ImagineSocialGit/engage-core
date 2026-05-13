<?php

return [
    'section' => 'bg-white',

    'hero' => [
        'section' => 'bg-secondary text-white',
        'inner' => 'mx-auto grid w-full max-w-7xl gap-10 px-6 py-16 sm:py-24 lg:grid-cols-[1.05fr_0.95fr] lg:items-center',
        'wrapper' => 'max-w-4xl text-left',
        'title' => 'mt-5 flex flex-col gap-3 text-4xl font-extrabold tracking-[-0.04em] leading-tight text-white sm:text-6xl',
        'body' => 'mt-6 max-w-2xl text-lg font-bold leading-8 text-white sm:text-xl',
        'supporting_copy_wrapper' => 'mt-5 space-y-2',
        'supporting_copy' => 'max-w-xl text-base font-medium leading-7 text-white/75',
        'bullets_wrapper' => 'mt-8',
        'bullets_intro' => 'text-lg font-extrabold text-primary',
        'bullets_list' => 'mt-4 grid gap-3',
        'bullet_item' => 'flex gap-3 text-base font-bold leading-6 text-white',
        'bullet_icon' => 'mt-1 inline-flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-primary text-xs font-extrabold text-white',
    ],

    'form_card' => [
        'class' => 'rounded-3xl border border-black/10 bg-white p-6 text-ink shadow-2xl shadow-black/20 sm:p-8',
        'title' => 'text-2xl font-extrabold tracking-[-0.03em] text-ink',
        'body' => 'mt-2 text-sm font-medium leading-6 text-slate-600',
        'helper_text' => 'text-center text-xs font-bold text-slate-500',
    ],

    'form' => [
        'class' => 'mt-6 space-y-5',
        'grid' => 'grid gap-4 sm:grid-cols-2',
        'label' => 'text-sm font-extrabold text-ink',
        'input' => 'mt-2 w-full rounded-2xl border border-black/10 px-4 py-3 text-base text-ink shadow-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20',
        'checkbox_row' => 'flex gap-3 rounded-2xl bg-soft p-4 text-sm font-medium leading-6 text-ink',
        'checkbox' => 'mt-1 h-4 w-4 rounded border-black/20 text-primary focus:ring-primary',
        'error' => 'mt-2 text-sm font-bold text-red-600',
    ],

    'compliance' => [
        'wrapper' => 'bg-secondary px-6 pb-10 text-center',
        'text' => 'mx-auto max-w-4xl text-xs font-medium leading-6 text-white/55',
    ],
];