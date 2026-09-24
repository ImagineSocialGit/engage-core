# Selected-Client Composer Packages

## Purpose

Engage Core may load private Composer packages that are needed by only one or a subset of client deployments.

This is the default home for new third-party provider integrations such as Shopify, Square, Arive, or another specialized vendor adapter. The package implements public contracts owned by Engage Core modules; it does not move the owning module's business meaning into vendor code.

The intended shape is:

```text
Engage Core module
    owns provider-neutral contracts and domain behavior
            ^
            | implements/registers
            |
selected-client private Composer package
    owns vendor API clients, webhook interpretation, and provider translation
```

Existing in-repository adapters such as Resend, Telnyx, Twilio, and Zoom may remain where they are until there is a concrete reason to extract them.

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

An Engage Core integration package must not install another Laravel application/framework tree merely to use host contracts. The Engage Core application supplies Laravel and the public Engage Core module contracts; the selected-client package supplies its own implementation code. Runtime bootstrap rejects nested `laravel/framework` or `illuminate/*` trees so a selected-client autoloader cannot shadow the host framework.

## Bootstrap order

For non-testing runtime bootstrap, Engage Core:

1. resolves the selected `CLIENT_KEY`;
2. loads `client/{CLIENT_KEY}/vendor/autoload.php` when present;
3. reads and validates `client/{CLIENT_KEY}/config/client_packages.php`;
4. exposes declared package environment definitions through the normal Engage environment catalog;
5. loads the selected-client `.env` using that expanded client-owned-key contract when configuration is not cached;
6. merges normal selected-client configuration;
7. registers declared package service providers before the normal application/module providers that follow `ClientServiceProvider`.

The testing bootstrap remains isolated from selected-client packages. Core seam tests explicitly install a package manifest/runtime when they need to exercise this behavior.

A manifest that declares package providers without an installed selected-client Composer autoloader fails loudly.

## Package manifest

Example:

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

        'EXAMPLE_ACCOUNT_ID' => [
            'owner' => 'example',
            'secret' => false,
        ],
    ],
];
```

`providers` is a list of Laravel service-provider classes supplied by the selected client's installed Composer packages.

`environment` declares ownership metadata only. It never contains environment values.

Every package environment key:

- is selected-client owned;
- must use uppercase environment-key syntax;
- must declare a lowercase owner key;
- may declare whether the value is secret;
- must not collide with an Engage-owned built-in environment key.

The package service provider may register a `DeploymentPlanContributor` or `DeploymentSetupContributor` using the same owner key. The existing deployment-plan resolver then treats those dynamically declared keys exactly like other catalogued client-owned environment requirements.

## Provider registration

A private integration package should register only against public seams owned by the relevant module.

For Commerce, an integration package may tag one provider implementation with:

```php
CommerceProviderRegistry::PROVIDER_TAG
```

and implement only the Commerce capability contracts it actually supports.

A package may also register:

- deployment-plan/setup contributors;
- setup-validation contributors through documented tags;
- webhook handlers or routes through an approved public integration seam;
- provider-specific services used internally by its adapter.

A package must not make `IntegrationsModuleServiceProvider` or another shared Core class the owner of vendor-specific business logic.

## Client configuration and credentials

Client-varying provider credentials belong in:

```text
client/{CLIENT_KEY}/.env
```

after their keys have been declared in `client_packages.php`.

Do not add new vendor-specific credential keys to the permanent Engage Core environment catalog merely to support an optional private package.

Client configuration may select provider roles or other provider-neutral module behavior, but the package remains responsible for translating that configuration into its vendor implementation.

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

Before the first package-backed production rollout, the deployment launcher should be verified or extended so the selected client's locked Composer install is performed as part of the normal deployment workflow rather than depending on an undocumented manual step.

## Boundary rules

Private integration packages:

- do not become Engage Core modules;
- do not own Core Contact identity;
- do not own another module's private tables or lifecycle;
- do not hard-code vertical meaning that belongs to Music, Mortgage, PetServices, Experiences, or another module;
- do not bypass provider-neutral module contracts merely because a vendor API exposes more fields;
- do not put secrets in source-controlled config;
- may remain completely absent from clients that do not need them.

This selected-client package seam is composition infrastructure. Vendor-specific implementation remains in the vendor package, and provider-neutral business behavior remains in the owning Engage Core module.