# Process Highway

Process Highway is a Core-owned, read-only composition surface for understanding configured business processes.

## v1 contract

- Process Highway is always reachable from the CRM navigation immediately below Contacts.
- It is not a source of truth and does not create, edit, or persist process definitions.
- It must render successfully when optional modules are disabled or their tables are absent.
- v1 uses current active FlowRoutes as an optional process source because Routes already express entry conditions and ordered consequences.
- Module-owned editors remain authoritative. When FlowRoutes is enabled, Process Highway links back to the Route editor.
- Scheduling, Webinars, Reporting, Messaging, Tasks, Relationships, Campaigns, and other modules are not dependencies of Process Highway.
- Point definitions from optional modules may be summarized as plain text when present, without importing those modules into Core.

## v1 presentation

Each process answers three human questions:

1. **Starts when** — the trigger or qualifying event.
2. **Then** — the ordered Route steps.
3. **Can lead to** — the durable actions or state changes visible in those steps.

Processes are grouped by Route category when available. The initial surface intentionally avoids process authoring, analytics overlays, and a large visual flowchart.

## Future layers

Future versions may add optional enrichments or performance overlays from module-owned read seams. Those additions must remain optional and must not make Process Highway unavailable when a contributing module is disabled.