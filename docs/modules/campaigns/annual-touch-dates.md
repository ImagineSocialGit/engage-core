# Annual touch dates

Campaigns owns the recurring annual-touch capability, but an annual-touch program is a standalone business process. It is not a child of a Campaign and does not require Campaign enrollment, eligibility, or active state.

This capability is intentionally separate from ordinary relative Campaign steps. It is for durable calendar touches such as birthdays, loan anniversaries, client anniversaries, and holidays.

## Ownership

Campaigns owns:

- the annual-touch program identity and enabled state;
- the qualifying audience;
- when each annual date occurs;
- how many years the program repeats;
- which Email/SMS variants participate;
- occurrence idempotency.

Messaging continues to own reusable message copy, channel + purpose consent enforcement, suppression policy, recipient destination validation, scheduling, and provider delivery.

The framework scheduler is used to wake Campaigns. This does not create a dependency on the Scheduling module.

## Standalone program identity

`campaign_touch_programs.key` is the Project State identity for the program. The current UI creates a globally unique key and treats the Contact Status audience as the operator-facing process identity.

The database retains a nullable `campaign_id` column only as a transitional compatibility/provenance seam for rows created before the standalone-program cutover. Application models, authoring, runtime selection, dispatch context, and Project State identity do not use that value. New programs leave it null, and deleting a legacy referenced Campaign nulls the column instead of deleting the annual-touch program.

## Current runtime

The runtime scanner is `ProcessDueCampaignTouchDatesJob`, registered every minute by `CampaignsModuleServiceProvider`.

For each enabled annual program it:

1. resolves today's eligible annual date in the client timezone;
2. resolves the Contact Status audience through Core's contact-filter registry;
3. excludes any contact/variant/year occurrence already recorded;
4. hands the selected template to `DispatchMessageAction` using the `CampaignTouchProgram` as the message context;
5. records the occurrence in `campaign_touch_dispatches`.

There is no parent-Campaign active-state check.

Messaging remains the delivery authority. A message denied by Messaging's planning gate is recorded as skipped for that annual occurrence and is not retried later in the same year.

## Supported date sources

### Contact field

Current first-class source:

- `birthday`

Birthdays on February 29 are treated as due on February 28 in non-leap years.

### Fixed annual date

A touch can provide `month` and `day`, such as December 25.

### Registered date source

`registered_date_source` remains reserved for a future module-contributed date registry. The runtime currently ignores it rather than importing another module's model or table.

## Audience

The current program audience type is `contact_status`.

Campaigns asks Core's generic `ContactFilterResolver` for criterion `status`. When Workflow contributes that criterion, a value such as `past_client` resolves normally. If no enabled module contributes the criterion, the filter fails closed and no contacts are selected.

Campaigns therefore does not depend on Workflow.

The Contact Status is the actual qualification boundary. An otherwise unrelated Campaign does not have to be selected merely to provide program identity.

## Repeat window

`starts_on` may explicitly anchor a touch program. If it is null, the program's creation date is used.

`repeat_years = 10` means annual occurrences are eligible from the start date until, but not including, the date ten years later.

Past dates are not backfilled.

## Message context

New messages authored from Annual Touches receive server-owned context from the annual-touch surface:

- purpose: `marketing`;
- scope: `annual_touch`;
- dispatch key: `campaign_touch_due`;
- queue: `marketing`;
- catalog/selection context: `campaign_annual_touch`.

The `campaign_touch_due` token context exposes Contact fields that the standalone runtime can actually supply. Campaign fields are intentionally not authorable for this dispatch context because no Campaign is required at send time.

Templates created during the brief pre-cutover Campaign-owned implementation that contain `{campaign.*}` fields must be edited before they are used by the standalone runtime. New Annual Touch template authoring rejects those fields through the existing token validator.

Existing saved reusable marketing templates can still be selected where the reusable-template catalog permits them. A selected variant preserves that template's channel, purpose, and scope; channel + purpose remains the Messaging permission boundary.

## Idempotency

`campaign_touch_dispatches` uniquely identifies:

- annual-touch variant;
- Contact;
- occurrence year.

The same logical occurrence also receives a stable Messaging occurrence key. Scheduler retries therefore do not intentionally produce duplicate sends.

The dispatch table is runtime state and is declared `insert_empty` in Project State, matching other runtime execution records.

## Authoring surface

The intended UI remains intentionally small:

- Contact Status audience;
- Repeat for X years;
- optional start date;
- enabled/off state;
- repeater rows for Birthday, fixed annual dates, and future registered date sources;
- Email/SMS template selections per row;
- inline reusable-message creation.

Annual-touch authoring intentionally selects only Messaging-owned saved reusable marketing messages. Campaign step messages, Webinar lifecycle reminders/confirmations, reply acknowledgements, and other owner-specific templates are excluded from new annual-touch selection.

The Annual Touches workspace can create an Email or SMS message inline. Messaging receives the standalone annual-touch context from server-side Campaigns code, creates the reusable template, returns it to the open editor, and the UI selects it without discarding unsaved rows.

The shared Messaging creation primitive remains context-driven rather than Annual-Touch-specific. Other authoring surfaces may supply their own context later without changing the Message Template persistence schema.

Available fields are projected from `TokenContractRegistry` for `campaign_touch_due`; the UI does not maintain a second token allowlist. Server-side `MessageTemplateTokenValidator` remains authoritative at creation time.

When an existing annual-touch program is reopened, saved Email/SMS template options are server-rendered before Alpine initializes each selector. The selected preset ids are normalized to string values for browser binding so persisted selections do not visually fall back to `No email` or `No SMS`.

If an older annual-touch program already references a now-ineligible active marketing template, the editor may continue to show and preserve that existing selection so a normal edit does not strand historical configuration. New selections must use the safe reusable catalog.