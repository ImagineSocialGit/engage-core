# Merchant notice — Shopify customer and order data processed by Engage Core

**Draft for merchant distribution and agreement; not an executed DPA or automatically effective contract.** Review with the service owner/merchant and applicable counsel. Include in the relevant customer agreement or privacy/data-processing addendum before relying on it as evidence for Shopify's transparency/agreement controls.

## Processing purpose

When the merchant enables Engage Core Commerce with Shopify, the integration receives the Shopify order/customer data needed to synchronize purchase history, connect orders to the merchant's CRM Contacts, administer/refund/fulfill orders and coordinate inventory. Square POS data may produce provider-neutral inventory changes when independently enabled. Shopify remains the configured online-order and stock authority. These purchase facts do **not** independently authorize marketing emails or SMS.

## Categories of data

- Shopify identifiers for the shop, order, line items and customer; order numbers, timestamps, prices, discounts, tax, shipping totals, refunds, products/variants and fulfillment/payment state.
- Customer first/last/display name, email and phone where needed for CRM reconciliation; guest checkout email/phone where supplied. **The current order-sync query does not request shipping or billing address details or names derived from `displayAddress`.**
- The merchant's corresponding CRM Contact links, customer purchase totals and limited provider metadata. HMAC-authenticated Shopify privacy webhooks are minimized to provider/shop/customer/order IDs and case metadata; customer email/phone are not persisted in the privacy webhook receipt.
- When other Core features (Messaging, Forms, Scheduling, etc.) are separately enabled, their purposes and data categories must be disclosed and governed separately by the selected merchant agreement.

## Use, access and transfers

Personal data is processed only to perform merchant-selected services and required security/privacy operations. Marketing and other optional messaging are subject to their own channel/purpose consent and customer preferences. Authorized staff access should be role-limited, logged where applicable, and protected with strong authentication. Customer data must not be sold or shared for unrelated advertising under this notice. Any processing by hosting, backup, delivery or integration subprocessors must be documented in the merchant's actual subprocessor register and agreements; the precise provider list varies by deployment.

## Retention and rights

The merchant defines and approves any legally required financial/order retention and applicable privacy instructions. Engage's operator must maintain the approved retention schedule, honor verified customer access/redaction requests within applicable timeframes, and investigate the effects of CRM Contacts, other modules, logs, exports and encrypted backups. Shopify privacy events are acknowledged and tracked; manual Commerce pseudonymization alone does not guarantee cross-module erasure. Where legal retention prevents redaction, the merchant must document its lawful basis and related restrictions.

## Security and incident response

Engage Core uses authenticated operator access, per-client permissions, signed webhooks and controlled processing, together with deployment-specific TLS, storage encryption, backups and access monitoring that must be separately verified. The service owner will use an adopted incident-response policy, coordinate investigation and notify the merchant promptly when a credible incident affects their data, subject to the applicable signed agreement and legal duties.

## Acceptance and controlling documents

This disclosure must be issued through a merchant-facing onboarding, legal addendum or accepted service agreement with a version and effective date. The final binding agreement should identify the legal parties, privacy contact channel, controller/processor roles, processing instructions, permitted subprocessors, international transfers (if any), termination/deletion process and security-incident notification terms. **Do not represent this draft as a signed DPA or confirm Shopify questionnaire item 4 until agreement exists.**
