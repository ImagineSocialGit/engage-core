# Webinar outcome Campaign eligibility

## Purpose

Webinars may contribute durable Contact facts to the generic Core Contact-filter system without Campaigns depending on the Webinars module.

Batch 5A adds the criterion:

```text
webinar_outcome
```

with semantic values such as:

```text
va-homebuyer-game-plan:attended
va-homebuyer-game-plan:missed
```

Campaigns can therefore target a durable webinar fact through the same `eligibility_filter` used for status, relationship, source, subsource, and tag criteria.

## Latest resolved outcome semantics

`webinar_outcome` means:

> The Contact's latest resolved terminal outcome for the selected webinar series.

Terminal outcomes are:

```text
attended
missed
```

A later nonterminal registration such as `registered` does not erase the most recent resolved attended/missed fact.

Example:

```text
July occurrence     attended
August occurrence   missed
```

The Contact currently matches:

```text
series:missed
```

and does not also match:

```text
series:attended
```

If a later September occurrence resolves to attended, the fact flips back to attended.

This is deliberate for recurring webinar series. "Has ever attended" and "has ever missed" would allow the same Contact to remain permanently eligible for both mutually exclusive post-webinar nurture Campaigns.

## Durable source of truth

The criterion reads Webinars-owned durable state:

```text
webinar_series
webinars
webinar_registrations
```

It does not use ephemeral event receipt or Automation Event history as the eligibility source of truth.

Automation Events are only the prompt to reevaluate current durable state.

## Reevaluation event seam

Webinars already publishes durable Automation Events:

```text
webinar.attended
webinar.missed
```

Campaigns maps those neutral event keys to the generic criterion key:

```text
webinar_outcome
```

and reevaluates only automatic Campaigns that depend on that criterion.

Campaigns does not import Webinars classes. Webinars does not import Campaigns classes.

The boundary is:

```text
Webinars durable registration state
    -> Core ContactFilterCriterion contribution

Webinars durable automation event
    -> shared AutomationEventRecorded
    -> Campaigns criterion-key reevaluation
```

The 15-minute automatic Campaign reconciliation pass remains the correctness backstop.

## Stable persistence

Campaign eligibility stores webinar series slugs, not database IDs:

```text
va-homebuyer-game-plan:attended
```

Normalization validates the semantic format without requiring the referenced series to exist at normalization time.

That means a temporarily unavailable or retired series criterion is preserved and fails closed instead of being silently discarded.

The authoring UI still requires a selectable current option when adding a new criterion. Existing selected values remain visible through the Campaign authoring stale-value preservation behavior.

## Authoring and Process Highway

When Webinars is enabled, its criterion is registered under the existing Core tag:

```text
core.contact_filter_criteria
```

Campaign Start authoring therefore discovers it through `ContactFilterCriterionRegistry`.

Process Highway recognizes the generic criterion key for presentation but does not import Webinars.

## Not changed in Batch 5A

- No Slam Dunk Campaign is switched to automatic enrollment yet.
- No Slam Dunk Flow Route is removed yet.
- No Contact import launch policy changes yet.
- No database migration is required.
- Messaging consent/scope behavior is unchanged.

The Slam Dunk config cutover and import launch bridge belong together in Batch 5B so launch timing cannot temporarily diverge from the client Campaign definitions.

## Remaining refactor roadmap

1. Batch 5B — Slam Dunk Campaign eligibility cutover, import launch bridge, and redundant Flow Route removal.
2. Messaging scope/consent cleanup.
3. Preset/bootstrap hardening and portable stable-key Campaign JSON.
4. Final acceptance and Slam Dunk go-live.