
<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Imported-contact permission invitation email copy
    |--------------------------------------------------------------------------
    |
    | This config does not hand-author the public preference URL. Messaging
    | injects cta.url and secondary_link.url at runtime when the invitation is
    | claimed for a specific imported Contact.
    |
    */
    'email' => [
        'subject' => 'Confirm how you want to hear from us',
        'body' => 'Hi {first_name}, please confirm your communication preferences so we know how you want to hear from us.',
        'cta_label' => 'Confirm my preferences',
        'secondary_link_label' => 'Or copy and paste this link into your browser',
    ],

    /*
    |--------------------------------------------------------------------------
    | Consent scopes created after public acceptance
    |--------------------------------------------------------------------------
    |
    | These scopes receive normal MessageConsent records for each accepted
    | channel. The one-time invitation send remains email-only; SMS consent is
    | created only when the contact explicitly selects SMS and provides/confirms
    | a phone number.
    |
    */
    'consent' => [
        'scopes' => [
            'broadcast',
            'campaign',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Public preference page copy
    |--------------------------------------------------------------------------
    |
    | Client overrides may replace copy, but should preserve explicit SMS opt-in
    | language. Missing optional copy keys should fall back safely to defaults.
    |
    */
    'content' => [
        'title' => 'Confirm how you want to hear from us',
        'meta_description' => 'Choose whether you want to receive email, text messages, or both.',

        'eyebrow' => 'Communication Preferences',
        'heading' => 'Choose how you want to hear from us.',
        'body' => 'We recently moved to a new communication system. Please confirm which messages you want to receive going forward.',

        'options' => [
            'email' => [
                'label' => 'Email updates',
                'body' => 'Receive helpful updates, reminders, and follow-up messages by email.',
            ],
            'sms' => [
                'label' => 'Text message updates',
                'body' => 'Receive helpful updates, reminders, and follow-up messages by SMS.',
            ],
        ],

        'phone_label' => 'Mobile phone number',
        'phone_help' => 'Required if you choose text messages.',
        'submit_label' => 'Confirm my preferences',

        'legal' => 'By confirming, you agree to receive messages from us using the channels selected. Consent is not a condition of purchase. Message frequency varies. Message and data rates may apply. Reply STOP to opt out of text messages or HELP for help.',

        'accepted_title' => 'Preferences confirmed',
        'accepted_heading' => 'Your preferences are confirmed.',
        'accepted_body' => 'Thank you. Your communication preferences have been saved.',
    ],

    /*
    |--------------------------------------------------------------------------
    | Public preference page classes
    |--------------------------------------------------------------------------
    |
    | These are Tailwind-style class strings consumed by the public permission
    | invitation view. Keep styling optional and copy-safe; do not encode runtime
    | behavior here.
    |
    */
    'style' => [
        'section' => 'bg-white text-ink',
        'inner' => 'mx-auto w-full max-w-3xl px-6 py-16 sm:py-24',
        'card' => 'rounded-3xl border border-black/10 bg-white p-6 shadow-xl shadow-black/10 sm:p-8',
        'eyebrow' => 'text-sm font-extrabold uppercase tracking-[0.18em] text-primary',
        'heading' => 'mt-3 text-4xl font-extrabold tracking-[-0.04em] text-ink sm:text-5xl',
        'body' => 'mt-5 text-lg font-medium leading-8 text-ink/75',
        'option' => 'rounded-2xl border border-black/10 bg-soft p-4',
        'option_label' => 'text-base font-extrabold text-ink',
        'option_body' => 'mt-1 text-sm font-medium leading-6 text-ink/70',
        'button' => 'inline-flex min-h-12 items-center justify-center rounded-full bg-primary px-8 text-sm font-extrabold uppercase tracking-[0.16em] text-white transition hover:scale-[1.02]',
        'legal' => 'text-xs leading-5 text-slate-600',
    ],
];
