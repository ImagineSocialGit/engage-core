# Campaign eligibility authoring

## Purpose

The Campaign Builder's **Start** stage is the operator-facing editor for Campaign eligibility and enrollment policy.

Campaigns do not require a separate trigger builder for normal automatic enrollment. An automatic Campaign asks:

> Who should receive this Campaign?

When a Contact moves from not eligible to eligible, the Campaign lifecycle runtime may enroll that Contact according to the Campaign's enrollment and re-entry policy.

The existing four-stage Campaign Builder remains:

```text
Start -> Schedule -> Messages -> Review
```

No fifth "Eligibility" stage is introduced.

## Start-stage contract

The Start stage edits:

```text
eligibility_filter
enrollment_mode
reentry_policy
ineligible_behavior
```

Supported enrollment modes:

```text
manual
automatic
```

Automatic enrollment requires at least one eligibility condition.

Supported re-entry policies:

```text
never
when_eligible_again
```

Supported behavior when a Contact stops matching eligibility:

```text
continue
pause
cancel
```

The runtime semantics remain owned by the Campaign lifecycle actions introduced before this UI.

## Generic Contact-filter seam

The authoring surface is driven by Core's `ContactFilterCriterionRegistry`.

Campaigns does not import Workflow, Relationships, Mortgage, Webinars, Forms, or other optional feature modules to build the editor.

Current authorable criterion keys are:

```text
status
relationship
source
subsource
tag
```

A criterion appears only when it is currently contributed through the Core filter registry.

`import_batch` is intentionally not exposed as a normal automatic-Campaign eligibility choice. It is installation-specific operational/provenance state rather than a portable business identity. Existing unsupported or unavailable stored criteria are preserved rather than silently discarded.

## Stable persisted values

Campaign eligibility must remain portable.

The existing Workflow `status` filter criterion consumes numeric ContactStatus IDs at runtime, but Campaign persistence stores stable ContactStatus keys.

The authoring adapter therefore exposes:

```text
prospect_nurture
past_contact
```

rather than installation-specific values such as:

```text
3
11
```

Audience preview and runtime eligibility translate those stable status keys to the active local ContactStatus IDs at evaluation time.

Relationship filter values are already semantic:

```text
realtor:*
realtor:target_agent
```

Source, subsource, and tag values use their business strings.

## Stale and unavailable values

An existing selected value that is no longer in a contributed criterion's current option list remains visible as "currently unavailable" so an operator can deliberately remove it.

An existing criterion whose contributor is not currently available is shown separately and preserved on save.

Campaign eligibility fails closed at runtime when a required criterion cannot currently be resolved.

This prevents an operator from accidentally widening a Campaign audience simply because a module was disabled or a configured value disappeared.

## Audience preview

The Start stage exposes a current matching Contact count.

Preview uses the same Core Contact-filter resolver as Campaign runtime eligibility. Different criterion categories are combined with AND semantics; the individual contributed criterion owns its internal value semantics.

Preview is an eligibility count, not a sendability count. Messaging still owns destination availability, consent, suppression, provider availability, and final delivery gating.

## Customization semantics

Saving Start-stage configuration marks the Campaign itself customized through the existing `is_customized` / `customized_at` contract.

That is intentional. Preset sync must not silently overwrite operator-authored Campaign policy later.

This batch does not introduce a second customization system.

## Not changed in Batch 3

- Slam Dunk client Campaign definitions remain unchanged.
- Existing Slam Dunk Campaign-routing Flow Routes remain in place.
- Process Highway is unchanged.
- Messaging consent/scope semantics are unchanged.
- Schedule authoring is still summarized from the current MessageChain journey.
- Existing Campaign message editing remains the Messages stage.
- No database migration is required.

## Remaining refactor roadmap

1. Process Highway integration: show Campaign eligibility/enrollment/journey alongside Flow Routes.
2. Slam Dunk migration: convert appropriate Campaigns to automatic eligibility and remove redundant Campaign-routing/cleanup Flow Routes while preserving real orchestration.
3. Messaging scope/consent cleanup: channel + purpose becomes the hard marketing-permission boundary.
4. Preset/bootstrap hardening and portable stable-key Campaign JSON.
5. Final acceptance and Slam Dunk go-live.