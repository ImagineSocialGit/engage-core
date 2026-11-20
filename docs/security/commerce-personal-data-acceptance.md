# Commerce protected customer data — acceptance and rollout

Source audit basis: `engage-source-20261120-220757.tar.gz`.

This is an engineering checklist, not a certification, a data-processing agreement,
or a retention policy. A Shopify webhook receipt means **received**, not exported,
redacted, or legally fulfilled. Shopify generally expects privacy requests completed
within 30 days; use merchant counsel and actual obligations for legal holds.

## Scope of this branch

- Core access can be made strict **per client** through `access.require_profiles`.
  Keep the existing legacy fallback during review of deployed clients.
- Explicitly profiled users with unrecognized roles are denied access.
- New CRM credentials and password resets require 12+ characters, upper/lower,
  numbers and symbols. Existing password hashes are not automatically rotated.
- Successful Contact and Commerce Order list/detail reads are logged (actor ID,
  resource type, resource ID, client key; not customer names/emails/phones) to
  `storage/logs/personal-data-access-*.log`. Set an actual log retention/collection
  policy before production; the local channel rotates daily with a 90-day default.
- Shopify privacy event intake verifies HMAC and shop, stores minimized identities,
  and exposes pending requests in the Commerce operator surface.
- Authorized operators may pseudonymize a **provider-scoped Commerce customer**
  after reviewing a customer redaction request. It unlinks historical Commerce
  orders from the CRM Contact and prevents provider sync from repopulating those
  Commerce customer fields. It does **not** erase the shared CRM Contact,
  redact every historical payload, delete financial records, or complete the
  Shopify privacy request automatically. It intentionally keeps a provider ID
  tombstone to block rehydration.

## Strict profile rollout

Use the selected client context. Before enabling strict profiles, inspect every
existing CRM user; the original access migration backfilled existing users,
but unprofiled records may have been introduced later by scripts or imports.

```bash
php artisan tinker --execute='dump(\Illuminate\Support\Facades\DB::table("users")
    ->leftJoin("user_access_profiles", "users.id", "=", "user_access_profiles.user_id")
    ->whereNull("user_access_profiles.user_id")
    ->count());'
```

After reviewing missing users, assign intended access profiles using the existing
Team management surface. Once the count is **zero**, set `require_profiles => true`
in the selected client's `config/access.php`. Verify an active owner can log in
and a missing-profile user cannot; do not blindly backfill everybody as owner.
Repeat per staging/production client after independent authorization.

## Shopify privacy operating procedure

1. Register the three compliance topics in the Shopify app version, separately
   from the shop-specific order webhook subscriptions:
   `customers/data_request`, `customers/redact`, `shop/redact`.
2. Point the app-specific topics to the verified HTTPS endpoint
   `https://webhooks.<client-domain>/commerce/shopify/privacy`. Verify invalid
   signatures receive 401 and valid requests create pending minimized receipts.
3. A merchant privacy owner reviews **Commerce → Privacy requests** regularly,
   tracks the received date and 30-day window, and decides applicable legal holds.
4. `customers/data_request`: compile the appropriate Commerce + shared CRM data,
   verify authorization and securely provide it to the merchant. Receipt intake
   is not the export itself.
5. `customers/redact`: authorized operator can pseudonymize the scoped Commerce
   customer; separately review linked CRM Contact, shared modules, attachments,
   backups and legally retained order records. Email-only requests require
   additional verified identity matching; do not guess by names or SKUs.
6. `shop/redact`: coordinate merchant-specific data lifecycle and contracts;
   never delete the entire Core client or another provider's records just because
   Shopify sent an uninstall event.
7. Keep a referenced external evidence record and confirm completion by the
   relevant deadline. An authorized operator can record a final review disposition and case reference after completing cross-module and retention checks; requests are never auto-closed.

## Shopify questionnaire — additional evidence still required

| Question(s) | Required verification outside this source snapshot |
| --- | --- |
| 1–3 | Actual data inventory, purpose-specific processing notice, merchant disclosure. |
| 4 | Signed merchant agreement / DPA and contact for privacy requests. |
| 5–7 | Consent policy across modules; documented data-sale and automated-decision assessment. |
| 8 | Approved retention rules for Contacts, Commerce records, audit logs, inbox and backups; erasure across modules. |
| 9 | HTTPS enforcement plus actual MySQL / Spaces encryption at rest configuration. |
| 10–12 | Deployed encrypted Restic repository, successful recent backups and restore exercise, access controls and DLP procedures. |
| 13 | Per-client user-access profile audit, least-privilege roles and strict-profile rollout. |
| 14 | New password policy verified; existing user credential policy and MFA (if used) need operational review. |
| 15 | Private log collection and retention configured; additional surfaces requiring access audit inventoried. |
| 16 | Written incident-response plan, owner, notification procedure and exercised response. |

The infrastructure snapshot includes an encrypted Restic backup design, but that
is not proof that any particular deployed client runs backups or encrypts its
primary database. Do not select Yes on the Shopify questionnaire without checking
operational reality.

**No provider or merchant production account is mutated by this branch.**
