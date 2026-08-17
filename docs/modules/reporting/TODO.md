# Reporting TODO

- [x] Phase 7B: add the first concrete external-platform import workflow using Meta CSV, including real-export header/result normalization, period-based measurements, name-only fallback, preview, idempotent import, and stable-ID comparison reads.
- [x] Rewrite the client ad-tracking checklist around the implemented platform-neutral `engage_*` attribution contract, with Meta-specific copy/paste parameters/export instructions and room for later platform appendices.
- [ ] Phase 7C: promote authoritative imported `reporting_external_measurements` history from resettable/re-importable policy into retained Project State transfer, with round-trip coverage.
- [ ] Add additional external-platform adapters only when a concrete client export/API workflow exists; keep provider normalization inside Reporting rather than adding vendor columns to the shared schema.