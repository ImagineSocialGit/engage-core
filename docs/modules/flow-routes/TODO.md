# FlowRoutes TODO

## Messaging identity cleanup

- [ ] Migrate direct Route messaging to stable Messaging template/chain authoring identity without repeated provenance bundles.

## Routes product completion

- [ ] Add simple inbound-reply authoring conditions for reply profile and normalized intent so clients can opt into different routes per conversation source; no matching/configured Route must remain a valid notification-only outcome.
Preserve the implemented linear Route boundary and current placement/eligibility rules.

- [ ] Add new Route creation.
- [ ] Add Route duplication.
- [ ] Add activate/deactivate controls.
- [ ] Add trigger changes through Assignments.
- [ ] Add clone-Point-from-another-current-Route without shared linkage.
- [ ] Add Task assignment/default authoring inside `create_task` Point UX.
- [ ] Add business-day/business-hour waits when they can remain deterministic and understandable.
- [ ] Add manual Contact-status consequence warning UX using `ContactStatusAutomationImpactResolver` as backend authority.
- [ ] Add contextual Automation Opportunity suggestion UX against Routes as the control center; do not create a parallel automation builder/feed.
- [ ] Consider simple future Point eligibility/continuation rules only if they remain linear and understandable; do not introduce arbitrary branching, joins, nested trees, connectors, generic node-editor behavior, or arbitrary jump-back loops.