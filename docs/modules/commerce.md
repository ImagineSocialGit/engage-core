# Commerce Module

Commerce is a current universal module with a provider-history foundation already present in the repository.

Current implementation owns normalized customer, product, order, order-item, and order-event records. The approved next direction expands Commerce into the provider-neutral purchasing capability used by Engage Core public offer pages, beginning with Shopify as the first provider integration.

Commerce must remain provider-neutral even though Shopify is the first implementation target.

A concise definition:

> Commerce owns the provider-neutral catalog, public-offer, checkout-orchestration, purchase-reconciliation, and purchase-history contracts that optional modules use without owning provider-specific payment or fulfillment internals.

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
no provider contracts or provider manager
no Shopify adapter
no Storefront cart/checkout orchestration
no verified Shopify webhook handlers
no purchase-confirmed public signal
no Commerce Project State transfer section
no Commerce CRM operations
```

The existing tables are a useful normalized purchase-history base, but they are not sufficient for the approved Shopify-backed storefront and VIP purchasing flow.

## Product barometer

Commerce should follow the Engage Core product barometer:

```text
If the client-facing task cannot realistically be completed in Engage Core in 10-15 minutes total, it should usually not be a client-facing workflow.
```

Appropriate client/admin Commerce work:

```text
See what a Contact purchased.
Review recent Shopify orders.
Create or manage a focused Engage Core public offer.
Associate an offer with one or more sellable variants.
Publish an eligible offer.
Open the provider order when deeper fulfillment work belongs in Shopify.
Target Contacts from normalized purchase facts through contributed filters later.
```

Operator/developer work:

```text
Configure Shopify credentials and webhook delivery.
Define provider reconciliation policy.
Map provider customers to Core Contacts.
Choose the client-configured public Commerce host.
Configure product/variant synchronization.
Configure optional Event and Experience relationships through public seams.
```

Commerce should not ask clients to maintain a second full ecommerce platform. Engage Core should add focused branded presentation and orchestration where Shopify-native pages are operationally awkward, while Shopify remains authoritative for its commercial records.

## Responsibility

Commerce should answer:

```text
Which sellable product and variant is this?
Which Engage Core offer presents it publicly?
May that offer be published?
Which provider cart/checkout was initiated?
Which authoritative order and order items were reconciled?
Which Core Contact or provider customer owns the purchase?
What provider-neutral purchase outcome should consumers receive?
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
general Shopify purchase history
```

Vertical modules own the meaning and fulfillment of those purchases.

## Approved storefront shape

Commerce supports three distinct surfaces:

```text
Shopify-native storefront
    may remain the simplest surface for ordinary merchandise

Engage Core Commerce public offer pages
    focused branded presentation for custom offers, VIP packages, or workflows
    that are cumbersome to express in the Shopify theme

Shopify-hosted checkout
    authoritative cart/checkout/payment execution for both paths
```

The initial VIP purchasing path is:

```text
Engage Core Commerce offer page
-> Shopify Storefront cart
-> Shopify-hosted checkout
-> Shopify webhooks
-> Commerce order reconciliation
-> provider-neutral purchase-confirmed signal
-> Experiences grants the mapped package
```

A browser return or success URL is not authoritative proof of payment. Commerce derives purchase state from provider reconciliation and verified webhooks.

## Owns

Commerce owns:

```text
commerce customer/provider identity records
normalized product identity
normalized product-variant identity
provider-neutral public offer identity and presentation state
offer-to-variant availability
public offer publication decisions
provider-neutral cart/checkout orchestration contracts
provider customer/contact reconciliation
normalized order identity
normalized order-item purchase snapshots
compact order lifecycle history
provider-neutral purchase-confirmed outcomes
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

Approved next durable concepts:

```text
commerce_product_variants
commerce_offers
commerce_offer_variants
```

Exact migration columns and indexes must be confirmed in the Commerce implementation audit against the current Shopify API contract and existing model fields.

## Does not own

Commerce does not own:

```text
Core Contact identity
Shopify credentials or webhook secrets
Shopify's authoritative pricing and inventory
Shopify's cart implementation
Shopify-hosted checkout UI
payment processing
card data
a general payment ledger unless later justified
warehouse or stock movement
shipping-label execution
full fulfillment operations
Experience entitlements
Experience participants
Experience benefits
Experience credentials or scanning
Event identity or lifecycle
artist, tour, fan, or music-specific meaning
Messaging consent or delivery
Broadcast recipient lifecycle
Campaign enrollment lifecycle
FlowRoute execution
Task lifecycle
```

