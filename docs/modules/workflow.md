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
