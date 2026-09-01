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

The graph read model exposes those contributions as:

```text
segments[]
segment_count
mechanism_node_key
segment_keys on nodes and lanes
segment_key on edges
```

It does not expose the previous ambiguous `processes[]`, `process_count`, or compatibility `groups` projection.

`ProcessHighwayMapBuilder` connects segments only through business entrances and explicit handoffs inside the same subject and lane. Each connected component becomes one business highway:

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

Segments connect when they share an entry node, when one segment produces a non-contact-fact node used as another segment's entry, or when an `exits_to` edge explicitly targets another mechanism. Merely producing the same downstream status, tag, relationship stage, Campaign state, or other outcome does not connect otherwise unrelated processes.

This directionality is essential. Cold Lead, Past Client, and Webinar reply Routes may all create an Engaged status, Hand Raiser tag, task, or Campaign-family outcome without becoming one giant highway. Shared downstream consequences remain visible beside the mechanism that causes them.

Connections never cross lanes. A standard-contact status and a relationship-scoped use of that same status therefore remain separate highways.

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
inbound_messaging:reply_profile:cold_lead_nurture
campaigns:campaign:cold_lead_nurture
flow_routes:route:cold_lead_high_intent_reply_routing
```

A contributor may publish a reference-only appearance of a semantic node it does not own. The composer merges compatible appearances, retains an authoritative definition when one exists, records every participating segment, and combines exact edit targets.

Status and tag appearances remain reusable facts, but an output does not automatically merge into every process that happens to read the same fact. The entry-ramp inspector may show the producing Flow Route as one configured way that fact can be applied without folding the producer into the selected audience's main road.

Conflicting owners, roles, or authoritative definitions for one semantic key fail composition instead of being guessed in Blade.

## Business-highway composition

Within each subject and lane, the builder computes deterministic components from shared entrances and explicit non-fact handoffs. General shared-node membership is not a composition rule.

For every business highway, the read model exposes:

- subject and lane identity;
- connected segment and source identities;
- shared entry ramps;
- terminal outcomes/exits;
- nested module-owned segments;
- compact mechanism-owned actions;
- outcomes attached to the exact mechanism or action that causes them;
- qualifier values discoverable when they either appear on a root entry ramp or are produced automatically by a contributed consequence edge;
- per-option entry and producer Highway membership so presentation can distinguish "what starts here" from "how contacts can get here";
- searchable business text;
- safe authoritative navigation targets.

An entry candidate is a top-level ramp only when it has no incoming edge inside the connected highway. A scoped reply can therefore start a Flow Route without being misrepresented as a second top-level entrance when the Campaign message journey already leads into that reply.

An exit candidate is terminal only when it has no outgoing edge inside the connected highway. If a Campaign segment exits directly into a Route mechanism, that Route remains part of the center road while the actual downstream completion is attached to the action that causes it.

The composer never invents missing transitions. If no contributor establishes a connection, the UI displays separate highways or a visible gap rather than pretending the business process is configured.

## Audience-first surface

The CRM surface does not render any process until the user selects at least one contact scope or entry-fact filter. Free-text search refines an already selected audience; it is not an audience by itself.

The available filters include relevant facts from both directions:

- a fact used by a root entry ramp because something automatic can happen from it;
- a fact produced by a contributed consequence edge because something automatic can arrive there.

Actual process matching still uses root entry requirements only. Selecting an output-only Status or Tag therefore does not make its producing Route appear as though that Route starts from the resulting fact. Its inspector instead explains the configured inbound source. Removed-tag consequences are not exposed as selectable present-tag facts.

For compatibility, an option's existing `highway_keys` field remains entry-only. `entry_highway_keys` makes that meaning explicit, while `producer_highway_keys` identifies automatic inbound paths.

The visual flow is top to bottom:

```text
selected entry ramps
        ↓
Campaign and/or Flow Route mechanisms
        ├─ outcome beside its triggering action
        └─ exit beside its triggering action
