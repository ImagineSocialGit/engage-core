# Campaign Eligibility

Campaign eligibility answers one business question:

> Which Contacts currently belong in this Campaign?

It is not an automation trigger system and it is not a Messaging-consent
contract.

## Canonical Campaign identity

`campaigns.key` is the stable machine identity for a Campaign.

Campaign lookup, Flow Route references, Project State references, future
portable exports, and automatic eligibility enrollment must identify a Campaign
by that key.

`channel`, `purpose`, and `scope` are Messaging context. They do not participate
in Campaign identity.

## Eligibility shape

Campaigns stores eligibility as stable semantic criterion values:

```php
'eligibility' => [
    'mode' => 'automatic',
    'criteria' => [
        'status' => ['prospect_nurture'],
        'tag' => ['VA'],
    ],
    'reentry' => 'never',
    'when_ineligible' => 'cancel',
],
```

The current condition language intentionally reuses the Core
`ContactFilterCriterion` registry:

- criterion types are ANDed together;
- multiple values within one criterion type are ORed;
- optional modules contribute their own criterion implementations;
- Campaigns must not depend directly on Workflow, Relationships, or another
  optional fact-owning module.

Stored eligibility uses stable semantic values. Workflow's existing `status`
criterion currently consumes database IDs, so Campaigns translates stored
ContactStatus keys to current IDs only at evaluation time. That translation is a
compatibility boundary, not the persisted contract.

Unknown/unavailable criteria and unresolved status keys fail closed.

## Enrollment mode

`manual`

The Campaign may still be started explicitly through a public Campaign action or
Flow Route. Eligibility can be absent or descriptive, but it does not
automatically enroll the Contact.

`automatic`

A later runtime batch may automatically enroll Contacts when eligibility moves
from false to true. Automatic mode requires at least one criterion.

The foundation batch does not yet perform automatic enrollment.

## Re-entry policy

Initial values:

- `never`
- `when_eligible_again`

Eligibility state tracks a monotonically increasing `eligibility_cycle` each
time a Contact moves from ineligible to eligible. A later automatic-enrollment
batch can use that cycle as a stable dedupe boundary.

## When eligibility becomes false

Initial policy values:

- `continue`
- `pause`
- `cancel`

The foundation batch records the transition but does not yet mutate a Campaign
enrollment. Lifecycle behavior is wired in a later runtime batch.

## Eligibility state

`campaign_eligibility_states` stores the last evaluated truth state for one
Campaign + Contact pair:

- current eligibility
- eligibility cycle
- last eligible/ineligible transition times
- last evaluation time

This is Campaign-owned derived runtime state. It does not replace the owning
source facts such as Contact Status, Contact tags, or Relationship stage.

## Relationship to Flow Routes

A Flow Route may always explicitly start, pause, resume, or stop a Campaign when
that action is part of a larger business process.

A Flow Route whose only purpose is:

```text
fact becomes true -> enroll Campaign
```

should generally become automatic Campaign eligibility after the automatic
runtime is enabled.

## Relationship to Messaging consent

Campaign eligibility decides whether a Contact belongs in a Campaign.

Messaging independently decides whether a particular message may be sent over a
channel for its purpose, including consent, suppression, provider, and
destination checks.

Campaign eligibility does not grant consent.