

# Workflow Module

This module reference owns the detailed responsibility, dependency, and boundary notes for this module. Keep global architectural rules in `docs/module-boundaries.md`; keep actionable backlog in `docs/TODO.md`.

Workflow is optional.

Workflow owns contact workflow state.

Workflow owns:

- `ContactWorkflowProfile`
- workflow/profile state around a contact
- shared workflow-facing status transition services/actions
- workflow events emitted after profile/status changes
- public services FlowRoutes can depend on

Workflow depends on Core.

Workflow does not own:

- `Contact`
- `ContactStatus`
- tasks as a capability
- TeamMembers as a capability
- internal notification routing
- scheduled message infrastructure
- automated route execution

Workflow state belongs in:

    contact_workflow_profiles

Not in:

    contacts

A contact may exist with no workflow profile.

Workflow should provide the shared path for status/profile transitions when Workflow is enabled.

Expected direction:

1. Core/manual CRM or another module requests a status/profile transition through Workflow.
2. Workflow records the state/profile change.
3. Workflow emits `ContactWorkflowStatusChanged`.
4. FlowRoutes may react to that event when enabled.

Manual CRM status changes use the same Workflow transition path as other legitimate status changes. Workflow does not decide whether a selected FlowRoute will run and does not own automation-consequence previews.

FlowRoutes owns the read-only backend impact seam for selected status-triggered routes. The current preview resolver reports the selected route impact without mutating Workflow state or starting route progress.

The eventual operator warning/confirmation UI should consume that FlowRoutes-owned read seam through an appropriate UI integration boundary rather than making Workflow depend on FlowRoutes.

Workflow should not depend on FlowRoutes.

Workflow should not call FlowRoutes directly.

## Automation opportunity producer boundary

Workflow owns durable Contact workflow transitions and emits `ContactWorkflowStatusChanged` after real status changes.

A plain repeated manual status transition is **not currently an Automation Opportunity producer**.

The transition alone does not prove whether the correct automatic trigger is a form submission, webinar outcome, Task completion, elapsed time, document upload, or another event.

Do not add a generic:

```text
contact.status_changed_manually
```

opportunity merely because transition data exists.

Current implemented Workflow-related compound patterns are:

```text
manual Contact status change
    -> manual Contact-associated Task creation within 10 minutes
    -> task.created_after_manual_status_change

manual Task completion evidence
    -> manual Contact status change within 10 minutes
    -> contact.status_changed_after_manual_task_completion
```

Workflow transition data provides:

```text
from status
to status
reason
source
actor
occurred_at
meta
```

The Task-creation compound pattern uses recent explicit manual status-change provenance.

The Task-completion -> status-change compound pattern requires:

```text
real changed transition
CRM manual status-update reason/source
contact status form surface provenance
same Contact
same actor
matching Task completion evidence within 10 minutes
```

Workflow should not make every transition a behavior occurrence.

Workflow should not depend on FlowRoutes or own automation-opportunity aggregation.

The shared opportunity evaluator remains generic; Workflow-specific semantics stay in the Workflow-owned producer action/listener.

