# Selected-Client Composer Packages

## Purpose

Engage Core may load private Composer packages that are needed by only one or a subset of client deployments.

Two package shapes are supported:

```text
integration package
    extends an existing Engage Core module through provider-neutral contracts
    does not become a module itself

vertical module package
    owns optional vertical/domain behavior
    contributes a real module definition and, when needed, its own migration scope
```

This keeps provider-specific and vertical-specific runtime code out of clients that do not need it while preserving one Engage Core host application and one module lifecycle.

Existing in-repository adapters such as Resend, Telnyx, Twilio, and Zoom may remain where they are until there is a concrete reason to extract them. Existing in-repository verticals may also remain until deliberately migrated to this package boundary.

## Client-owned package files

A selected client may own:

```text
client/{CLIENT_KEY}/composer.json
client/{CLIENT_KEY}/composer.lock
client/{CLIENT_KEY}/config/client_packages.php
```

The selected client's Composer lock file is part of the deployment contract and should be committed.

The nested runtime dependency directory is generated output and must not be committed:

```text
client/{CLIENT_KEY}/vendor/
```

Clients that use no private package do not need a nested Composer project or `client_packages.php`.

## Private repositories

A client Composer project may reference a private GitHub repository through a VCS repository entry.

Example:

```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "git@github.com:ImagineSocialGit/engage-integration-example.git"
        }
    ],
    "require": {
        "imagine-social/engage-integration-example": "dev-main"
    }
}
```

Local development may use the developer's normal GitHub credentials. Staging and production should use an appropriate read-only deployment credential.

Repository authentication is deployment infrastructure. GitHub tokens, SSH private keys, provider API credentials, and webhook secrets must not be committed to client package configuration.

A selected-client package must not install another Laravel application/framework tree merely to use host contracts. Engage Core supplies Laravel and public Engage Core contracts; the selected-client package supplies its implementation code. Runtime bootstrap rejects nested `laravel/framework` or `illuminate/*` trees so a selected-client autoloader cannot shadow the host framework.

## Bootstrap order

For non-testing runtime bootstrap, Engage Core:

1. resolves the selected `CLIENT_KEY`;
2. loads `client/{CLIENT_KEY}/vendor/autoload.php` when present;
3. reads and validates `client/{CLIENT_KEY}/config/client_packages.php`;
4. exposes declared package environment definitions through the normal Engage environment catalog;
5. makes package-contributed module definitions and migration scopes available to the shared module registries;
6. loads the selected-client `.env` using the expanded client-owned-key contract when configuration is not cached;
7. merges normal selected-client configuration;
8. registers always-loaded package service providers declared under `providers`;
9. lets `ModuleBootstrapServiceProvider` register providers for enabled built-in and package-contributed modules.

The testing bootstrap remains isolated from selected-client packages. Core seam tests explicitly install a package manifest/runtime when they need to exercise this behavior.

A manifest that declares package providers, modules, or migrations without an installed selected-client Composer autoloader fails loudly.

## Package manifest

A provider-only integration package may use:

```php
<?php

use Vendor\Example\ExampleServiceProvider;

return [
    'providers' => [
        ExampleServiceProvider::class,
    ],

    'environment' => [
        'EXAMPLE_API_KEY' => [
            'owner' => 'example',
            'secret' => true,
        ],
    ],
];
```

A vertical module package may contribute a module and schema scope without adding either to Engage Core:

```php
<?php

use Vendor\PetServices\PetServicesPackage;

return [
    'modules' => [
        PetServicesPackage::MODULE_KEY => PetServicesPackage::moduleDefinition(),
    ],

    'migrations' => [
        PetServicesPackage::MODULE_KEY => PetServicesPackage::migrationScope(),
    ],
];
```

A client using multiple packages composes all entries into the same manifest.

### `providers`

`providers` is a list of Laravel service-provider classes that are always registered for the selected client. Use this for package infrastructure that is not itself controlled by module enablement, such as a vendor integration adapter.

Do not put a vertical module's ordinary runtime provider here. Put that provider in the package-contributed module definition so it loads only when the module is enabled.

### `environment`

`environment` declares ownership metadata only. It never contains environment values.

Every package environment key:

- is selected-client owned;
- must use uppercase environment-key syntax;
- must declare a lowercase owner key;
- may declare whether the value is secret;
- must not collide with an Engage-owned built-in environment key.

### `modules`

`modules` contributes real module definitions to `ModuleManager` after the package autoloader has been loaded.

Package module definitions use the same fields as built-in definitions:

```text
name
ui
nav
settings
always_on
depends_on
requires_provider
preset_contributors
message_template_definition_contributors
providers
```

Package module keys may not collide with Engage Core module keys. Dependencies may reference built-in modules or other package modules available in the same selected-client package manifest.

Installed and enabled remain separate states:

```text
Composer package absent
    -> package module unknown/unavailable

Composer package installed + manifest contribution present
    -> package module available but not necessarily enabled

package module key present in effective modules.enabled
    -> normal dependency resolution and module-provider bootstrap apply
```

Client configuration continues to own enablement. Installing a Composer package does not silently enable its module.

### `migrations`

`migrations` maps a package-contributed module key to one package-owned migration directory.

The path is relative to the selected client directory and must live under:

```text
vendor/<vendor>/<package>/database/migrations
```

