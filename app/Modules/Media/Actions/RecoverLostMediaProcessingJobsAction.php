<?php

namespace App\Modules\Media\Actions;

use App\Modules\Media\Jobs\GenerateMediaImageVariantsJob;
use App\Modules\Media\Jobs\ProcessMediaVideoIngestionJob;
use App\Modules\Media\Models\MediaAsset;
use App\Modules\Media\Services\MediaImageVariantGenerator;
use App\Modules\Media\Services\MediaVideoIngestor;
use App\Support\Queues\QueueContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Restore Redis-lost Media work from the durable asset record and Spaces object.
 *
 * Recovery claims live in MySQL, not Redis. Jobs are queued after commit so a
 * crashed dispatcher leaves a claim that becomes eligible again on expiry.
 */
final class RecoverLostMediaProcessingJobsAction
{
    private const BATCH_SIZE = 25;

    // Image work is short. Long-running video jobs permit two 35-minute tries,
    // so do not replace a video job while legitimate ingestion may still run.
    private const IMAGE_STALE_MINUTES = 10;
    private const IMAGE_RETRY_MINUTES = 15;
    private const VIDEO_STALE_MINUTES = 120;
    private const VIDEO_RETRY_MINUTES = 120;

    public function __construct(
        private readonly MediaImageVariantGenerator $images,
        private readonly MediaVideoIngestor $videos,
        private readonly QueueContract $queues,
    ) {}

    /** @return array{images: int, videos: int} */
    public function handle(): array
    {
        $counts = ['images' => 0, 'videos' => 0];

        if ((bool) config('media.image_variants.enabled', true)
            && $this->images->available()
        ) {
            $counts['images'] = $this->recoverImages();
        }

        if ((bool) config('media.video_ingestion.enabled', true)
            && $this->videos->available()
        ) {
            $counts['videos'] = $this->recoverVideos();
        }

        return $counts;
    }

    private function recoverImages(): int
    {
        $mimeTypes = array_values(array_filter(
            config('media.image_variants.supported_mime_types', []),
            fn (mixed $value): bool => is_string($value) && trim($value) !== '',
        ));

        if ($mimeTypes === []) {
            return 0;
        }

        $candidates = MediaAsset::query()
            ->where('kind', MediaAsset::KIND_IMAGE)
            ->whereNull('archived_at')
            ->where('ingestion_status', MediaAsset::INGESTION_READY)
            ->whereIn('mime_type', $mimeTypes)
            ->where('updated_at', '<=', now()->subMinutes(self::IMAGE_STALE_MINUTES))
            ->where(function (Builder $query): void {
                $query->whereNull('meta->image_variants->files->medium->path')
                    ->orWhere('meta->image_variants->version', '!=', MediaImageVariantGenerator::VERSION)
                    ->orWhereNull('meta->image_variants->files->default->path');
            })
            ->where(function (Builder $query): void {
                $query->whereNull('image_variants_recovery_dispatched_at')
                    ->orWhere('image_variants_recovery_dispatched_at', '<=', now()->subMinutes(self::IMAGE_RETRY_MINUTES));
            })
            ->orderBy('id')
            ->limit(self::BATCH_SIZE)
            ->pluck('id');

        $dispatched = 0;

        foreach ($candidates as $id) {
            $assetId = (int) $id;

            if (! $this->claimImage($assetId)) {
                continue;
            }

            try {
                $queue = $this->queues->assertDispatchable(null);

                // Bus dispatch intentionally bypasses ShouldBeUnique's Redis
                // gate: a stale uniqueness lock may outlive a lost queue job.
                Bus::dispatch(
                    (new GenerateMediaImageVariantsJob($assetId))->onQueue($queue),
                );
                $dispatched++;
            } catch (Throwable $exception) {
                report($exception);
                // Leave the durable claim until its retry window expires.
            }
        }

        return $dispatched;
    }

    private function claimImage(int $id): bool
    {
        return DB::transaction(function () use ($id): bool {
            $asset = MediaAsset::query()->lockForUpdate()->find($id);

            if (! $asset instanceof MediaAsset
                || $asset->kind !== MediaAsset::KIND_IMAGE
                || ! $asset->isReady()
                || $asset->archived_at !== null
                || ! $this->images->supports($asset)
                || $asset->hasProgressiveImageVariants()
                || $asset->updated_at?->isAfter(now()->subMinutes(self::IMAGE_STALE_MINUTES))
                || $asset->image_variants_recovery_dispatched_at?->isAfter(now()->subMinutes(self::IMAGE_RETRY_MINUTES))
            ) {
                return false;
            }

            $asset->forceFill([
                'image_variants_recovery_dispatched_at' => now(),
            ])->save();

            return true;
        });
    }

    private function recoverVideos(): int
    {
        $candidates = MediaAsset::query()
            ->where('kind', MediaAsset::KIND_VIDEO)
            ->whereNull('archived_at')
            ->where('ingestion_status', MediaAsset::INGESTION_PROCESSING)
            ->where('updated_at', '<=', now()->subMinutes(self::VIDEO_STALE_MINUTES))
            ->where(function (Builder $query): void {
                $query->whereNull('video_ingestion_recovery_dispatched_at')
                    ->orWhere('video_ingestion_recovery_dispatched_at', '<=', now()->subMinutes(self::VIDEO_RETRY_MINUTES));
            })
            ->orderBy('id')
            ->limit(self::BATCH_SIZE)
            ->pluck('id');

        $dispatched = 0;

        foreach ($candidates as $id) {
            $assetId = (int) $id;

            if (! $this->claimVideo($assetId)) {
                continue;
            }

            try {
                $queue = $this->queues->assertDispatchable(
                    trim((string) config('media.video_ingestion.queue', QueueContract::MEDIA_PROCESSING))
                        ?: QueueContract::MEDIA_PROCESSING,
                );
                $connection = trim((string) config('media.video_ingestion.connection', 'redis-media'))
                    ?: 'redis-media';

                Bus::dispatch(
                    (new ProcessMediaVideoIngestionJob($assetId))
                        ->onConnection($connection)
                        ->onQueue($queue),
                );
                $dispatched++;
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return $dispatched;
    }

    private function claimVideo(int $id): bool
    {
        return DB::transaction(function () use ($id): bool {
            $asset = MediaAsset::query()->lockForUpdate()->find($id);

            if (! $asset instanceof MediaAsset
                || $asset->kind !== MediaAsset::KIND_VIDEO
                || ! $asset->isProcessing()
                || $asset->archived_at !== null
                || $asset->updated_at?->isAfter(now()->subMinutes(self::VIDEO_STALE_MINUTES))
                || $asset->video_ingestion_recovery_dispatched_at?->isAfter(now()->subMinutes(self::VIDEO_RETRY_MINUTES))
            ) {
                return false;
            }

            $asset->forceFill([
                'video_ingestion_recovery_dispatched_at' => now(),
            ])->save();

            return true;
        });
    }
}