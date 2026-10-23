# Contact CSV external evidence

Contact import mapping can retain historical signup/confirmation timestamps,
external record identifiers, country/region assertions, and IANA timezones.
These values are not permission grants. Evidence is stored on
`contact_import_occurrences.meta.import_evidence`, so later imports do not
replace the historical row evidence. Raw opt-in/confirmation IPs are **not**
collected in this batch; introduce a restricted retention/access policy before
storing those fields.

Valid IANA timezones are stored on Contact with source `import`; malformed
values are recorded as rejected occurrence evidence rather than stored as
usable timezone identifiers. The client's timezone remains the fallback when
there is no valid contact preference.

**Location owns geographic facts.** Core collects raw `country_code` / `region_code`
values as per-occurrence evidence regardless of whether Location is installed.
When Location is enabled, its `ImportContactGeographyHandler` uses the generic
Core import contract to project *valid* country/region assertions into shared
`locations` (`type=region`, `precision=country|region`) and the importing contact's
`contact_locations` link (`type=other`, `source=external_geo_estimate`). The link
is non-primary and unverified; it does not claim a home, work, billing, shipping,
or physical address. Existing verified or manually entered addresses are not
changed. A repeated import updates only the importer-owned estimated link;
historical values remain in `contact_import_occurrences.meta`.

Location has an address normalization contract, but it requires an actual address;
no fabricated street/city/ZIP is generated for a country/region estimate. When
Location is disabled, no normalized geographic profile or contact-location link
is created. Enabling Location later does not automatically replay old occurrences.

When Messaging is enabled, import fields also include external email delivery
status and cleanup evidence. The following values suppress email delivery:
`cleaned`, `bounce`, `bounced`, `hard_bounce`, `repeated_soft_bounce`,
`complaint`, `complained`, `spam_complaint`, `invalid`, `invalid_destination`,
`suppressed`, `provider_suppressed`, and `blocked`. A mapped nonempty
`CLEAN_TIME` in `YYYY-MM-DD ...` format also suppresses email when no status is
provided. Missing/unknown status without valid cleanup evidence does nothing.
The current provider-neutral Messaging gate enforces imported suppressions
across email purposes, independently of consent; it leaves CRM status alone.

For a file known *as a whole* to contain cleaned email addresses, use a
**client-owned** contact import profile with an explicit default. Example
snippet for a client's `config/contact_imports.php` (merge with existing
profiles rather than replacing other configuration):

```php
'profiles' => [
    'external_cleaned_audience' => [
        'label' => 'External cleaned email audience',
        'filename_contains' => ['cleaned_email_audience_export'],
        'defaults' => [
            'external_email_status' => 'cleaned',
        ],
    ],
],
```

Review the selected profile and mapping during import. This default is
necessary to cover *every row*, including records with no cleanup timestamp.
Only apply the default to a verified cleaned export, never a subscriber list.
`OPTIN_TIME`/`CONFIRM_TIME` and source IPs must not automatically grant
marketing permission or reverse revocations. `EMAIL_TYPE` and provider-specific
engagement scores are intentionally ignored.

This batch records preferences and import evidence for future use. Location's
unverified estimate is not automatically promoted into area memberships or used
for geographic targeting until a feature explicitly opts into inferred evidence.
It does not change Campaign or Broadcast scheduling behavior.