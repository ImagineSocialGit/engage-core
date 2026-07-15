# Engage Core Config Templates

This directory contains safe starting templates for Engage Core default configs and client-specific overrides.

Use this file as the template index. Use `docs/config-authoring-guide.md` for the full authoring rules, token rules, module-boundary rules, and review checklist.

## Primary references

Executable schemas and field availability are registered in `ConfigContractRegistry` and
`TokenContractRegistry`. These templates are maintained examples of those contracts; they are
not allowed to invent fields or tokens that runtime ignores.

Permanent implementation references:

- [`../config-contracts.md`](../config-contracts.md)
- [`../config-hardening-audit.md`](../config-hardening-audit.md)
- [`../config-generation-lock-in-roadmap.md`](../config-generation-lock-in-roadmap.md)

Read these before authoring or reviewing configs:

```text
docs/config-authoring-guide.md
docs/module-boundaries.md
docs/modules/*.md
docs/config-templates/TOKEN_REFERENCE.md
config/reference/keys.php
```

Optional client-specific key registries may exist at:

```text
client/{client-key}/config/reference/keys.php
```

Token availability is not created by adding a string to a reference file. The executable authority is `TokenContractRegistry`, and authorable Messaging copy is validated through `MessageTemplateTokenValidator` against the exact producer context. A client-specific token requires a real registered source/context/provider path that runtime can supply.

## Canonical contact fields and client-facing aliases

Engage Core uses `contact` as the canonical internal identity term.

Client-facing authoring may expose industry-appropriate aliases such as:

```text
fan_first_name
lead_first_name
customer_first_name
borrower_first_name
```

Those aliases should resolve to canonical fields such as:

```text
contact.first_name
```

Do not create separate runtime payload fields, database columns, event keys, preset keys, route keys, or validation branches for each client noun. The alias layer exists for UX; canonical runtime identity remains stable.

## Consent domains and opt-in acknowledgements

Message identity and consent identity are intentionally separate:

```text
Message identity
    channel + purpose + scope

Consent identity
    channel + purpose + consent domain
```

Do not add per-scope `opt_ins` groups to Webinar Messaging definition files. Messaging resolves consent acknowledgements through `ConsentDomainRegistry` and `ConsentOptInDefinitionResolver`.

Current Webinar direction:

```text
message scopes
    webinar
    webinar_waitlist
    webinar_nurture

consent domain
    webinar
```

Exact mappings win, otherwise the longest matching registered prefix wins. Ambiguous equal-specificity mappings fail loudly. Unknown unmapped scopes remain narrow by falling back to themselves.

Generic acknowledgement copy is Messaging-owned and may receive system markers such as:

```text
:client_name
:consent_topic
```

Those markers are resolved by the consent acknowledgement path. They are not normal message-template tokens and must not be replaced with `{client_name}` or another authorable token unless `TokenContractRegistry` explicitly registers it.

Imported consent uses the dedicated import action so state can be normalized without emitting `MessageConsentGranted` or sending an opt-in acknowledgement.

## Webinar schedule types and client timezone

Generic Messaging schedule resolution supports:

```text
delay
    minutes: integer

anchored
    minutes: integer

next_day_at
    time: HH:MM
```

`next_day_at` uses `config('client.timezone')`, with application timezone fallback. Do not duplicate timezone in each schedule item.

For delayed lifecycle messages, resolved conditions should be persisted with the `ScheduledMessage` and re-evaluated by `ScheduledMessageGate` immediately before provider delivery.

Client associative config merges over defaults. Numeric/list arrays replace the default list when present, so a client reminder cadence replaces the Core reminder list rather than appending duplicate slots.

## Module-first preset contribution architecture

Preset contributions are organized by owning contributor module and aggregated by preset domain.

Current examples:

```text
config/presets/modules/core/contact-statuses.php
config/presets/modules/tasks/tasks.php

config/presets/modules/webinars/contact-statuses.php
config/presets/modules/webinars/tasks.php
config/presets/modules/webinars/campaigns.php
config/presets/modules/webinars/flow-routes.php

client/{client-key}/config/presets/modules/client/contact-statuses.php
```

Future modules and verticals may contribute only the domains they actually own or extend. Do not create empty symmetry files.

The shared preset infrastructure is:

```text
PresetContributionRegistry
    discovers explicitly registered module contributors

PresetPackageResolver
    resolves package selection, selected groups, and package module composition/requirements

PresetCompositionResolver
    resolves selected normalized definitions for one preset domain

Domain sync action
    receives ResolvedPresetDomain and persists only that resolved composition
```

Keep these concepts separate:

```text
module availability
    runtime capability exists for the client

preset contribution availability
    installed contributors expose valid definitions independently of runtime enablement

package selection
    selected package chooses which contributed groups are installed/synced

runtime activation/binding
    DB-owned selections and bindings decide what actually runs
```

Enabling a module must not silently activate every preset it contributes. Preset contributions may remain discoverable even when a contributor module is runtime-disabled.

Preset groups are composition-only. Durable preset ownership belongs to contributor identity plus stable definition key, not persisted group membership.

## Template files

