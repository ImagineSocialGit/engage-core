# Config and Token Contracts

## Purpose

Engage Core uses executable, module-owned contracts to define configuration that authoring tools, setup validation, templates, CI, and export may safely expose.

Contracts live alongside the module that owns the behavior. Shared registry and schema primitives live under `app/Support`.

## Directory model

```text
app/Support/ConfigContracts
    shared schemas, fields, violations, and registry

app/Support/TokenContracts
    shared token source/context/provider definitions and registry

app/Modules/{Owner}/ConfigContracts
    concrete config contracts owned by that module

app/Modules/{Owner}/TokenContracts
    model/computed token sources and producer contexts owned by that module
```

`Contracts/` elsewhere in a module remains appropriate for PHP interfaces and public behavioral seams. `ConfigContracts/` is intentionally separate because it contains concrete executable configuration schemas.


`MessageTemplateTokenValidator` is the shared Messaging validation consumer for authorable template tokens. It resolves availability through `TokenContractRegistry` for the exact producer context and is reused by config/setup validation, MessageTemplatePreset sync, and CRM template editing.

## Registered configuration contracts

| Key | Owner | Purpose |
| --- | --- | --- |
| `app.module_definition` | App/module infrastructure | Module registration and dependencies |
| `app.preset_package` | Shared Presets infrastructure | Package metadata and definition-group selection |
| `core.contact_status_definition` | Core | ContactStatus preset definitions |
| `tasks.preset_definition` | Tasks | Task-template preset definitions |
| `messaging.email_definition` | Messaging | Reusable email definitions |
| `messaging.sms_definition` | Messaging | Reusable SMS definitions |
| `messaging.permission_invitation` | Messaging | Permission-invitation page/email configuration |
| `campaigns.preset_definition` | Campaigns | Campaign, step, and variant definitions |
| `flow_routes.preset_definition` | FlowRoutes | Route and Point-type-specific definitions |
| `webinars.schedule_profile` | Webinars | Schedule profiles and lifecycle items |
| `webinars.post_event` | Webinars | Post-provider orchestration and emitted events |


The `webinars.schedule_profile` contract supports three closed schedule shapes:

```text
delay
    minutes: integer

anchored
    minutes: integer

next_day_at
    time: HH:MM
```

`next_day_at` intentionally does not accept a per-item timezone. Runtime resolves it from client configuration.

## Schema rules

- Objects are closed by default.
- Unknown fields are violations.
- Open objects must be deliberate, such as module-owned diagnostic `meta` or evaluator-specific condition payloads awaiting their own contracts.
- Required and optional fields must reflect canonical authoring, not every compatibility alias tolerated by runtime.
- Defaults belong in contracts only when runtime applies the same default.
- Deprecated fields may remain temporarily when runtime intentionally normalizes them.
- References declare their target registry/domain.
- Cross-field constraints use at-least-one or mutually exclusive groups where applicable.
- Normalization must be deterministic.

## Ownership rules

The module that owns lifecycle behavior owns its configuration contract.

Examples:

- Messaging owns reusable copy and delivery-template metadata.
- Campaigns owns journey sequencing, timing, conditions, variant strategy, and dependencies.
- Webinars owns lifecycle schedules, conditions, enablement, and Webinar skip rules.
- Messaging owns consent-domain resolution and consent acknowledgements; message scopes and consent domains are separate identities.
- FlowRoutes owns route triggers, graph/order, Point type, and Point-specific executable definitions.
- Tasks owns task-template responsibility, assignment, and due defaults.

Shared registries compose these contracts. They do not transfer domain ownership to App/Core.

## Token sources

A model-backed token must declare:

- canonical token;
- owning module;
- Eloquent model;
- real database column;
- aliases, if any;
- nullable behavior and authoring description.

Model-column validation uses the real database schema. `meta` and nested metadata paths are rejected.

A computed token must declare an explicit value provider and source path. Computed tokens are appropriate for values such as:

- signed or redirected URLs;
- formatted dates/times;
- other deliberately derived presentation values.

Computed providers must not become a backdoor for arbitrary metadata exposure.

## Producer contexts

A token being registered does not mean it is available everywhere. Producer contexts select the sources available to a concrete runtime path.


Authorable Messaging copy should not maintain a parallel allowlist. `MessageTemplateTokenValidator` extracts referenced tokens and asks the registry for the exact context; unknown or registered-but-unavailable tokens are blocking errors for config, preset-sync, and CRM template-authoring paths.

Current contexts include:

- `consent_granted`;
- `imported_contact_permission_invitation`;
- `campaign_step_due`;
- `registration_created`;
- `webinar_added`;
- `webinar_ended`.

Contexts may constrain channel, purpose, scope, and surface.

Campaign caller-supplied values are not globally authorable. They require an explicit enrollment/start-payload contract before strict export may rely on them.

## Sensitive-field rule

Do not register secrets, raw provider data, or arbitrary metadata as authorable tokens.

Explicitly excluded examples:

- `WebinarRegistration.join_token`;
- `Webinar.playback_token`;
- provider settings and credentials;
- raw webhook/provider payloads;
- generic `meta.*` paths.

## Adding a new config field

Before adding a field:

1. Identify the owning runtime DTO/consumer.
2. Prove the consumer reads the field.
3. Prove sync persists it when persistence is expected.
4. Add or update the owning contract.
5. Add semantic validation when shape alone is insufficient.
6. Add a contract example and drift test.
7. Update or generate the template/reference.
8. Exercise the field through sync/resolution/runtime when it changes behavior.

Do not add a field only because it appears useful in a template.

## Adding a token

Before adding a token:

1. Identify the exact producer context.
2. Prefer a real non-meta model column.
3. If computed, register an explicit provider.
4. Exclude secrets and internal identifiers unless client copy genuinely needs them.
5. Register aliases only as authoring conveniences.
6. Add the source to the exact contexts that produce it.
7. Prove runtime payload output and strict validation agree.
8. Add documentation generated from or tested against the registry.

## Validation layers

Structural contract validation answers: “Does this object use the supported shape?”

Domain semantic validation answers questions such as:

- Does a key match its map identity?
- Does a Campaign dependency reference a real sibling variant?
- Does a FlowRoute have exactly one executable start Point?
- Does a Point capability/handler exist for enabled modules?
- Does a Webinar profile uniquely cover the required lifecycle slots?
- Does a Messaging definition resolve for the selected semantic assignment?

Both layers are required. Structural contracts should replace duplicated shape checks, not erase domain-specific runtime safety checks.

## Export requirement

An exporter must use the same registries and validators. It must not maintain parallel frontend-only schemas.

Strict export should require:

- every output file has a file-level contract;
- every object passes its closed schema;
- every reference resolves;
- every token resolves in the exact producer context;
- merged client configuration passes setup validation;
- fresh-database sync succeeds;
- representative runtime resolution/execution succeeds.