<?php

namespace App\Modules\Media\Actions;

use App\Modules\Media\Models\MediaAsset;
use App\Modules\Media\Services\MediaUploadPolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use RuntimeException;

final class StoreGeneratedMediaSvgAction
{
    public function __construct(
        private readonly StoreMediaAssetAction $storeMediaAsset,
    ) {}

    public function handle(
        string $svg,
        string $title,
        string $source,
        ?Model $createdBy = null,
    ): MediaAsset {
        $temporaryPath = tempnam(sys_get_temp_dir(), 'engage-generated-svg-');

        if (! is_string($temporaryPath) || $temporaryPath === '') {
            throw new RuntimeException('Unable to prepare generated SVG storage.');
        }

        try {
            $written = file_put_contents($temporaryPath, $svg);

            if (! is_int($written) || $written !== strlen($svg)) {
                throw new RuntimeException('Unable to prepare generated SVG storage.');
            }

            $file = new UploadedFile(
                path: $temporaryPath,
                originalName: 'generated.svg',
                mimeType: MediaUploadPolicy::SVG_MIME_TYPE,
                error: null,
                test: true,
            );

            $asset = $this->storeMediaAsset->handle(
                file: $file,
                title: $title,
                uploadedBy: $createdBy,
                source: $source,
            );

            if ($asset->isAuthoringSelectable()) {
                $meta = is_array($asset->meta) ? $asset->meta : [];
                $meta['authoring_selectable'] = false;

                $asset->forceFill(['meta' => $meta])->save();
            }

            return $asset;
        } finally {
            @unlink($temporaryPath);
        }
    }
}