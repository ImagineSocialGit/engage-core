# Commerce Module

Commerce is a current universal module with a provider-history foundation already present in the repository.

Current implementation owns normalized customer, product, order, order-item, and order-event records. The approved next direction expands Commerce into the provider-neutral storefront, purchasing, inventory-orchestration, and purchase-history capability used by Engage Core and optional modules.

No single commerce, payment, point-of-sale, inventory, or fulfillment provider is an architectural default.

A concise definition:

> Commerce owns the provider-neutral catalog identity, Engage Core storefront/presentation, provider-authoritative price/promotion projection, checkout orchestration, purchase reconciliation, inventory-effect orchestration, purchase history, promotion/source attribution, and cross-provider commerce contracts that optional modules use without owning provider-specific payment, discount-rule execution, warehouse, shipping, or deep store-operation internals.

## Current implementation status

Implemented foundation:

```text
commerce_customers
commerce_products
commerce_orders
commerce_order_items
commerce_order_events

CommerceCustomer
CommerceProduct
CommerceOrder
CommerceOrderItem
CommerceOrderEvent

CommerceModuleServiceProvider
```

Current limitations:

```text
no Commerce public routes or storefront surface
no product-variant model
no Commerce offer model
no provider contracts or provider manager/role registry
no normalized multi-provider product/variant mapping
no provider-authoritative pricing/promotion read model or resolver
no durable promotion/source attribution seam
no inventory-effect/adjustment orchestration
no configured inventory-authority resolution
no provider adapters for the planned Commerce seams
no provider-backed cart/checkout orchestration
no verified commerce-provider webhook handlers
no provider-neutral purchase-confirmed public signal
no Commerce Project State transfer section
no Commerce CRM operations
```

The existing tables are a useful normalized purchase-history base. They are not yet sufficient for the approved provider-neutral storefront, cross-provider inventory coordination, or optional-module purchasing flows.

## Product barometer

Commerce should follow the Engage Core product barometer:

```text
If the client-facing task cannot realistically be completed in Engage Core in 10-15 minutes total, it should usually not be a client-facing workflow.
```

Appropriate client/admin Commerce work:

```text
See what a Contact purchased.
Review recent reconciled orders.
Create or manage a focused Engage Core public offer/storefront presentation.
Associate an offer with one or more sellable variants.
Publish an eligible offer.
Review inventory availability/projection status.
Review the current customer-facing price, sale state, and provider-backed promotion that the Engage Core storefront will present.
Open the authoritative provider record when deeper store, promotion-rule, or fulfillment work belongs there.
```

Operator/developer work:

```text
Configure installed provider packages and credentials.
Bind provider capabilities/roles for the client ecosystem.
Define provider reconciliation and idempotency policy.
Map provider customers to Core Contacts.
Map provider catalog identities to canonical Commerce products/variants.
Choose the client-configured public Commerce host.
Configure inventory authority and external sales sources.
Configure optional Event and Experience relationships through public seams.
```

Commerce should not ask clients to maintain a second full operations platform when a mature provider already owns payment processing, shipping, warehouse/fulfillment, tax, fraud, or deep store administration well.

Commerce should also avoid forcing clients to adopt a separate middleware/integration SaaS merely to connect providers that Engage Core can integrate directly through its own provider-neutral seams.

## Architectural north star

Commerce is the experience and orchestration layer, not a replacement for every specialized external commerce system.

Preferred shape:

```text
Customer / operator
    -> Engage Core storefront and CRM experience
    -> Commerce provider-neutral orchestration
    -> one or more installed provider packages
    -> external systems performing the specialized work they own
```

Engage Core should own the parts where its combined CRM/module context creates unique value:

```text
custom storefront/presentation
CRM-aware buying flows
provider-authoritative price/discount/offer presentation
cross-module purchase meaning
normalized purchase history
cross-provider product identity
inventory-effect orchestration
promotion/source attribution
cross-provider reconciliation
automation/read-model outcomes
```

External providers may remain authoritative for one or more of:

```text
payment processing
secure payment-data handling
order creation
pricing rules
promotion/discount eligibility and calculation
inventory quantity
warehouse operations
shipping
returns
tax
fraud
deep store administration
point-of-sale execution
```

The exact authority split is client configuration, not a universal vendor assumption.

## Provider-role model

Commerce must support more than one provider in the same client ecosystem.

A client may use one provider for several roles or different providers for different roles.

Conceptual provider roles/capabilities may include:

```text
catalog authority/source
pricing authority
promotion/discount authority
inventory authority
online checkout/order provider
payment processor
order/fulfillment operations provider
in-person sales/POS source
external marketplace/sales source
```

