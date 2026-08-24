# Process Highway

Process Highway is a Core-owned composition and visibility surface for understanding configured business processes.

It is not a source of truth, it does not execute automation, and it is not a second workflow engine.

## Responsibility boundary

The platform boundary is:

```text
Process Highway   How does the business process work?
Campaigns         Who qualifies for this ongoing messaging program?
Flow Routes       When this happens, execute this procedural sequence.
Automation Events Something happened.
Statuses/stages/tags/fields
                  What is true about this contact?
Tasks/Messaging/Campaigns/etc.
                  Concrete actions and outcomes.
```

Process Highway discovers and composes those independent mechanisms. Flow Routes do not own the Highway and are not required for a process to appear.

Use a Flow Route when ordering, branching, waiting, event synchronization, or multi-module orchestration matters. Do not add a Flow Route merely to connect a durable Campaign eligibility fact to that Campaign.

## Authority boundary

Owning modules remain authoritative for definitions, runtime state, and mutations. Core composes their graph fragments through:

```text
App\Support\ProcessHighway\Contracts\ProcessHighwayContributor
```

Contributors remain registered under:

```text
process_highway.contributors
```

`ProcessHighwayReadService` resolves tagged contributors and delegates typed graph composition to `ProcessHighwayGraphComposer`. `ProcessHighwayMapBuilder` then assembles connected graph fragments into business highways. None of these Core services query Campaign, FlowRoute, Workflow, Relationship, Messaging, Task, Webinar, Scheduling, or other module tables directly.

Good:

```text
Campaigns -> Campaign-owned graph segment
FlowRoutes -> Route-owned graph segment
Core -> validate, merge, connect, sort, filter metadata, and expose business highways
Highway navigation -> owning module's exact GET editor
Future bounded Highway action -> owning module's declared capability
```

Bad:

```text
Core ProcessHighwayReadService -> campaigns table
Core ProcessHighwayReadService -> flow_routes table
Campaigns -> FlowRoutes solely for Highway presentation
FlowRoutes -> Campaigns solely for Highway presentation
Process Highway -> execute or mutate module state directly
Process Highway -> graphical Flow Route editor
```

An optional module may be disabled without making Process Highway unavailable.

## Graph segments versus business highways

A contributor returns `ProcessHighwayContribution` objects. Each contribution is a module-owned implementation segment, not automatically a complete business process.

Examples:

```text
Campaign segment
    Status: Past Client -> eligibility -> Past Client Nurture -> message journey -> outcomes

Flow Route segment
    High-intent reply -> inspect facts -> branch -> create task -> change status -> completion
```

Batch 6B exposes those contributions as:

```text
segments[]
segment_count
mechanism_node_key
segment_keys on nodes and lanes
segment_key on edges
```

It does not expose the previous ambiguous `processes[]`, `process_count`, or compatibility `groups` projection.

`ProcessHighwayMapBuilder` connects segments that share stable semantic nodes inside the same subject and lane. Each connected component becomes one business highway:

```text
highways[]
highway_count
```

This creates the intended relationship:

```text
business highway
    entry facts
        -> Campaign eligibility program
        -> Flow Route orchestration
        -> concrete actions
        -> durable outcomes and exits
```

Shared nodes never connect segments across lanes. A standard-contact status and a relationship-scoped use of that same status therefore remain separate highways.

Highway keys are deterministic hashes of their lane and connected segment membership. They identify the composed read model only; they are not persisted authority.

## Typed graph contract

Each contribution declares:

- source module;
- stable segment key and mechanism node;
- subject;
- standard or relationship-scoped lane;
- entry and exit/consequence nodes;
- graph nodes;
- directed graph edges;
- state, details, and machine-readable attributes;
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

Relationship-scoped processes therefore do not contaminate the standard-contact map.

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

Durable facts use a stable `qualifier` appearance regardless of whether one segment reads the fact and another segment produces it. The directed edge communicates whether the fact qualifies, starts, or results from that segment. This allows one semantic status/tag/stage node to connect otherwise independent module-owned fragments without conflicting contextual node roles.

The vocabulary supports:

- multiple entry ramps converging on one mechanism;
- compound AND/OR qualification;
- one entry fact starting several mechanisms;
- ordered actions;
- conditional branches;
- terminal exits;
- consequences that change durable facts;
- an outcome leading into another mechanism.

## Stable semantic nodes

Stable business facts and mechanism identities use shared semantic node keys.

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

A contributor may publish a reference-only appearance of a semantic node it does not own. The composer merges compatible appearances, retains an authoritative definition when one exists, records every participating segment, and combines exact edit targets.

That is how a status or tag consequence can feed Campaign eligibility without Core querying either module's tables or inventing a dependency between those modules.

Conflicting owners, roles, or authoritative definitions for one semantic key fail composition instead of being guessed in Blade.

## Business-highway composition

