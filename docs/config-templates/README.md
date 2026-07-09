# Engage Core Config Templates

This directory contains safe starting templates for Engage Core default configs and client-specific overrides.

Use this file as the template index. Use `docs/config-authoring-guide.md` for the full authoring rules, token rules, module-boundary rules, and review checklist.

## Primary references

Read these before authoring or reviewing configs:

```text
docs/config-authoring-guide.md
docs/module-boundaries.md
docs/modules/*.md
config/reference/keys.php
config/reference/tokens.php
```

Optional client-specific registries may exist at:

```text
client/{client-key}/config/reference/keys.php
client/{client-key}/config/reference/tokens.php
```

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

## Template files

| Template | Use for |
| --- | --- |
| `campaign-presets-template.php` | Campaign journeys, step order, step timing, variant strategy, and variant delivery references. Campaign presets must not own reusable message copy or payloads. |
| `messaging-email-template.php` | Email message definitions, including transactional webinar messages and campaign variant templates under `campaigns.{campaign_key}.steps.{step_number}.variants.{variant_key}`. |
| `messaging-sms-template.php` | SMS message definitions, including campaign variant templates. SMS must remain explicit and surface-controlled. |
| `flow-routes-template.php` | FlowRoute definitions, route points, waits, event waits, and automation/control-flow presets. |
| `task-presets-template.php` | DB-owned task template/default definitions. Task preset sync creates task templates only, not live tasks. |
| `webinar-schedule-profiles-template.php` | DB-owned webinar schedule profiles and schedule profile items. These own timing/slot identity, not reusable message copy. |
| `webinar-post-event-template.php` | Webinar post-event provider orchestration such as attendance recording, playback resolution, follow-up dispatch, and automation events. |
| `permission-invitations-template.php` | Imported-contact one-time permission invitation copy, public preference page copy/style, and accepted consent scopes. |
| `contact-status-presets-template.php` | Core contact status definitions. |
| `presets-root-template.php` | Root preset loading/orchestration config. |
| `modules-template.php` | Module enablement, dependencies, dashboard slots, and module tone settings. |
| `client-request-intake-template.md` | Structured intake notes for client config/setup requests. |
| `TOKEN_REFERENCE.md` | Human-readable token reference for config authors. |

## Core authoring rules

- Messaging configs own reusable message copy and delivery templates.
- Every list-based Messaging definition must declare a stable explicit `key`. The synced `MessageTemplatePreset.key` derives from that explicit identity rather than list position. `source_config_path` remains provenance/debug location, not durable template identity.
- Campaign presets own journeys, timing, step order, variant strategy, and delivery references.
- Campaign presets must not define reusable subject/body/CTA/message payloads.
- Campaign message templates live in Messaging configs under:

```text
messaging.{channel}.{purpose}.{scope}.campaigns.{campaign_key}.steps.{step_number}.variants.{variant_key}
```

- Campaign message templates resolve by channel, purpose, scope, campaign key, step number, and variant key, not author-created per-step message names.
- Campaign step variants must reference Messaging-owned templates/assignments and must not own reusable copy.
- FlowRoute presets own automation/control-flow routing and point definitions.
- Webinar post-event config owns provider orchestration, not reusable message copy.
- Task presets create DB-owned task template definitions only. They do not create live tasks.
- Use documented keys and tokens only.
- Runtime-only URLs must come from runtime payloads/services, not static config guesses.
- Runtime artifact payloads must stay compact; source/debug/template identity belongs in metadata where appropriate.
- SMS visibility belongs to Messaging channel availability for the relevant surface, not one-off provider checks.
- Imported-contact permission invitations are a distinct Messaging-owned one-time consent flow, not a normal Broadcast bypass.

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

Messaging templates should hold the reusable copy:

```text
Messaging template
  payload_class
  queue
  reusable payload/copy
  tokens
  conditions, when needed
```

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

## Setup validation direction

These templates are contracts for executable setup validation, not merely copy/paste examples.

The shared validation architecture should use:

```text
SetupValidationManager
    -> registered app/module validators
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

Do not add persistent validation tables unless a real operator workflow requires history, acknowledgements, or retained readiness records.

## Config review checklist

Before accepting a config change, confirm:

- The owning module supports the behavior.
- Every key exists in the key registry or client key registry.
- Every token exists in the token registry or client token registry.
- Campaign presets are free of reusable copy and payload overrides.
- Campaign variants use first-class `key`, `dispatch_key`, `channel`, `purpose`, and `scope` fields.
- Campaign Messaging templates are under `steps.{step_number}.variants.{variant_key}`.
- Runtime-only URLs are not guessed in static config.
- Purpose/scope pairs are correct.
- SMS exposure is controlled by Messaging channel availability for the relevant surface.
- Permission invitation configs preserve email-only bypass sending and explicit SMS opt-in.
- Runtime artifacts will persist compact IDs/scalars/context/source metadata, not full model graphs.

## New config-generation prompt

```text
We are generating Engage Core configs.

Read these first:
- config/reference/keys.php
- config/reference/tokens.php
- docs/config-authoring-guide.md
- docs/config-templates/README.md

Rules:
- Use existing keys when behavior matches.
- Recommend a new key only when behavior is meaningfully distinct.
- Messaging configs own reusable message copy.
- Campaign presets own journeys/timing/template references.
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
- If the client request needs a missing token, recommend adding that token to the runtime payload or changing the copy.
- Use `contact` for canonical internal keys and runtime concepts. Client-facing copy may use the configured industry noun such as Lead, Fan, Customer, Borrower, or Owner.
- Keep SMS options config-toggleable in UI.
- Keep imported-contact permission invitations separate from normal Broadcasts.

Client request:
[PASTE REQUEST HERE]

Return complete config files and list any recommended new keys/tokens separately.
```