Examples are illustrative only:

```text
one provider may own catalog + inventory + online checkout + fulfillment
another provider may produce in-person sales
a separate payment provider may process online payment
```

Do not reduce Commerce to one global `selected_provider` when the implemented workflows require multiple concurrent providers.

Provider configuration should bind capabilities/roles, not hard-code vendor names into Commerce controllers or vertical modules.

A single provider package may implement several Commerce contracts.

Several provider packages may be installed and active at once.

## Responsibility

Commerce should answer:

```text
Which canonical product and variant is this?
Which external provider identities represent it?
Which Engage Core offer/storefront presentation exposes it?
May that offer be published?
What authoritative price, sale price, discount, or promotion state should that storefront present now?
Which provider-backed checkout, promotion context, or transaction was initiated?
Which authoritative order and order items were reconciled?
Which Core Contact or provider customer owns the purchase?
What source/promotion attribution should be retained for business reporting and automation?
What inventory effect did the business activity create?
Does that effect require an authoritative provider mutation or only reconciliation?
What provider-neutral purchase/inventory outcome should consumers receive?
```

Commerce stays vertical-neutral.

It may support:

```text
merchandise
VIP or Experience packages
service packages
digital products
course purchases
appointment add-ons
pet-service packages
music fan products
general external commerce purchase history
```

Vertical modules own the vertical meaning of those purchases.

## Storefront and transaction boundary

Engage Core may own the public storefront/presentation layer.

That can include:

```text
product/offer discovery
custom layouts and presentation
CRM-aware content
variant selection
authoritative price/sale/promotion presentation
cart presentation when useful
cross-module context
custom calls to action
provider-backed promotion activation or deep-link handoff
provider-backed checkout initiation
```

Commerce should not require clients to build the customer experience inside a provider theme/template environment when Engage Core can present it better.

When an external provider is configured as pricing or promotion authority, the Engage Core storefront should display that provider's current customer-facing price, compare-at/sale state, discount eligibility, or promotion state through a provider-neutral projection/read seam. Core may control how that state is styled and explained, but it must not silently invent a competing price or duplicate the provider's discount-rule engine.

The storefront boundary does not make Engage Core the payment processor, promotion-rule engine, warehouse, shipping system, tax engine, or deep store-operations platform.

Payment execution must remain behind an external provider's secure primitives.

Depending on the selected provider role, the handoff may be:

```text
provider-hosted checkout URL
provider-backed checkout/session API
provider-secure embedded payment component
another provider-supported payment/transaction primitive
```

Engage Core must not receive or persist raw card data.

When the external provider also owns authoritative order or fulfillment state, Commerce reconciles that state rather than recreating a competing operations engine.

## Owns

Commerce owns:

```text
commerce customer/provider identity records
canonical normalized product identity
canonical normalized product-variant identity
provider identity mappings for Commerce products/variants
provider-neutral public offer/storefront identity and presentation state
offer-to-variant availability
provider-authoritative storefront pricing/promotion projections
public offer publication decisions
provider-neutral promotion/deep-link and cart/checkout orchestration contracts
provider customer/contact reconciliation
promotion/source attribution associated with Commerce visits/checkouts/purchases when justified
normalized order identity
normalized order-item purchase snapshots
compact order lifecycle history
provider-neutral purchase-confirmed outcomes
inventory-effect normalization and orchestration
inventory-authority resolution
inventory adjustment/reconciliation idempotency
purchase-history read/query services
Commerce-owned automation events
Commerce public and CRM surfaces
```

Current owned tables:

```text
commerce_customers
commerce_products
commerce_orders
commerce_order_items
commerce_order_events
```

Approved next durable concepts include:

```text
commerce_product_variants
commerce_offers
commerce_offer_variants
normalized provider mappings for canonical products/variants
durable inventory-effect/adjustment state sufficient for idempotent orchestration
```

Exact mapping/inventory table names, columns, and indexes must be confirmed in the Commerce implementation audit against the current repository and first concrete provider ecosystem. Do not force a vendor-specific schema merely because the first implementation uses one vendor.

## Does not own

Commerce does not own:

```text
Core Contact identity
provider credentials or webhook secrets
raw card/payment credentials
provider-specific secure payment internals
provider-native pricing/promotion rule execution when an external provider owns that authority
a general payment ledger unless later justified
warehouse management
shipping-label execution
carrier operations
provider-native tax/fraud systems
deep provider store administration
Experience entitlements
Experience participants
Experience benefits
Experience credentials or scanning
Experience benefit/check-in fulfillment
Event identity or lifecycle
artist, tour, fan, or music-specific meaning
Messaging consent or delivery
Broadcast recipient lifecycle
Campaign enrollment lifecycle
FlowRoute execution
Task lifecycle
```

