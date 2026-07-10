<?php

use App\Modules\Messaging\Payloads\EmailPayload;

return [
    'opt_ins' => [
        [
            'key' => 'opt_in',
            'dispatch_key' => 'consent_granted',
            'message_type' => 'opt_in',
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'webinar_nurture',
            'payload_class' => EmailPayload::class,
            'queue' => 'opt_in_messages',
            'payload' => [
                'subject' => 'You’re subscribed to Slam Dunk webinar follow-up emails',
                'body' => 'Thanks for subscribing to helpful webinar follow-up from Slam Dunk Home Loans. You can unsubscribe at any time.',
            ],
        ],
    ],

    'campaigns' => [
        'webinar_attended_nurture' => [
            'steps' => [
                1 => [
                    'variants' => [
                        'email' => [
                            'dispatch_key' => 'campaign_step_due',
                            'payload_class' => EmailPayload::class,
                            'queue' => 'marketing',
                            'payload' => [
                                'subject' => 'Most buyers mess this up',
                                'body' => <<<'TEXT'
Hi {first_name},

Most buyers think getting pre-approved is step one.

It’s not.

Most “pre-approvals” are just a quick credit pull and a number on paper. No documents reviewed. No real underwriting. No real approval.

That’s why deals fall apart.

A real approval is a strategy. It’s built to hold up when it matters.

If you want to make sure you’re actually ready—not just “pre-approved”—reply and tell me your biggest question.

– Stacey
TEXT,
                            ],
                        ],
                    ],
                ],
                2 => [
                    'variants' => [
                        'email' => [
                            'dispatch_key' => 'campaign_step_due',
                            'payload_class' => EmailPayload::class,
                            'queue' => 'marketing',
                            'payload' => [
                                'subject' => 'The mistake that costs buyers the most',
                                'body' => <<<'TEXT'
Hi {first_name},

Most buyers focus on price.

Smart buyers focus on payment.

Because price doesn’t matter if the payment doesn’t work.

There are multiple ways to structure a loan: lower rate, seller credits, buydowns, and different loan strategies.

Same house. Completely different payment.

That’s where strategy matters.

Reply if you want help thinking through what matters most for your situation.

– Stacey
TEXT,
                            ],
                        ],
                    ],
                ],
                4 => [
                    'variants' => [
                        'email' => [
                            'dispatch_key' => 'campaign_step_due',
                            'payload_class' => EmailPayload::class,
                            'queue' => 'marketing',
                            'payload' => [
                                'subject' => 'No, you don’t need 20% down',
                                'body' => <<<'TEXT'
Hi {first_name},

One of the biggest myths I hear is: “I need 20% down to buy.”

Not true.

There are options with 0% down for eligible VA buyers, 3% down, and 3.5% down.

What matters isn’t just the down payment—it’s how the entire deal is structured.

Waiting to “save more” sometimes costs more than it saves.

Reply if you want to talk through what could apply to you.

– Stacey
TEXT,
                            ],
                        ],
                    ],
                ],
                6 => [
                    'variants' => [
                        'email' => [
                            'dispatch_key' => 'campaign_step_due',
                            'payload_class' => EmailPayload::class,
                            'queue' => 'marketing',
                            'payload' => [
                                'subject' => 'This almost blew up at the finish line',
                                'body' => <<<'TEXT'
Hi {first_name},

I recently worked on a deal where the buyer was “pre-approved” with another lender.

Everything looked fine—until it didn’t.

Two weeks before closing, an income issue surfaced and the approval fell apart.

We stepped in, restructured it, and got it closed.

This happens more than people think.

The difference is having the right strategy upfront.

If you want to avoid that situation, reply and tell me where you are in the process.

– Stacey
TEXT,
                            ],
                        ],
                    ],
                ],
                8 => [
                    'variants' => [
                        'email' => [
                            'dispatch_key' => 'campaign_step_due',
                            'payload_class' => EmailPayload::class,
                            'queue' => 'marketing',
                            'payload' => [
                                'subject' => 'Waiting might cost you more',
                                'body' => <<<'TEXT'
Hi {first_name},

A lot of buyers are waiting right now.

Waiting for rates. Waiting for prices. Waiting for “the right time.”

Here’s the reality: the market doesn’t reward people who wait for perfect. It rewards people who have a strategy.

If rates improve later, you can adjust. But you can’t go back and buy at yesterday’s prices.

Reply if you want to talk through your timing and options.

– Stacey
TEXT,
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'webinar_missed_nurture' => [
            'steps' => [
                1 => [
                    'variants' => [
                        'email' => [
                            'dispatch_key' => 'campaign_step_due',
                            'payload_class' => EmailPayload::class,
                            'queue' => 'marketing',
                            'payload' => [
                                'subject' => 'You missed it… but I’ve got you',
                                'body' => <<<'TEXT'
Hi {first_name},

You signed up for the Homebuyer Game Plan, but we didn’t see you on.

No worries. Life happens.

But here’s the deal: this is the stuff most buyers never learn, and it’s exactly why deals fall apart.

I recorded it for you, and your replay is being handled separately through the webinar follow-up.

If you’re even thinking about buying, don’t wing this.

– Stacey
Mortgage Strategist
30+ years | $1B+ closed
TEXT,
                            ],
                        ],
                    ],
                ],
                2 => [
                    'variants' => [
                        'email' => [
                            'dispatch_key' => 'campaign_step_due',
                            'payload_class' => EmailPayload::class,
                            'queue' => 'marketing',
                            'payload' => [
                                'subject' => 'This is why people get denied',
                                'body' => <<<'TEXT'
Hi {first_name},

Too many buyers get pre-approved, go under contract, and then get denied.

It’s not always because they were unqualified. It’s often because their “pre-approval” was weak.

No document review. No real underwriting. No strategy. Just a number on a screen.

That’s what the Homebuyer Game Plan is designed to prevent.

Reply if you want help figuring out where you stand.

– Stacey
TEXT,
                            ],
                        ],
                    ],
                ],
                3 => [
                    'variants' => [
                        'email' => [
                            'dispatch_key' => 'campaign_step_due',
                            'payload_class' => EmailPayload::class,
                            'queue' => 'marketing',
                            'payload' => [
                                'subject' => 'Let me be blunt',
                                'body' => <<<'TEXT'
Hi {first_name},

Most lenders are guessing.

They quote a rate. They issue a quick pre-approval. And they hope it works.

That’s not a strategy. That’s gambling with your future.

If you want to actually win when you buy, you need a game plan before you ever make an offer.

Reply if you want help thinking through yours.

– Stacey
TEXT,
                            ],
                        ],
                    ],
                ],
                4 => [
                    'variants' => [
                        'email' => [
                            'dispatch_key' => 'campaign_step_due',
                            'payload_class' => EmailPayload::class,
                            'queue' => 'marketing',
                            'payload' => [
                                'subject' => 'This deal almost blew up',
                                'body' => <<<'TEXT'
Hi {first_name},

I had a client recently who was pre-approved by another lender and already under contract.

Looked fine on the surface. It wasn’t.

Income was calculated wrong. Debt was off. Approval wasn’t real.

The deal was about to die.

We stepped in. Fixed it. Closed it.

This happens more than people realize, and it’s avoidable.

– Stacey
TEXT,
                            ],
                        ],
                    ],
                ],
                5 => [
                    'variants' => [
                        'email' => [
                            'dispatch_key' => 'campaign_step_due',
                            'payload_class' => EmailPayload::class,
                            'queue' => 'marketing',
                            'payload' => [
                                'subject' => 'Biggest mistake buyers make',
                                'body' => <<<'TEXT'
Hi {first_name},

They shop for a house before they know what they can actually do.

That’s backwards.

A real pre-approval isn’t a form. It’s a strategy.

Income. Debt. Credit. Timing.

All mapped out before you step into a house.

That’s how you win.

– Stacey
TEXT,
                            ],
                        ],
                    ],
                ],
                6 => [
                    'variants' => [
                        'email' => [
                            'dispatch_key' => 'campaign_step_due',
                            'payload_class' => EmailPayload::class,
                            'queue' => 'marketing',
                            'payload' => [
                                'subject' => 'Last chance',
                                'body' => <<<'TEXT'
Hi {first_name},

If you’re even thinking about buying, don’t ignore the strategy side of the process.

Your transactional webinar follow-up handles replay availability separately.

If you missed this class, you can still reply and tell me what you’re trying to figure out.

– Stacey
TEXT,
                            ],
                        ],
                    ],
                ],
                7 => [
                    'variants' => [
                        'email' => [
                            'dispatch_key' => 'campaign_step_due',
                            'payload_class' => EmailPayload::class,
                            'queue' => 'marketing',
                            'payload' => [
                                'subject' => 'Next class is open',
                                'body' => <<<'TEXT'
Hi {first_name},

A new Homebuyer Game Plan session may be a better fit if the last time didn’t work for you.

Reply and we’ll help you find the next useful class or resource.

– Stacey
TEXT,
                            ],
                        ],
                    ],
                ],
                8 => [
                    'variants' => [
                        'email' => [
                            'dispatch_key' => 'campaign_step_due',
                            'payload_class' => EmailPayload::class,
                            'queue' => 'marketing',
                            'payload' => [
                                'subject' => 'Quick question…',
                                'body' => <<<'TEXT'
Hi {first_name},

Quick question for you.

What stopped you from attending the class?

Time? Already working with someone? Just not ready yet?

No judgment—I’m genuinely curious.

Depending on where you are, the advice changes. I’d rather point you in the right direction than throw generic information at you.

Hit reply and tell me.

– Stacey
TEXT,
                            ],
                        ],
                    ],
                ],
                9 => [
                    'variants' => [
                        'email' => [
                            'dispatch_key' => 'campaign_step_due',
                            'payload_class' => EmailPayload::class,
                            'queue' => 'marketing',
                            'payload' => [
                                'subject' => 'Most people wait too long',
                                'body' => <<<'TEXT'
Hi {first_name},

Here’s what I see all the time.

People wait. They wait until they think they have enough saved, rates drop, or the “perfect time” shows up.

And while they’re waiting, prices move, rent goes up, and opportunities pass.

I’m not saying rush into anything.

But waiting without a plan is expensive.

A smart strategy gives you options.

– Stacey
TEXT,
                            ],
                        ],
                    ],
                ],
                10 => [
                    'variants' => [
                        'email' => [
                            'dispatch_key' => 'campaign_step_due',
                            'payload_class' => EmailPayload::class,
                            'queue' => 'marketing',
                            'payload' => [
                                'subject' => 'You don’t need perfect credit',
                                'body' => <<<'TEXT'
Hi {first_name},

Another big myth: “I need perfect credit before I can buy.”

No, you don’t.

Are there minimums? Yes. Does structure matter? Absolutely.

But I’ve helped people buy with lower scores, past credit issues, and less-than-perfect situations because we built the right plan.

Most lenders just say “come back later.” That’s lazy.

– Stacey
TEXT,
                            ],
                        ],
                    ],
                ],
                11 => [
                    'variants' => [
                        'email' => [
                            'dispatch_key' => 'campaign_step_due',
                            'payload_class' => EmailPayload::class,
                            'queue' => 'marketing',
                            'payload' => [
                                'subject' => 'You don’t need 20% down',
                                'body' => <<<'TEXT'
Hi {first_name},

Most people still believe you need 20% down to buy a home.

Not true.

There are options as low as 3%, 3.5%, and 0% for eligible buyers.

And no, lower down payment is not automatically worse.

In some cases, waiting to save 20% can cost more in time, rent, and missed appreciation.

– Stacey
TEXT,
                            ],
                        ],
                    ],
                ],
                12 => [
                    'variants' => [
                        'email' => [
                            'dispatch_key' => 'campaign_step_due',
                            'payload_class' => EmailPayload::class,
                            'queue' => 'marketing',
                            'payload' => [
                                'subject' => 'Instant pre-approvals are not real approvals',
                                'body' => <<<'TEXT'
Hi {first_name},

I’m going to say this as clearly as possible.

Those instant online “pre-approvals” are not real approvals.

No one reviewed your income. No one verified your assets. No one ran actual underwriting logic.

It’s a guess.

And guesses don’t win contracts.

Real pre-approval equals strategy.

– Stacey
TEXT,
                            ],
                        ],
                    ],
                ],
                13 => [
                    'variants' => [
                        'email' => [
                            'dispatch_key' => 'campaign_step_due',
                            'payload_class' => EmailPayload::class,
                            'queue' => 'marketing',
                            'payload' => [
                                'subject' => 'They almost lost the house',
                                'body' => <<<'TEXT'
Hi {first_name},

Quick story.

A buyer was under contract, had a pre-approval, and thought everything was fine.

Then income was calculated wrong, debt wasn’t counted correctly, and the loan didn’t actually work.

The deal was about to fall apart.

We stepped in. Fixed it. Closed it.

That stress was completely avoidable.

– Stacey
TEXT,
                            ],
                        ],
                    ],
                ],
                14 => [
                    'variants' => [
                        'email' => [
                            'dispatch_key' => 'campaign_step_due',
                            'payload_class' => EmailPayload::class,
                            'queue' => 'marketing',
                            'payload' => [
                                'subject' => 'Don’t do this mid-process',
                                'body' => <<<'TEXT'
Hi {first_name},

If you’re even thinking about buying, read this.

Do not change jobs, open new credit cards, finance a car, or move money around randomly without talking to your lender first.

I’ve seen deals die over this—not because the buyer was unqualified, but because no one told them what not to do.

That’s part of a real strategy.

– Stacey
TEXT,
                            ],
                        ],
                    ],
                ],
                15 => [
                    'variants' => [
                        'email' => [
                            'dispatch_key' => 'campaign_step_due',
                            'payload_class' => EmailPayload::class,
                            'queue' => 'marketing',
                            'payload' => [
                                'subject' => 'Still renting?',
                                'body' => <<<'TEXT'
Hi {first_name},

Let me ask you something.

Are you still renting right now?

If yes, you’re paying 100% of the payment and none of it builds equity for you.

I’m not saying rush into buying.

But you should understand your options.

– Stacey
TEXT,
                            ],
                        ],
                    ],
                ],
                16 => [
                    'variants' => [
                        'email' => [
                            'dispatch_key' => 'campaign_step_due',
                            'payload_class' => EmailPayload::class,
                            'queue' => 'marketing',
                            'payload' => [
                                'subject' => 'This wins deals—and nobody talks about it',
                                'body' => <<<'TEXT'
Hi {first_name},

You know what wins offers?

Not just price. Execution.

Strong pre-approval. Clean file. Fast lender. Clear communication.

I’ve seen VA beat conventional. I’ve seen lower offers win because the lender knew what they were doing.

– Stacey
TEXT,
                            ],
                        ],
                    ],
                ],
                17 => [
                    'variants' => [
                        'email' => [
                            'dispatch_key' => 'campaign_step_due',
                            'payload_class' => EmailPayload::class,
                            'queue' => 'marketing',
                            'payload' => [
                                'subject' => 'Let me save you thousands',
                                'body' => <<<'TEXT'
Hi {first_name},

Small decisions in a mortgage can have big consequences.

Rate structure. Points. Credits. Loan type. Timing.

Most people don’t even know what to ask, so they overpay or choose the wrong setup entirely.

That can cost thousands upfront or tens of thousands long-term.

Strategy matters.

– Stacey
TEXT,
                            ],
                        ],
                    ],
                ],
                18 => [
                    'variants' => [
                        'email' => [
                            'dispatch_key' => 'campaign_step_due',
                            'payload_class' => EmailPayload::class,
                            'queue' => 'marketing',
                            'payload' => [
                                'subject' => 'Most buyers don’t know this',
                                'body' => <<<'TEXT'
Hi {first_name},

You can sometimes buy a home without selling yours first.

There are financing structures designed to create flexibility, avoid unnecessary double payments, and help buyers move before a sale is complete.

The right answer depends on your situation, but it’s worth knowing these options exist.

– Stacey
TEXT,
                            ],
                        ],
                    ],
                ],
                19 => [
                    'variants' => [
                        'email' => [
                            'dispatch_key' => 'campaign_step_due',
                            'payload_class' => EmailPayload::class,
                            'queue' => 'marketing',
                            'payload' => [
                                'subject' => 'Be honest…',
                                'body' => <<<'TEXT'
Hi {first_name},

Be honest with me for a second.

Are you still thinking about buying? Still unsure where to start? Still waiting for the “right time”?

Most people sit in that space for way too long—not because they can’t buy, but because no one ever showed them how.

That’s the gap.

Reply and I’ll point you in the right direction.

– Stacey
TEXT,
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
];
