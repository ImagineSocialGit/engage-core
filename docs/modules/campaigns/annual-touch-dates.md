# Annual touch dates

Campaigns owns the recurring annual-touch capability, but an annual-touch program is a standalone business process. It is not a child of a Campaign and does not require Campaign enrollment, Campaign eligibility, Workflow, or a Contact Status.

This capability is intentionally separate from ordinary relative Campaign steps. It is for durable calendar touches such as birthdays, loan anniversaries, client anniversaries, and holidays.

## Ownership

Campaigns owns:

- the annual-touch program identity and enabled state;
- the qualifying audience and exclusions;
- when each annual date occurs;
- how many years the program repeats;
- which Email/SMS variants participate;
- occurrence idempotency.

Messaging continues to own reusable message copy, channel + purpose consent enforcement, suppression policy, recipient destination validation, scheduling, missing-token behavior, and provider delivery.

The framework scheduler is used to wake Campaigns. This does not create a dependency on the Scheduling module.

## Standalone program identity

`campaign_touch_programs.key` is the Project State identity for the program.

The database retains nullable legacy `campaign_id`, `audience_type`, and `audience_key` columns as compatibility/provenance seams. New authoring stores the actual audience contract in `campaign_touch_programs.audience_filter`, sets `audience_type = filter`, leaves `audience_key` null, and does not require a parent Campaign.

Legacy `contact_status` programs remain readable. The audience migration backfills their stable Contact Status key into the first-class audience filter so their behavior is preserved.

## Current runtime

The runtime scanner is `ProcessDueCampaignTouchDatesJob`, registered every minute by `CampaignsModuleServiceProvider`.

For each enabled annual program it:

1. resolves today's eligible annual date in the client timezone;
2. resolves the program's audience through Core's contact-filter registry;
3. applies configured exclusions after the main audience;
4. excludes any Contact/variant/year occurrence already recorded;
5. hands the selected template to `DispatchMessageAction` using the `CampaignTouchProgram` as the message context;
6. records the occurrence in `campaign_touch_dispatches`.

There is no parent-Campaign active-state check and no Workflow-status check unless the operator explicitly chose Status as an audience condition.

Messaging remains the final delivery authority. Audience membership does not bypass channel availability, destination, consent, suppression, runtime, or provider gates. A message denied by Messaging's planning gate is recorded as skipped for that annual occurrence and is not retried later in the same year.

## Supported date sources

### Date from field

Campaigns consumes the shared typed `ModuleFactRegistry`. Each enabled producer module contributes only the facts it owns, including:

- a stable namespaced key and operator-facing label/description;
- its owner, Contact subject, date value type, and `annualizable` capability;
- producer-owned value and query resolvers.

Campaigns asks the registry only for queryable, annualizable Contact dates. It does not assume that every annual-touch date is a column on `contacts` and does not import producer-module models. Existing legacy `contact_field` birthday rows and the `birthday` alias remain readable; new authoring stores canonical fact keys as `registered_date_source`.

Core contributes the universal Contact fact:

- `core.contact.birthday` — resolved from `contacts.birthday`.

Birthdays on February 29 are treated as due on February 28 in non-leap years.

When Mortgage is enabled, Mortgage contributes:

- `mortgage.contact.home_purchase_date` — the most recent recorded **Purchase** loan closing date linked to the Contact through Mortgage loan participants.

Mortgage remains authoritative for that date and owns both resolvers. Campaigns and Core do not copy it onto `contacts` or `contact_mortgage_profiles`. Refinance closings do not replace the home-purchase anniversary source.

### Fixed annual date

A touch can provide `month` and `day`, such as December 25. Fixed dates can apply to otherwise eligible Contacts even when they do not have a birthday or Contact Status.

## Audience

The durable audience contract is `campaign_touch_programs.audience_filter`.

Supported main-audience modes are:

- `all` — every Contact, subject to exclusions and Messaging delivery gates;
- `criteria` — Contacts matching all configured criterion groups;
- `contacts` — explicitly selected Contacts.

Optional exclusions can contain both contributed criteria and explicit Contact ids. Exclusions are applied after the main audience; matching any configured exclusion group disqualifies the Contact.

Criteria come from Core's `ContactFilterCriterionRegistry`. Campaigns does not import producer-module models to implement those filters. Core-owned criteria such as tags/source can therefore work without Workflow, while optional modules may contribute additional criteria such as Status or Relationship when enabled.

Status is only an optional audience condition. When Workflow contributes the `status` criterion, Annual Touches stores stable Contact Status keys and translates them to the criterion's runtime identifiers at the Core boundary. A Contact with no status remains eligible for an `all`, tag, relationship, explicit-contact, or other non-status annual-touch audience.

If a saved criterion belongs to an optional feature that is not currently available, Campaigns preserves the saved condition and fails closed rather than broadening the audience silently.

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

Missing optional personalization follows Messaging's explicit per-message token-fallback contract. Missing required fields remain a Messaging pre-send safety decision.

Existing saved reusable marketing templates can still be selected where the reusable-template catalog permits them. A selected variant preserves that template's channel, purpose, and scope; channel + purpose remains the Messaging permission boundary.

## Idempotency

`campaign_touch_dispatches` uniquely identifies:

- annual-touch variant;
- Contact;
- occurrence year.

The same logical occurrence also receives a stable Messaging occurrence key. Scheduler retries therefore do not intentionally produce duplicate sends.

The dispatch table is runtime state and is declared `insert_empty` in Project State, matching other runtime execution records.

## Authoring surface

The Annual Touches workspace keeps the process task-oriented:

- choose `All eligible contacts`, `Contacts matching conditions`, or `Specific contacts`;
- choose any currently available contributed conditions;
- optionally exclude conditions or specific Contacts;
- preview the current matching count;
- choose `Repeat for X years` and optional start date;
- add Birthday or fixed annual dates;
- choose Email/SMS templates per date;
- create a reusable annual-touch message inline.

The page explains that audience selection decides who the program considers, while Messaging still decides whether Email or SMS can actually send.

Annual-touch authoring intentionally selects only Messaging-owned saved reusable marketing messages. Campaign step messages, Webinar lifecycle reminders/confirmations, reply acknowledgements, and other owner-specific templates are excluded from new annual-touch selection.

Available message fields are projected from `TokenContractRegistry` for `campaign_touch_due`; the UI does not maintain a second token allowlist. Server-side `MessageTemplateTokenValidator` remains authoritative at creation time.

When an existing annual-touch program is reopened, saved Email/SMS template options are server-rendered before Alpine initializes each selector. If an older annual-touch program already references a now-ineligible active marketing template, the editor may continue to show and preserve that existing selection so a normal edit does not strand historical configuration. New selections must use the safe reusable catalog.