Commerce may normalize provider status, inventory effects, and history without claiming authority over provider-owned execution.

## Authority and source-of-truth rules

Authority is a configured business contract.

Do not assume the provider that produced a sale is also the pricing authority, promotion/discount authority, inventory authority, order authority, payment processor, or fulfillment system.

Conceptual examples:

```text
external POS sale
    source = POS provider
    inventory authority = another provider
    -> Commerce may need to request an inventory adjustment from the authority

sale completed by the configured inventory/order authority itself
    source = authority provider
    authority already changed inventory
    -> Commerce reconciles the authoritative result
    -> Commerce must not apply the same decrement again

internal package consumption
    source = Engage Core module
    inventory authority = configured external provider
    -> Commerce requests the authoritative adjustment
```

This distinction prevents double-decrements.

The same authority rule applies to customer-facing price and promotions:

```text
external provider is pricing/promotion authority
    -> Commerce reads or refreshes the authoritative customer-facing state
    -> Engage Core may cache/project that state for storefront rendering
    -> checkout revalidates when required
    -> provider remains authoritative for final eligibility/calculation

Engage Core-authored storefront promotion presentation
    -> Core may own the label, artwork, placement, campaign/source identity, and CTA
    -> if redemption depends on a provider-backed discount, Core carries/resolves the provider promotion context rather than recreating its rules
```

Commerce may keep normalized local projections/caches required for storefront presentation and business logic, but the configured authority remains authoritative for the facts assigned to that role.

A future client may configure different authorities for different inventory pools or business scopes. Do not hard-code a single global authority into schema unless the first implementation proves that restriction is intentional.

## Integration/provider-package boundary

New Commerce provider implementations should follow the external provider-package pattern.

Expected implementation location:

```text
separate private Composer package/repository
    engage-integration-[provider]
```

Engage Core's Commerce module owns:

```text
provider-neutral contracts
provider role/capability registration
normalized persistence
public storefront/offer orchestration
checkout orchestration
purchase outcomes
inventory-effect orchestration
read/query services
```

A provider package owns only the vendor-specific implementation it can authoritatively perform, for example:

```text
catalog/product lookup
pricing and compare-at/sale-state reads
promotion/discount reads, eligibility, or provider-backed activation/deep-link generation
checkout/session creation
order reads/reconciliation
inventory reads/adjustments
webhook verification
webhook payload interpretation
customer mapping inputs
provider-specific identifier conversion
```

A provider package must not become the place where Experience, Music, Event, Contact, Campaign, or other cross-module business meaning is hard-coded.

Existing in-repository adapters may remain until deliberately extracted. New Commerce providers should normally establish the private-package pattern instead of adding new long-lived vendor directories merely for symmetry.

## Provider-neutral contracts

Commerce should expose provider-neutral contracts before public pages or optional modules depend on provider internals.

Likely capability contracts/services include:

```text
CommerceProviderRegistry
CommerceProviderRoleResolver
CommerceCatalogProvider
CommercePricingProvider
CommercePromotionProvider
CommerceCheckoutProvider
CommerceOrderProvider
CommerceInventoryProvider
CommerceWebhookReconciler
CommerceProductReadService
CommerceVariantReadService
CommerceOfferReadService
CommerceStorefrontStateResolver
CommercePromotionLinkResolver
CommerceOrderReadService
CommercePurchaseHistoryQuery
CommerceContactLinker
CommercePromotionGate
CommerceCheckoutService
CommercePurchaseOutcomePublisher
CommerceInventoryEffectRecorder
CommerceInventoryOrchestrator
CommerceInventoryReconciler
```

Exact class names should follow repository conventions confirmed during implementation.

Do not require every provider package to implement every contract.

For example:

```text
POS-only provider
    may implement webhook/order-sale input
    may not implement online checkout

payment-only provider
    may implement checkout/payment capability
    may not own catalog or inventory

full commerce provider
    may implement catalog, pricing, promotions, checkout, orders, inventory, and fulfillment-facing reads
```

Role resolution should fail loudly when an intended workflow requires a capability that no installed/configured provider implements.

## Commerce customers and Core Contacts

Core Contacts answer:

```text
Who is this person, how can we reach them, and where did they come from?
```

Commerce customers answer:

```text
Who is this customer/account according to a commerce provider, and which Core Contact can that provider identity be linked to?
```

Good:

