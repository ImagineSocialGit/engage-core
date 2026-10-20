# Cross-Platform Deployment and Database Topology

## Scope and ownership

This is the **sole architecture owner** of the staging/production data topology across Engage Core, Engage SEO Sites, and Engage Artist Sites. The Engage Core deployment orchestrator consumes this boundary but is not the deployment authority for SEO Sites or Artist Sites.

The documented default topology is a design contract; each actual target must still be verified before changing a live database or provider connection. Any exception must be documented for that particular deployment.

## Database topology

| Platform | Development | Staging | Production |
| --- | --- | --- | --- |
| Engage Core | Local development database | Separate local, isolated and disposable client database | Remote authoritative client database |
| Engage SEO Sites | Local development database | Production-content preview using the shared production database | Remote authoritative client database |
| Engage Artist Sites | Local development database | Production-content preview using the shared production database | Remote authoritative client database |

SEO/Artist staging is a **preview surface over live production content**, not an ordinary disposable development database.

## Preview staging safeguards

For SEO/Artist staging that shares production content:

- Use credentials distinct from the production application. Prefer **read-only** database privileges.
- Do not execute migrations, destructive refreshes, seeders, or production-side-effect workers from preview staging.
- Treat schema changes as production-database changes; deploy them through a backward-compatible production migration sequence.
- Preserve preview-only access controls while allowing deliberate preview of draft or unpublished content.
- Do not assume a staging hostname makes a database disposable.

Core staging is deliberately isolated and can use a local client database and local client-scoped application account. The Core staging launcher may provision those local resources through an authorized `sudo mysql` flow. Core production connects to the remote authoritative client database; remote provisioning remains an operator-controlled step unless a future approved integration explicitly owns it.

## Database identities and privileges

Keep the operator/admin database account separate from application credentials.

- An administrator may have controlled access across client databases.
- Each production application must use its own client-scoped database user, with privileges limited to that client's database rather than unrelated clients.
- Shared-preview SEO/Artist staging should use a separate, preferably read-only credential.
- Do not reuse one client's production credentials as another client's identity, or grant application-level accounts server-wide administration rights.

## Platform orchestration boundary

The Core launcher deploys Core-owned services and records whether the root website is externally owned or managed through another application. It must never point the root website to Core as a side effect of provisioning CRM, Webinars, Messaging, Scheduling, or webhook hosts.

A future higher-level deployment orchestrator may coordinate separate Core and SEO/Artist deployment entry points, but must preserve the distinct database modes and prohibit production-data mutations from shared-data preview staging.