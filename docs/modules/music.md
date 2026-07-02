# Music Module

This module reference owns the detailed responsibility, dependency, and boundary notes for this module. Keep global architectural rules in `docs/module-boundaries.md`; keep actionable backlog in `docs/TODO.md`.

Music is a planned vertical module.

Music should own music-specific business meaning and fan/customer strategy.

Music may own, when implemented:

- artist/fan-specific profile data, if needed
- release campaign configuration/meaning
- music product interest categories
- fan segmentation rules that are music-specific
- show/event interest behavior that is not generic Scheduling or Location
- music-specific Commerce mappings, if generic Commerce records are not enough
- music-specific FlowRoute/Campaign presets

Music may consume:

- Core
- Commerce
- Messaging
- Campaigns
- Broadcasts
- FlowRoutes
- Scheduling
- Portal
- Location
- Reporting
- Integrations

Music must not push music-specific state into Core contacts.

Vertical-specific migrations should live in:

    database/migrations/verticals/music

Good:

    Commerce owns normalized Shopify orders
    Music decides what buying vinyl, merch, or tickets means for fan segmentation
    Location provides show-radius contact filtering when needed

Bad:

    Core contacts store purchased_shopify_product_ids
    Music imports Shopify adapter directly for generic order sync