```text
commerce_customers.contact_id = Contact #123
commerce_customers.provider = configured provider key
commerce_customers.external_id = provider customer identity
```

Bad:

```text
contacts.provider_customer_id
contacts.total_spent_cents
contacts.last_ordered_at
contacts.purchased_product_ids
```

Commerce-owned purchase facts stay in Commerce tables.

Contact matching must be explicit, deterministic, and repairable. Email or phone similarity may suggest a match but should not silently merge identities when confidence is insufficient.

## Products, variants, and provider mappings

A Commerce product represents canonical provider-neutral product identity.

A Commerce product variant represents the canonical sellable/inventory unit used by offers, purchases, inventory effects, and provider mapping.

The existing `commerce_products` table remains useful for product-level identity and purchase intelligence, but its current provider/external identity assumptions must be audited before it is treated as the final cross-provider catalog shape.

The next schema slice should add normalized product variants rather than storing all variants in product metadata or raw provider payloads.

Expected canonical variant facts may include:

```text
commerce_product_id
stable key
sku nullable
title/name
status
presentation facts required by Commerce
```

Provider-specific facts should not force the canonical row to represent only one provider.

A product/variant may need several external mappings:

```text
Core variant 42
    -> provider A catalog/variant identity
    -> provider B POS/catalog identity
    -> provider C marketplace identity
```

Use normalized provider mapping records when cross-provider orchestration requires them.

Do not rely on SKU equality as the only cross-provider identity rule.

SKU may help suggest mappings, but durable reconciliation should use explicit provider identity mappings once confirmed.

Price, promotion/discount, and availability authority are role/configuration decisions. Commerce should resolve the current authoritative storefront state through provider-neutral reads/projections and revalidate provider state before checkout or inventory-sensitive publication when required by the selected provider contract. A local projection is a rendering/performance aid, not permission for Core to drift from the configured authority.

## Inventory orchestration

Commerce should own a provider-neutral inventory-effect contract.

The important incoming fact is not merely:

```text
inventory_reduced
```

because the authoritative inventory system may not have been changed yet.

The incoming concept is closer to:

```text
business activity consumed or restored quantity
```

The producing module/adapter must determine the actual inventory effect from its authoritative business facts. Do not assume every purchase decrements immediately, every refund restocks, or every cancellation restores quantity.

Examples:

```text
external point-of-sale purchase
Experience package purchase consuming mapped merchandise
Commerce storefront purchase
refund or return
manual provider reconciliation
```

A normalized inventory effect should identify compact durable facts such as:

```text
canonical Commerce variant/item identity
quantity delta or consumed/restored quantity
reason
source type/provider/module
source reference/idempotency identity
occurred_at
inventory scope/location identity when the configured authority requires it
authority/reconciliation context required to avoid duplicate mutation
```

Exact persistence shape is deferred to the implementation audit.

### One orchestration path, different authority behavior

Multiple sources may normalize into the same Commerce inventory pipeline:

```text
internal module
external provider webhook
Commerce purchase reconciliation
    -> canonical inventory effect
    -> inventory orchestration
```

But they do not always produce the same outbound provider mutation.

Commerce must decide:

```text
authoritative system has NOT already applied this effect
    -> issue one idempotent authoritative mutation through the configured provider path

authoritative system ALREADY applied this effect
    -> reconcile/refresh local projection only
    -> do not decrement again

another authoritative operation being created by Core will itself apply the effect
    -> perform that operation once
    -> do not also send a separate inventory adjustment
```

That decision is critical for provider-originated purchases and Commerce storefront purchases whose checkout/order/fulfillment provider may also be the inventory authority.

### Idempotency

Inventory effects must be durable and idempotent.

Webhook retries, queue retries, job retries, browser retries, or duplicate provider notifications must not apply the same inventory change twice.

Use stable source identity and bounded normalized payloads.

Do not rely on an ephemeral event listener alone for a business-critical stock mutation.

### Feedback-loop prevention

Provider reconciliation must not create an endless or duplicate adjustment loop.

Bad:

```text
provider sale
-> Core adjusts authority
-> authority inventory webhook
-> Core treats webhook as a new sale/effect
-> Core adjusts authority again
```

Core must distinguish:

```text
new business effect requiring propagation
authoritative state confirmation/reconciliation
```

### Bundles and package composition

A consuming module may own the business meaning of a package while Commerce owns the canonical inventory effects.

Example:

```text
Experience package
    -> 1 canonical shirt variant
    -> 1 canonical poster variant
    -> 1 canonical credential/merch variant when inventory-managed

authoritative package purchase
    -> Experiences resolves package composition
    -> Experiences submits compact inventory effects through Commerce public seams
    -> Commerce applies/reconciles them against the configured inventory authority
```