Commerce may normalize provider status and history without claiming authority over provider-owned execution.

## Provider authority

For the initial Shopify integration:

```text
Engage Core Commerce
    owns branded public offer pages
    owns provider-neutral offer publication
    owns Contact association
    owns normalized purchase records
    owns post-purchase provider-neutral signals

Shopify
    owns authoritative products and variants
    owns authoritative price and inventory availability
    owns cart and checkout
    owns payment processing
    owns authoritative order/payment/fulfillment state
```

Engage Core may cache or normalize provider facts required for public presentation and reconciliation, but it must not silently diverge from Shopify's authoritative state.

## Shopify integration boundary

Shopify is the first Commerce provider adapter.

Expected adapter location:

```text
app/Integrations/Commerce/Shopify
```

Expected provider-facing responsibilities:

```text
Storefront GraphQL client
    product/variant lookup required by public offers
    cart creation and updates
    hosted checkout URL retrieval

Admin GraphQL client
    product/variant reconciliation
    customer reconciliation
    order and order-item reconciliation
    administrative provider reads required by sync/recovery

Webhook verifier and handlers
    verify provider signatures
    use the shared webhook inbox/receipt infrastructure
    reconcile normalized Commerce state idempotently
```

Do not build a new Shopify integration on the legacy REST Admin API.

Provider-specific payload parsing and identifiers remain inside the adapter or normalized Commerce provider DTOs. Consumers such as Experiences must not import Shopify adapter classes.

Expected conceptual adapter collaborators:

```text
ShopifyStorefrontClient
ShopifyAdminClient
ShopifyWebhookVerifier
ShopifyWebhookHandler
ShopifyProductMapper
ShopifyOrderMapper
ShopifyCustomerMapper
```

Exact class names should follow the repository conventions confirmed during implementation.

## Provider-neutral contracts

Commerce should expose provider-neutral contracts before public pages or optional modules depend on Shopify internals.

Likely contracts and services:

```text
CommerceProvider
CommerceProviderManager
CommerceCatalogProvider
CommerceCheckoutProvider
CommerceOrderProvider
CommerceWebhookReconciler
CommerceProductReadService
CommerceVariantReadService
CommerceOfferReadService
CommerceOrderReadService
CommercePurchaseHistoryQuery
CommerceContactLinker
CommercePromotionGate
CommerceCheckoutService
CommercePurchaseOutcomePublisher
```

