# Engage Core Development Checklist

Use this for repeatable platform-wide checks. It is not backlog.

## UI review

- Use `ui-ux-guide.md` for client/operator-facing screens.
- Apply the “no what did I get myself into?” test: make the next action obvious before exposing platform detail.
- Keep powerful features summarized, guided, and consequence-aware by default.

## After a production code slice

- Run focused tests for the touched modules and broader adjacent-module tests when boundaries are involved.
- Confirm the change is an architectural/product fix, not test-shaped legacy preservation.
- Confirm no new direct cross-module model/table writes were introduced where a public action/service belongs.
- While the pre-rollout migration convention applies, use replacement create-table migrations rather than new alter-table migrations.
- Before finalizing a MySQL migration, review every generated foreign-key, unique-key, and index identifier against MySQL's 64-character identifier limit; give long constraints concise explicit names instead of relying on Laravel defaults.
- Run `php artisan optimize:clear` after config, route, provider, or view changes when applicable.
- Update docs only when architecture or operator/client behavior changed.

## Before committing a feature slice

- Review changed files for stale terminology. Internal/runtime identifiers use `contact`; client-facing UI may use the configured business noun.
- Confirm new public routes/controllers follow module directory conventions.
- Update the owning module's state/TODO when useful; update global docs only for global rules.
- Keep `module-boundaries.md` limited to long-lived architectural decisions.
- Delete completed disposable TODOs.

## Before staging smoke tests

- Run focused and adjacent-module tests locally.
- Run staging migrations only after confirming the migration shape is final for the branch.
- Run `php artisan optimize:clear` on staging.
- Confirm module visibility/navigation matches config.
- Confirm provider credentials/config exist for enabled providers.
- Confirm Horizon/workers are running when scheduled/send behavior is under test.

Configuration-specific checks live in `configuration/checklist.md`. Messaging consent/channel checks live in `modules/messaging/checklist.md`.