| Template | Use for |
| --- | --- |
| `campaign-presets-template.php` | Campaign journeys, step order, step timing, variant strategy, and variant delivery references. Campaign presets must not own reusable message copy or payloads. |
| `messaging-email-template.php` | Reusable email content/template definitions. Module-owned lifecycle timing, conditions, sequencing, dependencies, and skip behavior do not belong here. |
| `messaging-sms-template.php` | Reusable SMS content/template definitions. Module-owned lifecycle behavior does not belong here; SMS remains explicit and surface-controlled. |
| `flow-routes-template.php` | FlowRoute definitions, route points, waits, event waits, and automation/control-flow presets. |
| `task-presets-template.php` | DB-owned task template/default definitions. Task preset sync creates task templates only, not live tasks. |
| `webinar-schedule-profiles-template.php` | DB-owned Webinar behavior profiles/items. These exclusively own Webinar lifecycle timing, conditions, enablement, and Webinar-specific skip behavior, not reusable copy. |
| `webinar-post-event-template.php` | Webinar post-event provider orchestration such as attendance recording, playback resolution, follow-up dispatch, and automation events. |
| `permission-invitations-template.php` | Imported-contact one-time permission invitation copy, public preference page copy/style, and accepted consent scopes. |
| `contact-status-presets-template.php` | Core contact status definitions. |
| `presets-root-template.php` | Root preset loading/orchestration config. |
| `modules-template.php` | Module enablement, dependencies, dashboard slots, and module tone settings. |
| `client-request-intake-template.md` | Structured intake notes for client config/setup requests. |
| `TOKEN_REFERENCE.md` | Human-readable token reference for config authors. |

## Core authoring rules

- Messaging configs own reusable message copy and delivery-template metadata only.
- Every list-based Messaging definition must declare a stable explicit `key`. The synced `MessageTemplatePreset.key` derives from that explicit identity rather than list position. `source_config_path` remains provenance/debug location, not durable template identity.
- Reusable Messaging templates must not own business timing, lifecycle conditions, sequencing, dependencies, enablement, or module-specific skip behavior.
- Owning modules resolve their own behavior and use `ResolvedMessageDispatchBuilder` to produce a normalized `ResolvedMessageDispatch` with an exact `send_at`.
- Resolved dispatches require either exact caller-owned `sendAt` or explicit caller-owned behavior; there is no implicit immediate fallback.
- Module-owned dispatch paths should provide stable logical `occurrenceKey` identity for retries/idempotency. `send_at` is not logical occurrence identity.

- Missing module-owned behavior must never silently fall back to hidden template timing or an implicit immediate send.
- Campaign presets own journeys, timing, step order, variant strategy, and delivery references.
- Campaign presets must not define reusable subject/body/CTA/message payloads.
- Campaign message templates live in Messaging configs under:

```text
messaging.{channel}.definitions.{purpose}.{scope}.campaigns.{campaign_key}.steps.{step_number}.variants.{variant_key}
```

- Campaign message templates resolve by channel, purpose, scope, campaign key, step number, and variant key, not author-created per-step message names.
- Campaign step variants must reference Messaging-owned templates/assignments and must not own reusable copy.
- FlowRoute presets own automation/control-flow routing and concrete `FlowRoutePoint` definitions. `FlowRoutePoint` directly owns its type/configuration; there is no global reusable `Point` model/template layer.
- Webinar post-event config owns provider orchestration, not reusable message copy.
- Task presets create DB-owned task template definitions only. They do not create live tasks.
- Use documented keys and tokens only.
- Runtime-only URLs must come from runtime payloads/services, not static config guesses.
- Runtime artifact payloads must stay compact; source/debug/template identity belongs in metadata where appropriate.
- SMS visibility belongs to Messaging channel availability for the relevant surface, not one-off provider checks.
- Imported-contact permission invitations are a distinct Messaging-owned one-time consent flow, not a normal Broadcast bypass.
- Message scopes and consent domains are separate. Do not create a new consent identity merely because a new message scope exists.
- Webinar consent acknowledgements come from consent-domain resolution, not scope-specific `opt_ins` groups in reusable message definition files.
- Authorable message tokens are validated through `TokenContractRegistry` + `MessageTemplateTokenValidator`; do not use a global token allowlist.
- Webinar schedule profiles may use `delay`, `anchored`, or `next_day_at`. `next_day_at` uses client timezone and strict `HH:MM`.
- Core Webinar Messaging should stay small, complete, generic, and vertical-neutral. Rich branded copy/cadence belongs in client config.
- Rich vertical/client preset packages belong in `client/{client-key}/config/presets.php`; any selected package key must exist after effective merge.

## Campaign config split

Campaign presets should look like this conceptually:

```text
Campaign preset
  campaign identity
  step order
  step timing
  variant_strategy
  variants
    key
    dispatch_key
    channel
    purpose
    scope
    dependency_rules, when needed
```

Messaging templates should hold reusable content and delivery-template metadata:

```text
Messaging template
  payload_class
  queue
  reusable payload/copy
  tokens
  template identity/provenance
```

The owning module should hold timing, lifecycle conditions, sequencing,
dependencies, enablement, and module-specific behavior.

