# Messaging CTA Engagement Tracking

## Status

Generic CTA engagement tracking is implemented as a Messaging-owned capability.

The contract is:

```text
immutable MessageTemplateVersion link
    + optional tracking_key
    + ScheduledMessage
        -> signed Messaging redirect
        -> bounded engagement aggregate
        -> destination
```

Webinars, Campaigns, Broadcasts, and other producers may opt individual HTTP(S) links into this capability by supplying a stable `tracking_key`. They do not own separate click-tracking tables or redirect controllers.

## Authoring contract

Tracking is explicit. A link without `tracking_key` is never wrapped.

Example:

```php
'ctas' => [
    [
        'tracking_key' => 'replay',
        'label' => 'Watch the Recording',
        'url' => '{webinar_playback_url}',
    ],
    [
        'tracking_key' => 'pre_approval',
        'label' => 'Get Pre-Approved',
        'url' => 'https://example.test/apply',
    ],
],
```

`tracking_key` must match:

```text
^[a-z0-9][a-z0-9._-]{0,95}$
```

Only absolute HTTP(S) destinations are trackable. Unsubscribe, transactional opt-out, registration cancellation, and other links remain untracked unless they are deliberately authored with a tracking key.

The key is part of immutable published message content. Existing ScheduledMessages pinned to older MessageTemplateVersions are not rewritten when tracking is added later.

CRM composition editing treats the tracking key as structural identity rather than operator-facing copy. Editing a tracked link's label or URL preserves its existing tracking key.

## Runtime contract

`EmailPayload` wraps an opted-in link only when the runtime payload identifies the current ScheduledMessage.

New redirects are generated on the dedicated public Messaging host:

```text
https://messaging.[ROOT_DOMAIN]/messaging/click/{scheduled_message}/{tracking_key}
```

The signed redirect binds:

```text
ScheduledMessage ID
tracking key
resolved destination
```

The signature prevents destination or attribution tampering. The public click hostname intentionally matches the Messaging surface used by email preference/unsubscribe links instead of exposing the authenticated CRM hostname in newly delivered email.

The former CRM-host route remains registered as `messaging.cta.redirect.legacy` only so already-delivered signed links continue to work. New link generation must use `messaging.cta.redirect`.

Preview/editor rendering without a ScheduledMessage keeps the direct destination.

No generic automation event is emitted for a raw click.

## Trust classification

Each redirect request is conservatively classified as one of:

```text
likely_human
scanner
prefetch
unknown
```

`likely_human` requires browser navigation/user-activation fetch metadata. Known link-scanner user agents are classified separately. HEAD/prefetch/preview signals are not trusted as human engagement.

This classification is evidence, not proof of a human identity.

## Persistence and bloat boundary

The table is:

```text
scheduled_message_cta_engagements
```

Identity:

```text
scheduled_message_id + cta_key + classification
```

Repeated requests increment one aggregate row instead of creating raw clickstream rows.

Stored facts are limited to:

```text
ScheduledMessage FK
logical CTA key
classification
occurrence count
first occurrence time
last occurrence time
```

The table stores no:

```text
raw IP address
user agent
request headers
provider payload
destination copy
Campaign/Webinar IDs
generic meta/json
per-request click row
```

Campaign/Webinar provenance remains derivable through ScheduledMessage and its existing runtime relationships.

## Retention

Engagement evidence is retained for 180 days from `ScheduledMessage.send_at` by default.

Requests to older messages still redirect to the signed destination but do not create or extend engagement evidence.

A daily Messaging pruning job removes expired aggregate rows in bounded batches.

## Example consumer

A post-event email may opt its replay and application CTAs into generic tracking with stable keys such as:

```text
replay
pre_approval
```

This provides generic replay/application engagement evidence without creating producer-specific tracking infrastructure.