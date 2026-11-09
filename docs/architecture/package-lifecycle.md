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

**10D4 implementation:** The existing Core `scripts/package.sh` now accepts `--type vertical-group --modules key_one,key_two` when creating a new Core package. The descriptor uses `kind: "vertical-group"` with a `modules` array, one `{module_key, module_class, migration_scope}` per child. Each new child has a dedicated module definition and runtime provider and an initially disabled migration scope at `database/migrations/<module_key>/`. `client_package_registry.json` and the generated PHP manifest continue to feed the existing Core module and migration registries, without a group-wide activation flag.

Example scaffold (does not touch an existing package):

```bash
./scripts/package.sh create engage-animal-services --type vertical-group \
  --modules pet_profiles,pet_bookings --description "Optional pet vertical modules" --dry-run
```

To opt a child into migrations later, place **real** timestamped Laravel migrations in that child's directory, then change that child's `migration_scope` from `false` to `true` and use the existing explicit metadata synchronization workflow. A module with `migration_scope: false` contributes no migration scope even though its `migrationScope()` method is available. This is a **new package-authoring contract**: it does not retroactively split an existing Pet Services deployment or change its module keys.

## Existing Core manifest adoption (10D4)

Older Core clients may have a hand-maintained `config/client_packages.php`. The managed installer still refuses to overwrite it. Adoption is a **separate, explicit local workflow**, not something an install/audit/deploy command does automatically:

1. Prepare a **complete, independently reviewed** `schema: 1` JSON registry listing every direct private Composer requirement. It must declare actual package providers, module classes, migration-scope flags and environment key ownership; never infer them from a PHP file. Libraries with no runtime contributions may still be listed with no module/provider fields.
2. Run `./scripts/package.sh adopt CLIENT_KEY --registry /path/to/reviewed-registry.json`. This reads Composer and lock evidence, shows the entire PHP manifest diff and a content-based fingerprint. It **does not execute PHP**, read the root `.env`, change files, or call Composer.
3. Review that diff against the original client-owned PHP file and its existing runtime behavior. This is crucial because nontrivial PHP logic, extra registrations, or conditionals are not inferred and would otherwise be replaced.
4. Only if the replacement is correct, run the explicit `--apply --confirm-plan FINGERPRINT` command printed by the review. This is restricted to the active local client by the existing `APP_ENV=local` and matching `CLIENT_KEY` guards. It creates `config/client_package_registry.json` and replaces the PHP manifest, verifies its syntax and managed consistency, and normalizes managed-file permissions. Review and commit both files.

The fingerprint covers the old manifest, proposed PHP and JSON, client identity, and Composer/lock evidence. Stale plans cannot be applied. A failure restores the original PHP manifest and removes the newly created registry. The operation does not modify Composer files, perform migrations, enable modules or provision secrets. **If the PHP diff cannot be understood or safely represented by the managed registry, do not adopt that client yet; retain its hand-maintained manifest and use read-only audit.**

## Ownership and tool reuse

**Core:** Continue using selected-client Composer and `config/client_packages.php`, `ModuleManager`, migration registry, deployment contributors and environment-key catalog. Extend the existing `scripts/package.sh` and `scripts/operations/lib/package_workflow.py` for developer tooling rather than creating a parallel installer. `scripts/add-client-modules.sh` changes configuration; module/schema activation remains a separate explicit step. `engage:refresh` remains local/testing-only.

**SEO:** Continue using selected-client Composer, package providers, feature/vertical registries, and existing local link/unlink/bootstrap tools. An optional SEO package must not impose features or schema on clients that do not select it. Platform-owned package management may be added through existing seams without giving Core ownership over SEO client source.

**Artist and Label:** Keep existing application-specific integrations and deployment checks. Do not invent package discovery, module registries, migration semantics or Label syndication credentials as if already implemented.

