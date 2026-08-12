# Music Module

Music is a planned vertical module.

Music owns music-specific business meaning, artist/show context, fan strategy, and music-specific presets. It composes universal modules rather than pushing music state into Core, Events, Commerce, or Experiences.

## Owns

Music may own, when implemented:

```text
artist and fan-specific profile data when needed
artist-to-Event/show associations
concert/show meaning
lineup
setlist
tour context
music-specific production requirements
release campaign configuration and meaning
music product interest categories
fan segmentation rules
music-specific Commerce mappings when generic Commerce identity is insufficient
music-specific Experience terminology and mappings
Bandsintown mapping and export options
music-specific FlowRoute, Campaign, Broadcast, Messaging, and Task presets
```

Music must not push music-specific state into Core Contacts.

Vertical-specific migrations should live in:

```text
database/migrations/verticals/music
```

## Consumes

Music may consume:

```text
Core
Events
Commerce
Experiences
Messaging
Campaigns
Broadcasts
FlowRoutes
Tasks
Scheduling
Portal
Location
Reporting
Integrations
```

## Events boundary

Events owns one concrete Event's universal identity, schedule, location snapshot, lifecycle, announcement gate, stakeholders, external references, readiness, and generic attendance outcomes.

Music owns the artist/show interpretation of that Event.

Preferred relationship:

```text
Music-owned show/artist association -> Event
```

Events must not contain:

```text
artist_id
lineup
setlist
tour_id
music production requirements
Bandsintown-specific columns
```

Music may contribute Event types, readiness rules, tokens, filters, automation capabilities, and presentation labels through Events public registries and read contracts. It must not write Event tables directly when a public Events action exists.

## Bandsintown boundary

Bandsintown is initially an export-only adapter assembled from multiple owners:

```text
Events
    Event name, schedule, timezone, location snapshot, description, announcement timing, structured references

Music
    Artist Name, lineup, setlist, music-specific display decisions

Bandsintown adapter
    exact CSV headings/order, accepted values, scheduling fields, formatting, template version
```

The Bandsintown template must not dictate Events or Music schema.

A pre-announcement export is allowed only when publication can be guaranteed no earlier than the Event `announcement_at`. Otherwise export remains blocked until the Event promotion gate allows it.

Initial export is deterministic and on demand. Do not add export-history tables or retained per-row payload copies without a proven operational recovery need.

## Commerce boundary

Commerce owns canonical provider-neutral products, variants, provider mappings, storefront/offers, checkout orchestration, orders, purchase facts, and inventory-effect orchestration.

Music decides what those facts mean for fan strategy.

Good:

```text
Commerce records that a Contact bought vinyl or merch.
Music assigns the music-specific fan/customer interpretation.
```

Bad:

```text
Core contacts store provider-specific purchased product IDs.
Music imports a commerce/payment/inventory provider adapter for generic order sync, inventory sync, or checkout.
```

## Experiences boundary

Experiences owns reusable VIP/package entitlements, participants, benefits, management access, credentials, scanning, and fulfillment.

Music owns artist/tour-specific meaning and terminology around those Experiences.

Good:

```text
Event owns the canonical show.
Commerce records the provider-neutral purchase.
Experiences grants and fulfills the VIP package.
Music associates the artist/tour context and music-specific presets.
```

Bad:

```text
Music owns generic QR credential infrastructure.
Events stores VIP participant slots.
Commerce performs Experience check-in.
```

## Location boundary

Location provides geocoding, radius, market, region, and service-area capability. Events retains its own historical location snapshot.

Music may use Location to target Contacts near a show after Location exposes the required geocoding and contributor-based Contact-filter seams.

## Project State

Music-owned durable tables must receive an explicit Project State section before production use. Music references to Events, Commerce, or Experiences must use stable dependency-safe remapping contracts rather than copied cross-module JSON snapshots.

## Implementation order

Music Event/show integration and Bandsintown export begin only after the Events foundation, lifecycle/readiness contracts, Project State section, and CRM operations are stable.

Music Experience integration begins only after provider-capable Commerce and Experiences have stable public contracts for the concrete client ecosystem.