The provider manager should resolve the selected provider from deployment/client configuration without hard-coded Shopify branching in controllers or vertical modules.

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
commerce_customers.provider = shopify
commerce_customers.external_id = provider customer identity
```

Bad:

```text
contacts.shopify_customer_id
contacts.total_spent_cents
contacts.last_ordered_at
contacts.purchased_product_ids
```

Commerce-owned purchase facts stay in Commerce tables.

Contact matching must be explicit, deterministic, and repairable. Email or phone similarity may suggest a match but should not silently merge identities when confidence is insufficient.

## Products and variants

A Commerce product represents provider-neutral product identity.

A Commerce product variant represents the actual sellable unit required by Shopify cart lines and order reconciliation.

The existing `commerce_products` table remains useful for product-level identity and purchase intelligence.

The next schema slice should add a normalized `commerce_product_variants` table rather than storing all variants in `commerce_products.meta` or raw provider payloads.

Expected variant facts may include:

```text
commerce_product_id
key or provider-neutral identity
sku nullable
title/name
status
currency
price_cents or synchronized presentation price
compare-at price when operationally required
availability state
provider
external_id
external_product_id
external_url nullable
published_at nullable
provider synchronization timestamps
```

Shopify remains authoritative for price and inventory. Engage Core should revalidate provider availability before cart creation.

Do not add inventory/warehouse tables merely because Shopify exposes inventory concepts. Add only the normalized fields required by the approved Engage Core workflow.

## Public offers

A Commerce offer is the Engage Core-authored public presentation and publication identity.

An offer is not a second provider product.

It may provide:

```text
stable slug
client-facing title and description
presentation order
publication lifecycle
optional hero/supporting copy according to the shared media/content conventions
selected sellable variants
default variant
publication window or explicit publication state
optional Event linkage through a Commerce-owned relationship or subject seam
optional vertical-owned mapping references
```

The exact content/media model must follow repository-wide media and content conventions. Do not introduce an isolated Commerce image-storage pattern without that shared decision.

Expected tables:

```text
commerce_offers
commerce_offer_variants
```

`commerce_offer_variants` should normalize the variants available through an offer, their ordering, and any default selection. Do not store a variant-ID list in offer JSON.

## Public Commerce host

The public Commerce host must be deployment/client configuration and must not be hard-coded.

The exact environment key and host convention should be finalized in the Commerce implementation audit.

Public routes should expose focused offer pages and checkout initiation, not CRM internals or provider credentials.

The public surface should remain usable independently of Experiences. An offer may sell merchandise or another provider-backed product without an Experience relationship.

## Publication and promotion gates

Commerce owns the authoritative Commerce offer publication decision.

A Commerce offer may require:

```text
offer active/publishable state
at least one active mapped variant
provider availability
valid public slug/presentation
provider checkout capability
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
load the current offer and selected variant through Commerce read services
run Commerce and optional upstream promotion gates
revalidate provider availability
create/update a provider cart through the selected provider contract
obtain the provider-hosted checkout URL
redirect the customer to the provider checkout
record only minimal operational correlation required for reconciliation
```

Do not store payment data.

Ephemeral carts and checkout sessions should not become durable Project State unless a later recovery requirement proves they must survive a clean rebuild.

A checkout redirect response is not an order.

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

`fulfillment_status` is a provider-style status, not an Engage Core fulfillment engine.

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

The next implementation should link normalized order items to `commerce_product_variants` where deterministically resolvable while retaining provider identity snapshots required for historical truth.

## Order events and webhook inbox

`commerce_order_events` represents compact append-style order lifecycle/provider history.

It must not become a duplicate full raw webhook archive.

The shared `webhook_inbox_receipts` infrastructure should own webhook receipt deduplication, processing status, retry evidence, and idempotent provider delivery handling.

Commerce order events should store only normalized lifecycle facts and narrowly justified provider context.

Avoid retaining large raw Shopify payloads on every normalized record when the required historical/business facts are already represented in first-class columns.

## Purchase-confirmed outcome

Commerce should publish one provider-neutral purchase-confirmed outcome only after authoritative reconciliation establishes the required paid/valid state.

The final outcome contract should identify compact canonical records such as:

```text
commerce_order_id
commerce_order_item_id or item identities
contact_id nullable
commerce_customer_id nullable
provider
provider order identity
occurred_at
```

Do not copy full order/product/provider payloads into the automation event.

The outcome must be idempotent so repeated Shopify webhooks cannot grant duplicate Experience packages or start duplicate automation.

## Experiences boundary

Commerce exclusively owns everything through purchase:

```text
public discovery
public offer page
product/variant selection
cart and checkout orchestration
provider order reconciliation
purchase-confirmed signal
```

Experiences owns everything operational after purchase:

```text
package mapping meaning
entitlement/grant
participant slots
benefits
management access
credentials
scanning
check-in
fulfillment
```

The mapping from a Commerce variant/order item to an Experience package belongs to Experiences or an explicit Experiences-owned mapping table because Experiences owns the entitlement meaning.

Commerce must not create Experience grants or credentials directly.

## Events boundary

Commerce may own an optional offer-to-Event relationship or generic subject reference when the concrete schema is finalized.

Events remains unaware of Commerce.

Commerce must call the Events public promotion gate for Event-linked offer publication and checkout initiation.

Commerce does not copy Event schedule/location/lifecycle snapshots into generic offer JSON.

## Music and other vertical boundaries

Commerce records purchase facts.

Vertical modules interpret those facts.

Examples:

```text
Commerce records that a Contact bought a T-shirt.
Music decides whether that Contact belongs in a merch-buyer segment.

Commerce records that a Shopify order item maps to a VIP package.
Experiences grants and fulfills the package.
Music may provide artist/tour-specific meaning.

