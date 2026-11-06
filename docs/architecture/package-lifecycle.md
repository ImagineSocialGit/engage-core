# Cross-platform package lifecycle contract

## Purpose and status

This document defines the common **semantics** for optional Engage packages across Core, SEO, Artist Sites and, when supported, Label Sites. It is a contract for package authors and deployment tooling, not a new Laravel package manager. The owning platform retains its existing registries, commands, schema rules and runtime boundaries; Infrastructure coordinates approved multi-platform deployments.

**Status (November 6, 2026):** The principles and Core multi-module composition below are established. Several cross-platform install, capability-discovery, provisioning and removal steps are target behavior **not yet automated**. Do not treat a documented target as an already functioning CLI command.

Related existing sources:

- Core `docs/architecture/client-packages.md` — selected-client Composer, manifest, provider, module and migration contracts.
- SEO `docs/architecture/client-packages.md` — client-selected Composer/provider and environment-key contracts.
- Core `docs/architecture/deployment/command-ownership.md` — module and schema command ownership.
- Infrastructure's deployment planner/executor — approved immutable source release and deployment guards.
- Proposed November 5 capability-aware provisioning contract — credential applicability, pairing and ownership; implementation is pending.

## Shared states; do not collapse them

A package and its contributions move through separate concepts:

1. **Available/discovered:** a source package can be identified; its metadata and requirements can be inspected without loading or mutating a client.
2. **Selected/locked:** the owning client declares the package as a dependency and commits its resolved Composer lock. A package absent from a client's locked dependencies remains absent from that client.
3. **Installed:** the selected client has its locked package code and autoloader. Installation does not enable domain capabilities.
4. **Registered:** the owning platform loads only the allowed provider/module/feature contributions for that selected client.
5. **Configured/selected:** the selected client's normal module/feature and provider-role configuration determines what is actually applicable.
6. **Provisioned, where applicable:** required environment state and approved provider/peer credentials exist for the selected capabilities. Optional, disabled and offline-only roles do not demand unrelated credentials.
7. **Schema-ready, where applicable:** migrations and bootstrap tasks were explicitly planned and run by the owning platform for the relevant selected capabilities.
8. **Activated and ready:** runtime routes, providers, listeners, workers, resources and external dependencies have passed applicable validation. Installed or registered alone is not ready.
9. **Updated/disabled/retired:** code upgrades preserve committed lock authority and compatibility. Disabling a feature does not implicitly uninstall its schema, revoke shared secrets or remove the package.

A deployment planner reports the distinction between these states, including unknown and blocked states; it must not report readiness merely from class existence or from an environment key appearing in an allowlist.

## Package kinds and their runtime owner

| Kind | Composition | Enablement and schema owner |
| --- | --- | --- |
| Core vertical module | One selected-client Composer package contributes one Core module definition and optional package-owned migration scope. Donations is an example. | Core `ModuleManager`, module configuration and migration registry. |
| Core vertical module group | One selected-client Composer package contributes **multiple independently selectable** Core module definitions, each with its own dependencies, runtime providers and optional migration scope. Pet Services is a prospective example; its actual module breakdown is not specified here. | The same existing Core module config, dependency resolver and migration registry, **per module key**, not a new group-wide activation toggle. |
| Provider integration | Adapter for provider-neutral contracts of an owning domain capability. Stripe and Shopify are examples. | The domain module chooses provider roles and applicability; the integration owns provider-specific implementation and verification. Composer install or provider bootstrap is not a payment/commerce activation decision. |
| Shared feature or library | Reusable package that may be pure code (QR Rendering) or conditionally contribute services (Event Signup Kit). | Consumers explicitly opt into the feature. No Core module or migration scope is required merely because the library is installed. |
| SEO selected-client extension | Client-scoped Composer code offering vertical/feature/provider contributions, such as SEO Mortgage and Nonprofit. | SEO client package provider, `FeatureManager`/`VerticalManager`, and SEO's own migration/setup contracts. Do not install vertical schema or runtime code for unrelated clients. |
| Artist extension | Artist currently exposes application integrations and readiness checks, but a generic selected-client package installer is not evidenced. | Artist's present application contracts until an explicit extension workflow is approved. |
| Label extension | Label is incomplete and does not yet have an approved selected-client package contract. | No assumed generic package loader, installer or new descriptor kind. |

Package kinds describe capability ownership, not a mandate to create modules, tables, service providers or secret keys for every package. A group is **composition of ordinary modules**, not a second module manager. No child module may be marked `always_on` merely because the group package is present.

## Core vertical group: existing runtime seam

Core's selected-client `client_packages.php` already accepts associative `modules` and `migrations` mappings with more than one key. Each module entry is passed to the existing `ModuleManager`; each matching migration scope is passed to the existing `ModuleMigrationRegistry` and planner. Dependency closure is resolved by module key. Package-scoped PHP providers under a module definition boot only when that module is enabled through the normal mechanism.

For a package contributing multiple modules:

- Each child module must have its own stable, collision-free key; its normal dependency list determines which other modules it requires.
- Child enablement belongs to the selected Core client's effective `modules.enabled` configuration. Composer installation must not turn on all children.
- A migration scope, if contributed, must be associated with the corresponding child key and live under the selected-client package's allowed `vendor/<vendor>/<package>/database/migrations` tree.
- Unselected children must not be included in an enabled-only schema plan, except when a selected module explicitly depends on them.
- The package may share internal implementation classes and infrastructure; that does not make optional child routes, schema or external-provider effects always-on.