**Infrastructure:** Continue the approved immutable-source release and Ansible ownership model. Infrastructure coordinates authorization, pairing, environment provisioning and cross-platform readiness; it must not become a competing Laravel module registry. The old host-mutating Core launch workflow remains disabled for managed staging/production.

**Local development:** Composer link/unlink is only an edit-speed tool. Unlinking a checkout is not uninstalling a package, disabling a module, rolling back a schema, revoking a credential or removing a provider integration.

## Read-only package lifecycle evidence (10D2)

Core extends the existing `scripts/package.sh` rather than adding a competing package CLI:

```bash
cd /var/www/engage-core
./scripts/package.sh audit CLIENT_KEY
./scripts/package.sh audit CLIENT_KEY --source /var/www/engage-donations
./scripts/package.sh audit CLIENT_KEY --source /var/www/engage-donations --json
```

The command reads selected-client `composer.json`, `composer.lock`, `config/client_package_registry.json`, `config/client_packages.php` **as text**, and the presence of generated vendor files. Optional `--source` (repeatable) inspects the explicit package source checkout's Composer metadata and `engage-package.json` when present. The report distinguishes managed manifests, hand-maintained manifests, missing or drifted generated files, locked package references, and source descriptors that are missing or invalid. The script never evaluates arbitrary PHP manifests, calls Composer, reads `.env`, rewrites client files, updates dependencies, or provisions credentials. It is safe to run without selecting the client in the root `.env`.

A missing descriptor on an existing manually maintained package is a reported **warning**, not permission to synthesize registration metadata. A hand-maintained PHP manifest is explicitly **not evaluated**: only its presence and ownership mode are known. A generated manifest that differs from its managed registry is an **error** and is not regenerated. The command exits nonzero for source-evidenced structural errors; warnings alone do not fail the audit.

The report deliberately does **not** certify Composer content-hash consistency, actual dependency resolution, PHP class loading, module/feature activation, migration completion, provider role applicability, credential state or deployment readiness. Those gates remain with their existing platform commands and 35D5 work. SEO, Artist and Label are not treated as Core clients by this command; their respective tooling must preserve their documented platform-specific package contracts.

## Manifest, dependencies and compatibility

The selected client's committed `composer.json` and `composer.lock` govern which private packages and revisions exist in that client. `client/vendor` is generated and not committed. Packages must not install a second Laravel/Illuminate runtime. Private package service providers and migration paths must be declared through the host's documented selected-client registration seam. Unknown metadata, ownership collisions and missing class/code dependencies fail closed.

The generated `client_package_registry.json` / `client_packages.php` pair is a distinct ownership mode from older hand-maintained manifests. The managed installer still refuses to overwrite hand-maintained PHP; use only the deliberate 10D4 adoption operation described above. No audit or installation silently adopts clients or regenerates their lockfiles.

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

1. **10D1:** Establish the shared semantic contract and regression-test Core's existing multi-module package runtime. No installer behavior changes.
2. Add a read-only lifecycle audit/plan to the existing developer and platform tools, including missing descriptor/manifest/lock evidence without guessed states.
3. **10D4:** Extend the existing Core package scaffolder/registry with plural group descriptors and a reviewed, fingerprint-confirmed path for adopting hand-maintained manifests. No runtime migration or module activation changes.
4. Align existing standalone packages with their applicable type contracts (including Stripe, Shopify, Donations, SEO verticals, Event Signup Kit and Pet Services).
5. Implement 35D5 conditional credential discovery and authorized provisioning through the established Core contributors and platform-owned recipients.
6. Connect capability gates to Infrastructure's New Client / Update Client / Add Platform workflow when each platform's source release path is verified.

Separate decisions still required: whether/when Label supports selected-client packages, how to migrate an existing Pet Services client to plural modules (if desired), and exact deployment hooks for SEO/Artist immutable releases. Their absence must block only the corresponding implementation batch, not invalidate the already supported Core runtime composition.