Commerce records that a Contact bought a dog-training package.
PetServices decides what that purchase means operationally.
```

Vertical modules must not import Shopify adapter classes for generic purchase sync or checkout behavior.

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

Likely implemented outcomes may include:

```text
commerce.order_reconciled
commerce.order_paid
commerce.order_cancelled
commerce.order_refunded
commerce.purchase_confirmed
```

Exact keys should be introduced only with implemented actions and consumers.

Provider-specific webhook topics must not become the public domain-event contract.

Current FlowRoutes runtime is Contact-centered. Contact-linked purchase events can use the existing generic seam when integration is added. Contactless provider/order events that require subject-only orchestration depend on the planned subject-first FlowRoutes generalization.

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

Provider checkout/order emails remain Shopify-owned unless the client explicitly configures Engage Core transactional follow-up through Messaging.

## Reporting boundary

Reporting may consume Commerce read/query services for purchase totals, products, variants, offers, orders, and Contact purchase history.

Reporting must not mutate Commerce state.

## FlowRoutes boundary

Commerce remains functional without FlowRoutes.

FlowRoutes may coordinate purchase follow-up through Commerce public actions and neutral automation events.

Commerce must not import FlowRoutes models or store FlowRoutes-specific foreign keys merely for provenance symmetry.

## Project State

Current Commerce tables are explicitly discovered and policy-controlled by Project State, but Commerce has no first-class transfer section yet.

That is acceptable only while Commerce remains an unused foundation whose tables must be empty.

Before Shopify-backed Commerce becomes operational, Project State must transfer all durable Commerce state required to survive a controlled clean rebuild.

Expected transfer coverage:

```text
commerce_customers
commerce_products
commerce_product_variants
commerce_offers
commerce_offer_variants
commerce_orders
commerce_order_items
commerce_order_events
```

Transfer:

```text
Engage Core-authored offers and mappings
provider identity mappings
Contact associations
normalized customers/products/variants/orders/items
compact operational lifecycle history
current publication state
```

Do not transfer as durable state:

```text
Shopify access tokens
webhook secrets
provider credentials
ephemeral carts
short-lived checkout sessions
reconstructible caches
full redundant provider payload archives
```

Do not enable production Shopify workflows while Commerce remains under a must-be-empty Project State policy.

The expected Project State sequence, if no intervening format-changing batch lands, is:

```text
current repository format: version 10
Events foundation: version 11
Shopify-capable Commerce foundation: version 12
```

Recalculate those numbers from the fresh repository snapshot if another Project State batch lands first.

## Setup validation

Commerce setup validation should eventually verify:

```text
module and provider configuration consistency
selected provider contract is registered
required Shopify credentials are present outside test/dev sink modes
public Commerce host is valid and client-configured
webhook configuration is complete
configured offer mappings reference valid products/variants
Event-linked offers can resolve the Events promotion gate when Events is enabled
Project State no longer classifies operational Commerce tables as must-be-empty
```

Validation should report actionable findings without making external provider calls unless an explicit connectivity check is requested.

## Implementation order

```text
1. Commerce architecture audit against current tables and Shopify requirements
2. provider-neutral contracts and provider manager
3. normalized product-variant schema/model
4. Commerce offer and offer-variant schema/model
5. Project State Commerce section and current-format version bump
6. Shopify Admin/Storefront GraphQL adapter foundation
7. verified webhook inbox integration and idempotent reconciliation
8. provider-neutral purchase-confirmed outcome
9. Commerce CRM operations
10. client-configured public offer surface and checkout redirect
11. optional Event promotion gate integration
12. Experiences package mapping and grant consumption
13. optional Contact filters, Messaging, FlowRoutes, and Reporting contributors
```

Commerce provider/contracts, persistence, Project State, and reconciliation must precede Experiences purchasing.

## Deferred work

Defer until a proven workflow requires it:

```text
additional commerce providers
full payment ledger
refund transaction ledger
fulfillment/shipment execution
warehouse/inventory management
Shopify theme management
complex promotion/discount engine
multi-provider cart
retained checkout-session history
customer self-service order portal
advanced purchase segmentation UI
```

## Durable defaults

```text
Use cents-based integer money fields.
Use normalized provider/external identity columns.
Keep order-item purchase snapshots stable.
Normalize product variants rather than storing variant lists in JSON.
Normalize offer-to-variant relationships.
Treat Shopify as authoritative for price, inventory, checkout, payment, and order state.
Use the shared webhook inbox for idempotency and retry evidence.
Keep provider payload retention minimal and justified.
Keep vertical entitlement/fulfillment meaning outside Commerce.
Require Project State support before operational production use.
```