# Reporting Module

This module reference owns the detailed responsibility, dependency, and boundary notes for this module. Keep global architectural rules in `docs/module-boundaries.md`; keep actionable backlog in `docs/TODO.md`.

Reporting is optional.

Reporting owns reporting-specific queries, dashboards, data objects, and report controllers.

Reporting may read from other modules through public read services, query services, events, or documented reporting interfaces.

Reporting should avoid becoming a dumping ground for cross-module business logic.

Reporting should not mutate another module’s internal state directly.