Engage Core normalizes that to the selected client's repository-relative vendor path and passes it through the same migration registry, planner, checksum ledger, preflight, install, migrate, reconcile, status, and setup-validation machinery used by built-in modules.

A package migration scope:

- must belong to a module declared by the same package manifest;
- may not collide with an Engage Core migration-scope key;
- must contain normal timestamped Laravel migration filenames;
- remains absent from clients that do not install the package;
- is not auto-run merely because the package is installed;
- is selected only through the normal module migration plan.

Local package symlinks are allowed. Migration-path validation therefore trusts the normalized selected-client vendor path lexically while allowing its local development symlink to resolve outside the Engage Core checkout.

## Provider registration

An integration package should register only against public seams owned by the relevant module.

For Commerce, an integration package may tag one provider implementation with:

```php
CommerceProviderRegistry::PROVIDER_TAG
```

and implement only the Commerce capability contracts it actually supports.

A vertical module package owns its own module provider. That provider may conditionally register optional integrations when another horizontal module is enabled. For example, a PetServices package may register a Scheduling booking-subject provider when Scheduling is enabled. The adapter belongs to the vertical package; Engage Core's shared `ModuleIntegrations` tree must not accumulate vertical-specific bridge classes.

A package may also register:

- deployment-plan/setup contributors;
- setup-validation contributors through documented tags;
- webhook handlers or routes through an approved public integration seam;
- module facts, token contributors, filters, or other documented extensions;
- provider-specific services used internally by its adapter.

A package must not make `IntegrationsModuleServiceProvider` or another shared Core class the owner of package-specific business logic.

## Client configuration and credentials

Client-varying provider credentials belong in:

```text
client/{CLIENT_KEY}/.env
```

after their keys have been declared in `client_packages.php`.

Do not add new vendor-specific credential keys to the permanent Engage Core environment catalog merely to support an optional private package.

Client configuration may select provider roles or other provider-neutral module behavior, but the package remains responsible for translating that configuration into its implementation.

## Installation and deployment

For a selected client with a nested Composer project:

```bash
cd client/{CLIENT_KEY}
composer install
```

Normal staging/production deployment should install the exact committed client lock file, for example:

```bash
composer install --no-dev --prefer-dist --optimize-autoloader
```

Core runtime bootstrap intentionally does not run Composer or mutate the selected client repository. Package installation remains an explicit deployment step.

After Composer installation, package-contributed schema is still installed through the normal Engage module command, for example:

```bash
php artisan modules:install pet_services --force
```

Before the first package-backed production rollout, the deployment launcher should be verified or extended so the selected client's locked Composer install is performed as part of the normal deployment workflow rather than depending on an undocumented manual step.

## Local package development

Do not require a commit, GitHub push, and Composer update for every local edit to an Engage package.

Engage Core provides a local-only development link workflow that temporarily replaces the selected client's Composer-installed package directory with a symlink to a local package checkout while leaving the client's committed `composer.json` and `composer.lock` unchanged.

For example:

```bash
cd /var/www/engage-core

./scripts/dev-link-package.sh \
    imagine-social/engage-pet-services \
    /var/www/engage-pet-services
```

The link script:

- runs only when root `APP_ENV` resolves to `local`;
- uses the selected root `CLIENT_KEY` unless an explicit matching client key is supplied;
- verifies that the selected client actually requires the requested package;
- verifies that the local package `composer.json` declares the same package name;
- requires the normal Composer-managed package to be installed first;
- requires the selected client's Composer `vendor-dir` to remain the default `vendor`, matching Core's runtime package bootstrap;
- replaces only the installed package directory with a symlink to the local checkout;
- does not rewrite the client's `composer.json` or `composer.lock`;
- clears compiled Blade views after linking.

While linked, ordinary PHP, Blade, CSS, JavaScript, migrations, and other source files read from the package checkout are available to the selected client without pushing Git or running Composer again.

If a package changes its Composer dependency or autoload contract, treat that as a dependency change rather than a normal source edit. Restore the Composer-managed package, update the selected client's dependency normally, and then link the local checkout again.

To restore the exact Composer-managed revision pinned in the selected client's lock file:

```bash
./scripts/dev-unlink-package.sh imagine-social/engage-pet-services
```

Local package links are development state only. Never create or depend on these symlinks in staging or production.

## Boundary rules

Integration packages:

- do not become Engage Core modules;
- do not own another module's business lifecycle;
- implement provider-neutral contracts supplied by the host module;
- keep vendor API/webhook meaning inside the package;
- may remain completely absent from clients that do not need them.

Vertical module packages:

- may contribute real optional modules and package-owned migration scopes;
- own their vertical models, workflows, UI, presets, and optional horizontal-module adapters;
- must not push vertical columns or business rules into Core or horizontal modules;
- must use public host contracts when consuming Scheduling, Documents, Forms, Messaging, Commerce, Reporting, or another horizontal capability;
- may remain completely absent from clients outside that vertical.

All selected-client packages:

- do not own Core Contact identity;
- do not bypass another module's private tables or lifecycle;
- do not put secrets in source-controlled config;
- must not ship a second Laravel/Illuminate runtime into the selected client's nested vendor tree.

This selected-client package seam is composition infrastructure. Engage Core owns horizontal capability contracts and module lifecycle; installed packages own their optional implementation and vertical meaning.