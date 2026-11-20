# Shopify protected-customer-data questionnaire — Engage Core

**Evidence baseline:** `engage-source-20261120-220757.tar.gz` plus the four applied security/privacy patches and the follow-up patches from this branch. **Status:** technical review, not a compliance certificate. Use this for the Shopify Dev Dashboard's **Data protection details** (16 questions). Do not claim external controls are operational merely because their design exists in source.

## Required field access and stated purpose

Request **Level 1: Customer data — App functionality**. Request **Name, Email, and Phone — App functionality** for Shopify order-to-CRM customer reconciliation. **Do not request Address.** The current Shopify order reader deliberately does not query shipping/billing addresses or `displayAddress`. This scope is for matching orders and customers; it does not itself authorize marketing outreach.

## Answer sheet

These are the questions in the **same order as the Shopify dashboard screenshots**, even though Shopify's published requirements page groups some controls differently. **Conditional = select Yes only after the evidence named below exists; otherwise select No.** N/A is appropriate only where the stated facts hold.

| # | Question | Selection / required fact | Current code evidence / blocker |
|---|---|---|---|
| 1 | Minimum personal data? | **Yes** for the Shopify order integration after the minimization patch is applied/tested | Order reader now queries no shipping/billing/display address; retains order/customer identity and Commerce-required name, email, phone and purchase facts. Validate other selected integrations separately. |
| 2 | Tell merchants what data is processed and why? | **Yes only when** `merchant-shopify-data-processing-notice.md` is actually supplied to every applicable merchant | Draft notice exists; source does not show delivery or assent. |
| 3 | Limit personal-data use to the stated purpose? | **Conditional** — verify actual workflows/permissions conform to the notice | Shopify order sync/Commerce boundaries supported; connected Core marketing/automations must use valid consent and declared purposes. |
| 4 | Privacy and data-protection agreements with merchants? | **Conditional** — a signed/accepted agreement is required | Draft terms are not an executed merchant agreement. |
| 5 | Apply customers' consent decisions? | **Conditional** — verify consent/revocation across *all enabled* processing | Messaging consent implemented; Shopify order/CRM privacy requests need merchant-managed handling. |
| 6 | Respect opt-outs of data selling? | **Not applicable** only if no covered sale/sharing takes place; otherwise verify a real opt-out process | Neither source nor this branch can establish company-wide data-sale practices. |
| 7 | Consequential automated decision opt-out? | **Not applicable** only if no legally/significantly impactful automated decisions are made | Ordinary order reconciliation/inventory adjustments are not those decisions; verify other enabled workflows. |
| 8 | Enforce personal-data retention periods? | **No until** retention schedule is adopted and actually enforced for CRM, Commerce, webhooks, logs, exports and backups | Scoped Commerce pseudonymization and manual privacy resolution exist; no complete cross-module, scheduled retention guarantee. |
| 9 | Encrypt data at rest and in transit? | **Conditional** — verify primary DB/disks/files and actual HTTPS connections | Code/transport design alone cannot verify production at-rest encryption. |
| 10 | Encrypt backups? | **Conditional** — verify deployed Restic repository and successful encrypted backup/restore | Infrastructure defines encrypted Restic backups; deployment/activation unconfirmed. |
| 11 | Separate test and production data? | **Conditional** — verify distinct databases/secrets and no raw production PII imported into Dev Lab | Separate local Dev Lab and production environments exist; imports of production data must be specifically audited. |
| 12 | Have a data-loss prevention strategy? | **Conditional** — adopt and exercise the DLP section of `customer-data-protection-operations.md` | Controls include least privilege, private logs, authentication, webhook signing; verification and monitoring remain operational. |
| 13 | Limit staff access to customer data? | **Conditional** — `access.require_profiles` enabled after backfill, permissions reviewed, staff server/API access restricted | Role grants exist; unprofiled legacy owners remain a compatibility fallback while strict mode is disabled. |
| 14 | Require strong staff passwords? | **Yes for CRM credential creation/reset**; verify other accounts and legacy credentials before company-wide Yes | 12+ character mixed-case/number/symbol policy tested; existing credentials not forcibly rotated, other IAM outside CRM. |
| 15 | Keep access logs for customer data? | **Conditional** — ensure log collection/review covers relevant paths, API/system access and exports | Contact list/detail/lookup/CSV export initiation, Commerce orders and privacy queue reads logged without customer PII; background/DB/admin access needs separate coverage. |
| 16 | Have security incident response policy? | **Conditional** — adopt, assign owner, exercise and retain incident runbook | `customer-data-protection-operations.md` contains a proposed incident procedure; no operational adoption is evidenced by a repository commit. |

**Do not submit unsupported Yes answers just to activate development access.** Shopify explicitly permits protected customer-data access on development stores after selecting fields, completing the details and saving, without public app review; that does not waive actual security obligations.

## Verify before selecting Yes

On the **selected non-production client**, after applying/tests:

```bash
cd /var/www/engage-core
php artisan route:list --name=crm.commerce.privacy
php artisan route:list --name=webhooks.commerce.shopify.privacy
php artisan shopify:admin-check
# Profile inventory: prints a count, no identities
php artisan tinker --execute='dump(\Illuminate\Support\Facades\DB::table("users")->leftJoin("user_access_profiles", "users.id", "=", "user_access_profiles.user_id")->whereNull("user_access_profiles.user_id")->count());'
# Audit channel existence is not proof of collection or monitoring
ls -ld storage/logs && ls -l storage/logs/personal-data-access* 2>/dev/null || true
```

On each **actual hosting environment**, an authorized operator must separately verify:

1. **TLS:** HTTPS enforced for CRM/webhook endpoints, valid certificate, TLS to database/storage/providers where traffic crosses untrusted networks. Verify Nginx and DB/Spaces connection configs without displaying secrets.
2. **At-rest encryption:** MySQL underlying volume/database and Spaces buckets/objects; identify key owner and rotation/recovery procedure. `findmnt -T /var/lib/mysql -o SOURCE,FSTYPE,OPTIONS` identifies a mount, **not proof of encryption**. Check hosting provider guarantees and real server configuration.
3. **Encrypted backups:** scheduled Restic unit/timer actually enabled, latest successful snapshot, password/key escrow and a completed restore rehearsal. `systemctl list-timers --all | grep -Ei 'restic|backup'` is a discovery command, not a backup test.
4. **Data segregation:** separate production/staging/development databases, credentials, storage and support access. Confirm no unsanitized production exports on developer machines.
5. **Security operations:** adopted retention schedule, privacy/merchant agreement, named incident owner, logged access/export review, and a real privacy-request escalation process.
6. **Strict profiles:** count/profile all CRM users, then set `require_profiles => true` in the selected client's `config/access.php` only when a named active owner can still log in.

Do not copy secrets, raw customer rows, access tokens, real addresses or unredacted backup output into evidence documents or chat.

## Current Shopify access blocker

A failure such as `This app is not approved to access the Customer object` means the protected-data selection in the **Shopify Dev Dashboard** has not taken effect for the app. Apply real answers to all required fields, save the settings, confirm app distribution/version/installation if still blocked, then retry `shopify:admin-check` and the **existing** test order. Do not relax the readiness query.

References: https://shopify.dev/docs/apps/launch/protected-customer-data and https://shopify.dev/docs/apps/build/compliance/privacy-law-compliance
