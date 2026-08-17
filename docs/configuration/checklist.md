# Configuration Change Checklist

Use after config, preset, or client-template changes. It is not backlog.

- Confirm every key is supported by the owning contract/template/guide.
- Confirm client copy uses documented tokens only and Messaging copy passes `MessageTemplateTokenValidator` for the exact producer context.
- Do not guess runtime-only URLs/tokens in static config.
- Campaign presets own journey behavior, not reusable message copy; resolve templates through first-class channel/purpose/scope identity.
- Messaging templates live under the expected channel/purpose/scope path and do not own module lifecycle timing/conditions.
- Webinar Messaging definitions do not reintroduce per-scope `opt_ins`; consent acknowledgements resolve through Messaging consent domains.
- Message scopes map to intentional consent domains; unknown scopes remain narrow.
- `next_day_at` uses strict `HH:MM` plus client timezone rather than embedding a timezone per item.
- Delayed conditions remain available for send-time revalidation without copying canonical chain definitions into every ScheduledMessage.
- Task presets create DB-owned TaskTemplate definitions only, never live Tasks.
- FlowRoute presets reference public actions/services/capabilities rather than private module internals.
- SMS visibility follows per-surface channel availability without disabling backend protections.
- Missing optional content/style keys must fall back safely; behavioral tests should not freeze arbitrary client prose.
- Unsupported keys must be rejected, flagged, or deliberately ignored with clear diagnostics.
- Client overrides preserve unspecified nested defaults where fallback is intended.
- Numeric/list overrides replace defaults where that is the merge contract; verify lists do not accidentally append duplicate Core slots.
- Any client-selected preset package must exist in effective merged `presets.packages`; rich client/vertical packages stay client-owned.
