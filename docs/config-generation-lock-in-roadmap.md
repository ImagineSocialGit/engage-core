# Config Generation Lock-In Roadmap

## Outcome

Build a schema-aware authoring and export system that can produce client configuration with a meaningful executable guarantee.

The system should expose only capabilities registered by installed code and refuse strict export when the merged client package cannot safely load, compose, sync, resolve, or execute.

## Completed foundation

- Slam Dunk effective config and runtime golden fixtures.
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

1. Represent Slam Dunk configuration as structured authoring state.
2. Export deterministic PHP files.
3. Snapshot exported text for drift review.
4. Reload through `ClientServiceProvider`.
5. Normalize through contracts.
6. Compare semantic effective configuration.
7. Fresh-sync and setup-validate.
8. Run the existing Slam Dunk runtime golden fixture.

Text equality is a useful secondary check. Semantic equality and runtime execution are the primary proof.

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

## Decision questions

### 1. First export surface

**A. Slam Dunk full package first — recommended.**

Implementation: build the complete deterministic vertical slice around the existing golden client.

UX impact: validates the real complex workflow early, but the first UI/export model is larger.

**B. One small domain first.**

Implementation: export only ContactStatus or Task definitions before expanding.

UX impact: faster visible progress, but delays proof that cross-domain composition and runtime execution work.

### 2. Export artifact shape

**A. Sparse client overrides — recommended for final client files.**

Implementation: author against normalized effective state, then emit only deliberate client differences plus required list replacements.

UX impact: smaller maintainable client config, but export review must clearly show inherited defaults.

**B. Fully materialized client config.**

Implementation: emit every effective value into client files.

UX impact: easier standalone inspection, but bloated files drift from defaults and obscure intentional overrides.

### 3. Condition contracts

**A. Provider-owned condition registry — recommended.**

Implementation: each evaluator/provider registers discriminator, fields, supported subjects, operators, and value schema.

UX impact: dynamic, safe forms and contextual options across modules.

**B. Keep generic open condition arrays.**

Implementation: validate only at the eventual evaluator call.

UX impact: quicker initially, but prevents a strong export guarantee and forces technical JSON-like editing.

### 4. Caller-supplied Campaign tokens

**A. Explicit start-payload contracts — recommended.**

Implementation: producer/enrollment capability declares supplied fields and Campaign contexts reference them.

UX impact: token picker is accurate for each enrollment path.

**B. Free-form documented aliases.**

Implementation: keep accepting arbitrary payload keys documented per Campaign.

UX impact: flexible but impossible to guarantee before runtime.

### 5. Strict validation timing

**A. Live advisory plus strict review — recommended.**

Implementation: validate continuously in advisory mode and run full strict/fresh-sync verification at export review.

UX impact: responsive authoring without pretending every keystroke has completed expensive runtime proof.

**B. Strict validation on every edit.**

Implementation: run all closure/readiness checks after each state change.

UX impact: immediate certainty but likely slower and noisier.

### 6. Contract documentation strategy

**A. Generate field/token tables and examples — recommended.**

Implementation: contracts generate mechanical reference content; prose stays hand-authored.

UX impact: lower drift and clearer developer documentation.

**B. Continue hand-maintained templates with drift tests only.**

Implementation: CI compares templates to contracts.

UX impact: preserves current writing workflow but duplicates more structure.

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
