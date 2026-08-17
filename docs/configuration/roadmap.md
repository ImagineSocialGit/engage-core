# Configuration Generation Roadmap

## Outcome

Build a schema-aware authoring and export system that can produce client configuration with a meaningful executable guarantee.

The system should expose only capabilities registered by installed code and refuse strict export when the merged client package cannot safely load, compose, sync, resolve, or execute.

## Completed foundation

- Slam Dunk was used as temporary end-to-end vertical proof while the shared
  config contracts and runtime seams were hardened; the client-specific golden
  fixtures were later pruned once shared contract, setup-validation, and runtime
  coverage became the durable authority.
- Semantic Campaign variant assignment resolution.
- Shared config schema/contract registry.
- Foundational module/package/status/task contracts.
- Messaging email/SMS/permission-invitation contracts.
- Campaign, FlowRoute, Webinar schedule, and Webinar post-event contracts.
- Model-column and computed token-source registry.
- Producer-context token registry.
- Shared context-aware `MessageTemplateTokenValidator` reused by config/setup validation, MessageTemplatePreset sync, and CRM template editing.
- Database-column proof and sensitive-field exclusions.
- Closed Webinar schedule-profile support for `delay(minutes)`, `anchored(minutes)`, and client-timezone `next_day_at(time = HH:MM)`.
- Successful `test_everything` preset sync.
- Setup validation with no findings.
- Green full test suite at the audit checkpoint.

## Next phase — Finish contract enforcement

### Objective

Make registered contracts part of normal setup/export validation rather than a test-only structural layer.

### Implementation

1. Add a shared setup-validation contributor that resolves effective selected configuration and runs every applicable registered contract.
2. Convert duplicated structural checks in module contributors to contract calls.
3. Keep module-specific semantic checks in the owning contributor.
4. Add structured mapping from `ConfigContractViolation` to `SetupValidationFinding`.
5. Include owner, source path, contract key, and normalized field path in findings.
6. Add file-envelope contracts for exported config files containing `groups`, `definitions`, or routed Messaging maps.
7. Add a contract for reference-key registry structure.
8. Add condition-provider contracts for Campaign, Webinar, and FlowRoute condition shapes.

### Acceptance criteria

- A malformed selected config produces the same structural finding through tests, `setup:validate`, and strict export.
- Structural field rules are not duplicated across contracts and contributors.
- Semantic validation remains intact.
- Unknown selected fields are errors in strict mode.

## Phase 3 — Strict export validation

### Pipeline

1. Resolve enabled modules and dependencies.
2. Resolve the proposed package and contributed groups.
3. Build proposed default/client files from structured authoring state.
4. Load them through the real client loader and merge behavior.
5. Validate every output file envelope.
6. Validate every object through its owning closed contract.
7. Resolve cross-domain references.
8. Resolve tokens against the exact producer context.
9. Sync into a fresh isolated database.
10. Run setup validation against synced state.
11. Resolve representative definitions and fake-dispatch runtime paths.
12. Permit export only with zero strict errors.

### Advisory vs strict modes

Recommended direction: one validation engine with severity/policy modes.

- Advisory mode supports in-progress UX and may surface warnings.
- Strict mode upgrades unresolved fields/tokens/references and missing required coverage to blocking errors.
- Both modes return the same structured finding shape.

Avoid separate validator implementations for UI, CLI, and export.

## Reference closure

Strict export should resolve at least:

- enabled modules and dependencies;
- package-selected contribution groups;
- ContactStatus keys;
- Task-template keys;
- Campaign keys, steps, and variant keys;
- Messaging semantic template assignments;
- dispatch keys, purposes, scopes, and surfaces;
- FlowRoute capability keys, Point keys, next-Point references, and trigger events;
- Webinar schedule-profile item/template references;
- post-event automation event keys.

## Token closure

Token validation must use producer context, not a global union.


The implemented `MessageTemplateTokenValidator` already enforces that rule for Messaging config/setup validation, MessageTemplatePreset sync, and CRM template editing. Strict export must reuse that validator/registry path rather than create a second token engine.

Examples:

