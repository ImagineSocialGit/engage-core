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
- original filename, MIME type, extension, size, and SHA-256 exact-content identity
- nullable image perceptual hash, fingerprint algorithm version, width, and height
- public visibility
- source/meta
- archive timestamp

Archiving never deletes the underlying object. This preserves old and queued references. Permanent purge semantics are intentionally deferred.

Exact byte-identical uploads are idempotent regardless of filename. Media calculates SHA-256 before permanent object storage, reuses an existing asset with the same checksum, restores it when archived, and relies on a database uniqueness contract to close concurrent-upload races. A race loser removes its just-written object before returning the winning asset identity.

Image perceptual fingerprints are advisory metadata only. They never replace SHA-256 identity, never make an image unique, and never prevent an operator from storing an intentional visual variant.

## Image near-duplicate suggestions

When `media.near_duplicate_images.enabled` is true, Media can inspect an image before permanent storage and suggest existing active images that look substantially similar.

Version 1 uses a 64-bit difference hash (`dhash64_v1`):

- the uploaded image is decoded through PHP GD;
- it is normalized to a 9×8 grayscale sample;
- adjacent pixels produce a 64-bit visual fingerprint stored as 16 hexadecimal characters;
- candidate images must use the same fingerprint algorithm;
- candidates outside the configured aspect-ratio tolerance are ignored;
- Hamming distance ranks remaining candidates;
- only the configured top candidate count is returned.

The default policy is:

```text
max Hamming distance:     8 / 64
aspect-ratio tolerance:   8%
maximum suggestions:      3
```

The Media upload workspace performs this inspection before the ordinary upload request. A near match pauses the browser flow and offers `Use existing` or `Upload anyway`. Choosing `Upload anyway` proceeds through the normal `StoreMediaAssetAction`; similarity is never a hard server-side block.

The inspection endpoint is Media-owned and reusable by consuming authoring surfaces. Consumers must not implement their own perceptual hashing or query Media fingerprint columns directly.

Exact SHA-256 matches remain stronger than perceptual matches. The ordinary storage action still owns exact reuse and archived restoration, so an exact duplicate cannot create another durable object even if a browser bypasses or cannot run the advisory preflight.

GD is optional for the Media module as a whole. If GD is unavailable, setup validation emits a warning and perceptual comparison is unavailable, while exact SHA-256 deduplication and ordinary Media storage continue to work.

Existing image rows can be fingerprinted after the schema upgrade with:

```bash
php artisan media:image-fingerprints:backfill
```

The backfill reads the existing storage object and writes only fingerprint/dimension metadata. It does not rename objects, replace checksums, or change Media identity.

Video, audio, PDF, and other non-image perceptual matching are intentionally out of scope for this version.


## Progressive image derivatives

Supported still-image uploads retain the exact original Media object and also receive two deterministic WebP children in the same UUID directory:

```text
media/{uuid}/{uuid}.{original-extension}
media/{uuid}/medium.webp
media/{uuid}/default.webp
```

The original remains authoritative for SHA-256 identity, perceptual fingerprinting, downloads, Messaging snapshots, and future regeneration. Derivatives never replace `media_assets.path` and do not participate in duplicate identity.

Version 1 deliberately mirrors the useful runtime behavior proven by Engage Artist Sites while adapting it to Core Media ownership:

- `medium.webp` is capped at 500px wide;
- `default.webp` is capped at 1920px wide;
- neither derivative upscales an image smaller than its target;
- both use WebP quality 82 by default;
- PNG transparency is preserved through the GD resample/encode path;
- animated GIFs remain original-only so Media does not silently flatten animation; and
- source images above the configured pixel-safety ceiling remain original-only rather than risking excessive worker memory.

Derivative generation is queued on the existing executable default queue after the original object and Media row have succeeded. Variant failure is intentionally non-fatal to the upload: the original remains a valid Media asset and can be repaired later.

The generated file paths and dimensions are recorded under `media_assets.meta.image_variants` as regenerable presentation metadata. No schema migration is required. The deterministic child objects may be rebuilt without changing UUID, checksum, original path, archive state, or immutable Messaging snapshots.

The shared `<x-media.progressive-image>` browser component uses the medium WebP as the blurred first paint and fades in the display WebP. If current derivative metadata is absent, it falls back to the original public URL.

Existing image assets can be processed with:

```bash
php artisan media:image-variants:backfill
```

Use `--force` to rewrite the current derivative version when repairing missing objects or changing committed derivative policy.

This browser-presentation layer is separate from the curated client/site image preprocessing pipeline under `client/{client-key}/resources/images`. The dev/site pipeline remains unchanged.

## Current committed behavior

The narrow shared CRM Media workspace can:

- upload supported image, video, audio, PDF/archive/text assets;
- store them through Laravel Filesystem;
- progressively preview supported still images when derivatives are ready, with original-image fallback;
- preview video and audio when a public URL is available;
- expose the public asset URL;
- archive and restore assets without deleting the object;
- reuse exact-content duplicates instead of creating another Media row or storage object;
- fingerprint new images when GD is available; and
- suggest visually similar active images before a new image is stored.

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

Perceptual image suggestions additionally benefit from the PHP GD extension. Progressive still-image derivatives require GD with WebP encoding support. Missing image-processing support is a setup warning rather than a deployment-blocking Media storage error because exact-content identity and original Media objects remain fully available without it.

## Product surface

Media is a silent universal module with one narrow shared asset-management surface when explicitly enabled for the selected client.

The `Media` workspace aggregates upload, preview, archive, restore, exact dedupe, near-duplicate suggestion, and public-URL management because those operations belong to the shared asset authority rather than to any one consuming module. It is linked from shared Settings and may also be opened contextually by consuming modules such as Messaging or a future Social Media module.

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

All uploads still pass through Media's storage action, so new image uploads accumulate Media-owned perceptual fingerprints automatically. The canonical Messaging authoring surface should invoke Media's reusable similarity preflight before storing an image; it must not duplicate the similarity algorithm inside Messaging.

Archived assets disappear from new selection but are not deleted. Already-published message versions retain their resolved media snapshot and therefore do not depend on a live Media lookup at send time.