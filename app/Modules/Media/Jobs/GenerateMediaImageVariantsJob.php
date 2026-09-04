<?php

namespace App\Modules\Media\Jobs;

use App\Modules\Media\Models\MediaAsset;
use App\Modules\Media\Services\MediaImageVariantGenerator;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class GenerateMediaImageVariantsJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $uniqueFor = 300;

    public function __construct(
        public readonly int $mediaAssetId,
    ) {}

    public function uniqueId(): string
    {
        return 'media:image-variants:'.$this->mediaAssetId;
    }

    public function handle(MediaImageVariantGenerator $generator): void
    {
        $asset = MediaAsset::query()->find($this->mediaAssetId);

        if (! $asset instanceof MediaAsset
            || $asset->kind !== MediaAsset::KIND_IMAGE
            || $asset->hasProgressiveImageVariants()
        ) {
            return;
        }

        $generator->generate($asset);
    }

    /** @return array<int, string> */
    public function tags(): array
    {
        return [
            'media',
            'media_asset:'.$this->mediaAssetId,
            'media_image_variants',
        ];
    }
}