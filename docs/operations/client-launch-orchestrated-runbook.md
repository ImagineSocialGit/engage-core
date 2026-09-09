# Core Client Launch — Orchestrated Runbook

## Purpose

This is the operator-facing path for a new Core staging or production environment after the client configuration has been tested, committed, and pushed.

The launcher automates repeatable server work and pauses only for external account/dashboard work or values that cannot safely be inferred.

## Before starting

Have:

- SSH access to the target server as the normal deploy user;
- `sudo` access for server configuration;
- GitHub access to the Core and client repositories;
- the exact root domain for this environment;
- the client repository URL;
- access to applicable third-party accounts (Resend, Telnyx, Zoom, DigitalOcean, Cloudflare, etc.);
- for production Core, a provisioned remote client database/application user or access to your remote DB operator account so you can create them during the DB pause.

Do not pre-invent Redis prefixes, Horizon process names, database recommendations, app paths, or queue lists. The launcher derives those.

## New environment

From a Core checkout containing the launcher:

```bash
bash scripts/operations/launch-client-environment.sh new
```

You may also provide the stable inputs up front:

```bash
bash scripts/operations/launch-client-environment.sh new \
  --environment production \
  --client-repo git@github.com:ImagineSocialGit/slam-dunk-crm.git \
  --root-domain slamdunkhomeloans.com \
  --topology managed_main_site \
  --main-site-type seo
```

The launcher prints the complete derived identity before mutating the target and asks for confirmation.

## Core staging database

Core staging uses a local isolated MySQL database.

The launcher:

- derives a staging database name and client-scoped application user;
- generates the application password without printing it;
- writes the credential directly to the selected-client `.env`;
- provisions the local database/user through `sudo mysql`;
- grants that application user only the local staging client database.

This staging database is disposable test/review data and is not authoritative client CRM data.

## Core production database

Core production uses the authoritative remote client database.

The launcher does **not** create remote databases/users. It pauses and tells you to provision them with your remote DB operator/admin account, then prompts for:

```text
remote DB host
remote DB port
client database name
client application DB username
client application DB password (hidden)
optional MySQL SSL CA path
```

Your global/operator account does not go into the client runtime environment.

## External provider pauses

The application deployment plan determines which pauses appear. The launcher brings the required Core callback hosts through Nginx, DNS, and TLS before running these provider-dashboard steps so a provider can reach the real HTTPS endpoint during setup.

A Zoom-enabled client, for example, receives the exact current Server-to-Server OAuth scopes, webhook endpoint, and native event subscriptions from the Webinars deployment contributor. After you complete the Zoom Marketplace work, the launcher asks for the related credential values with secrets hidden.

The same pattern applies to Resend, Telnyx, Spaces, Turnstile, and signed external Forms intake.

You can stop at any pause with Ctrl+C. The launcher records completed non-secret checkpoints and can resume later.

## Resume

The launcher prints the state-file path during `new`.

Resume with:

```bash
bash scripts/operations/launch-client-environment.sh resume ~/.local/state/engage/deployments/<state>.json
```

Completed phases and completed provider steps are not repeated unnecessarily.

## DNS/Nginx/TLS

After the application/runtime is ready, the launcher derives the Core-owned host list from the active modules and client URL configuration.

It generates one Nginx site pointing only those hosts at the Core `public/` directory.

It never points the root domain at Core simply because Core is installed.

The launcher prints exact A records such as:

```text
crm.example.com        -> SERVER_IP
webhooks.example.com   -> SERVER_IP
webinar.example.com    -> SERVER_IP
messaging.example.com  -> SERVER_IP
```

Only the hosts actually used by the current deployment are included.

After you create/update DNS, the launcher waits until every required host resolves to the confirmed server IP. It then installs Certbot packages when approved/needed and acquires/refreshes the Nginx certificate for the exact Core host list.

## Final verification

The launcher verifies:

- deployment-plan readiness;
- module migration status;
- `setup:validate`;
- Supervisor/Horizon process identity;
- Horizon status;
- Scheduler cron + `schedule:list`;
- CRM HTTPS response;
- CRM login HTTPS response.

Provider verification that requires a real provider event remains a deliberate final human smoke: for example sending a real staging-safe email/SMS, receiving a signed callback, or exercising a Zoom registration/ended-event path.

## Normal deployment after launch

Use:

```bash
bash scripts/operations/launch-client-environment.sh update /path/to/state.json
```

The update path:

- pulls only clean approved commits;
- installs production dependencies/builds assets;
- resolves new deployment requirements/provider steps;
- runs platform migrations;
- runs migrations only for already-installed module scopes;
- syncs presets;
- validates;
- refreshes Horizon/Scheduler/Nginx/TLS when needed;
- performs safe runtime verification.

It does not run the production test suite.

## Adding a module later

Enable/test the module in development, commit it in the client repository, and push it first.

Then run, for example:

```bash
bash scripts/operations/launch-client-environment.sh add-modules /path/to/state.json \
  --module scheduling
```

The launcher first resolves any new environment/provider setup obligations exposed by the pulled configuration. It then installs the requested module's schema/dependency closure through `modules:install`, syncs presets, validates, refreshes runtime/hosts, and verifies.

## Root-site ownership

When starting a new Core environment choose one:

```text
core_services_only
managed_main_site
```

`core_services_only` means the root website is external. The launcher must not modify it.

`managed_main_site` records that an SEO Site, Artist Site, or another managed application owns the root website. The current Core launcher still does not point that root domain at Core; the future higher-level site orchestrator will deploy the owning site application separately.