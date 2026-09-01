# Appointment-relative Flow Route automation

Scheduling contributes two Flow Route points when the required modules are enabled:

- **Create appointment task** creates a template-backed Task due before or after the Appointment start. The Route author may keep the Task Template assignment or assign the Task to the Appointment host.
- **Notify appointment host** schedules one internal notification for the Appointment host using the host Team Member's internal-notification preferences.

These points are offered only on Routes triggered by appointment scheduled, confirmed, or rescheduled activity. Their definitions are stored in the existing Flow Route point definition JSON; no schema or client preset definition is required.

## Capability materialization

Module providers register capability definitions in code. The Route editor reads the persisted `flow_route_capabilities` catalog because authored points retain a durable capability relationship.

The canonical lifecycle is:

```text
enabled module closure
    -> provider contributors
    -> presets:sync
    -> flow_route_capabilities
    -> Route editor catalog
```

`engage:install` runs `presets:sync` for a new client. Existing-client deployments must run `php artisan presets:sync` after code or module changes that add or alter DB-owned definitions. The operation is idempotent and must run before `setup:validate`.

Setup validation reports registered capabilities missing from an already-materialized catalog. Runtime HTTP requests do not write deployment state merely to repair an incomplete deployment.

## Appointment correlation

Both points own durable Appointment correlation:

- Tasks link the Appointment as their subject and store the timing anchor and offset in Task metadata.
- Host reminders use the Appointment as ScheduledMessage context and store the timing definition in ScheduledMessage metadata.

When an Appointment is rescheduled, open appointment Tasks move to the replacement Appointment and pending host reminders are skipped and recreated against the replacement time. When an Appointment is canceled, open appointment Tasks are canceled and pending host reminders are skipped. Completed Tasks and terminal messages are never rewritten.