# Documents Portal Surface

The Documents Portal integration is a cross-module adapter. Documents remains dependent only on Core; Portal remains responsible for account authentication and the customer shell.

The first customer slice is intentionally guided by existing `DocumentRequest` records:

```text
Portal user
  -> authorized document subject
  -> DocumentRequest
  -> upload
  -> private download
```

It does not add a free-form customer document library, requirement builder, reminder scheduler, or compliance engine.

## Subject access

Documents does not decide which vertical records a Portal user owns or may manage.

The app-level `PortalDocumentSubjectRegistry` collects authorized subjects from providers. Core contributes active Portal-linked Contacts. Optional verticals may contribute their own subjects without Documents importing those vertical modules.

A record with `subject_type` / `subject_id` is authorized by that explicit subject. `contact_id` is used as a Portal authorization fallback only when the record has no explicit subject.

This distinction prevents a document about a vertical subject from becoming visible merely because its bookkeeping `contact_id` points at a linked Contact.

## Uploads

Portal uploads use the existing `DocumentAttachmentLibrary`. The request's subject, requirement definition, Contact linkage, review behavior, private storage rules, and expiration behavior remain Documents-owned.

The optional `Valid through` date is stored in the existing `document_uploads.expires_at` field. When omitted, an existing requirement `expires_after_days` policy may supply the expiration date.

No new renewal-date column is required.

## Future vertical use

A vertical provider may expose an authorized subject such as a Pet. Documents can then display and accept requested documents about that subject without knowing what the subject means.

The vertical may later consume `DocumentEvidenceResolver` to decide whether an approved requirement satisfies its own business rules.