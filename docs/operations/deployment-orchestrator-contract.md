# Deployment Orchestrator Contract

## Purpose

This document defines the host-side deployment direction for Core, SEO Sites, and Artist Sites. The deployment orchestrator should automate repeatable server work, derive names consistently, consume application-owned deployment contracts, and stop at explicit operator gates for third-party dashboards or credentials that cannot safely be created from committed source.

The orchestrator must not duplicate module requirement logic already owned by the application. For Core, `php artisan engage:deployment-plan --json` is the machine contract for enabled modules, environment requirements, and module/provider setup steps.

## Environment and database topology

Use this standard topology unless a client has an explicitly documented exception.

| Platform | Development | Staging | Production |
| --- | --- | --- | --- |
| Core | Local database | Local isolated database | Remote client database |
| SEO Sites | Local database | Shared production database in preview mode | Remote client database |
| Artist Sites | Local database | Shared production database in preview mode | Remote client database |

SEO/Artist staging is a preview surface over production content. It is not an independently mutable copy of production data.

For shared-data preview staging:

- use a distinct database credential from the production application;
- prefer read-only database privileges;
- do not run schema migrations, destructive refreshes, seeders, or production-side-effect workers from preview staging;
- treat schema changes as production-database changes and deploy them through a backward-compatible production migration sequence;
- keep preview-only access controls in place while allowing draft/unpublished content to be rendered for review.

Core staging remains isolated and disposable. Core production uses the remote authoritative client database.

## Database identities

Keep the operator/admin database account separate from application credentials.

Recommended model:

- one operator account may retain administrative access across client databases;
- each production application uses its own client-scoped database user;
- that application user is granted only the privileges it needs on the client's database (`client_database.*`), not unrelated client databases;
- shared-preview SEO/Artist staging should use a separate read-only credential when practical;
- remote database provisioning remains an operator gate unless a future database-management integration is explicitly approved.

The orchestrator may test connectivity and effective database identity after credentials are supplied. It should not assume it can create remote users/databases.

## Deployment topology

The deployment must explicitly distinguish whether the platform owns the root website.

Supported topology concepts:

- `core_services_only`: Core owns CRM and enabled Core public/service hosts; the root website is external and must not be changed.
- `managed_main_site`: the deployment also owns the root website through an SEO Site, Artist Site, or another explicitly supported site application.

The orchestrator must never infer root-site ownership from enabled Core modules alone.

Root-site ownership affects:

- which repositories are deployed;
- DNS instructions;
- Nginx server blocks;
- certificate SAN/host lists;
- HTTP smoke tests;
- which hostnames the deployment is authorized to modify.

## Naming contract

Derive operational names from stable deployment identity instead of asking the operator to invent each value.

Inputs should be kept small. Typical stable inputs are:

- environment (`staging` or `production`);
- client repository URL/slug;
- root domain;
- root-site ownership/topology;
- an explicit client-key override only when the repository convention cannot determine it safely.

Derived names include:

- runtime stem;
- application path;
- database recommendation;
- Redis prefix;
- cache prefix;
- Horizon prefix;
- Supervisor/Horizon program name;
- Scheduler identity/entry marker;
- log names/paths;
- Core-owned hostnames.

Normalization and length limits must be deterministic. If a platform limit requires truncation, use a deterministic short hash rather than silent ambiguous clipping.

The executable queue list is application-owned. Do not require an operator-maintained Horizon queue list when the runtime can resolve it from `QueueContract`/Horizon configuration.

## Core deployment-plan machine contract

Core exposes:

```bash
php artisan engage:deployment-plan --json
```

The JSON payload includes:

- environment;
- client key;
- enabled modules;
- covered deployment owners;
- readiness;
- resolved environment requirements;
- operator/external setup steps;
- present-but-unused environment keys.

The launcher should iterate the plan until blocking requirements are resolved. Provider/capability selectors may reveal additional requirements on a later pass; do not assume the first plan contains every conditional key.

## Operator/external setup steps

Deployment-plan contributors may also implement `DeploymentSetupContributor`. These steps describe actions that must happen in an external dashboard or on another first-party application and therefore cannot be completed only by writing environment variables.

A setup step contains:

- stable step key;
- owner;
- title and reason;
- ordered instructions;
- related environment keys to collect after the external work;
- verification checklist;
- priority.

Current recipe families include:

- Cloudflare Turnstile when public human verification is enabled;
- DigitalOcean Spaces when live Media storage is enabled;
- Resend for live email delivery and inbound receiving when enabled;
- Telnyx when live SMS is enabled;
- Zoom Server-to-Server OAuth and event subscriptions when Webinars is enabled;
- signed external Forms intake when configured.

The launcher should render each active step as an explicit pause. It should show exact current URLs/scopes/events when the contributor provides them, wait for the operator to finish the external work, then collect the related environment values using hidden input for secrets.

## Core host defaults

Core-owned hostnames are derived from the selected client domain and committed/runtime URL configuration. Typical roles are:

- CRM/admin host from `CRM_APP_URL`;
- `webhooks.<ROOT_DOMAIN>` for provider/server-to-server callbacks;
- `webinar.<ROOT_DOMAIN>` when Webinars uses its normal derived host;
- `messaging.<ROOT_DOMAIN>` for Messaging public preference routes;
- the configured `SCHEDULING_APP_URL` when generic public Scheduling is exposed.

Only provision hosts that the enabled/configured runtime actually uses. The root domain must not be pointed at Core when another application owns the main website.

## Orchestrator lifecycle

The finished deployment flow should be resumable and roughly follow this order:

1. Confirm deployment identity/topology and derive canonical names.
2. Verify the approved Core/client/site repositories and revisions.
3. Install dependencies and build assets where applicable.
4. Create minimal runtime environment files; do not copy every example key into live environment files.
5. Populate deterministic non-secret infrastructure values.
6. Resolve `engage:deployment-plan --json`.
7. Iterate blocking application requirements, collecting operator values securely.
8. Render and complete active external/provider setup steps.
9. Re-resolve the plan until environment readiness is clean.
10. Connect/test the correct local or remote database according to the topology matrix.
11. Install or migrate platform/module schema through the application-owned commands.
12. Sync presets and run setup validation.
13. Configure runtime permissions, Supervisor/Horizon, Scheduler, logging/observability, and PHP-FPM integration.
14. Generate/validate only the authorized Nginx hosts.
15. Pause for DNS changes that cannot be automated through an approved provider API.
16. Acquire/verify TLS after DNS resolves.
17. Run HTTP, provider, queue, Scheduler, and application smoke checks.
18. Create/verify the initial CRM owner when Core is newly installed.
19. Present an explicit final launch gate and record the deployed revisions/configuration.

Production application tests are not part of the production deployment flow. Tests run in development/staging/CI; production uses focused read-only/safe smoke checks and setup validation.

## Later module additions

Module changes are authored and tested in development, committed, and pushed. Production/staging deployment must not edit `client/<CLIENT_KEY>/config/modules.php` to enable a module.

After the approved commit is pulled:

1. resolve the new deployment plan;
2. show only the newly active environment/provider obligations;
3. complete newly active setup steps;
4. install the missing module dependency closure with the module installation command;
5. sync presets and validate;
6. update runtime/public-host configuration only when the new capability requires it;
7. run focused smoke verification.

A future `add-modules` deployment mode should orchestrate this delta without becoming a second source of module truth.