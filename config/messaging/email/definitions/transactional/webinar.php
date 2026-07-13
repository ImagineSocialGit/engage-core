<?php

use App\Modules\Messaging\Payloads\EmailPayload;

return [
    'confirmations' => [
        [
            'key' => 'confirmation',
            'dispatch_key' => 'registration_created',
            'message_type' => 'confirmation',
            'channel' => 'email',
            'purpose' => 'transactional',
            'scope' => 'webinar',
            'payload_class' => EmailPayload::class,
            'queue' => 'confirmation_messages',
            'payload' => [
                'subject' => 'You’re In – Your {webinar_title} Starts Here',
                'body' => <<<'TEXT'
Hi {first_name},

You’re officially registered for the {webinar_title} Webinar.

I’m glad you’re joining us—this is where we take the confusion out of buying a home and give you a clear strategy to move forward with confidence.

Webinar Details
Date: {webinar_start_date}
Time: {webinar_start_time}

{cta}

What You’ll Learn
• The 6-step mortgage game plan
• How to get fully pre-approved the right way
• What most buyers do wrong that can cost them the deal
• How to structure your loan so you can win in this market

Important
This is not a generic webinar.
This is strategy.

Come ready with questions—there will be time for live Q&A at the end.

Bonus
Anyone who attends live will have the opportunity to schedule a free one-on-one strategy session to build a personal game plan.

Looking forward to seeing you there.

Stacey
TEXT,
                'cta' => [
                    'label' => 'Join Webinar',
                    'url' => '{webinar_join_url}',
                ],
                'secondary_link' => [
                    'label' => 'Need to cancel your registration?',
                    'url' => '{cancel_registration_url}',
                ],
            ],
        ],
    ],

    'opt_ins' => [
        [
            'key' => 'opt_in',
            'dispatch_key' => 'consent_granted',
            'message_type' => 'opt_in',
            'channel' => 'email',
            'purpose' => 'transactional',
            'scope' => 'webinar',
            'payload_class' => EmailPayload::class,
            'queue' => 'opt_in_messages',
            'payload' => [
                'subject' => 'You’re subscribed to Slam Dunk webinar emails',
                'body' => 'Thanks for subscribing to receive webinar access details, reminders, replay access, and follow-up communications from Slam Dunk Home Loans. You can opt out using the link in any webinar email.',
            ],
        ],
    ],

    'reminders' => [
        [
            'key' => 'reminder_10_day',
            'dispatch_key' => 'registration_created',
            'message_type' => 'reminder',
            'channel' => 'email',
            'purpose' => 'transactional',
            'scope' => 'webinar',
            'payload_class' => EmailPayload::class,
            'queue' => 'reminders',
            'payload' => [
                'subject' => 'Your Homebuyer Game Plan Is Coming Up',
                'body' => <<<'TEXT'
Hi {first_name},

You’re 10 days away from the {webinar_title} Webinar.

Details
Date: {webinar_start_date}
Time: {webinar_start_time}

{cta}

This is where we break down how to buy smart, get properly approved, and move forward with a real strategy—not guesswork.

Come ready with questions. This is not fluff.

Talk soon,

Stacey
TEXT,
                'cta' => ['label' => 'Join Webinar', 'url' => '{webinar_join_url}'],
            ],
        ],
        [
            'key' => 'reminder_1_week',
            'dispatch_key' => 'registration_created',
            'message_type' => 'reminder',
            'channel' => 'email',
            'purpose' => 'transactional',
            'scope' => 'webinar',
            'payload_class' => EmailPayload::class,
            'queue' => 'reminders',
            'payload' => [
                'subject' => 'One Week Out – Don’t Miss This',
                'body' => <<<'TEXT'
Hi {first_name},

We’re one week away from the {webinar_title} Webinar.

Details
Date: {webinar_start_date}
Time: {webinar_start_time}

{cta}

If you’re thinking about buying, this is where we’ll walk through what actually matters:

• Getting fully approved the right way
• Avoiding the mistakes that cost buyers deals
• Understanding how to structure your loan so you can win

Make sure you’re there live.

Stacey
TEXT,
                'cta' => ['label' => 'Join Webinar', 'url' => '{webinar_join_url}'],
            ],
        ],
        [
            'key' => 'reminder_1_day',
            'dispatch_key' => 'registration_created',
            'message_type' => 'reminder',
            'channel' => 'email',
            'purpose' => 'transactional',
            'scope' => 'webinar',
            'payload_class' => EmailPayload::class,
            'queue' => 'reminders',
            'payload' => [
                'subject' => 'We Go Live Tomorrow – Don’t Miss This',
                'body' => <<<'TEXT'
Hi {first_name},

Quick reminder—we go live tomorrow for the {webinar_title} Webinar.

Details
Date: {webinar_start_date}
Time: {webinar_start_time}

{cta}

This is where we break down exactly how to buy smart, get properly approved, and avoid the mistakes that cost buyers deals.

Make sure you’re on live—this is strategy, not fluff.

See you tomorrow,

Stacey
TEXT,
                'cta' => ['label' => 'Join Webinar', 'url' => '{webinar_join_url}'],
            ],
        ],
        [
            'key' => 'reminder_30_minute',
            'dispatch_key' => 'registration_created',
            'message_type' => 'reminder',
            'channel' => 'email',
            'purpose' => 'transactional',
            'scope' => 'webinar',
            'payload_class' => EmailPayload::class,
            'queue' => 'reminders',
            'payload' => [
                'subject' => 'We Start in 30 Minutes',
                'body' => <<<'TEXT'
Hi {first_name},

We’re going live in 30 minutes for the {webinar_title} Webinar.

Time: {webinar_start_time}

{cta}

Jump on a few minutes early so you’re ready to go.

See you shortly,

Stacey
TEXT,
                'cta' => ['label' => 'Join Webinar', 'url' => '{webinar_join_url}'],
            ],
        ],
        [
            'key' => 'reminder_10_minute',
            'dispatch_key' => 'registration_created',
            'message_type' => 'reminder',
            'channel' => 'email',
            'purpose' => 'transactional',
            'scope' => 'webinar',
            'payload_class' => EmailPayload::class,
            'queue' => 'reminders',
            'payload' => [
                'subject' => 'Starting Soon – 10 Minutes',
                'body' => <<<'TEXT'
Hi {first_name},

We’re starting in 10 minutes.

If you’re planning to join, now’s the time to hop on.

{cta}

See you in a few,

Stacey
TEXT,
                'cta' => ['label' => 'Join Webinar', 'url' => '{webinar_join_url}'],
            ],
        ],
        [
            'key' => 'reminder_live',
            'dispatch_key' => 'registration_created',
            'message_type' => 'reminder',
            'channel' => 'email',
            'purpose' => 'transactional',
            'scope' => 'webinar',
            'payload_class' => EmailPayload::class,
            'queue' => 'reminders',
            'payload' => [
                'subject' => 'We’re Live – Jump In Now',
                'body' => <<<'TEXT'
Hi {first_name},

We just went live.

If you haven’t joined yet, you can still jump in now—we’re just getting started.

{cta}

Don’t miss this.

Stacey
TEXT,
                'cta' => ['label' => 'Join Now', 'url' => '{webinar_join_url}'],
            ],
        ],
    ],

    'post_attended' => [
        [
            'key' => 'post_attended',
            'dispatch_key' => 'webinar_ended',
            'message_type' => 'post_attended',
            'channel' => 'email',
            'purpose' => 'transactional',
            'scope' => 'webinar',
            'payload_class' => EmailPayload::class,
            'queue' => 'post_event',
            'payload' => [
                'subject' => 'Thanks for Joining',
                'body' => <<<'TEXT'
Hi {first_name},

Thank you for joining the {webinar_title} Webinar.

I hope it gave you clarity and a real strategy on how to move forward the right way.

Here’s the replay in case you missed anything:

{cta}

If you’re serious about buying, the next step is to build your personal game plan.

We’ll go over:

• Your numbers
• Your options
• Exactly how to structure your loan to win

Talk soon,

Stacey
TEXT,
                'cta' => ['label' => 'Watch Replay', 'url' => '{webinar_playback_url}'],
            ],
        ],
    ],

    'post_missed' => [
        [
            'key' => 'post_missed',
            'dispatch_key' => 'webinar_ended',
            'message_type' => 'post_missed',
            'channel' => 'email',
            'purpose' => 'transactional',
            'scope' => 'webinar',
            'payload_class' => EmailPayload::class,
            'queue' => 'post_event',
            'payload' => [
                'subject' => 'Sorry We Missed You',
                'body' => <<<'TEXT'
Hi {first_name},

Sorry we missed you live for the {webinar_title} Webinar.

Here’s the replay:

{cta}

If you’re serious about buying, the next step is to build your personal game plan.

We’ll go over:

• Your numbers
• Your options
• Exactly how to structure your loan to win

Talk soon,

Stacey
TEXT,
                'cta' => ['label' => 'Watch Replay', 'url' => '{webinar_playback_url}'],
            ],
        ],
    ],
];
