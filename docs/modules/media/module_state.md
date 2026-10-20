# Media Module State

## Responsibility

Media is the universal reusable asset library for Engage Core.

It owns reusable public images, video, audio, documents, and other files that may be selected by Messaging or other modules without turning those consuming modules into storage providers.

Media does not own Documents request/review lifecycle, Messaging delivery, client-specific copy, or provider-specific DigitalOcean APIs.

## Dependencies

Media depends only on Core.

Storage access goes through Laravel Filesystem. DigitalOcean Spaces is the current live writable object-storage backend, but Media code does not call DigitalOcean APIs directly.

## Reusable image consumer seam

Media exposes a module-owned provider-neutral read seam for product surfaces that need reusable image assets:

```text
ReusableMediaImageLibrary
    -> selectable image summaries
    -> one active image by UUID
    -> exact original image bytes when rendering requires them
```

Consumers receive Media-owned DTOs rather than `MediaAsset`, filesystem disk names, object paths, or storage-provider details. The concrete Media implementation owns the active/ready/public checks, public/preview URL resolution, and storage read.

A consuming module or selected-client package may depend on this public contract when Media is part of the approved workflow. It must not query `media_assets` directly or reach into DigitalOcean Spaces, S3, or another configured filesystem backend.

The seam is intentionally image-specific in this slice. It does not imply that every Media kind should expose raw content to every consumer.

## Schema

Media owns two durable concepts:

```text
media_assets
    stable reusable asset identity and storage metadata

media_asset_assignments
    one current Media asset selected for a persisted subject + role
```

The current migration history preserves the existing `media_assets` foundation and ingestion-state upgrade, then adds `media_asset_assignments` append-only so installed environments do not require historical migration rewrites.

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

`media_asset_assignments` is intentionally narrow:

```text
media_asset_id
subject_type / subject_id
role
timestamps
```

One persisted subject may have at most one current asset for one role. Reassigning a role updates that pointer and does not delete the previously referenced Media asset. The consuming module/package owns the meaning of its role key; Media owns only the generic durable relationship.

## Reusable image authoring and assignment seam

Media exposes `ReusableMediaImageAuthoring` for consumers that need to create durable generated SVG images and point one persisted subject role at a reusable image.

The contract allows a consumer to:

- store a validated generated SVG through Media without knowing the filesystem disk/path;
- resolve the current image assigned to one persisted subject + role;
- assign a different active/ready/public image to that role; and
- clear the current assignment without deleting any Media asset.

Generated SVG storage reuses Media SHA-256 identity. Repeating byte-identical generated output reuses the existing Media asset rather than creating another object. Consumers still receive `ReusableMediaImage` DTOs and never receive `MediaAsset` or `MediaAssetAssignment` models.

This seam is suitable for generated product artifacts such as an Event signup QR SVG. The consuming package owns the SVG's business meaning and generation rules; Media owns validation, durable object storage, identity, and the current subject-role pointer.

## Authoring selection policy

Media distinguishes durable assets from assets that should be offered in reusable authoring pickers. Normal operator-uploaded Media remains authoring-selectable by default. System-generated artifacts may remain public, assigned, directly resolvable, rasterizable, and historically durable while opting out of reusable authoring catalogs with `meta.authoring_selectable = false`.

`StoreGeneratedMediaSvgAction` applies that non-selectable marker to generated SVG assets, including exact-content assets reused through Media deduplication. `ReusableMediaImageLibrary::selectable()`, Messaging body-media selection, and Messaging Media attachment selection all honor the marker. Direct UUID resolution intentionally does not: existing assignments and previously persisted references must keep working even when an asset is not appropriate to offer as a new authoring choice.

The Media library workspace may still expose these assets for storage/audit visibility. "Not authoring-selectable" is a picker policy, not deletion, archival, privacy, or loss of Media identity. Consumers must not special-case their own generated source names; they should rely on Media's shared authoring-selection policy.

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

## SVG safety

Media accepts SVG as a reusable image only through the ordinary Media upload action.

SVG is treated as active document content rather than as harmless opaque bytes. Before storage, Media parses the document without network access and rejects DTD/entity declarations, scripts/foreign content, event-handler attributes, external resource links, nonlocal resource URLs, and unsupported SVG/XML elements.

Accepted SVG remains the original authoritative Media object. It does not receive GD/WebP progressive derivatives or a perceptual image fingerprint merely for symmetry.

A self-contained SVG may embed a bounded raster image through a base64 `data:image/...` URL only for PNG, JPEG, GIF, or WebP content whose signature matches the declared raster type. External image URLs, embedded SVG data URLs, active content, and network-capable references remain rejected. This allows generated artifacts such as branded QR SVGs to remain self-contained without weakening the external-resource safety boundary.

Consumers that need raster output should use Media's `ReusableMediaImageLibrary::renderedPng()` seam rather than owning a second SVG fetch/rasterization path.

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

## Reusable image rendering seam

Media exposes `ReusableMediaImageLibrary` as the generic read boundary for reusable public images. Consumers may resolve selectable image summaries, exact original bytes, or an ephemeral PNG rendering without importing `MediaAsset`, querying `media_assets`, or knowing the backing filesystem disk/object path.

`renderedPng()` is reconstructible presentation output. It never creates another Media row, changes the original object, or becomes a second asset identity. Raster images are decoded and proportionally bounded through GD. Safe stored SVG images are revalidated and rasterized through the Media-owned SVG rendering implementation, then returned as PNG bytes through the same provider-neutral DTO.

This seam exists for consumers that need one consistent raster representation across browser, PDF, QR, or other composition paths while preserving the original reusable Media asset as authority. Consumers must not implement their own storage lookup or SVG-fetch/rasterization path when this contract satisfies the workflow.

## Product surface

Media is a silent universal module with one narrow shared asset-management surface when explicitly enabled for the selected client.

The `Media` workspace aggregates upload, preview, archive, restore, exact dedupe, near-duplicate suggestion, and public-URL management because those operations belong to the shared asset authority rather than to any one consuming module. It is linked from shared Settings and may also be opened contextually by consuming modules such as Messaging or a future Social Media module.

Media does not own the recipient/publishing workflow that consumes an asset, and it does not receive a primary workflow navigation item merely because it is enabled.

The module is not enabled by default merely because its schema exists.

## Project State

`media_assets` and `media_asset_assignments` are currently classified as environment-owned.

Project State does not transfer binary objects, so copying asset rows without their underlying object-storage contents would create broken references. Assignments therefore remain environment-local with the Media objects they reference. A future portable-media transfer design may export metadata and assignments together with an explicit binary/object-copy workflow.

Consuming modules should not assume Project State currently transports the Media library or its subject-role assignments.

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