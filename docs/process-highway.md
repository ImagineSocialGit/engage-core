# Process Highway

Process Highway is a Core-owned composition surface for understanding and navigating configured business processes.

It is not a source of truth and it is not a second workflow engine.

## Authority boundary

Owning modules remain authoritative for their definitions, runtime state, and mutations. Core composes their graph fragments through:

```text
App\Support\ProcessHighway\Contracts\ProcessHighwayContributor
```

Contributors remain registered under:

```text
process_highway.contributors
```

`ProcessHighwayReadService` resolves tagged contributors and delegates composition to `ProcessHighwayGraphComposer`. Neither service queries Campaign, FlowRoute, Workflow, Relationship, Messaging, Task, Webinar, Scheduling, or other module tables directly.

Good:

```text
Campaigns -> Campaign-owned graph fragment
FlowRoutes -> Route-owned graph fragment
Core -> validate, merge, sort, and expose the shared graph
Highway action -> owning module's exact editor or bounded authoring capability
```

Bad:

```text
Core ProcessHighwayReadService -> campaigns table
Core ProcessHighwayReadService -> flow_routes table
Campaigns -> FlowRoutes solely for Highway presentation
FlowRoutes -> Campaigns solely for Highway presentation
Process Highway -> mutate module state directly
```

An optional module may be disabled without making Process Highway unavailable.

## Typed graph contract

Contributors return `ProcessHighwayContribution` instances rather than unvalidated presentation arrays.

Each contribution declares:

- source module;
- stable process key and process node;
- subject;
- standard or relationship-scoped lane;
- entry and exit/consequence nodes;
- graph nodes;
- directed graph edges;
- process state, details, and machine-readable attributes;
- module ownership and authoritative editing metadata.

The first subject is:

```text
contacts
```

The contract supports additional subjects later without changing contributor ownership.

Contact lanes are explicit:

```text
contacts:standard
contacts:relationship
contacts:relationship:{relationship_key}
```

Relationship-scoped processes therefore do not contaminate the standard-contact highway.

## Graph vocabulary

Node roles:

```text
trigger
qualifier
gateway
process
action
consequence
exit
```

Edge roles:

```text
requires
starts
continues
branch
consequence
exits
exits_to
```

This vocabulary supports:

- multiple entry ramps converging on one process;
- compound AND/OR qualification;
- one entry fact starting several processes;
- ordered actions;
- conditional branches;
- terminal exits;
- consequences that change durable facts;
- an exit or action leading into another process.

## Stable semantic nodes

Stable business facts and process identities use shared semantic node keys.

Examples:

```text
workflow:status:prospect_nurture
core:contact_tag:present:Old%20Lead
relationships:relationship:realtor:stage:engaged_agent
webinars:series:va-homebuyer-game-plan:outcome:attended
automation:event:inbound_message.normal_reply
campaigns:campaign:cold_lead_nurture
flow_routes:route:cold_lead_high_intent_reply_routing
```

A contributor may publish a reference-only appearance of a semantic node it does not own. The composer merges compatible appearances, retains the authoritative definition when one exists, records every participating process, and combines their edit targets.

That is how a status or tag consequence can visibly feed Campaign eligibility without Core querying either module's tables or inventing a new dependency between them.

Conflicting owners, roles, or authoritative definitions for one semantic key fail composition instead of being guessed in Blade.

## Ownership, wayfinding, and editing

Every visible process, node, and edge must declare:

- owner module key;
- owner label;
- module wayfinding tone;
- at least one exact authoritative edit target;
- resource identity;
- container identity when the editable resource is nested;
- link or inline capability mode.

The composer resolves tones from the existing module wayfinding configuration. Color communicates ownership, not urgency.

Current relevant tones include:

```text
Core                 slate
Messaging            sky
Inbound Messaging    blue
Tasks                emerald
Relationships        cyan
Workflow             amber
Flow Routes          orange
Campaigns            rose
Broadcasts           purple
Webinars             stone
```

Urgency or failure styling remains separate and must visually win over module tone.

An edit target distinguishes the module that owns the visible fact from the module capability that edits its use in a process. For example:

```text
Status qualifier node
    visible fact owner: Workflow
    Campaign criterion editor: Campaigns eligibility capability
```

This permits inline-safe Campaign eligibility authoring while preserving the authority boundary.

Inline mode is a capability declaration, not permission for Core to mutate another module. The owning module supplies the endpoint, HTTP method, resource identity, and capability key. More complex definitions may expose an exact deep link instead.

## Campaign graph projection

Campaigns contributes every non-archived Campaign.

Automatic Campaigns expose:

- durable eligibility fact nodes;
- OR gateways for multiple values inside one criterion;
- an AND gateway across criterion types;
- `not eligible -> eligible` entry;
- Campaign process identity;
- message-journey action;
- completion;
- configured ineligible behavior;
- re-entry cycle when allowed.

Manual Campaigns expose explicit enrollment as their entry. Saved targeting criteria do not falsely appear as an automatic start.

Criterion fact ownership is preserved:

```text
status             Workflow
tag/source         Core
relationship       Relationships
webinar_outcome    Webinars
```

Campaigns owns the edges that make those facts Campaign eligibility.

## Flow Routes graph projection

FlowRoutes contributes current active Routes.

Each Route exposes:

- a semantic trigger node;
- Route process identity;
- active Points in runtime order;
- Point ownership from the automation authoring registry/capability;
- ordinary next edges;
- branch edges and readable branch conditions;
- no-match/default outcomes;
- terminal completion;
- durable fact consequences such as status, tag, and relationship-stage changes;
- links into a Campaign process when a Point starts that Campaign;
- exact Route and Point edit identities.

Route sequencing and branch edges remain FlowRoutes-owned. A cross-module Point uses the owning module's wayfinding tone while its edit target identifies the exact Point inside the Route container.

## Composition output

The composed read model exposes:

```text
schema_version
subjects[]
    lanes[]
processes[]
nodes[]
edges[]
subject_count
lane_count
process_count
node_count
edge_count
source_count
```

Nodes also expose every participating process key. Shared facts and cross-process destinations can therefore be rendered once while retaining all authoritative edit destinations.

The temporary `groups` projection keeps the existing pre-6B Blade screen operational. Batch 6B will render the graph directly and remove that adapter.

## Batch 6A boundary

Batch 6A establishes the graph and contributor contract only.

It does not:

- rebuild the Process Highway screen;
- add filtering/navigation UI;
- wire inline forms into the Highway;
- change Campaign or FlowRoute runtime behavior;
- change Slam Dunk client definitions;
- change Messaging consent/scope behavior;
- add a migration;
- add queue/job behavior.

## Remaining refactor roadmap

1. Batch 6B — render the contact highway with subject, standard/relationship scope, qualifiers, convergence, branches, exits, wayfinding, and exact navigation.
2. Batch 6C — wire bounded inline authoring where declared; retain exact owner-editor links for complex changes.
3. Batch 6D — Slam Dunk acceptance/polish for cold-lead, Past Client, VA attended/missed, reply orchestration, relationship separation, and optional-module degradation.
4. Messaging scope/consent cleanup — channel + purpose becomes the hard marketing-permission boundary.
5. Preset/bootstrap hardening and portable stable-key Campaign JSON.
6. Final acceptance and Slam Dunk go-live.