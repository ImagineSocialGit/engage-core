# Reporting TODO

- [x] Phase 7B: add the first concrete external-platform import workflow using Meta CSV, including real-export header/result normalization, period-based measurements, name-only fallback, preview, idempotent import, and stable-ID comparison reads.
- [x] Rewrite the client ad-tracking checklist around the implemented platform-neutral `engage_*` attribution contract, with Meta-specific copy/paste parameters/export instructions and room for later platform appendices.
- [x] Phase 7C: promote authoritative imported `reporting_external_measurements` history into retained Project State transfer, with Reporting section v2 identity/column coverage and round-trip tests.
- [x] Phase 7D: calibrate browser request classification so recognized same-origin browser traffic remains likely-human when optional Fetch Metadata is absent, with classifier provenance advanced to v2.
- [ ] Phase 8: turn retained first-party funnel metrics plus external platform measurements into prioritized, factual client actions and comparisons without claiming unsupported causation.
- [ ] Add additional external-platform adapters only when a concrete client export/API workflow exists; keep provider normalization inside Reporting rather than adding vendor columns to the shared schema.