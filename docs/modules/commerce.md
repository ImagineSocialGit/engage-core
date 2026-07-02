# Commerce Module

Commerce is a planned universal module.

Commerce owns reusable commerce-history and purchase-intelligence capability that can be used by multiple verticals without pushing order, product, or purchase state into Core contacts or vertical-specific tables.

Commerce is not intended to be an Engage Core storefront, checkout engine, payment processor, inventory system, or Shopify replacement.

The intended product shape is:

```text
External commerce providers such as Shopify remain the source of storefront, checkout, payment, fulfillment, inventory, and catalog operations.
Engage Core syncs normalized commerce history so clients can understand and act on purchase behavior.
Clients can later target messages, campaigns, broadcasts, reports, or automation from purchase facts.
Vertical modules can decide what purchase history means in their domain.
```

## Product barometer

Commerce should follow the Engage Core product barometer:

```text
If the client-facing task cannot realistically be completed in Engage Core in 10-15 minutes total, it should usually not be a client-facing workflow.
```

For Commerce, this creates a clear split.

Client-facing Commerce work:

```text
See what a contact purchased.
See recent orders from a synced store.
Send a message to contacts who previously purchased a T-shirt.
Start a follow-up sequence for customers who bought a product.
Review customer/order history before contacting someone.
```

Developer/operator Commerce work:

```text
Configure provider sync.
Map provider customers to contacts.
Choose which commerce provider facts become searchable/reportable.
Create reusable purchase-based segments later.
Wire purchase events to Messaging, Broadcasts, Campaigns, FlowRoutes, or Reporting.
```

Commerce should make provider-powered customer intelligence easier for small business clients. It should not ask those clients to maintain a second ecommerce platform.

## Responsibility

Commerce should answer:

```text
Who bought something, what did they buy, when did they buy it, what provider did it come from, what order/item facts were recorded, and what lifecycle/provider events happened?
```

Commerce should stay vertical-neutral.

It may support Shopify purchase history, merch buyers, music fans, service purchases, digital products, pet-service packages, appointment add-ons, course purchases, or general ecommerce history without owning the vertical meaning of those purchases.

## FOSS feature-shape assumptions

Before proposing schema, Commerce was evaluated against common patterns in mature open-source and open-source-adjacent ecommerce, ERP, and modular commerce systems.

Those systems commonly support:

```text
- customers or accounts
- products and variants
- carts and checkouts
- orders
- order line items
- payments
- refunds
- fulfillment and shipping
- inventory, warehouses, and stock locations
- sales channels
- tax, currency, and regions
- discounts and promotions
- provider integrations
- events, webhooks, and workflows
```

Engage Core should use those products as feature-shape references, not as implementation sources.

The durable conclusion is that Commerce should begin as a normalized purchase-history and provider-sync foundation. Carts, checkouts, payments, fulfillment, shipping, warehouses, inventory, discounts, tax, channels, and product variants are real commerce concepts, but they should not be first-slice tables until Engage Core has a concrete workflow requiring them.

## Intended authoring model

Commerce should support provider-synced records first.

Likely commerce data sources:

```text
Shopify sync
other ecommerce provider sync
manual/imported historical order records later, if needed
vertical-owned commerce interpretation later
```

The first implementation should not require a storefront builder, product manager, payment UI, inventory manager, or checkout flow.

The foundation should instead optimize for repeatable integration and segmentation use cases:

```text
link provider customers to Core contacts
sync provider products
sync provider orders
sync provider order items
record provider lifecycle events
make future contact filters/reporting possible
```

## Owns

Commerce owns:

```text
commerce_customers
commerce_products
commerce_orders
commerce_order_items
commerce_order_events
```

Commerce should also own, when implemented:

```text
commerce provider customer identity mapping
commerce provider product/order/item sync records
commerce history read/query services
purchase-history contact filter providers, if needed later
commerce-related domain events, if automation-worthy later
provider sync orchestration intent behind Commerce-owned contracts
commerce reporting source records
```

Commerce should not own checkout, cart, payment processing, fulfillment execution, inventory management, customer portal identity, Messaging consent/delivery, Broadcast recipient bookkeeping, Campaign enrollment, FlowRoute execution, or vertical-specific purchase meaning.

