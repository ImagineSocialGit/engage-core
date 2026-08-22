# Campaign annual touch dates

Campaigns owns recurring annual touch-date configuration because these are nurture/re-engagement moments, not appointments or calendar availability.

This foundation is configuration-only. It does not schedule or send messages yet.

## Stored shape

A Campaign may have one or more touch programs. A program currently supports:

- an audience selector (`audience_type` + `audience_key`), initially intended for Contact Status such as `past_client`;
- annual recurrence;
- a finite repeat window such as 10 years;
- multiple ordered touch dates.

A touch date may resolve from:

- a Core Contact field, initially `birthday`;
- a fixed month/day such as December 25;
- a future registered date source contributed by another module, such as a loan anniversary, without making Campaigns depend on that module.

Each touch date may have multiple channel variants. The variant stores channel/purpose/scope and an optional logical `message_template_preset_id`. Messaging remains the owner of reusable email/SMS copy and delivery.

## Intended first authoring surface

The first UI can stay small:

1. `Have recurring annual touch-base dates`
2. choose Contact Status (for example, Past Client)
3. choose the number of years
4. add repeater rows for Birthday, fixed dates, or registered date sources
5. choose send time
6. add Email and/or SMS and select the Messaging template for each channel

## Deferred runtime

Do not add annual scanning, due-date calculation, scheduling jobs, or message dispatch in this foundation batch. Runtime should be added separately so leap-day behavior, timezone rules, status eligibility at send time, dedupe/idempotency, consent, and campaign cancellation semantics can be specified and tested explicitly.