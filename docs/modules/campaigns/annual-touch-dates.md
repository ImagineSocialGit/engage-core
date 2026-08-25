# Campaign annual touch dates

Campaigns owns recurring annual touch behavior.

This capability is intentionally separate from ordinary relative Campaign steps.
It is for durable calendar touches such as birthdays, loan anniversaries, client
anniversaries, and holidays.

## Ownership

Campaigns owns:

- which Campaign the annual touch program belongs to;
- the qualifying audience;
- when the annual date occurs;
- how many years it repeats;
- which Email/SMS variants participate;
- occurrence idempotency.

Messaging continues to own reusable message copy, consent/suppression policy,
recipient destination validation, scheduling, and provider delivery.

The framework scheduler is used to wake Campaigns. This does not create a
dependency on the Scheduling module.

## Current runtime

The runtime scanner is `ProcessDueCampaignTouchDatesJob`, registered every minute
by `CampaignsModuleServiceProvider`.

For each active annual program it:

1. confirms the parent Campaign is active;
2. resolves today's eligible annual date in the client timezone;
3. resolves the audience through Core's contact-filter registry;
4. excludes any contact/variant/year occurrence already recorded;
5. hands the selected template to `DispatchMessageAction`;
6. records the occurrence in `campaign_touch_dispatches`.

Messaging remains the delivery authority. A message denied by Messaging's
planning gate is recorded as skipped for that annual occurrence and is not
retried later in the same year.

## Supported date sources

### Contact field

Current first-class source:

- `birthday`

Birthdays on February 29 are treated as due on February 28 in non-leap years.

### Fixed annual date

A touch can provide `month` and `day`, such as December 25.

### Registered date source

`registered_date_source` remains reserved for a future module-contributed date
registry. The runtime currently ignores it rather than importing another
module's model or table.

## Audience

The current program audience type is `contact_status`.

Campaigns asks Core's generic `ContactFilterResolver` for criterion `status`.
When Workflow contributes that criterion, a value such as `past_client`
resolves normally. If no enabled module contributes the criterion, the filter
fails closed and no contacts are selected.

Campaigns therefore does not depend on Workflow.

## Repeat window

`starts_on` may explicitly anchor a touch program. If it is null, the program's
creation date is used.

`repeat_years = 10` means annual occurrences are eligible from the start date
until, but not including, the date ten years later.

Past dates are not backfilled.

## Idempotency

`campaign_touch_dispatches` uniquely identifies:

- Campaign touch variant;
- Contact;
- occurrence year.

The same logical occurrence also receives a stable Messaging occurrence key.
Scheduler retries therefore do not intentionally produce duplicate sends.

The dispatch table is runtime state and is declared `insert_empty` in Project
State, matching other runtime execution records.

## Authoring direction

The intended Campaign UI remains intentionally small:

- Have recurring annual touch-base dates
- Audience / Contact Status
- Repeat for X years
- Repeater rows:
  - Birthday
  - fixed annual date
  - future registered date source
- Email/SMS template selections per row

Annual-touch authoring intentionally selects only Messaging-owned saved reusable
marketing messages. It does not expose the full active template catalog. Campaign
step messages, Webinar lifecycle reminders/confirmations, reply acknowledgements,
and other owner-specific templates are excluded from new annual-touch selection.

The Annual Touches workspace can also create an Email or SMS message inline. The operator
provides the message name and copy; Campaigns supplies the selected Campaign's server-owned
annual-touch context to Messaging. New templates therefore receive the correct marketing
purpose, Campaign scope, `campaign_touch_due` dispatch context, payload class, queue,
Campaign catalog grouping, and annual-touch selection eligibility automatically. The new
template is returned to the open editor and selected without discarding unsaved annual-touch
rows.

The shared creation primitive is intentionally not Annual-Touch-specific. Other authoring
surfaces may later supply their own context to the same Messaging action without schema,
client-config, or preset-sync changes.

If an older annual-touch program already references a now-ineligible active marketing
template, the editor may continue to show and preserve that existing selection so a
normal edit does not strand historical configuration. New selections must use the
safe reusable catalog.

The runtime and schema do not require that UI to exist.