# Config Hardening Audit

## Purpose

This document records the source-of-truth audit that established Engage Core's executable configuration-contract foundation. It explains what was inspected, what was corrected, what is now proven, and what is not yet guaranteed.

Use this document as historical and architectural context. Use `config-contracts.md` for the current contract model and `config-generation-lock-in-roadmap.md` for remaining implementation work.

## Goal

Future client configuration should be produced from supported code-level contracts and should be rejected before handoff when it cannot safely load, compose, sync, resolve, or execute.

The intended guarantee is stronger than “the docs look consistent.” A generated package should be:

- structurally valid;
- closed against unknown fields except for deliberately open extension objects;
- valid for the installed and enabled modules;
- reference-complete;
- token-complete for the exact producer context;
- safe to sync into a fresh database;
- resolvable through DB-owned assignments and selections;
- executable through representative runtime paths.

## Audit scope

The audit reconciled these layers:

1. Config authoring documentation and PHP templates.
2. Default runtime configuration.
3. The serious Slam Dunk client configuration.
4. Client loading and recursive merge behavior.
5. Preset packages, contributors, composition, and ownership.
6. Definition DTOs and normalization behavior.
7. Domain sync actions and customization rules.
8. Messaging resolution, assignment, token, payload, and dispatch behavior.
9. Executable FlowRoute point definitions and handlers.
10. Webinar schedule, waitlist, registration, and post-event consumers.
11. Setup-validation contributors and provider registration.
12. Database models and migrations used as token-source proof.
13. Focused, golden-fixture, runtime, and full-suite tests.

## Confirmed loading and composition behavior

- Client config loads recursively.
- Associative arrays merge recursively.
- Numeric/list arrays replace defaults.
- Preset contributors are explicitly registered by module definitions.
- Preset packages select contributed groups by domain.
- Global sync executes in dependency-safe order.
- Module providers register their owning setup validators and executable contracts.
- Runtime dispatch consumes resolved DB/config definitions through Messaging's normalized path.

These rules are part of the export target. An exporter must preview and validate the merged effective configuration rather than only sparse client overrides.

## Phase 1 — Frozen behavior

Slam Dunk was established as the first golden vertical slice.

Coverage now proves that:

- the real client boot produces the expected effective module/package configuration;
- the selected package syncs into a fresh database;
- selected statuses, tasks, Messaging templates, schedule profiles, Campaigns, variants, capabilities, and FlowRoutes resolve;
- Webinar registration and waitlist messages use synced profiles and templates;
- post-event attended and missed follow-ups resolve real definitions;
- attended and missed automation events change status and enroll the intended Campaigns;
- representative Campaign messages are scheduled through the actual runtime path.

Golden text or array snapshots are useful drift detectors, but the primary proof is semantic round-trip behavior through the real loader, sync, resolver, and runtime services.

## Semantic identity correction

Campaign-step template assignment previously leaned too heavily on `source_config_path`. That path is useful provenance, but it is not durable business identity.

The hardened resolution order is:

1. exact context-specific semantic Campaign/step/variant assignment;
2. global semantic Campaign/step/variant assignment;
3. legacy source-config-path fallback.

Variant-specific resolution does not fall back to a broad step assignment. This prevents one variant from accidentally selecting another variant's copy.

## Phase 2A–2B — Shared and foundational contracts

Shared PHP-native primitives now provide:

- contract identity and ownership;
- canonical source patterns;
- closed and deliberately open objects;
- scalar, list, map, one-of, and mixed schemas;
- required, optional, defaulted, deprecated, and reference fields;
- allowed values;
- at-least-one and mutually exclusive field groups;
- deterministic normalization;
- structured violations;
- provider-tagged registration.

Registered foundational contracts cover:

- module definitions;
- preset packages;
- ContactStatus definitions;
- Task preset definitions.

## Phase 2C — Messaging and contextual token foundations

Registered Messaging contracts cover:

- reusable email definitions;
- reusable SMS definitions;
- permission-invitation configuration.

The contracts enforce the ownership correction established by runtime testing:

- reusable Messaging definitions own copy and delivery-template metadata;
- they do not own another module's timing, schedule, lifecycle conditions, sequencing, dependencies, enablement, or skip behavior;
- canonical authoring uses email `subject`/`body` and SMS `message` fields even when runtime payload constructors tolerate compatibility aliases;
- a definition requires `dispatch_key` or `dispatch_keys`.

Token contracts now separate:

- a source: where a value comes from;
- a producer context: where that source is available;
- aliases: authoring conveniences that normalize to a source;
- computed values: values exposed through an explicit provider.

Arbitrary `meta` paths are rejected as token sources.

## Phase 2D — Campaign, FlowRoute, and Webinar contracts

### Campaigns

The Campaign contract covers Campaign, step, and variant identity, timing criteria, strategies, dependencies, enablement, source provenance, and Messaging-template references.

Campaign timing remains Campaign-owned. Reusable message payload copy remains Messaging-owned.

### FlowRoutes