The **developer scaffolder/managed registry** currently recognizes a singular Core vertical module descriptor (`module_key`, `module_class`, `migration_scope`). Its future plural descriptor representation, any new scaffolder flags and migration from hand-maintained manifests remain **open implementation choices**; do not infer a field name or rewrite existing manifests. Runtime composition already supports more than one module, independently of that scaffolder gap.

## Ownership and tool reuse

**Core:** Continue using selected-client Composer and `config/client_packages.php`, `ModuleManager`, migration registry, deployment contributors and environment-key catalog. Extend the existing `scripts/package.sh` and `scripts/operations/lib/package_workflow.py` for developer tooling rather than creating a parallel installer. `scripts/add-client-modules.sh` changes configuration; module/schema activation remains a separate explicit step. `engage:refresh` remains local/testing-only.

**SEO:** Continue using selected-client Composer, package providers, feature/vertical registries, and existing local link/unlink/bootstrap tools. An optional SEO package must not impose features or schema on clients that do not select it. Platform-owned package management may be added through existing seams without giving Core ownership over SEO client source.

**Artist and Label:** Keep existing application-specific integrations and deployment checks. Do not invent package discovery, module registries, migration semantics or Label syndication credentials as if already implemented.

**Infrastructure:** Continue the approved immutable-source release and Ansible ownership model. Infrastructure coordinates authorization, pairing, environment provisioning and cross-platform readiness; it must not become a competing Laravel module registry. The old host-mutating Core launch workflow remains disabled for managed staging/production.

**Local development:** Composer link/unlink is only an edit-speed tool. Unlinking a checkout is not uninstalling a package, disabling a module, rolling back a schema, revoking a credential or removing a provider integration.

## Manifest, dependencies and compatibility

The selected client's committed `composer.json` and `composer.lock` govern which private packages and revisions exist in that client. `client/vendor` is generated and not committed. Packages must not install a second Laravel/Illuminate runtime. Private package service providers and migration paths must be declared through the host's documented selected-client registration seam. Unknown metadata, ownership collisions and missing class/code dependencies fail closed.

The existing Core generated `client_package_registry.json` / `client_packages.php` pair has a different ownership mode from older hand-maintained manifests. The managed installer currently refuses to overwrite those manifests. **Preserve that refusal** until a separately reviewed adoption workflow exists. Do not silently convert clients or regenerate Composer lockfiles during read-only audits.

For already-shipped modules, schema changes require production-safe append-only migrations; do not rerun scaffolds or edit deployed migration history. Development-only module schema can follow the existing explicit dev refresh policy. Removal of an installed package must not drop customer data implicitly.

## Capability requirements and credentials (35D5 boundary)

A provider adapter declares what it can supply; the owning module/feature and selected provider role determine when it is actually required. Required environment keys and dependencies are **capability-scoped**, not inferred from installed Composer files. Preserve Core's deployment contributor and environment ownership mechanisms and SEO's existing package-key allowlist.

Generated first-party signing pairs need stable client/environment/purpose/recipient identity and authorized, generate-once coordination; externally issued provider keys must be supplied by their owner, never fabricated. Plans and setup checks remain read-only and redacted. Provisioning, mutation, distribution and rotation require explicit authorized operations owned by the platform/coordinator. A failed cross-platform pairing must remain not-ready rather than activating either half of the feature.

No new credential descriptor format, cross-platform recipient protocol or provisioning CLI command is defined by this batch; those are 35D5 implementation work after the lifecycle contract.

## Verification gates for every new package

For a new package, verify the applicable (not artificially universal) gates:

1. Package identity and supported host/type are explicit; Composer autoload, tests and required host contracts resolve without duplicating framework dependencies.
2. A client that does not select the package has no package code, module registration, migrations or runtime side effects.
3. A client that installs the package but disables its modules/features does not trigger their providers, schema migration, credentials or billable external calls.
4. Per-module dependencies, migrations, routes and providers are activated only through the owning platform's selected capability closure.
5. Environment variables have one declared owner; readiness requirements are conditional on capability/provider role; secrets never enter source or plans.
6. A committed lock and read-only plan agree on the desired revision. Deployment does not accidentally invoke generic Composer hooks that generate keys or run migrations.
7. Local linking, staging/production deployment, package updates, feature disablement and removal are distinct, with explicit gates for any destructive action.
8. Tests cover runtime behavior and module boundaries with generic fixtures, not client-specific copy or fragile UI wording. Validate migrations against MySQL where relevant.

## Implementation sequence and open decisions

1. **10D1 (this batch):** Establish the shared semantic contract and regression-test Core's existing multi-module package runtime. No installer behavior changes.
2. Add a read-only lifecycle audit/plan to the existing developer and platform tools, including missing descriptor/manifest/lock evidence without guessed states.
3. Decide and implement managed **plural module-group descriptor** and a deliberate legacy manifest adoption workflow; extend `package.sh` without replacing its existing local-only safety checks.
4. Align existing standalone packages with their applicable type contracts (including Stripe, Shopify, Donations, SEO verticals, Event Signup Kit and Pet Services).
5. Implement 35D5 conditional credential discovery and authorized provisioning through the established Core contributors and platform-owned recipients.
6. Connect capability gates to Infrastructure's New Client / Update Client / Add Platform workflow when each platform's source release path is verified.

Separate decisions still required: plural group descriptor shape; existing hand-maintained Core manifest adoption; whether/when Label supports selected-client packages; and exact deployment hooks for SEO/Artist immutable releases. Their absence must block only the corresponding implementation batch, not invalidate the already supported Core runtime composition.