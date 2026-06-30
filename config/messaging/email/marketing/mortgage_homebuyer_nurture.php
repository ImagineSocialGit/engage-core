<?php

use App\Modules\Messaging\Payloads\EmailPayload;

return [

    /*
    |--------------------------------------------------------------------------
    | Mortgage Homebuyer Nurture Email Templates
    |--------------------------------------------------------------------------
    |
    | Purpose/scope:
    |
    | marketing:mortgage_homebuyer_nurture
    |
    | Mortgage-specific templates.
    | Only mortgage preset groups/client configs should reference this scope.
    |
    */

    'campaigns' => [
        'mortgage_homebuyer_nurture' => [
            'steps' => [
                1 => [
                    'dispatch_key' => 'campaign_step_due',
                    'payload_class' => EmailPayload::class,
                    'queue' => 'marketing',

                    'payload' => [
                        'subject' => 'The best time to prepare is before you are ready',
                        'body' => 'Hi {first_name}, if buying a home is still a future goal, a little preparation now can make the process much easier later. Start with your budget, credit, cash needed, and timeline.',
                    ],
                ],

                2 => [
                    'dispatch_key' => 'campaign_step_due',
                    'payload_class' => EmailPayload::class,
                    'queue' => 'marketing',

                    'payload' => [
                        'subject' => 'What to do 3–6 months before buying',
                        'body' => 'Hi {first_name}, if you are 3–6 months away from buying, this is a good time to review your credit, avoid major debt changes, estimate cash needed, and talk through your loan options.',
                        'cta' => [
                            'label' => 'Ask a Question',
                            'url' => '{contact_url}',
                        ],
                    ],
                ],

                3 => [
                    'dispatch_key' => 'campaign_step_due',
                    'payload_class' => EmailPayload::class,
                    'queue' => 'marketing',

                    'payload' => [
                        'subject' => 'Should we revisit your homebuying plan?',
                        'body' => 'Hi {first_name}, checking in to see whether buying a home is still on your radar. If it is, we can revisit your numbers and next steps.',
                        'cta' => [
                            'label' => 'Revisit My Plan',
                            'url' => '{application_url}',
                        ],
                    ],
                ],
            ],
        ],
    ],

];