FlowRoute contracts are Point-type-specific. A `create_task` definition cannot silently accept `send_message` fields, and an `enroll_campaign` definition cannot silently accept status-change fields.

The audit found template fields that the preset DTO ignored:

- route-level `status`;
- Point-level `conditions`;
- event-wait `timeout`.

Those fields are removed from the canonical template rather than legitimized as inert configuration.

The template also now uses the registered Campaign capability key `campaigns.enroll_contact`.

### Webinars

Registered contracts cover:

- Webinar schedule profiles and items;
- post-event orchestration.

Schedule-profile `source_version` is numeric because sync persists numeric values. Descriptive version strings were silently discarded and are no longer canonical.

`replay_available` is a real optional post-event automation-event override. Runtime falls back to `webinar.replay_available` when it is omitted.

## Token-source findings

Database migrations and runtime payload production exposed two stale reference assumptions:

- `webinar.status` was documented even though the `webinars` table has no `status` column;
- Webinar waitlist documentation referred to `source`, while the persisted and produced field is `source_page`.

The executable registry follows table/runtime truth.

Sensitive or internal fields are deliberately not authorable tokens, including:

- `meta`;
- provider settings and raw provider payloads;
- `WebinarRegistration.join_token`;
- `Webinar.playback_token`;
- provider credentials or access tokens.

Generated links and formatted values are computed sources, including Webinar join, cancellation, playback, waitlist registration, and formatted date/time values.

## Verification result

The final checkpoint produced:

- a successful `test_everything` preset sync;
- no unavailable FlowRoute handlers;
- successful sync of 53 Messaging presets and assignments;
- successful sync of one Webinar schedule profile and 20 items;
- successful sync of two Campaigns, 24 steps, and 26 variants;
- successful sync of 10 FlowRoute capabilities and selected routes/bindings;
- `setup:validate` with no findings;
- a green full test suite after correcting one stale whole-page UI assertion.

The stale assertion prohibited the legitimate global label `Routes` while testing plain-language contact sections. It now prohibits only technical `FlowRoutes`/`Flow Routes` wording.

## Subsequent hardening after the audit checkpoint

Later production-prep work strengthened the audited foundation without changing the authority order below.

Completed additions:

```text
Context-aware Messaging token validation
    MessageTemplateTokenValidator resolves authorable tokens through TokenContractRegistry.
    Config/setup validation, MessageTemplatePreset sync, and CRM template editing reuse it.
    Unknown and registered-but-unavailable tokens are hard errors.
    A global reference token list is not executable authority.

Consent domains
    Message identity remains channel + purpose + scope.
    Consent identity is channel + purpose + consent domain.
    ConsentDomainRegistry resolves exact and longest-prefix mappings, fails on ambiguity,
    and keeps unknown unmapped scopes narrow.
    Webinar-related scopes intentionally resolve to the webinar consent domain.
    Scope-specific Webinar opt_ins definitions were removed in favor of
    ConsentOptInDefinitionResolver and generic/module/client acknowledgement copy.

Consent acknowledgement delivery consolidation
    Messaging may consolidate newly activated acknowledgement intents into a compatible
    lifecycle message while preserving intent keys and consent-record provenance.
    Consolidated acknowledgements inherit the primary message schedule, queue, conditions,
    and behavior owner. Any acknowledgement that cannot be consolidated requires an explicit
    standalone delivery path rather than being silently dropped. Reserved
    delivery_consolidation_* placeholders are composition fields, not globally authorable tokens.

Webinar schedule contract expansion
    delay(minutes)
    anchored(minutes)
    next_day_at(time = HH:MM)
    next_day_at uses client timezone rather than per-item timezone.

Delayed-message safety
    resolved lifecycle conditions persist into ScheduledMessage metadata
    and ScheduledMessageGate rechecks them before provider delivery.
```

Core Webinar Messaging was also returned to a small, vertical-neutral baseline, while richer branded sequences remain client-owned. This ownership cleanup is covered by regression/golden tests rather than by broadening Core contracts.

## Current guarantee boundary

The contract registry and golden tests prove substantially more than the original documentation, but generated export is not yet fully locked in.

Still missing:

- setup validation does not yet universally execute every structural contract;
- file-envelope contracts are incomplete;
- reference-key contracts are incomplete;
- condition objects remain open until evaluator-specific contracts exist;
- strict token closure is not yet part of an export pipeline;
- templates are maintained manually rather than generated from contracts;
- deterministic export and semantic reload are not implemented;
- the dev authoring UX is not yet registry-driven.

Therefore the current state is:

```text
Canonical contract foundation: complete through the audited domains.
Strict generated-config guarantee: not yet complete.
```

## Authority order

When sources disagree, use this order:

1. Database schema for persisted fields.
2. Executable DTOs, sync actions, resolvers, handlers, and runtime consumers.
3. Registered config and token contracts.
4. Setup validation and golden/runtime tests.
5. Default/client configs.
6. Templates and prose documentation.

Fix lower layers when they drift. Do not expand a contract merely to preserve an ignored or stale documented field.
