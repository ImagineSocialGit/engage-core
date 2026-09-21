<?php

namespace App\Modules\Media\Actions;

use App\Modules\Media\Data\ImagePerceptualFingerprint;
use App\Modules\Media\Jobs\GenerateMediaImageVariantsJob;
use App\Modules\Media\Jobs\ProcessMediaVideoIngestionJob;
use App\Modules\Media\Models\MediaAsset;
use App\Modules\Media\Services\ImagePerceptualHasher;
use App\Modules\Media\Services\MediaFileIdentity;
use App\Modules\Media\Services\MediaUploadPolicy;
use App\Support\Queues\QueueContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class StoreMediaAssetAction
{
    public function __construct(
        private readonly MediaUploadPolicy $uploadPolicy,
        private readonly MediaFileIdentity $fileIdentity,
        private readonly ImagePerceptualHasher $perceptualHasher,
        private readonly QueueContract $queueContract,
    ) {}

    public function handle(
        UploadedFile $file,
        ?string $title = null,
        ?Model $uploadedBy = null,
    ): MediaAsset {
        $mimeType = $this->uploadPolicy->effectiveMimeType($file);

        if ($mimeType === null) {
            throw new RuntimeException('The uploaded media type is not supported.');
        }

        $checksum = $this->fileIdentity->checksum($file);
        $existing = $this->existingAssetForChecksum($checksum);

        if ($existing instanceof MediaAsset) {
            return $this->reuseExisting(
                asset: $existing,
                file: $file,
                mimeType: $mimeType,
            );
        }

        $kind = $this->uploadPolicy->kindForMimeType($mimeType);

        if ($kind === MediaAsset::KIND_VIDEO
            && ! (bool) config('media.video_ingestion.enabled', true)
        ) {
            throw new RuntimeException('Video ingestion is disabled.');
        }

        $fingerprint = $kind === MediaAsset::KIND_IMAGE
            ? $this->perceptualHasher->fingerprint($file)
            : null;
        $disk = $this->disk();
        $uuid = (string) Str::uuid();
        $extension = $this->extension($file);
        $storedPath = $kind === MediaAsset::KIND_VIDEO
            ? $this->storeVideoSource($disk, $uuid, $file, $extension)
            : $this->storeDurableAsset($disk, $uuid, $file, $extension);

        try {
            $asset = MediaAsset::query()->create([
                'uuid' => $uuid,
                'uploaded_by_type' => $uploadedBy?->getMorphClass(),
                'uploaded_by_id' => $uploadedBy?->getKey(),
                'title' => $this->title($title, $file),
                'kind' => $kind,
                'disk' => $disk,
                'path' => $storedPath,
                'original_filename' => $file->getClientOriginalName(),
                'mime_type' => $mimeType,
                'extension' => $extension,
                'size_bytes' => is_int($file->getSize()) ? $file->getSize() : null,
                'ingestion_status' => $kind === MediaAsset::KIND_VIDEO
                    ? MediaAsset::INGESTION_PROCESSING
                    : MediaAsset::INGESTION_READY,
                'ingestion_error' => null,
                'ingested_at' => $kind === MediaAsset::KIND_VIDEO ? null : now(),
                'checksum_sha256' => $checksum,
                ...$this->fingerprintAttributes($fingerprint),
                'visibility' => MediaAsset::VISIBILITY_PUBLIC,
                'source' => 'crm',
                'meta' => $kind === MediaAsset::KIND_VIDEO
                    ? $this->videoIngestionMeta($mimeType, $extension, $file)
                    : null,
            ]);

            if ($asset->kind === MediaAsset::KIND_VIDEO) {
                $this->queueVideoIngestion($asset);
            } else {
                $this->queueImageVariants($asset);
            }

            return $asset;
        } catch (QueryException $exception) {
            Storage::disk($disk)->delete($storedPath);

            if ($this->isChecksumUniquenessViolation($exception)) {
                $existing = $this->existingAssetForChecksum($checksum);

                if ($existing instanceof MediaAsset) {
                    return $this->reuseExisting(
                        asset: $existing,
                        file: $file,
                        mimeType: $mimeType,
                    );
                }
            }

            throw $exception;
        } catch (Throwable $exception) {
            Storage::disk($disk)->delete($storedPath);

            throw $exception;
        }
    }

    /** @return array<string, int|string|null> */
    private function fingerprintAttributes(?ImagePerceptualFingerprint $fingerprint): array
    {
        return [
            'perceptual_hash' => $fingerprint?->hash,
            'perceptual_hash_algorithm' => $fingerprint?->algorithm,
            'image_width' => $fingerprint?->width,
            'image_height' => $fingerprint?->height,
        ];
    }

    private function existingAssetForChecksum(string $checksum): ?MediaAsset
    {
        return MediaAsset::query()
            ->where('checksum_sha256', $checksum)
            ->first();
    }

    private function reuseExisting(
        MediaAsset $asset,
        UploadedFile $file,
        string $mimeType,
    ): MediaAsset {
        if ($asset->kind === MediaAsset::KIND_VIDEO
            && $asset->hasIngestionFailed()
        ) {
            return $this->restartFailedVideoIngestion(
                asset: $asset,
                file: $file,
                mimeType: $mimeType,
            );
        }

        if ($asset->archived_at !== null) {
            $asset->forceFill(['archived_at' => null])->save();
        }

        if ($asset->kind === MediaAsset::KIND_IMAGE) {
            $this->queueImageVariants($asset);
        }

        return $asset;
    }

    private function restartFailedVideoIngestion(
        MediaAsset $asset,
        UploadedFile $file,
        string $mimeType,
    ): MediaAsset {
        $disk = $this->disk();
        $extension = $this->extension($file);
        $storedPath = $this->storeVideoSource(
            disk: $disk,
            uuid: (string) $asset->uuid,
            file: $file,
            extension: $extension,
        );

        $oldDisk = trim((string) $asset->disk);
        $oldPath = trim((string) $asset->path);

        $asset->forceFill([
            'disk' => $disk,
            'path' => $storedPath,
            'original_filename' => $file->getClientOriginalName(),
            'mime_type' => $mimeType,
            'extension' => $extension,
            'size_bytes' => is_int($file->getSize()) ? $file->getSize() : null,
            'ingestion_status' => MediaAsset::INGESTION_PROCESSING,
            'ingestion_error' => null,
            'ingested_at' => null,
            'archived_at' => null,
            'meta' => $this->videoIngestionMeta($mimeType, $extension, $file),
        ])->save();

        if ($oldDisk !== '' && $oldPath !== '' && $oldPath !== $storedPath) {
            try {
                Storage::disk($oldDisk)->delete($oldPath);
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        try {
            $this->queueVideoIngestion($asset);
        } catch (Throwable $exception) {
            Storage::disk($disk)->delete($storedPath);

            $asset->forceFill([
                'ingestion_status' => MediaAsset::INGESTION_FAILED,
                'ingestion_error' => mb_substr($exception->getMessage(), 0, 2000),
            ])->save();

            throw $exception;
        }

        return $asset->refresh();
    }

    private function queueImageVariants(MediaAsset $asset): void
    {
        if ($asset->kind !== MediaAsset::KIND_IMAGE
            || ! (bool) config('media.image_variants.enabled', true)
            || $asset->hasProgressiveImageVariants()
        ) {
            return;
        }

        try {
            $queue = $this->queueContract->assertDispatchable(null);
            $job = (new GenerateMediaImageVariantsJob(
                (int) $asset->getKey(),
            ))->onQueue($queue);

            dispatch($job);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function queueVideoIngestion(MediaAsset $asset): void
    {
        try {
            $queue = $this->queueContract->assertDispatchable(
                trim((string) config(
                    'media.video_ingestion.queue',
                    QueueContract::MEDIA_PROCESSING,
                )) ?: QueueContract::MEDIA_PROCESSING,
            );

            $connection = trim((string) config(
                'media.video_ingestion.connection',
                'redis-media',
            )) ?: 'redis-media';

            dispatch(
                (new ProcessMediaVideoIngestionJob((int) $asset->getKey()))
                    ->onConnection($connection)
                    ->onQueue($queue),
            );
        } catch (Throwable $exception) {
            $asset->forceFill([
                'ingestion_status' => MediaAsset::INGESTION_FAILED,
                'ingestion_error' => mb_substr($exception->getMessage(), 0, 2000),
            ])->save();

            throw $exception;
        }
    }

    private function storeDurableAsset(
        string $disk,
        string $uuid,
        UploadedFile $file,
        ?string $extension,
    ): string {
        $filename = $uuid.($extension !== null ? '.'.$extension : '');
        $directory = trim((string) config('media.directory', 'media'), '/');
        $directory = ($directory !== '' ? $directory.'/' : '').$uuid;

        $storedPath = Storage::disk($disk)->putFileAs(
            $directory,
            $file,
            $filename,
            ['visibility' => MediaAsset::VISIBILITY_PUBLIC],
        );

        if (! is_string($storedPath) || trim($storedPath) === '') {
            throw new RuntimeException("Media upload to disk [{$disk}] failed.");
        }

        return trim($storedPath);
    }

    private function storeVideoSource(
        string $disk,
        string $uuid,
        UploadedFile $file,
        ?string $extension,
    ): string {
        $filename = 'source'.($extension !== null ? '.'.$extension : '');
        $directory = trim(
            (string) config('media.video_ingestion.temporary_directory', 'media-ingest'),
            '/',
        );
        $directory = ($directory !== '' ? $directory.'/' : '').$uuid;

        $storedPath = Storage::disk($disk)->putFileAs(
            $directory,
            $file,
            $filename,
            ['visibility' => 'private'],
        );

        if (! is_string($storedPath) || trim($storedPath) === '') {
            throw new RuntimeException("Video upload to ingestion storage [{$disk}] failed.");
        }

        return trim($storedPath);
    }

    /** @return array<string, mixed> */
    private function videoIngestionMeta(
        string $mimeType,
        ?string $extension,
        UploadedFile $file,
    ): array {
        return [
            'video_ingestion' => [
                'version' => 1,
                'source_mime_type' => $mimeType,
                'source_extension' => $extension,
                'source_size_bytes' => is_int($file->getSize()) ? $file->getSize() : null,
                'queued_at' => now()->toIso8601String(),
            ],
        ];
    }

    private function isChecksumUniquenessViolation(QueryException $exception): bool
    {
        return str_contains(
            strtolower($exception->getMessage()),
            'media_assets_checksum_sha256_unique',
        );
    }

    private function disk(): string
    {
        $configured = config('media.disk');
        $disk = is_string($configured) && trim($configured) !== ''
            ? trim($configured)
            : trim((string) config('filesystems.default', 'local'));

        if ($disk === '') {
            throw new RuntimeException('Media storage disk is not configured.');
        }

        return $disk;
    }

    private function extension(UploadedFile $file): ?string
    {
        $extension = $file->guessExtension();

        if (! is_string($extension) || trim($extension) === '') {
            $extension = pathinfo($file->getClientOriginalName(), PATHINFO_EXTENSION);
        }

        $extension = strtolower(trim((string) $extension));

        return preg_match('/^[a-z0-9]{1,12}$/', $extension) === 1
            ? $extension
            : null;
    }

    private function title(?string $title, UploadedFile $file): string
    {
        $title = is_string($title) ? trim($title) : '';

        if ($title === '') {
            $title = trim((string) pathinfo(
                $file->getClientOriginalName(),
                PATHINFO_FILENAME,
            ));
        }

        if ($title === '') {
            $title = 'Media asset';
        }

        return Str::limit($title, 255, '');
    }
}