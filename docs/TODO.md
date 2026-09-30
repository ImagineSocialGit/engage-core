# Engage Core Platform TODO

This file is only for actionable work with no single module owner. Module-specific backlog belongs in `docs/modules/<module>/TODO.md`; configuration backlog belongs in `docs/configuration/TODO.md`.

Delete completed items rather than accumulating project history. Durable architecture belongs in the relevant state/architecture document.

## Module system and shared surfaces

- [ ] Apply the module identity/state standard when each module is next materially revised; do not churn files solely for symmetry.
- [ ] Refactor the hard-coded installed-module inventory assertion in `tests/Feature/Modules/ModuleDependencyBoundaryTest.php` so `config/modules.php` remains the only hand-maintained module-existence registry.

## Project State framework

Module-specific transfer requirements belong in each module's state/TODO. Shared framework backlog:

- [ ] Add external translators when a real older Project State format must be carried forward.
- [ ] Add a richer create/update/skip/conflict preview only when operational need justifies it; do not weaken the closed validator.
- [ ] Add production-sized transfer timing and memory measurements before materially larger client datasets.

## Automation Opportunities

Durable architecture: `automation-opportunities.md`.

- [ ] Add dynamic suggestion-time checks for current capability availability, equivalent existing automation, snooze/dismissal availability, conversion state, context validity, and attribution ambiguity when the first user-facing suggestion surface needs them.

The Routes-specific suggestion experience belongs in `modules/flow-routes/TODO.md`.

## Shared CRM / UX infrastructure

- [ ] Add a shared available-field insertion pattern for message/template/config authoring that consumes the real token/field registries and preserves cursor/focus.
- [ ] Add a reusable hover/focus/tap/click hint pattern for secondary confusing terms; important decision guidance remains visible below the control, hints explain behavior in business language, and hints do not replace consequence previews.
- [ ] Apply preserve-context/AJAX interaction patterns to row/panel/modal workflows when reloads materially frustrate operators; keep ownership in the affected module rather than creating a central mutation layer.

## Client self-serve readiness

- [ ] Separate controlled beta/operator-assisted readiness from true client self-serve readiness.
- [ ] Audit which admin surfaces must exist before clients can operate without developer help, then assign each resulting item to its owning module/configuration area.

## Operations tooling

- [ ] Exercise the guided `fix` workflow plus non-interactive `--dry-run` / `--apply` modes against representative legacy, drifted, and fresh staging deployments; tighten only prompts, classifications, or safe-remediation prerequisites proven wrong by real deployment evidence.
- [ ] Add deployment-owned direct-upload request sizing for Media: ask whether direct video uploads are needed, suggest the current 256 MB Media ceiling by default, allow an explicit operator-selected ceiling, and keep application validation, Nginx `client_max_body_size`, PHP `upload_max_filesize`, and PHP `post_max_size` coordinated; request/post ceilings must include enough headroom for multipart overhead rather than merely equaling the file ceiling. Audit the effective limits and surface a BREAKING mismatch only when a configured valid application upload would be rejected by the host request limits.
- [ ] Harden `scripts/operations/configure-client-logging.sh` so dry-run/apply output never prints unrelated environment values or secrets, and align the helper with root `.env` logging ownership before it is used against a secret-bearing production environment again.

## Documentation maintenance

- [ ] Keep top-level docs platform-wide; move module-owned state/backlog into `docs/modules/<module>/`.
- [ ] Treat FOSS, competitive, and provider inventories as non-binding research unless an approved workflow adopts the capability.
- [ ] Regenerate `core-project-tree.txt` from the repository after structural module/file changes; do not hand-maintain it.
- [ ] Keep `module-boundaries.md` architectural, not a backlog.
- [ ] Avoid parallel hand-maintained inventories of executable module/preset/contract facts; prefer executable registries and generated references where appropriate.

## Deployment audit/fix follow-up

- Use a legacy staging deployment as the first guided `fix` proof: accept intentional legacy drift, collect missing provider-owned environment values through operator prompts, repair any unowned host/TLS gap, then start Horizon/Scheduler only after readiness is green.
- Exercise a staging deployment with known environment-file metadata drift to verify harmless metadata differences remain informational when both required process identities have effective access, and BREAKING only when effective access actually fails.
- Exercise a fresh canonical staging deployment as the clean reference.
- Keep the automatic fix registry BREAKING-only. Guided review may acknowledge INFO/WARNING drift or collect operator-supplied blocking values, but must never silently normalize harmless legacy state or invent provider credentials.
- Remove deprecated pre-Core server/repository naming residue during a clean staging rebuild only after confirming no live Nginx, Supervisor, cron, or process dependency still references the retired tree.