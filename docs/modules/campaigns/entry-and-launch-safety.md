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

## Bounded progression fan-out

Bulk Contact imports persist CampaignEnrollment + MessageChainEnrollment state synchronously but set `eagerProcess=false`.

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

It reuses the existing unique `campaign_enrollments.dedupe_key` field for explicit entry identity and the existing `start_context` field for the compact logical `entry_key`. No audience membership copies, raw queue history, or per-attempt click/reply evidence are added to Campaign metadata.