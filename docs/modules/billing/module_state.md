# Billing Module

Billing is a universal, silent Engage Core module.

It owns provider-neutral pricing and money-collection state that other modules may consume without learning Stripe, Square, or another payment provider.

A concise definition:

> Billing owns price definitions, collection requirements, payment obligations, recognized payment state, and collection-provider contracts. The consuming module owns why money is owed and the workflow in which collection is presented.

## Current committed foundation

The first Billing foundation owns:

```text
billing_prices
billing_collection_requirements
billing_payment_requests
billing_payments

BillingPrice
BillingCollectionRequirement
BillingPaymentRequest
BillingPayment

BillingCollectionAmountResolver
CreateBillingPaymentRequestAction
RecordBillingPaymentAction

BillingCollectionProvider
BillingCollectionProviderRegistry
BillingCollectionRequest
BillingCollectionResult
```

Billing directly depends only on Core.

Billing has no standalone navigation in this foundation. A consuming loud module presents the relevant price, deposit, payment, or balance state inside its own workflow.

## Price and collection are separate concepts

A price answers:

```text
What does this thing cost?
```

A collection requirement answers:

```text
How much, if anything, must be collected now?
```

A billable subject may therefore have a price without automatically having an online collection requirement.

The current collection modes are:

```text
none
full
fixed_deposit
percentage_deposit
pay_later
```

Examples:

```text
Free
  total: $0
  mode: none
  due now: $0

Full payment
  total: $60
  mode: full
  due now: $60

Fixed deposit
  total: $4,000
  mode: fixed_deposit
  fixed amount: $500
  due now: $500
  balance after deposit: $3,500

Percentage deposit
  total: $1,000
  mode: percentage_deposit
  percentage: 25%
  due now: $250
  balance after deposit: $750

Pay later
  total: $150
  mode: pay_later
  due now: $0
  outstanding: $150
```

Percentages are stored as basis points so 25% is `2500`.

Money is stored in integer minor units (`*_cents`) plus a three-letter currency code. Billing does not use floating-point money.

## Payment request snapshot

`BillingPaymentRequest` is the runtime obligation snapshot.

It copies the price total, collection mode, and amount due now at creation time. Later edits to the reusable `BillingPrice` or `BillingCollectionRequirement` do not rewrite an existing payment request.

The request may link to:

```text
Contact
optional polymorphic context
```

The context is intentionally generic. A later Scheduling bridge may use a BookingHold or Appointment without Billing importing Scheduling. Another module may contribute a different context without adding its columns to Billing.

A payment request tracks:

```text
total_cents
due_now_cents
paid_cents
```

The difference between immediate collection and remaining balance stays explicit:

```text
immediate remaining
    max(0, due_now_cents - paid_cents)

outstanding balance
    max(0, total_cents - paid_cents)
```

For a deposit, satisfying the immediate collection requirement does not falsely mark the whole obligation settled.

## Provider boundary

Payment providers are integrations/adapters, not Billing modules.

A provider implements:

```text
BillingCollectionProvider
```

and is registered under:

```text
billing.collection_providers
```

Billing supplies provider-neutral collection input/output DTOs. A future Stripe package may create Checkout Sessions or PaymentIntents and return a provider identity plus redirect URL through that contract.

Billing must not contain:

```text
Stripe SDK calls
Square SDK calls
provider secrets
webhook signature verification
card data
provider-specific payment-method state
```

`RecordBillingPaymentAction` is the public reconciliation seam for provider adapters to persist recognized payment state idempotently by provider/external identity.

## Operator-management direction

Billing is silent today and this foundation does not add standalone navigation. That does not mean future provider-backed money operations must be managed only in the payment provider's dashboard.

For an approved workflow, the normal direction is:

```text
common business operation
    -> consuming Engage Core workflow, or a later justified Billing operator surface
    -> Billing/provider-neutral action contract
    -> configured collection/payment provider
    -> authoritative provider execution
    -> provider result reconciled into Billing
```

Potential future Core-managed operations include straightforward actions such as collecting an amount, issuing a simple refund, or changing/canceling a subscription **only after** the corresponding Billing domain and provider-neutral contracts are explicitly approved and implemented.

Provider administration should remain external for concerns such as:

```text
account identity/KYC
banking and payout setup
provider account billing
legal/compliance agreements
tax registration/provider tax administration
advanced fraud/risk configuration
rare provider-specific payment-routing/infrastructure settings
```

The goal is not to reproduce a payment provider dashboard. It is to keep ordinary business work inside the owning Engage Core workflow when Core can represent the intent safely, while the configured provider remains authoritative for transaction execution and provider-owned state.

The deferred-capability list below remains authoritative for what Billing does **not** implement yet. This section defines the management-surface direction for capabilities if and when those workflows are approved.

## Scheduling boundary

Scheduling owns:

```text
Appointment Types
availability
hosts/resources
booking holds
appointment lifecycle
time/capacity
```

Billing owns:

```text
price
collection requirement
payment obligation
payment state
```

This foundation does not make Scheduling depend on Billing.

A later optional Scheduling/Billing bridge may associate a BookableService with a Billing price and require the payment request's immediate amount to be satisfied before the reservation/confirmation transition that the product chooses to gate.

Scheduling must remain fully usable when Billing is disabled.

## Commerce boundary

Commerce remains the owner of:

```text
catalog/product identity
Commerce offers
provider-backed storefront state
Commerce orders
Commerce checkout orchestration
purchase history
inventory effects
```

Commerce does not depend on Billing in this foundation.

Commerce's existing provider checkout/payment roles describe its product/order provider ecosystem. They are not a reason to make Billing own Commerce orders or to make Commerce the generic payment system for Scheduling.

Any future Commerce use of Billing must be an explicit integration decision rather than an implicit shared-table dependency.

## Portal boundary

Portal owns customer authentication and customer account context.

Billing does not depend on Portal. A Portal-facing payment surface may later be contributed through public integration seams while Billing continues to use Core Contact identity and provider-neutral payment records.

## Deferred capabilities

Do not add these until an approved workflow requires them:

```text
invoices
subscriptions
tax calculation
discount engines
accounting/general ledger
stored payment methods
installment schedules
multi-currency conversion
refund-detail workflow
chargebacks/disputes
customer Billing workspace
standalone Billing navigation
```

Refund and dispute provider behavior belong to later explicit contracts rather than being guessed into the first foundation.

## Migration ownership

Billing owns:

```text
database/migrations/modules/billing
```

Billing is new and not enabled by default. This initial migration creates its foundation schema; there is no production Billing data to preserve.

After Billing has shipped with real client data, future schema changes must use append-only migrations.