Commerce should not own Experience package semantics merely because inventory is involved.

### Inventory projections

Commerce may maintain a normalized local inventory projection/cache when needed for storefront availability, reporting, or orchestration.

That projection is not automatically the source of truth.

When provider authority is configured, authoritative reconciliation wins.

Do not build a warehouse-management subsystem merely because providers expose warehouses, bins, transfers, or locations. Add normalized location/pool concepts only when an approved Engage Core workflow requires them.

## Public offers and storefront presentation

A Commerce offer is the Engage Core-authored public presentation and publication identity.

An offer is not automatically a second provider product, provider discount, or provider promotion. It may present and activate provider-backed commercial state while Core owns the storefront experience around it.

It may provide:

```text
stable slug
client-facing title and description
presentation order
publication lifecycle
optional hero/supporting copy according to shared media/content conventions
selected sellable variants
default variant
publication window or explicit publication state
optional Event linkage through a Commerce-owned relationship or subject seam
optional vertical-owned mapping references
```

Expected tables remain:

```text
commerce_offers
commerce_offer_variants
```

`commerce_offer_variants` should normalize the variants available through an offer, their ordering, and any default selection. Do not store a variant-ID list in offer JSON.

The same Commerce product/variant may appear in:

```text
an Engage Core storefront
an external provider-native storefront
an external POS
another marketplace/channel
```

Commerce should not assume only one sales surface exists.

## Pricing, discounts, promotions, and attribution

Commerce must distinguish **presentation and attribution** from **pricing/promotion authority**.

When a configured provider owns price or discount logic:

```text
provider
    owns authoritative base price / compare-at price / sale price
    owns promotion eligibility, combination rules, usage limits, and final discount calculation

Commerce
    resolves that state through provider-neutral contracts
    may cache/project it for storefront rendering
    styles and explains it in the Engage Core storefront
    preserves compact promotion/source attribution where useful
    carries provider-backed promotion context into checkout
```

A public campaign or physical QR code is a valid Commerce entry point. For example:

```text
printed merchandise bag
    -> QR code identifies a stable promotion/source
    -> QR opens an Engage Core storefront route or a provider-backed promotion link
    -> Commerce resolves the current provider-authoritative product price and promotion state
    -> customer sees the discount/offer in the Engage Core-controlled storefront when that provider contract supports it
    -> checkout receives the provider-backed promotion context
    -> verified order reconciliation records the actual provider-calculated discount and retains justified source/promotion attribution
```

The QR code itself does not create a second discount engine. A provider-native shareable discount link may be used directly, or a stable Core-owned attribution URL may resolve/redirect into the provider-backed promotion path. The implementation choice should preserve one authoritative discount calculation and avoid leaking provider-specific URL semantics into generic Commerce contracts.

Core-owned attribution should remain compact. Prefer stable campaign/source/promotion identity and relationships needed for reporting or automation; do not copy complete query strings, provider payloads, or rendered storefront state into generic metadata.

## Public Commerce host

The public Commerce host must be deployment/client configuration and must not be hard-coded.

The exact environment key and host convention should be finalized in the Commerce implementation audit.

Public routes should expose storefront/offer pages and checkout initiation, not CRM internals or provider credentials.

The public surface should remain usable independently of Experiences. An offer may sell merchandise or another provider-backed product without an Experience relationship.

## Publication and promotion gates

Commerce owns the authoritative Commerce offer publication decision.

A Commerce offer may require:

```text
offer active/publishable state
at least one active mapped variant
required provider-role readiness
authoritative availability when applicable
valid public slug/presentation
checkout capability
optional upstream Event promotion eligibility
optional vertical capability readiness
```

For Event-linked offers:

```text
Event promotion blocked
-> Commerce offer publication and checkout initiation are blocked

Event promotion allowed
-> Commerce evaluates its remaining readiness
```

The Event promotion gate is upstream and cannot be bypassed by Commerce admin actions, direct links, automation, or provider adapters.

Existing valid orders and Experience entitlements remain manageable after an Event is later cancelled or completed. Promotion blocking must not erase historical purchases.

## Checkout orchestration

Commerce public checkout initiation should:

```text
load the current offer and selected canonical variant through Commerce read services
run Commerce and optional upstream promotion gates
resolve the configured checkout provider capability
revalidate authoritative availability/pricing/promotion state when required
carry the selected provider-backed promotion context without recalculating provider-owned discount rules
create/update the provider-backed checkout/cart/session
obtain the provider-supported next checkout/payment step
record only minimal operational correlation and justified source/promotion attribution required for reconciliation/reporting
```