Within each subject and lane, two segments are connected when they participate in the same semantic node. The builder computes deterministic connected components from those shared memberships.

For every business highway, the read model exposes:

- subject and lane identity;
- connected segment and source identities;
- shared entry ramps;
- terminal outcomes/exits;
- nested module-owned segments;
- nested journey and branch nodes;
- qualifier values used for Status, Tag, Relationship, Webinar Outcome, Source, and future filters;
- searchable business text;
- safe authoritative navigation targets.

An exit candidate is terminal only when it has no outgoing edge inside the connected highway. If a Campaign segment exits directly into a Route mechanism, that Route is part of the center road and only the actual downstream completion remains in the terminal-outcome column.

The composer never invents missing transitions. If no contributor establishes a connection, the UI displays separate highways or a visible gap rather than pretending the business process is configured.

## Ownership, wayfinding, and navigation

Every visible segment, node, and edge declares:

- owner module key;
- owner label;
- module wayfinding tone;
- at least one exact authoritative edit target;
- resource identity;
- container identity when the editable resource is nested;
- link or inline capability mode.

Color communicates ownership, not urgency. Urgency or failure styling remains separate and must visually win over module tone.

An edit target distinguishes the module that owns the visible fact from the module capability that edits its use in a segment. For example:

```text
Status qualifier node
    visible fact owner: Workflow
    Campaign criterion editor: Campaigns eligibility capability
    safe navigation: Campaign editor
```

Batch 6B only follows `link` targets using `GET`. Inline mutation targets remain present as capability metadata for Batch 6C but are never rendered as ordinary navigation URLs.

Campaign eligibility nodes and edges therefore include both:

- the bounded inline capability target;
- the Campaign editor link.

More complex Flow Route changes continue to link to the exact Route or Point editor.

## Campaign graph projection

Campaigns contributes every non-archived Campaign as an implementation segment.

Automatic Campaigns expose:

- durable eligibility fact nodes;
- OR gateways for multiple values inside one criterion;
- an AND gateway across criterion types;
- `not eligible -> eligible` entry;
- Campaign mechanism identity;
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

FlowRoutes contributes current active Routes as procedural orchestration segments.

Each Route exposes:

- a semantic trigger node;
- Route mechanism identity;
- active Points in runtime order;
- Point ownership from the automation authoring registry/capability;
- ordinary next edges;
- branch edges and readable branch conditions;
- no-match/default outcomes;
- terminal completion;
- durable fact consequences such as status, tag, and relationship-stage changes;
- links into a Campaign mechanism when a Point starts that Campaign;
- exact Route and Point edit identities.

Route sequencing and branch edges remain FlowRoutes-owned. A cross-module Point uses the action owner's wayfinding tone while its edit target identifies the exact Point inside the Route container.

Flow Routes are intentionally not the center of Process Highway. A Highway with only Campaign eligibility remains complete and useful when FlowRoutes is disabled.

## Batch 6B surface

The graph-native surface renders:

```text
entry ramps -> what happens automatically -> outcomes and exits
```

It includes:

- subject selection;
- standard versus relationship-scoped lane filtering;
- independently combinable contributed qualifier filters;
- text search across entrances, actions, outcomes, and program names;
- one card per composed business highway;
- connected Campaign and Flow Route segments inside the same highway;
- module-tone wayfinding on every visible owned item;
- progressive disclosure for implementation details;
- exact safe navigation to authoritative editors;
- responsive horizontal/vertical map treatment;
- no-result and optional-module empty states.

The primary information hierarchy is business meaning first and implementation ownership second. The surface does not group by Campaigns versus Flow Routes.

## Batch 6B non-goals

Batch 6B does not:

- execute automation;
- persist a second business-process definition;
- add a graphical Flow Route editor;
- submit inline mutation capabilities;
- change Campaign or FlowRoute runtime behavior;
- change client presets or configuration;
- change Messaging consent/scope behavior;
- add a migration, queue, or job.

## Remaining refactor roadmap

1. Batch 6C — bounded actionability
   - preserve exact owner-editor links for all complex changes;
   - wire only genuinely bounded owner-declared inline actions;
   - Campaign eligibility is the first candidate;
   - do not turn Highway into a workflow editor.
2. Batch 6D — Slam Dunk acceptance and polish
   - cold-lead Status + Old Lead convergence;
   - Past Client process;
   - VA attended/missed durable-outcome entry;
   - reply orchestration at the correct point in the highway;
   - processes that do not use Flow Routes;
   - relationship/Realtor separation;
   - optional-module degradation;
   - final visual-density and business-language pass.
3. Messaging scope/consent cleanup
   - channel + purpose becomes the hard marketing-permission boundary;
   - scope becomes compatibility/context metadata.
4. Preset/bootstrap hardening and portable stable-key Campaign JSON.
5. Final acceptance and Slam Dunk go-live.