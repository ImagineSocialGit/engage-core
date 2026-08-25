# Campaign eligibility reevaluation

## Purpose

Automatic Campaigns react to Contact-filter facts without requiring a Flow Route whose only job is to start or stop a Campaign.

Campaigns remains the authority for eligibility, enrollment policy, re-entry, and behavior when eligibility becomes false. The module that owns a fact remains authoritative for mutating that fact.

## Two event classes with different jobs

Contact-filter fact changes and durable automation outcomes are intentionally not the same thing.

Core source/subsource changes, Contact tags, and Relationships membership/stage changes are current-state mutations. They emit the transient Core `ContactFilterFactsChanged` domain event.

That transient event is deliberately not a queued Laravel listener and is deliberately not written to:

```text
automation_event_outbox_events
automation_event_consumer_receipts
```

The synchronous listener does only lightweight routing work:

1. resolve the Contact;
2. reject Contacts still inside a processing import batch;
3. determine whether any active automatic Campaign depends on one of the changed criteria;
4. enqueue `ReconcileContactCampaignEligibilityJob` only when relevant work exists.

The dedicated job runs on the `campaigns` queue and re-resolves the current Campaign dependencies before applying lifecycle behavior. It is marked `afterCommit`, so eligibility consequences do not escape a transaction that later rolls back.

This avoids a global `Illuminate\Events\CallQueuedListener` side effect for every ordinary Contact mutation while keeping actual Campaign reevaluation off the request path.

Workflow status changes are different because Workflow already publishes the durable `workflow.contact_status_changed` Automation Event. Campaigns continues to consume that existing durable event as a change to the `status` criterion. Campaigns does not import Workflow classes.

## Targeted reevaluation

A fact-change signal does not scan every Campaign. `CampaignEligibilityDependencyResolver` selects only active automatic Campaigns whose persisted `eligibility_filter` contains one of the changed criterion keys.

When no automatic Campaign depends on the changed fact, no Campaign job is queued.

When work is relevant, the contact-scoped job passes matching Campaigns through the Batch 2A lifecycle engine:

```text
false -> true  => enroll when policy permits
true  -> false => continue, pause, or cancel
true  -> true  => no lifecycle churn
false -> false => no lifecycle churn
```

The lifecycle engine is idempotent, so repeated transient fact notifications are harmless. Workflow's durable Automation Event path retains its normal consumer-receipt idempotency.

## Import safety

Contacts whose current `contact_import_batch_id` points to a processing import batch are not queued for automatic reevaluation while the batch is still being assembled. This prevents row-by-row fact changes from starting Campaigns before post-import launch timing and other batch policy have finished.

After the batch is no longer processing, normal reevaluation is allowed. A periodic reconciliation pass is the safety net for changes intentionally deferred during import, missed transient events, failed jobs, direct database maintenance, and future integrations.

## Periodic reconciliation

`ReconcileAutomaticCampaignEligibilityJob` runs every 15 minutes on the `campaigns` queue. It scans contacts once per run, skips currently processing import contacts, and applies every active automatic Campaign through the normal lifecycle action.

This reconciliation is the correctness backstop. Transient fact-change events provide the prompt path without requiring a permanent event ledger entry for every Contact mutation.

## Test boundary

Evaluator-only tests that intentionally mutate eligibility facts while directly calling `EvaluateCampaignEligibilityAction` suppress model events for those fixture mutations. That keeps pure state-transition tests separate from the automatic lifecycle wiring introduced later.

## Boundaries

- Core owns Contact source/subsource and tags and the neutral `ContactFilterFactsChanged` event.
- Workflow owns Contact lifecycle status and its existing durable status-change Automation Event.
- Relationships owns relationship membership and stage mutation and emits the Core-owned fact-change event.
- Campaigns performs lightweight dependency routing and queues only relevant contact-scoped reconciliation work.
- Flow Routes remain available for explicit Campaign lifecycle actions when a larger business process genuinely requires orchestration.
- Process Highway remains a read/composition surface and will consume these Campaign semantics in a later batch.

## Not changed in Batch 2B

- Slam Dunk Campaigns remain manually enrolled.
- Existing Slam Dunk Campaign-routing/cleanup Flow Routes remain in place.
- Campaign authoring UI is not changed.
- Process Highway does not yet display Campaign eligibility.
- Messaging now enforces consent at channel + purpose; Campaign scope remains operational/context identity and does not gate permission.

## Remaining refactor roadmap

1. Campaign authoring UI: eligibility builder, automatic/manual enrollment, re-entry, ineligible behavior, and audience preview.
2. Process Highway integration: Campaign eligibility/enrollment/journey alongside Flow Routes.
3. Slam Dunk migration: enable automatic eligibility and remove redundant lifecycle Campaign-routing routes while keeping real orchestration.
4. Preset/bootstrap hardening and portable stable-key Campaign JSON.
5. Final acceptance and Slam Dunk go-live checks.