- registration messages use `registration_created`;
- waitlist messages use `webinar_added`;
- post-event messages use `webinar_ended`;
- Campaign messages use `campaign_step_due`;
- permission invitations use their Messaging-owned context.

Caller-supplied Campaign values require an explicit start-payload/enrollment contract. Do not keep free-form caller aliases as a permanent exception.

## Phase 4 — Contract-driven documentation/templates

Generate or mechanically verify:

- canonical PHP examples;
- required/optional/default/deprecated field tables;
- allowed values;
- reference targets;
- token references by context;
- file path/owner maps.

Hand-authored prose should explain ownership, UX intent, and business consequences. It should not independently define field shapes.

CI drift tests should fail when:

- a DTO accepts a canonical field absent from its contract;
- a contract exposes a field ignored by sync/runtime;
- an enum gains an uncovered value;
- a template contains an unknown field;
- a registered model token lacks its database column;
- a computed token lacks an explicit provider;
- a token is documented in a context that does not produce it.

## Minimal deterministic exporter

Build this before the polished UX.

Recommended first vertical slice:

1. Represent one representative full-package configuration as structured
   authoring state.
2. Export deterministic PHP files.
3. Snapshot exported text for drift review.
4. Reload through `ClientServiceProvider`.
5. Normalize through contracts.
6. Compare semantic effective configuration.
7. Fresh-sync and setup-validate in an isolated test database.
8. Run the shared config-contract and representative runtime tests against the
   generated output.

Text equality is a useful secondary check. Semantic equality, shared contract
validation, setup validation, and representative runtime execution are the
primary proof. Do not create a permanent client-specific golden suite merely to
freeze one client's prose, list ordering, or presentation config.

## Phase 5 — Dev authoring UX

### Package builder

- Select modules and show automatic dependencies.
- Select registered contribution groups by domain.
- Preview effective definitions and provenance.
- Prevent missing or duplicate selection.

### Message editor

- Select channel, purpose, scope, surface, and producer context.
- Show only tokens available in that context.
- Preview fixture rendering.
- Keep lifecycle behavior out of reusable templates.

### Campaign editor

- Edit journey sequencing, timing, conditions, variants, and dependencies.
- Select compatible reusable Messaging templates.
- Validate semantic Campaign/step/variant identity continuously.

### Routes editor

- Render Point forms from Point-type contracts and capability metadata.
- Show only fields accepted by that Point type.
- Validate start Point, next Point, handler availability, and references.
- Preserve the established linear Routes product boundary unless a later explicit decision changes it.

### Webinar editor

- Select compatible Messaging templates.
- Edit schedule-profile lifecycle timing and conditions.
- Preview a resolved schedule for a sample Webinar.
- Flag missing required lifecycle coverage.

### Export review

- Show files to create/replace.
- Show merged effective configuration.
- Show provenance and normalized semantic diff.
- Show reference and token closure.
- Show fresh-sync/runtime readiness results.
- Enable export only at zero strict errors.

## Locked implementation choices

The earlier decision points are resolved for the current direction:

1. Use one representative full-package fixture for the first deterministic end-to-end exporter proof; do not restore a permanent client-specific golden suite.
2. Emit sparse client overrides as the final client artifact while making inherited effective configuration visible during review.
3. Use provider-owned/registered condition contracts rather than open generic condition arrays.
4. Require explicit producer/start-payload contracts for caller-supplied Campaign fields.
5. Use live advisory validation during authoring and a strict full review/fresh-sync gate before export.
6. Generate or mechanically verify field/token reference tables and examples from executable contracts instead of maintaining a second hand-authored schema.

## Definition of done

Config generation is locked in when:

- every exported file has an owner and closed file-level contract;
- every executable object validates through the same registry used by setup/export/UI;
- all references resolve;
- all tokens resolve in their exact producer context;
- caller-supplied values have explicit contracts;
- merged behavior is visible before export;
- fresh-database sync succeeds;
- setup validation has zero errors;
- representative runtime paths execute;
- templates and references cannot drift from contracts unnoticed;
- the dev UX is a projection of these registries rather than a parallel schema.
