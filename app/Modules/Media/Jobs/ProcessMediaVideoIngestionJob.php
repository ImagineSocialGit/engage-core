<?php

namespace App\Modules\Media\Jobs;

use App\Modules\Media\Models\MediaAsset;
use App\Modules\Media\Services\MediaVideoIngestor;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class ProcessMediaVideoIngestionJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 2100;

    public bool $failOnTimeout = true;

    public int $uniqueFor = 3600;

    public function __construct(
        public readonly int $mediaAssetId,
    ) {}

    public function uniqueId(): string
    {
        return 'media:video-ingestion:'.$this->mediaAssetId;
    }

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(MediaVideoIngestor $ingestor): void
    {
        $asset = MediaAsset::query()->find($this->mediaAssetId);

        if (! $asset instanceof MediaAsset
            || $asset->kind !== MediaAsset::KIND_VIDEO
            || ! $asset->isProcessing()
        ) {
            return;
        }

        $ingestor->ingest($asset);
    }

    public function failed(?Throwable $exception): void
    {
        $asset = MediaAsset::query()->find($this->mediaAssetId);

        if (! $asset instanceof MediaAsset
            || $asset->kind !== MediaAsset::KIND_VIDEO
            || ! $asset->isProcessing()
        ) {
            return;
        }

        $disk = trim((string) $asset->disk);
        $path = trim((string) $asset->path);

        if ($disk !== '' && $path !== '') {
            try {
                Storage::disk($disk)->delete($path);
            } catch (Throwable $cleanupException) {
                report($cleanupException);
            }
        }

        $message = trim((string) $exception?->getMessage());
        $message = $message !== ''
            ? mb_substr($message, 0, 2000)
            : 'Video ingestion failed.';

        $meta = is_array($asset->meta) ? $asset->meta : [];
        $meta['video_ingestion'] = array_replace(
            is_array($meta['video_ingestion'] ?? null)
                ? $meta['video_ingestion']
                : [],
            [
                'version' => 1,
                'failed_at' => now()->toIso8601String(),
            ],
        );

        $asset->forceFill([
            'ingestion_status' => MediaAsset::INGESTION_FAILED,
            'ingestion_error' => $message,
            'ingested_at' => null,
            'meta' => $meta,
        ])->save();
    }

    /** @return array<int, string> */
    public function tags(): array
    {
        return [
            'media',
            'media_asset:'.$this->mediaAssetId,
            'media_video_ingestion',
        ];
    }
}