Do not store raw payment data.

Ephemeral carts and checkout sessions should not become durable Project State unless a later recovery requirement proves they must survive a clean rebuild.

A checkout redirect, session creation, or browser success return is not an authoritative paid order.

Authoritative purchase state comes from provider reconciliation and verified provider events/reads.

## Orders and order items

`commerce_orders` represents the normalized provider order/purchase record.

Important existing facts include:

```text
commerce_customer_id nullable
contact_id nullable
order_number/order_name
status
financial_status
fulfillment_status
currency
subtotal/discount/tax/shipping/total cents
ordered_at
closed_at
cancelled_at
refunded_at
source
provider
external_id
external_url
```

`financial_status` is a provider-style status, not a full payment ledger.

`fulfillment_status` is a provider-style normalized status, not an Engage Core shipping/warehouse engine.

`commerce_order_items` preserves the purchase-time snapshot.

Important existing facts include:

```text
commerce_order_id
commerce_product_id nullable
item_type
sku
name/title/variant_title
options
quantity
currency
unit/discount/tax/total cents
fulfillment_status
source
provider
external_id
external_product_id
external_variant_id
external_url
```

Order-item snapshots must remain stable when current provider product copy or price changes.

The next implementation should link normalized order items to canonical `commerce_product_variants` where deterministically resolvable while retaining provider identity snapshots required for historical truth.

## Order events and webhook inbox

`commerce_order_events` represents compact append-style order lifecycle/provider history.

It must not become a duplicate full raw webhook archive.

The shared `webhook_inbox_receipts` infrastructure should own webhook receipt deduplication, processing status, retry evidence, and idempotent provider delivery handling when that infrastructure is used by the provider package.

Commerce order events should store only normalized lifecycle facts and narrowly justified provider context.

Avoid retaining large raw provider payloads on every normalized record when required historical/business facts are already represented in first-class columns.

External sales/inventory provider webhooks should likewise normalize through Commerce rather than becoming public vendor-specific domain events.

## Purchase-confirmed outcome

Commerce should publish one provider-neutral purchase-confirmed outcome only after authoritative reconciliation establishes the required paid/valid state.

The final outcome contract should identify compact canonical records such as:

```text
commerce_order_id
commerce_order_item_id or item identities
contact_id nullable
commerce_customer_id nullable
provider/source identity
provider order identity
occurred_at
```

Do not copy full order/product/provider payloads into the automation event.

The outcome must be idempotent so repeated provider webhooks cannot grant duplicate Experience packages, duplicate inventory effects, or start duplicate automation.

## Experiences boundary

Commerce owns everything through provider-neutral purchasing and inventory orchestration:

```text
public discovery/storefront
public offer page
product/variant selection
checkout orchestration
provider order reconciliation
purchase-confirmed signal
canonical inventory effects
provider inventory adjustment/reconciliation
```

Experiences owns the post-purchase special-access meaning:

```text
package mapping meaning
entitlement/grant
participant slots
benefits
management access
credentials
scanning
check-in
experience-benefit fulfillment
```

The mapping from a Commerce variant/order item to an Experience package belongs to Experiences or an explicit Experiences-owned mapping table because Experiences owns the entitlement meaning.

If an Experience package consumes inventory-managed merchandise, Experiences owns the package-to-item composition meaning and submits the resulting canonical inventory effects through Commerce's public inventory seam.

Commerce must not create Experience grants or credentials directly.

Experiences must not become the shipping/warehouse fulfillment system for ordinary Commerce orders merely because it uses the word fulfillment for package benefits.

## Events boundary

Commerce may own an optional offer-to-Event relationship or generic subject reference when the concrete schema is finalized.

Events remains unaware of Commerce.

Commerce must call the Events public promotion gate for Event-linked offer publication and checkout initiation.

Commerce does not copy Event schedule/location/lifecycle snapshots into generic offer JSON.

## Music and other vertical boundaries

Commerce records provider-neutral purchase and inventory facts.

Vertical modules interpret those facts.

Examples:

```text
Commerce records that a Contact bought a T-shirt.
Music decides whether that Contact belongs in a merch-buyer segment.

Commerce records that an order item maps to a VIP package.
Experiences grants and fulfills the experience package.
Music may provide artist/tour-specific meaning.

Commerce records that a Contact bought a dog-training package.
PetServices decides what that purchase means operationally.
```

Vertical modules must not import provider adapter classes for generic purchase sync, inventory sync, or checkout behavior.

## Segmentation and Contact filters

