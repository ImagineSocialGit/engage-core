# Customer data protection: retention, DLP and incident response

**Status: proposed operator policy.** Requires adoption by the service owner and each merchant's agreed processing/retention instructions. It is not evidence that controls have already run. Owner assigns staff/contractors, approves the retention schedule and checks existing production obligations before putting it into effect.

## Responsibility and scope

Engage Core processes Shopify orders, customer identifiers, names, emails, phones, CRM linkages and purchase history on behalf of a merchant. Depending on enabled modules it may also store messages, documents, consent and other data. The merchant determines permitted purposes, consumer responses and legally necessary financial retention; Engage's service owner controls technical security, subprocessors, response execution and production access. Do not use order/checkout data for optional marketing unless the selected purpose/channel has required permission.

## Retention and erasure schedule — adopt and verify before claiming enforcement

| Dataset | Proposed rule | Implementation reality |
|---|---|---|
| Shopify Commerce customers | Retain while necessary for active merchant order/CRM services; handle verified customer redaction requests within the Shopify response window, subject to legal holds. Keep a minimal provider identity tombstone to stop reimport. | Scoped Commerce pseudonymization exists; CRM Contact and other module data are NOT automatically removed. |
| Order/payment records | Preserve legally required order/tax/refund facts for the merchant-specific statutory period; minimize or unlink unnecessary customer identifiers when legally permitted. | No universal period can be imposed safely; review merchant jurisdiction/contract before disposal. |
| Shopify privacy requests | Review promptly; record merchant case and disposition. **Proposed:** retain resolved minimized receipts for 90 days absent hold, then schedule approved deletion. Pending requests are never auto-purged. | Operator can close with evidence; no automatic receipt-pruning job is provided. |
| PII access audit | 90-day daily rotation on application disk, subject to central log collection/security incident holds. | Core logging channel rotates locally at 90 days; aggregated logs, filesystem copies and backups need independent expiry settings. |
| Contact exports/attachments | Avoid durable export copies unless required; store in private, access-controlled areas and delete once delivered or after a defined short business window. | Existing export streams; other modules/files must be inventoried. |
| Backup snapshots | Encrypt and retain per the approved operational backup rotation. Deleted source records may remain in immutable encrypted backups until those backups expire; prohibit routine reimport of erased customer identities. | Restic design exists; confirm timers, retention, encryption, restore tests and handling of holds in live environments. |

**No destructive global purge is authorized by this document.** A merchant privacy request is not authority to delete unrelated Core records or financial evidence. Record legal holds and their expiry in the merchant case; complete a periodic quarterly review for stale records and long-running holds. Shopify privacy requests normally need action within **30 days**; escalate immediately if deadlines or legal holds conflict. Receipt status `completed` is an operator attestation, not technical proof of full erasure.

## Privacy request procedure

1. Validate the request, merchant identity and applicable legal basis. Incoming Shopify HMAC and shop identity are verified before request intake; do not trust an email or screenshot alone.
2. Assign a case reference without customer PII. Review the Commerce privacy queue and supporting CRM/Commerce/attachments/messaging data; record the receipt date and due date.
3. For **data access**: securely assemble and transmit the authorized customer's data to the merchant using approved private channels. Verify identity and scope. Do not place PII in access logs, issue tracker titles or webhook receipts.
4. For **customer redaction**: inspect financial retention, optional marketing/consent records and third-party processors. Invoke the scoped Commerce redaction when appropriate, then separately review linked Contacts, Orders and other module data; preserve the non-rehydration tombstone.
5. For **shop redaction**: confirm uninstall/termination and lawful merchant retention; review all merchant-scoped data, never other clients' records.
6. After all necessary external actions, legal hold review and merchant confirmation, close the operator case with a reference and truthful disposition. A 2xx webhook response only acknowledges receipt.
7. Review pending cases each business day and escalate requests before the 30-day window. Log decisions without PII. Verify backups and later restoration cannot accidentally rehydrate deleted identities.

## Data-loss prevention (DLP) operating standard

- Restrict read/export permissions to explicitly assigned staff roles; review access profiles quarterly and on termination. After migration, enforce `access.require_profiles` on each eligible client; inspect API tokens, SSH/sudo and database administration separately.
- Require strong CRM credentials, secure staff SSO/passwords and MFA where available; terminate stale credentials. Do not log access tokens, message bodies, names, emails or phone numbers in audit metadata.
- Restrict public traffic to necessary routes, require HTTPS in production, enforce HMAC on webhooks and request forgery protection for authenticated operator actions. Rate-limit and inspect unusual export/download activity.
- Keep customer-bearing database data, Spaces objects, exports, backups and logs private and encrypted as required. Validate actual configuration and key management, not just source defaults.
- Use distinct secrets/data for development, staging and production; create synthetic test customers. Do not take raw production customer dumps into local tests.
- Review `personal-data-access` logs on a recurring schedule for unusual lookup/export frequency, after-hours activity, or misuse. Escalate anomalies; record outcomes in a restricted operations system.
- Verify encrypted Restic backups and perform restore rehearsals. Limit staff ability to download or copy customer datasets; maintain evidence of cleanup and access reviews.

## Security incident response policy — adopt with an accountable owner

**Incident owner:** designated service operator/security lead. **Deputy:** designated infrastructure operator. **Privacy/legal decision maker:** merchant-appointed contact and counsel. Replace these role descriptions with actual authorized people in the secured operations registry; don't publish personal contact details in source.

**Severity**

- **Critical:** confirmed widespread customer-data exposure, active unauthorized access, or loss of control over production credentials.
- **High:** suspected data extraction, significant provider compromise or customer-data loss.
- **Moderate:** contained attempted access, limited unauthorized disclosure or vulnerability without known exfiltration.
- **Low:** suspicious activity with no confirmed customer impact.

**Response**

1. **Identify and record:** open restricted incident case, UTC onset and discovery times, affected clients/systems, initial severity and evidence. Limit copying raw PII.
2. **Escalate promptly:** contact service incident owner and affected merchant; for Critical/High, page immediately using the established out-of-band contact method. Engage legal/privacy contacts to determine applicable notice deadlines (which vary by law and contract).
3. **Contain:** revoke affected tokens/sessions, isolate compromised application workers or access paths, block dangerous ingress as appropriate; preserve logs and forensic evidence. Do not indiscriminately destroy evidence or violate recovery policies.
4. **Investigate:** establish access scope, types/number of affected records, whether data actually left systems, and what merchants/subprocessors were affected. Securely preserve an evidence timeline.
5. **Eradicate/recover:** patch vulnerabilities, rotate exposed credentials, validate data integrity, restore from verified encrypted backups if needed, and monitor for recurrence. Stage fixes and promote using standard immutable deployment controls.
6. **Notify:** obtain the merchant/privacy/legal decision about required notices to individuals, regulators, Shopify and subprocessors. Record what was sent and when. Never assert a universal statutory 72-hour deadline without jurisdiction review.
7. **Review:** within the next business review cycle, document root cause, improvement owners, verified remediation and a tabletop/restore follow-up. Retain incident evidence under an approved restricted legal-hold schedule.

**Verification required before answering Shopify Yes:** service owner formally adopts this policy; actual escalation contacts exist, staff know the process, at least one tabletop exercise is recorded, and logs/backups/permissions are independently verified per deployment. Merely committing this document is not sufficient.
