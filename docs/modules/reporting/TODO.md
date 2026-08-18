# Reporting TODO

- [x] Phase 7B: add the first concrete external-platform import workflow using Meta CSV, including real-export header/result normalization, period-based measurements, name-only fallback, preview, idempotent import, and stable-ID comparison reads.
- [x] Rewrite the client ad-tracking checklist around the implemented platform-neutral `engage_*` attribution contract, with Meta-specific copy/paste parameters/export instructions and room for later platform appendices.
- [x] Phase 7C: promote authoritative imported `reporting_external_measurements` history into retained Project State transfer, with Reporting section v2 identity/column coverage and round-trip tests.
- [x] Phase 7D: calibrate browser request classification so recognized same-origin browser traffic remains likely-human when optional Fetch Metadata is absent, with classifier provenance advanced to v2.
- [x] Phase 8A: add a bounded first-investigation summary, explicit measurement/ad-attribution context, denominator clarity in comparison tables, and an authenticated recent-data refresh action.
- [x] Phase 8B: add guarded directional comparisons across source, campaign, ad group, creative, placement, landing page, page/presentation, and device dimensions where retained slices provide directly comparable likely-human registration conversion.
- [ ] Add additional external-platform adapters only when a concrete client export/API workflow exists; keep provider normalization inside Reporting rather than adding vendor columns to the shared schema.

- [ ] Phase 8C: extend decision-useful acquisition economics across additional imported platform adapters only after concrete TikTok/Google exports are available, and consider richer comparison confidence only if a client workflow proves the need.