Do not use `meta.message` as the canonical Campaign step or variant message reference.

## Campaign variant strategies

Supported Campaign step variant strategies:

```text
first_available
send_all_eligible
dependency_aware
```

Use `first_available` when variants are alternatives.

Use `send_all_eligible` when every eligible variant should be scheduled.

Use `dependency_aware` when one variant depends on another sibling variant reaching an explicit state.

Dependency-aware rules should be explicit and should not rely on broad channel/purpose/scope matching. Prefer variant-key/state rules such as:

```php
'dependency_rules' => [
    'requires_variant_states' => [
        'email' => ['scheduled', 'sent'],
    ],
],
```

The dependency context should be scoped to the same campaign enrollment and same campaign step.

## Setup validation

These templates are contracts for executable setup validation, not merely copy/paste examples. Phase 6 implemented the shared validation architecture and CLI.

The shared validation architecture uses:

```text
SetupValidationManager
    -> registered app/module contributors
    -> structured findings
        severity
        code
        message
        source
        path
        module
        context
        meta
```

Owning modules validate their own private config/preset shapes. Cross-module orchestration composes findings without teaching one monolithic validator every module's internals.

Hard errors block staging/client handoff when intended runtime behavior is unsafe or impossible. Warnings remain non-blocking when configuration is dormant, unused, discouraged, or surprising but safe.

Do not add persistent validation tables unless a real operator workflow requires history, acknowledgements, or retained readiness records. Run `php artisan setup:validate` for a non-mutating readiness check; errors fail, warnings remain non-blocking, and clean results succeed.

## Config review checklist

Before accepting a config change, confirm:

- The owning module supports the behavior.
- Every key exists in the key registry or client key registry.
- Every authorable token resolves through `TokenContractRegistry` for the exact producer context and passes `MessageTemplateTokenValidator`.
- Campaign presets are free of reusable copy and payload overrides.
- Messaging templates are free of module-owned timing, lifecycle conditions, sequencing, dependencies, enablement, and module-specific skip behavior.
- Campaign variants use first-class `key`, `dispatch_key`, `channel`, `purpose`, and `scope` fields.
- Campaign Messaging templates are under `steps.{step_number}.variants.{variant_key}`.
- Runtime-only URLs are not guessed in static config.
- Purpose/scope pairs are correct.
- Consent domains are intentional and do not accidentally create one consent identity per message scope.
- Webinar Messaging definition files do not reintroduce per-scope `opt_ins` groups.
- Webinar schedule items use supported schedule shapes and do not embed timezone on `next_day_at`.
- Client list/numeric-array overrides intentionally replace, rather than append to, default lists.
- SMS exposure is controlled by Messaging channel availability for the relevant surface.
- Permission invitation configs preserve email-only bypass sending and explicit SMS opt-in.
- Runtime artifacts will persist compact IDs/scalars/context/source metadata, not full model graphs.

## New config-generation prompt

```text
We are generating Engage Core configs.

Read these first:
- config/reference/keys.php
- docs/config-authoring-guide.md
- docs/config-templates/README.md
- docs/config-templates/TOKEN_REFERENCE.md

Executable token authority:
- App\Support\TokenContracts\TokenContractRegistry
- App\Modules\Messaging\Services\MessageTemplateTokenValidator

Rules:
- Use existing keys when behavior matches.
- Recommend a new key only when behavior is meaningfully distinct.
- Messaging configs own reusable message copy and delivery-template metadata only.
- Campaign presets own journeys, timing, conditions, strategies, dependencies, enablement, and template references.
- Campaign presets must not own or override payloads.
- Campaign preset steps define business moments, timing, and variant_strategy.
- Campaign preset variants reference Messaging templates with first-class key/dispatch_key/channel/purpose/scope keys.
- Campaign dependency-aware variants must use explicit sibling variant dependency rules.
- Do not use meta.message for new Campaign preset step or variant message references.
- Campaign message templates resolve by channel + purpose + scope + campaign_key + step_number + campaign_step_variant_key.
- FlowRoute presets own automation/control flow.
- Webinar post-event config owns provider orchestration, not message copy.
- Default webinar copy should be vertical-neutral.
- Mortgage-specific copy should use mortgage-specific scopes or client overrides.
- Use documented tokens only.
- Validate authorable tokens against the exact producer context; do not use a global token allowlist.
- Keep message scope separate from consent domain.
- Do not add per-scope `opt_ins` groups to Webinar Messaging definitions; use consent-domain acknowledgement resolution.
- For Webinar schedule items, use only `delay(minutes)`, `anchored(minutes)`, or `next_day_at(time=HH:MM)`. `next_day_at` uses client timezone.
- If the client request needs a missing token, recommend adding that token to the runtime payload or changing the copy.
- Use `contact` for canonical internal keys and runtime concepts. Client-facing copy may use the configured industry noun such as Lead, Fan, Customer, Borrower, or Owner.
- Keep SMS options config-toggleable in UI.
- Keep imported-contact permission invitations separate from normal Broadcasts.

Client request:
[PASTE REQUEST HERE]

Return complete config files and list any recommended new keys/tokens separately.
```