```

Campaigns and Flow Routes receive visible mechanism badges because they are the user-facing executable mechanisms. Core, Workflow, and other fact owners remain authoritative in the graph contract but do not display implementation-oriented module badges on entry ramps or outcomes.

Campaign eligibility gateways and criteria are intentionally omitted from the Campaign card. The same conditions already appear as the Highway's entry ramps.

### Contextual status wayfinding

The Contact workspace may link to Process Highway with the contact's current Status:

```text
/process-highway?status=past_contact
```

This is a read-only wayfinding hint, not an execution request. The controller carries the Status key into the audience filter and the Highway answers the same question the operator would answer manually:

```text
What happens automatically to contacts with this status?
```

The Status may be preselected even when no configured Highway currently uses it. When other highways exist, that lands on the normal zero-match state; when no business highway exists at all, the general empty state remains authoritative. Either way, the operator can distinguish "nothing is configured for this status" from "I could not find the right automation screen."

The contextual launcher does not mutate Contact, Campaign, Flow Route, Messaging, or other runtime state. It also does not add a hidden dependency from Core to those modules.

## Entry-ramp inspection

Discoverable Status and Tag facts open a bounded read-only inspector, whether the fact is a process entrance, an automatic outcome, or both. Owner modules contribute current counts and ordinary application paths through:

```text
App\Support\ProcessHighway\Contracts\ProcessHighwayEntryRampContributor
process_highway.entry_ramp_contributors
```

Core contributes Tag counts and import capability. Workflow contributes Status counts plus direct Contact editing and import capability. The shared inspector discovers configured Flow Routes from graph consequence edges and links to their authoritative Route editors. Output-only facts receive inspectors even when they have no downstream process.

Automatic Flow Route sources also expose one `highway_targets` entry for every Highway occurrence containing the consequence edge. Each target carries the Highway key, the exact edge key, a stable exit anchor, the originating entry selection, and a URL reserved for scroll-and-highlight navigation. A shared downstream segment therefore retains separate destinations for separate originating audiences instead of collapsing them into one link.

Decorated outcome records carry the same stable `exit_anchor` plus a `fact_target` for the resulting Status, present Tag, or other query-safe criterion. Removed-Tag outcomes intentionally have no fact target because they do not represent the selectable presence of that Tag.

The count is the number of contacts whose current facts match the selected ramp. The application-path list explains how the fact can currently be assigned. It is not historical attribution.

The same inspector also derives forward impact from the already composed Highway graph. For the selected fact it shows:

- exact processes whose complete entrance is satisfied by that one fact;
- partial processes that still require additional facts or relationship context;
- every visible owning mechanism in those business highways;
- visible ordered actions and branches;
- durable outcomes such as status/tag changes and Campaign starts/stops;
- Campaign message journeys and reply-routing handoffs when their contributors expose them;
- exact GET links back to the owning editors.

This forward-impact summary is generic graph composition. `ProcessHighwayEntryRampInspector` does not query Campaigns, Flow Routes, Messaging, Tasks, Inbound Messaging, or other owning-module tables to manufacture consequences. It summarizes only nodes, edges, segment effects, and navigation targets already contributed to the read model.

The two inspector directions are intentionally distinct:

```text
What happens with this fact?
    selected fact -> exact/partial processes -> actions/outcomes

How can this fact be applied?
    configured editors/imports/Routes -> selected fact
