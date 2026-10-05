# Generic Document Evidence

Documents already has the horizontal shape needed for reusable dated evidence. This capability should be reused by vertical modules instead of creating a new table for each kind of certificate, license, vaccination record, waiver, or other expiring document.

## Existing contract

`DocumentRequirementDefinition` is the reusable document type or requirement.

Examples:

```text
rabies_certificate
insurance_certificate
professional_license
signed_waiver
```

`DocumentUpload` is the actual uploaded evidence.

The upload already supports:

```text
subject_type / subject_id
document_requirement_definition_id
review_status
expires_at
replaces_document_upload_id
```

The subject is polymorphic. A document can therefore concern a Contact, Pet, team member, or another model without changing Documents schema.

## Valid-through semantics

`expires_at` is the document's validity boundary.

A caller may supply an explicit validity date when storing a document. When it does not, a requirement definition with `expires_after_days` may derive the validity boundary from the submission time.

Do not add a second `renewal_date` or vertical-specific expiration column for the same meaning.

A future renewal reminder may calculate:

```text
expires_at - current time
```

and decide whether to create a Task, send a transactional reminder, or surface an item elsewhere. That notification policy is separate from the evidence record itself.

## Evidence resolution

`DocumentEvidenceResolver` answers the generic question:

```text
Does this subject have approved evidence for requirement X that remains valid through date Y?
```

It intentionally does not know what the requirement means.

Examples of vertical interpretation:

```text
PetServices:
  Appointment Type requires rabies_certificate through appointment end.

Contractor workflow:
  Work may proceed only while insurance_certificate remains current.

Licensing workflow:
  professional_license must remain current through the scheduled service date.
```

The vertical owns the requirement meaning. Documents owns the evidence, review state, and validity period.

## Review

A requirement with `requires_review = false` is approved for evidence resolution immediately when stored.

A requirement with `requires_review = true` does not satisfy evidence resolution until the upload's existing review lifecycle marks it approved.

Customer-entered evidence must never be treated as verified merely because it was uploaded.

## Renewal

This foundation does not introduce a renewal scheduler or notification engine.

That is deliberate. The durable fact is the validity boundary already stored in `expires_at`. A later workflow may use that fact to create Tasks or transactional reminders without adding another document lifecycle table.