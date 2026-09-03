# Media Module State

## Responsibility

Media is the universal reusable asset library for Engage Core.

It owns reusable public images, video, audio, documents, and other files that may be selected by Messaging or other modules without turning those consuming modules into storage providers.

Media does not own Documents request/review lifecycle, Messaging delivery, client-specific copy, or provider-specific DigitalOcean APIs.

## Dependencies

Media depends only on Core.

Storage access goes through Laravel Filesystem. DigitalOcean Spaces is the current live writable object-storage backend, but Media code does not call DigitalOcean APIs directly.

## Schema

`media_assets` owns stable reusable asset identity and storage metadata:

- UUID identity suitable for future cross-module references
- uploader polymorphic identity
- title and media kind
- filesystem disk and object path
- original filename, MIME type, extension, size, and SHA-256 checksum
- public visibility
- source/meta
- archive timestamp

Archiving never deletes the underlying object. This preserves old and queued references. Permanent purge semantics are intentionally deferred.

## Current committed behavior

The narrow shared CRM Media workspace can:

- upload supported image, video, audio, PDF/archive/text assets;
- store them through Laravel Filesystem;
- preview images, video, and audio when a public URL is available;
- expose the public asset URL;
- archive and restore assets without deleting the object.

The application upload ceiling defaults to 256 MB and is committed config in `config/media.php`.

Web-server/PHP request limits remain deployment/server configuration. A deployment intended to accept large video uploads must set Nginx `client_max_body_size` and PHP `upload_max_filesize` / `post_max_size` high enough for the configured Media ceiling.

## Deployment requirements

The Media provider contributes storage-owned deployment requirements.

Local/testing keeps storage variables optional so fake/local disks can be used.

When Media is enabled in staging/production, the current live storage contract requires:

```text
FILESYSTEM_DISK=spaces
DO_SPACES_KEY
DO_SPACES_SECRET
DO_SPACES_ENDPOINT
DO_SPACES_REGION
DO_SPACES_BUCKET
CDN_BASE_URL
```

Endpoint and CDN values must be root HTTP/HTTPS origins. The CDN origin is required because Media assets are intended for durable public consumption by outbound communications and other product surfaces.

## Product surface

Media is a silent universal module with one narrow shared asset-management surface when explicitly enabled for the selected client.

The `Media` workspace aggregates upload, preview, archive, restore, and public-URL management because those operations belong to the shared asset authority rather than to any one consuming module. It is linked from shared Settings and may also be opened contextually by consuming modules such as Messaging or a future Social Media module.

Media does not own the recipient/publishing workflow that consumes an asset, and it does not receive a primary workflow navigation item merely because it is enabled.

The module is not enabled by default merely because its schema exists.

## Project State

`media_assets` is currently classified as environment-owned.

Project State does not transfer binary objects, so copying asset rows without their underlying object-storage contents would create broken references. A future portable-media transfer design may export metadata together with an explicit binary/object-copy workflow.

Consuming modules should not assume Project State currently transports the Media library.

## Messaging integration

When both Media and Messaging are enabled, a support-layer bridge exposes active Media assets to the canonical Messaging template editor without making either module depend directly on the other.

Messaging may:

- select an existing active asset or upload a new asset while editing an email;
- snapshot the selected asset UUID, public URL, title, kind, MIME type, and optional video poster into the immutable message version;
- render video as a poster/play card linked to the public video URL rather than relying on inconsistent in-email HTML5 playback;
- render image, audio, document, and file cards through the same media payload;
- include a plain-text fallback URL; and
- reuse Messaging CTA engagement tracking under the stable `media_primary` tracking key.

Archived assets disappear from new selection but are not deleted. Already-published message versions retain their resolved media snapshot and therefore do not depend on a live Media lookup at send time.