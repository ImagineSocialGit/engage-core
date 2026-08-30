# Campaign Entry and Launch Safety

Campaign activation and Campaign audience entry are intentionally separate operations.

Activating a Campaign only makes its selected immutable Messaging MessageChain available for future starts. It never selects Contacts and never enrolls a historical audience by itself.

## Public entry contract

`EnrollContactInCampaignAction` is the Campaign-owned single-Contact start seam.

Callers may supply an `entryKey` when the business event or operator action has a stable identity. Campaigns derives a bounded unique enrollment dedupe key from:

- Campaign ID;
- Contact ID;
- SHA-256 of the supplied entry key.

Repeating the same explicit entry key for the same Contact and Campaign returns the original CampaignEnrollment even when its MessageChainEnrollment is already terminal. A distinct entry key remains an intentional new start, subject to the existing open-enrollment and family-priority arbitration rules.

Callers that do not have a stable external identity may omit `entryKey`; existing behavior remains unchanged: an open enrollment is reused, while a terminal enrollment permits a later new start.

## Contact-import starts

Campaign enrollment configured through a Contact import profile uses a stable entry identity:

`contact_import:{profile_key}:{campaign_key}`

Because Contact ID is part of the derived Campaign dedupe key, the same Contact imported repeatedly through the same profile cannot accidentally restart a completed Campaign. An intentionally different profile or explicit entry identity may start a new lifecycle later.

The Contact import occurrence remains the CampaignEnrollment provenance source. Project State therefore treats `ContactImportOccurrence` as a supported deferred polymorphic reference.

## Batch first-message launch timing

Every add-import may expose `campaign_launch_timing` when at least one ready automatic
Campaign exists. A ready choice is active, has saved eligibility criteria, and points to
an active MessageChain with a published current version. The preview labels each choice
with its saved criteria so an operator can connect imported status, relationship,
source, subsource, tag, and other facts to the available follow-up.

The operator must choose one of three outcomes:

- import only;
- start the selected Campaign as soon as the batch completes;
- schedule the selected Campaign's first message for a local date and time.

Import only is the safe default. A filename-matched profile may keep one Campaign key
server-owned, but the operator may still decline launch. Browser input cannot invent a
Campaign key; a profile-free selection must match the ready option set built by
Campaigns.

When a launch is selected, `campaign_launch_timing` remains a
reconciliation/finalization behavior:

1. Core applies the mapped row, domain handlers, and operator-selected treatments.
2. Campaigns evaluates the selected automatic Campaign through its normal saved
   eligibility rules and family arbitration. Only eligible Contacts enroll.
3. While the Contact's import batch is still processing, Campaign automation disables
   eager progression and holds the first MessageChain action in a temporary far-future
   non-due state.
4. The row-level processor verifies that the open enrollment is new for this import and
   still has an unmaterialized first MessageChain action. It does not change timing
   row-by-row.
5. After every CSV row finishes, the Campaign batch finalizer selects only Campaign
   enrollments for Contacts in this import whose enrollment started during this batch.
6. In one transaction, it verifies that none has materialized a ScheduledMessage and
   applies one operator-selected first-message timestamp to all linked active
   MessageChainEnrollments.

This makes the first wave visible atomically after batch completion. The temporary
non-due hold also prevents the periodic Messaging due scanner from discovering an
early row before finalization, even when the Campaign's authored first-step delay is
immediate or the operator-selected launch time passes while the CSV is still importing.

The selected value is **first-message timing**, not a rewrite of Campaign enrollment
`started_at`. After the first wave becomes terminal, Messaging continues normal
MessageChain timing from that runtime point.

If the selected time has passed by the time a long import finishes, the finalizer makes
the whole safe batch due at finalization time rather than releasing rows piecemeal.

Existing open Campaign enrollments that predate the import are never retimed.

Static import facts do not simulate event-only Flow Routes. For example, a high-intent
reply Route still requires a real correlated inbound reply and its normalized reply
profile/intent Automation Event. The import launch choice only starts the eligible
Campaign that can produce that later conversation.

## Bounded progression fan-out

Bulk Contact imports persist CampaignEnrollment + MessageChainEnrollment state synchronously but set `eagerProcess=false`.

This protection applies both to the direct Campaign import post-processor and to
Campaign enrollment reached through automation while the Contact's current
`ContactImportBatch` is still `processing`. A status-treatment → FlowRoute → Campaign
path therefore does not bypass the bulk-entry guard. Launch-timed imports additionally
use the temporary non-due first-action hold described above until batch finalization.

They do not enqueue one immediate progression job per imported row.

Messaging's existing due-enrollment scanner is the replenishment boundary. Each scanner run:

1. selects only due active MessageChainEnrollments;
2. limits the selection with `BulkMessageDeliveryPolicy::chunkSize()`;
3. dispatches those progression jobs to the dedicated `bulk_messages` queue.

This preserves normal MessageChain timing and immutable-version semantics while preventing a large import from producing an uncontrolled immediate progression-job burst.

Direct single-contact starts and normal automation starts remain eager by default because they are not bulk audience operations.

## Ownership boundaries

- Campaigns owns Campaign entry identity, family arbitration, provenance, and CampaignEnrollment.
- Messaging owns MessageChainEnrollment progression, due scanning, queue replenishment, ScheduledMessage execution, consent, suppression, and provider delivery safety.
- Contact imports are a Core producer that invoke the public Campaign entry seam through the registered Campaign post-processor.
- Campaigns does not depend on FlowRoutes, Workflow, InboundMessaging, Webinars, or other producer modules.

## Database footprint

This contract adds no table and no column.

It reuses the existing unique `campaign_enrollments.dedupe_key` field for explicit entry
identity and the existing `start_context` field for the compact logical `entry_key`.

Batch first-message timing stores one normalized timestamp in the existing
`ContactImportBatch.meta.post_import` configuration/finalization summary. It does not
copy that timestamp onto every Contact, CampaignEnrollment, or ScheduledMessage, and it
does not create a second audience-membership table.

No raw queue history or per-attempt click/reply evidence is added to Campaign metadata.