Future purchase-based filters may include:

```text
Contacts who purchased product or variant X
Contacts who purchased a product with selected provider/vendor/category facts
Contacts who spent over a threshold
Contacts who ordered recently
Contacts who have never ordered
Contacts who purchased an Event-linked or Experience-linked offer
```

Core's current Contact filter resolver is a closed built-in type list. Before Commerce contributes reusable Broadcast/Campaign filters, Core needs the planned contributor-based Contact-filter registry/seam.

Commerce should own filter definitions and Commerce query logic. Broadcasts and Campaigns continue to own their recipient/enrollment lifecycles.

Do not make Broadcasts or Campaigns query Commerce private tables directly.

## Automation events

Commerce records its own state first and emits neutral automation events through the shared Automation Event outbox.

Likely outcomes may include:

```text
commerce.order_reconciled
commerce.order_paid
commerce.order_cancelled
commerce.order_refunded
commerce.purchase_confirmed
commerce.inventory_effect_recorded
commerce.inventory_adjusted
commerce.inventory_reconciled
```

Exact keys should be introduced only with implemented actions and consumers.

Provider-specific webhook topics must not become the public domain-event contract.

Current FlowRoutes runtime is Contact-centered. Contact-linked purchase events can use the existing generic seam when integration is added. Contactless provider/order/inventory events that require subject-only orchestration depend on the planned subject-first FlowRoutes generalization.

## Messaging boundary

Commerce owns the business trigger and purchase context.

Messaging owns:

```text
templates and immutable versions
chains and enrollment
consent and suppression
delivery scheduling
provider submission and attempts
```

Commerce must use public Messaging actions/contracts and must not create ScheduledMessage rows directly.

Provider-native checkout/order emails may remain provider-owned when that external platform is authoritative for those operations. Engage Core transactional follow-up is a separate configured Messaging decision.

## Reporting boundary

Reporting may consume Commerce read/query services for purchase totals, products, variants, offers, orders, inventory effects/projections, and Contact purchase history.

Reporting must not mutate Commerce state.

## FlowRoutes boundary

Commerce remains functional without FlowRoutes.

FlowRoutes may coordinate purchase or inventory follow-up through Commerce public actions and neutral automation events.

Commerce must not import FlowRoutes models or store FlowRoutes-specific foreign keys merely for provenance symmetry.

## Project State

Current Commerce tables are explicitly discovered and policy-controlled by Project State, but Commerce has no first-class transfer section yet.

That is acceptable only while Commerce remains an unused foundation whose tables must be empty.

Before provider-backed Commerce becomes operational, Project State must transfer all durable Commerce state required to survive a controlled clean rebuild.

Expected transfer coverage includes at least:

```text
commerce_customers
commerce_products
commerce_product_variants
commerce_offers
commerce_offer_variants
commerce_orders
commerce_order_items
commerce_order_events
provider product/variant mappings once implemented
durable inventory orchestration records once implemented
```

Transfer:

```text
Engage Core-authored offers and mappings
provider identity mappings
Contact associations
normalized customers/products/variants/orders/items
compact operational lifecycle history
current publication state
durable inventory effects required for idempotency/reconciliation
```

Do not transfer as durable state:

```text
provider access tokens
webhook secrets
provider credentials
ephemeral carts
short-lived checkout sessions
reconstructible caches
full redundant provider payload archives
```

Do not enable production Commerce workflows while Commerce remains under a must-be-empty Project State policy.

The expected Project State version must be recalculated from the fresh repository snapshot when the Commerce section lands. Do not preserve a predicted version number as architectural truth.

## Setup validation

Commerce setup validation should eventually verify:

```text
module and provider-role configuration consistency
required provider contracts are registered for every configured role
required provider credentials are present outside test/dev sink modes
public Commerce host is valid and client-configured
webhook configuration is complete for enabled inbound provider flows
configured provider mappings reference valid canonical products/variants
configured pricing/promotion authority is resolvable for storefronts that require provider-backed commercial state
provider-backed promotion links/contexts can be resolved without Core inventing a competing discount rule
configured inventory authority is resolvable
an inventory effect cannot be routed into an impossible/missing authority path
Event-linked offers can resolve the Events promotion gate when Events is enabled
Project State no longer classifies operational Commerce tables as must-be-empty
```

Validation should report actionable findings without making external provider calls unless an explicit connectivity check is requested.

## Implementation order

