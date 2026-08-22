<?php

use App\Modules\Forms\Models\FormDefinition;

return [
    'groups' => [
        'artist_updates' => [
            'artist_updates',
        ],
    ],

    'definitions' => [
        'artist_updates' => [
            'key' => 'artist_updates',
            'name' => 'Artist Updates',
            'description' => 'Reusable public intake for artist news, releases, tour updates, VIP opportunities, and merchandise updates.',
            'category' => FormDefinition::CATEGORY_INTAKE,
            'is_public' => true,
            'schema' => [
                'sections' => [[
                    'key' => 'updates',
                    'label' => 'Updates',
                    'fields' => [
                        [
                            'key' => 'first_name',
                            'label' => 'First Name',
                            'type' => 'text',
                            'required' => false,
                        ],
                        [
                            'key' => 'last_name',
                            'label' => 'Last Name',
                            'type' => 'text',
                            'required' => false,
                        ],
                        [
                            'key' => 'email',
                            'label' => 'Email',
                            'type' => 'email',
                            'required' => true,
                        ],
                        [
                            'key' => 'phone',
                            'label' => 'Phone',
                            'type' => 'tel',
                            'required' => false,
                        ],
                        [
                            'key' => 'postal_code',
                            'label' => 'ZIP / Postal Code',
                            'type' => 'text',
                            'required' => false,
                        ],
                        [
                            'key' => 'interests',
                            'label' => 'Updates I am interested in',
                            'type' => 'checkboxes',
                            'required' => false,
                            'options' => [
                                ['value' => 'music', 'label' => 'Music'],
                                ['value' => 'tour', 'label' => 'Tour'],
                                ['value' => 'vip', 'label' => 'VIP'],
                                ['value' => 'merch', 'label' => 'Merchandise'],
                            ],
                        ],
                        [
                            'key' => 'email_marketing_consent',
                            'label' => 'Email me artist updates',
                            'type' => 'checkbox',
                            'required' => true,
                        ],
                        [
                            'key' => 'sms_marketing_consent',
                            'label' => 'Text me artist updates',
                            'type' => 'checkbox',
                            'required' => false,
                        ],
                    ],
                ]],
            ],
            'rules' => [
                'first_name' => ['nullable', 'string', 'max:255'],
                'last_name' => ['nullable', 'string', 'max:255'],
                'email' => ['required', 'email', 'max:255'],
                'phone' => ['nullable', 'string', 'max:255', 'required_if:sms_marketing_consent,true'],
                'postal_code' => ['nullable', 'string', 'max:20'],
                'interests' => ['array', 'max:4'],
                'email_marketing_consent' => ['accepted'],
                'sms_marketing_consent' => ['nullable', 'boolean'],
            ],
            'layout' => [],
            'settings' => [
                'submission' => [
                    'contact' => [
                        'fields' => [
                            'email' => 'email',
                            'first_name' => 'first_name',
                            'last_name' => 'last_name',
                            'phone' => 'phone',
                        ],
                        'source' => 'engage_sites',
                        'subsource' => 'artist_updates',
                    ],
                    'tags' => [
                        [
                            'field' => 'email_marketing_consent',
                            'values' => [
                                'true' => 'interest:general_updates',
                            ],
                        ],
                        [
                            'field' => 'interests',
                            'values' => [
                                'music' => 'interest:music',
                                'tour' => 'interest:tour',
                                'vip' => 'interest:vip',
                                'merch' => 'interest:merch',
                            ],
                        ],
                    ],
                    'consents' => [
                        [
                            'field' => 'email_marketing_consent',
                            'channel' => 'email',
                            'purpose' => 'marketing',
                        ],
                        [
                            'field' => 'sms_marketing_consent',
                            'channel' => 'sms',
                            'purpose' => 'marketing',
                        ],
                    ],
                    'verification' => [
                        'required' => false,
                        'providers' => ['turnstile'],
                        'max_age_seconds' => 300,
                        'action' => 'artist_updates',
                        'require_hostname' => true,
                    ],
                ],
            ],
            'meta' => [],
        ],
    ],
];