## Does not own

Commerce does not own:

```text
Core Contact records
customer portal identity/auth
message delivery infrastructure
Messaging consent records
Broadcast send lifecycle
Campaign journey lifecycle
FlowRoute execution state
storefront pages
carts
checkouts
payment processing
refund processing as a full ledger
shipping labels or fulfillment execution
inventory, warehouses, or stock movement
provider adapter internals outside Commerce-owned contracts
vertical-specific product/customer meaning
music fan strategy
pet-service package fulfillment rules
mortgage-specific payment or LOS state
```

Commerce may store purchase facts that a vertical module later interprets, but the vertical module owns that interpretation.

Examples:

```text
Commerce stores that a contact bought a T-shirt.
Music decides whether that means the contact belongs in a merch-buyer fan segment.

Commerce stores that a contact bought a dog-training package.
PetServices decides what that means for training eligibility or package balance.

Commerce stores product/order history from Shopify.
Reporting summarizes purchase behavior; Messaging/Broadcasts may later target contacts from Commerce-owned query/filter seams.
```

## Consumes

Commerce may consume these modules through public seams when enabled:

```text
Core
Messaging
Broadcasts
Campaigns
FlowRoutes
Reporting
Portal
Integrations/adapters
```

Expected usage:

```text
Core -> contact-linked commerce customers/orders
Messaging -> purchase confirmations or follow-up messages only if Engage Core later sends them
Broadcasts -> purchase-history recipient filters later
Campaigns -> purchase-triggered or purchase-segmented nurture later
FlowRoutes -> purchase events later
Reporting -> commerce summaries and dashboards later
Portal -> customer-facing order/account visibility later
Integrations -> provider adapters such as Shopify behind Commerce-owned contracts/managers
```

For the first foundation slice, Commerce should depend only on Core. Messaging, Broadcasts, Campaigns, FlowRoutes, Portal, Reporting, and provider adapters should remain optional later integrations through public seams.

## Consumed by

Commerce may be consumed by:

```text
Broadcasts
Campaigns
FlowRoutes
Reporting
Portal
Music
PetServices
Mortgage
Core contact show extensions
```

Consumers should use public Commerce actions/services/contracts/events/read services rather than directly mutating Commerce internals once those seams exist.

Expected future examples:

```text
Broadcasts asks Commerce for contacts who purchased a specific product.
Campaigns enrolls contacts from a purchase-history segment.
FlowRoutes reacts to commerce.order_created or commerce.product_purchased events.
Reporting reads order totals and product history.
Music interprets product purchases as fan/customer signals.
```

## Public seams to add later

The first foundation slice does not need full actions or filters yet.

Likely future public seams:

```text
CommerceCustomerResolver
CommerceContactLinker
SyncCommerceCustomerAction
SyncCommerceProductAction
SyncCommerceOrderAction
SyncCommerceOrderItemAction
RecordCommerceOrderEventAction
CommerceReadService
CommerceCustomerReadService
CommerceOrderReadService
CommercePurchaseHistoryQuery
CommerceContactFilterProvider
CommerceProviderManager
CommerceProvider
CommerceAutomationEventEmitter
```

Public actions and read services should exist before other modules directly create, mutate, or query Commerce internals.

Do not add the contact filter provider until a consuming workflow needs it. The foundation schema only needs to make future filters possible.

## Commerce customer vs Core contact

Core contacts answer:

```text
Who is this person, how can we reach them, and where did they come from?
```

Commerce customers answer:

```text
Who is this person/account according to a commerce provider, and which Core contact can we link that provider identity to?
```

Good:

```text
commerce_customers.contact_id = Contact #123
commerce_customers.provider = shopify
commerce_customers.external_id = gid://shopify/Customer/1001
```

Bad:

```text
contacts.shopify_customer_id
contacts.total_spent_cents
contacts.last_ordered_at
contacts.purchased_product_ids
```

Commerce-owned purchase facts should stay in Commerce tables.

## Product and order item snapshots

Commerce should distinguish synced product identity from order item snapshots.

A product answers:

```text
What product does the provider currently know about?
```

An order item answers:

```text
What exactly was purchased on this order at the time of purchase?
```

Good:

```text
commerce_products.name = Classic T-shirt
commerce_order_items.title = Classic T-shirt
commerce_order_items.variant_title = Medium
commerce_order_items.external_product_id = gid://shopify/Product/2001
commerce_order_items.external_variant_id = gid://shopify/ProductVariant/5001
commerce_order_items.unit_price_cents = 2500
```

Bad:

```text
Only store current product name and price, then let old order history change when the product changes.
```

Order item snapshots make purchase-history filters and reporting stable even when provider product records change later.

## Segmentation and filters

A major Commerce use case is future purchase-based targeting:

```text
contacts who purchased product X
contacts who purchased a product titled T-shirt
contacts who purchased vendor/category/tag X
contacts who spent over a threshold
contacts who ordered recently
contacts who have never ordered
```

Do not add Broadcast/Campaign/Core filter seams in the foundation slice unless a current workflow needs them.

The foundation only needs to keep Commerce-owned tables capable of supporting those filters later:

```text
commerce_customers.contact_id nullable
commerce_orders.contact_id nullable
commerce_orders.commerce_customer_id nullable
commerce_order_items.commerce_order_id
commerce_order_items.commerce_product_id nullable
commerce_order_items.external_product_id nullable
commerce_order_items.external_variant_id nullable
commerce_order_items.sku/title/name snapshot fields
commerce_products.provider/external_id/name/product_type/vendor/category/tags
commerce_orders.ordered_at/status/financial_status/fulfillment_status
```

## Automation events

Commerce should use the existing app-level automation event seam when purchase outcomes become automation-worthy.

Current seam:

```text
App\Support\AutomationEvents\Data\AutomationEventData
App\Support\AutomationEvents\Events\AutomationEventRecorded
```

Likely future Commerce automation events:

```text
commerce.customer_synced
commerce.product_synced
commerce.order_created
commerce.order_paid
commerce.order_cancelled
commerce.order_refunded
commerce.product_purchased
```

Commerce should emit automation events after it records its own domain state.

FlowRoutes should listen to `AutomationEventRecorded`, not Commerce-specific events.

Good:

```text
Commerce records commerce.order_created.
Commerce emits AutomationEventRecorded(commerce.order_created).
FlowRoutes reacts through the generic automation event seam.
```

Bad:

```text
Commerce imports FlowRoutes.
FlowRoutes adds a Commerce-specific listener.
Provider adapters call FlowRouteExternalEvent directly.
```

Automation events should be contact-aware, not contact-required.

A commerce event may have:

```text
contact_id nullable
subject_type = CommerceOrder or CommerceOrderItem
subject_id = related Commerce record
```

## Schema foundation

The first Commerce foundation should add these tables:

```text
commerce_customers
commerce_products
commerce_orders
commerce_order_items
commerce_order_events
```

These tables are intentionally roomy but generic.

They include generic fields such as:

```text
contact_id nullable where contact-linked
status
source
provider nullable
external_id nullable
external_url nullable
ordered_at / cancelled_at / refunded_at / occurred_at where appropriate
currency and cents-based totals where appropriate
raw_payload json for provider snapshots
meta json
timestamps
soft deletes
```

They avoid storefront-specific, checkout-specific, payment-ledger-specific, fulfillment-execution-specific, inventory-specific, and vertical-specific columns.

## Table notes

### commerce_customers

Represents a customer/account identity from a commerce provider, optionally linked to a Core contact.

Important fields:

```text
contact_id nullable
first_name
last_name
name
email
phone
status
currency
first_ordered_at
last_ordered_at
total_orders
total_spent_cents
source
provider
external_id
external_url
raw_payload
meta
```

### commerce_products

Represents a provider/product identity useful for order history, purchase filters, and reporting.

Important fields:

```text
key
sku
name
description
status
product_type
vendor
category
tags
currency
price_cents
published_at
source
provider
external_id
external_url
raw_payload
meta
```

Do not add full variant, inventory, storefront, collection, or price-list tables yet.

### commerce_orders

Represents a provider order/purchase record.

Important fields:

```text
commerce_customer_id nullable
contact_id nullable
order_number
order_name
status
financial_status
fulfillment_status
currency
subtotal_cents
discount_cents
tax_cents
shipping_cents
total_cents
ordered_at
closed_at
cancelled_at
refunded_at
source
provider
external_id
external_url
raw_payload
meta
```

`financial_status` is a provider-style string, not a full payment ledger.

`fulfillment_status` is a provider-style string, not a fulfillment engine.

### commerce_order_items

Represents line-level purchase history and preserves a purchase-time snapshot.

Important fields:

```text
commerce_order_id
commerce_product_id nullable
item_type
sku
name
title
variant_title
options
quantity
currency
unit_price_cents
discount_cents
tax_cents
total_cents
fulfillment_status
source
provider
external_id
external_product_id
external_variant_id
external_url
raw_payload
meta
```

This table is the key future path for questions like:

```text
Which contacts purchased a T-shirt?
```

### commerce_order_events

Represents append-style order lifecycle/provider history.

Important fields:

```text
commerce_order_id
actor_type / actor_id nullable
event
from_status
to_status
occurred_at
source
provider
external_id
payload
meta
```

## First foundation slice

### Schema/model/migration changes

```text
app/Modules/Commerce/Providers/CommerceModuleServiceProvider.php
app/Modules/Commerce/Models/CommerceCustomer.php
app/Modules/Commerce/Models/CommerceProduct.php
app/Modules/Commerce/Models/CommerceOrder.php
app/Modules/Commerce/Models/CommerceOrderItem.php
app/Modules/Commerce/Models/CommerceOrderEvent.php
database/migrations/*_create_commerce_customers_table.php
database/migrations/*_create_commerce_products_table.php
database/migrations/*_create_commerce_orders_table.php
database/migrations/*_create_commerce_order_items_table.php
database/migrations/*_create_commerce_order_events_table.php
database/factories/CommerceCustomerFactory.php
database/factories/CommerceProductFactory.php
database/factories/CommerceOrderFactory.php
database/factories/CommerceOrderItemFactory.php
database/factories/CommerceOrderEventFactory.php
```

Create standard empty directories:

```text
app/Modules/Commerce/Actions
app/Modules/Commerce/Contracts
app/Modules/Commerce/Controllers
app/Modules/Commerce/Data
app/Modules/Commerce/Requests
app/Modules/Commerce/Services
app/Modules/Commerce/Support
```

### Code integration changes

Register `commerce` in `config/modules.php`:

```text
name = Commerce
depends_on = core
provider = CommerceModuleServiceProvider
```

Do not enable Commerce by default unless the client needs it.

Do not add routes, navigation, controllers, provider sync, contact filters, Broadcast filters, Campaign filters, FlowRoutes behavior, Portal surfaces, or Reporting dashboards in the foundation slice.

### Docs/process changes

Update:

```text
docs/modules/commerce.md
docs/module-boundaries.md
docs/project-organization.md
core-project-tree.txt after applying files
```

If schema is added, the table ownership freeze should include:

```text
commerce_customers = Commerce
commerce_products = Commerce
commerce_orders = Commerce
commerce_order_items = Commerce
commerce_order_events = Commerce
```

### Tests only

```text
Commerce module registration/config visibility test
Commerce durable schema/model test
Commerce contact/product/order/item/event relationship test
Commerce no-storefront-table test
```

## Deferred work

```text
Shopify provider adapter
other commerce provider adapters
provider sync commands/jobs/actions
customer/contact matching rules
Commerce read/query services
Commerce contact filter provider
Broadcast purchase-history recipient filters
Campaign purchase-history segments
FlowRoutes commerce automation events
Reporting dashboards
Portal order visibility
product variants table
payments table
refunds table
fulfillments/shipments table
inventory/warehouse tables
checkout/cart/storefront behavior
```

## Open questions and recommended defaults

```text
Use cents-based integer money fields in foundation tables.
Use provider/external_id on synced records.
Use raw_payload for provider snapshots.
Keep financial_status and fulfillment_status as strings for provider compatibility.
Keep products generic and snapshot order items separately.
Do not add product variants as a first-slice table.
Do not add filter seams until a consuming workflow needs them.
```