```text
1. Commerce architecture audit against current tables and the first concrete client provider ecosystem
2. provider-neutral capability/role contracts and provider registration
3. canonical product-variant schema/model
4. normalized multi-provider product/variant mapping
5. provider-authoritative pricing/promotion read and projection contracts
6. Commerce offer/storefront presentation schema/model
7. durable promotion/source attribution seam for storefront/checkout/purchase flows
8. durable inventory-effect/orchestration contract with idempotency and loop prevention
9. Project State Commerce section and current-format version bump
10. first required external provider package(s) for the concrete client roles
11. verified webhook inbox integration and idempotent order/inventory reconciliation
12. provider-neutral purchase-confirmed outcome
13. Commerce CRM operations
14. client-configured public storefront/offer surface with provider-backed promotions and checkout
15. optional Event promotion gate integration
16. Experiences package mapping/grant and inventory-component consumption
17. optional Contact filters, Messaging, FlowRoutes, and Reporting contributors
```

Provider/contracts, canonical mappings, persistence, Project State, and reconciliation must precede production cross-provider orchestration.

## Illustrative provider ecosystem only

The following is the concrete example that motivated this architecture. It is one client/provider combination, not an Engage Core default:

```text
Engage Core
    owns custom storefront presentation and orchestration

Shopify
    owns authoritative catalog pricing for this client
    owns provider-native discounts/promotions and final discount calculation
    owns authoritative inventory
    owns online order/fulfillment operations
    owns or coordinates the provider-backed online checkout/payment path

Square
    owns venue POS/payment execution

Square venue sale
    -> Square provider event/webhook
    -> Commerce normalizes canonical item consumption
    -> Commerce adjusts Shopify because Shopify is the configured inventory authority

Engage Core storefront sale completed through Shopify
    -> Core renders the storefront using current Shopify-authoritative pricing/promotion state
    -> Shopify processes the order, calculates the final provider-owned discount, and changes its own inventory
    -> Commerce reconciles the authoritative Shopify order/discount/inventory result
    -> Commerce does NOT send a second decrement or recalculate the Shopify discount

Printed merch-bag QR promotion
    -> QR identifies the physical-bag promotion/source
    -> Core storefront or Shopify-backed promotion link opens with the provider promotion context
    -> Core presents the current Shopify-authoritative price/offer state in the Engage storefront when using the Core route
    -> Shopify applies the actual 10% discount at checkout according to its configured rules
    -> Commerce reconciles the resulting order and can attribute the purchase back to the bag/QR promotion

Experience package purchase
    -> Experiences resolves package component meaning
    -> Commerce receives canonical component inventory effects for components not already represented by an authoritative inventory mutation
    -> Commerce adjusts or reconciles Shopify according to the configured authority path
```

Another client may use Square for more roles, Stripe for payment, another ecommerce platform for fulfillment/inventory, or entirely different providers.

The architecture must not require Shopify, Square, Stripe, or the specific role split from this example.

## Deferred work

Defer until a proven workflow requires it:

```text
full payment ledger
refund transaction ledger beyond normalized provider history
warehouse/bin management
shipping-label execution
carrier management
deep provider fulfillment administration
Core-owned complex promotion/discount rule engine when an authoritative provider already owns those rules
multi-provider payment splitting
retained checkout-session history
customer self-service order portal
advanced purchase segmentation UI
speculative inventory-location topology
```

Additional commerce providers are not conceptually deferred; they should be implemented only when a real client requires them, using the same provider-neutral capability/role seams.

## Durable defaults

```text
Engage Core owns storefront experience and commerce orchestration where that creates unique value.
When a provider is configured as pricing/promotion authority, the Engage Core storefront must reflect that authoritative price/discount/offer state rather than maintain a competing rule set.
Core may own promotion presentation and source attribution while the configured provider owns final discount eligibility/calculation and secure checkout execution.
Specialized external platforms remain responsible for payment processing, deep store operations, warehouse/shipping fulfillment, or other capabilities they perform better.
Do not require a separate middleware SaaS when direct provider packages can connect the client's existing systems.
Do not assume one commerce provider per client.
Bind provider capabilities/roles explicitly.
Treat provider names in documentation examples as illustrative, not architectural defaults.
Use cents-based integer money fields.
Use canonical Commerce product/variant identity plus explicit provider mappings.
Do not rely on SKU equality as cross-provider identity.
Normalize purchase and inventory facts before consumers use them.
Treat inventory effects as durable/idempotent business operations, not fire-and-forget listener side effects.
Distinguish adjustment-required effects from authority-already-mutated reconciliation so inventory is not decremented twice.
Keep order-item purchase snapshots stable.
Keep provider payload retention minimal and justified.
Keep vertical entitlement/experience meaning outside Commerce.
Require Project State support before operational production use.