```

Tag rows currently contain only Contact, tag, and timestamps, and Core fact-change events are deliberately transient. A future request for source-by-source historical Tag counts would therefore require explicit provenance persistence; Process Highway does not infer or fabricate it.

### Bounded entry-ramp authoring launchers

Entry-ramp inspectors may receive optional owner-provided GET launchers through `ProcessHighwayEntryRampActionContributor`. The Highway validates/presents those links but does not mutate owner state itself.

FlowRoutes contributes the first launcher for Status entry ramps: **Automate something for this status**. It deep-links to Flow Routes with the Status preselected in Create Route. Route creation remains safe because the new Route is unassigned until the operator explicitly chooses it in Flow Routes Assignments. If FlowRoutes is disabled, its contributor is absent and the Highway exposes no FlowRoutes authoring action.

This is the preferred pattern for future Highway action affordances: contextual GET navigation into the owning module, never a Highway-owned POST or duplicated business-rule implementation.

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

The Highway follows `link` targets using `GET`. Inline mutation targets remain capability metadata and are never rendered as ordinary navigation URLs.

Campaign eligibility nodes and edges therefore include both:

- the bounded inline capability target;
- the exact Campaign Start editor link.

Campaign journey nodes link directly to the Campaign message-review modal. Campaign completion nodes link to Campaign Review. These are owner-editor handoffs; Process Highway does not host or submit the Campaign forms.

Reply-profile nodes link first to InboundMessaging's authoritative **Reply Handling** workspace with the exact profile selected. Campaign and Flow Route editors remain secondary context targets. The Highway displays the business handoff but does not define reply phrases, intents, or execution consequences.

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
- reply-profile handoff nodes declared by the current immutable MessageChainVersion;
- completion;
- configured ineligible behavior;
- re-entry cycle when allowed.

Manual Campaigns expose explicit enrollment as their entry. Saved targeting criteria do not falsely appear as an automatic start.

Campaign reply-profile discovery reads the Campaign-selected published MessageChainVersion first. Legacy Campaign message-template assignments remain a compatibility fallback only. The resolver never reads message payloads and does not make Campaigns responsible for inbound reply execution.

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

For `inbound_message.normal_reply`, a Route whose route-level entry conditions positively scope `reply_profile_key` uses those reply-profile semantic nodes as its visible trigger. Older definitions that put this positive scope in a branch condition retain the same projection as a compatibility fallback. Route-level entry conditions take precedence because they decide whether the Route starts at all. The Route does not use the global inbound-reply event as its composition identity. This prevents unrelated reply Routes from collapsing into one highway and lets the Route attach after the Campaign message journey that emits the same profile.

Unscoped automation-event Routes continue to expose the ordinary automation-event trigger. A Flow Route with multiple positive reply-profile values deliberately represents one orchestration mechanism shared by those business entrances.

Route sequencing and branch edges remain FlowRoutes-owned. A cross-module Point uses the action owner's wayfinding tone while its edit target identifies the exact Point inside the Route container.

Flow Routes are intentionally not the center of Process Highway. A Highway with only Campaign eligibility remains complete and useful when FlowRoutes is disabled.

## Business-map surface

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

The primary information hierarchy is business meaning first and implementation ownership second. The surface does not group by Campaigns versus Flow Routes. Inside one connected highway, eligibility-driven Campaign mechanisms sort before scoped reply orchestration, and reply acknowledgements follow the main orchestration. The ordering is presentation metadata only and does not change runtime execution.

## Fact navigation and exact exits

Durable outcome facts link back to Process Highway with the produced qualifier selected. A fact selection is a view of the matching contact set, not another process definition.

The fact inspector is deliberately inbound-only. It shows the current matching-contact count and the configured ways that the fact can be applied. Automatic sources link to their authoritative owner and, when the same source produces the fact from one or more composed Highways, expose one exact-exit link per originating Highway. Manual, imported, and other non-automatic sources remain secondary context. Downstream programs, recommendations, and entry actions stay on the main Highway surface rather than being repeated in the inspector.

An exact-exit destination uses both parts of the read-model contract:

```text
?highway=<semantic highway key>#<exact exit anchor>
```

The query parameter restores the originating Highway's subject, lane mode, relationship scope, and entry facts, then pins that Highway as the exact result. The fragment expands the Highway, waits for the expanded layout to settle, moves focus to the precise outcome, centers it in the viewport, and applies a temporary visual emphasis. Invalid Highway keys and malformed or missing anchors fail safely without changing the ordinary filter experience.

## Campaign actionability and immutable authoring

Process Highway remains a map while its Campaign destinations are genuinely actionable:

```text
Campaign eligibility or ineligible behavior
    -> Campaign Setup / Start

Campaign message journey
    -> Campaign Setup / Messages modal

Campaign completion or lifecycle review
    -> Campaign Setup / Review
```

Campaign Setup remains Campaign-owned. It hosts the Campaign eligibility authoring service, Campaign lifecycle actions, and the reusable Messaging-owned message editor carousel. Saving message copy publishes a new immutable MessageTemplateVersion and a replacement Campaign-selected MessageChainVersion.

The Current Schedule popup edits human labels, ordering, and timing without exposing payloads. Schedule changes publish a replacement immutable MessageChainVersion for future enrollments. Existing enrollments and scheduled messages keep their original pins.

## Non-goals

Process Highway does not:

- execute automation;
- persist a second business-process definition;
- add a graphical Flow Route editor;
- submit mutations from Process Highway;
- change Campaign or FlowRoute runtime behavior;
- change client presets or configuration;
- change Messaging consent/scope behavior;
- add a migration, queue, or job.

## Batch 6D acceptance cases

The focused business-map contract proves:

- Prospect – Nurture plus Old Lead converges into the cold-lead Campaign;
- Past Client remains its own eligibility-driven Campaign process;
- VA attended and missed durable outcomes enter their respective Campaigns and converge only where a shared reply mechanism is intentional;
- scoped reply Routes attach after the Campaign message journey instead of appearing as generic top-level reply highways;
- Campaign-only processes remain complete without a Flow Route;
- Realtor reply orchestration stays in the Realtor relationship lane;
- disabling optional process modules leaves the Highway surface available;
- visible mechanisms retain their authoritative owner navigation.

## Remaining refactor roadmap

1. Reply Handling + Message Template authoring integration.
2. Dev visual acceptance and bounded follow-up fixes after the full-site refresh.
3. Preset/bootstrap hardening and portable stable-key Campaign JSON.
4. Final acceptance and Slam Dunk go-live.

Messaging consent now uses channel + purpose as the hard permission boundary; scope remains compatibility/context/audit metadata.