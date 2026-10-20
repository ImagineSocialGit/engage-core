<?php

namespace App\Modules\Media\Services;

use App\Modules\Media\Contracts\ReusableMediaImageLibrary;
use App\Modules\Media\Data\ReusableMediaImage;
use App\Modules\Media\Data\ReusableMediaImageContent;
use App\Modules\Media\Data\ReusableMediaRenderedImage;
use App\Modules\Media\Models\MediaAsset;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class DatabaseReusableMediaImageLibrary implements ReusableMediaImageLibrary
{
    public function __construct(
        private readonly MediaImageRasterizer $rasterizer,
    ) {}

    public function selectable(
        array $mimeTypes = [],
        int $limit = 200,
    ): array {
        $mimeTypes = $this->normalizeMimeTypes($mimeTypes);

        return $this->imageQuery()
            ->authoringSelectable()
            ->when(
                $mimeTypes !== [],
                fn (Builder $query) => $query->whereIn('mime_type', $mimeTypes),
            )
            ->latest('id')
            ->limit(max(1, min(500, $limit)))
            ->get()
            ->map(fn (MediaAsset $asset): ?ReusableMediaImage => $this->present($asset))
            ->filter()
            ->values()
            ->all();
    }

    public function find(string $uuid): ?ReusableMediaImage
    {
        $asset = $this->findAsset($uuid);

        return $asset instanceof MediaAsset
            ? $this->present($asset)
            : null;
    }

    public function content(string $uuid): ?ReusableMediaImageContent
    {
        $asset = $this->findAsset($uuid);

        if (! $asset instanceof MediaAsset) {
            return null;
        }

        $image = $this->present($asset);
        $disk = trim((string) $asset->disk);
        $path = trim((string) $asset->path);

        if (! $image instanceof ReusableMediaImage
            || $disk === ''
            || $path === ''
        ) {
            return null;
        }

        try {
            $bytes = Storage::disk($disk)->get($path);
        } catch (Throwable) {
            return null;
        }

        if (! is_string($bytes) || $bytes === '') {
            return null;
        }

        return new ReusableMediaImageContent(
            image: $image,
            bytes: $bytes,
        );
    }

    public function renderedPng(
        string $uuid,
        int $maximumWidth = 1024,
        int $maximumHeight = 1024,
    ): ?ReusableMediaRenderedImage {
        $content = $this->content($uuid);

        if (! $content instanceof ReusableMediaImageContent) {
            return null;
        }

        $rendered = $this->rasterizer->renderPng(
            mimeType: $content->image->mimeType,
            bytes: $content->bytes,
            maximumWidth: $maximumWidth,
            maximumHeight: $maximumHeight,
        );

        if (! is_array($rendered)) {
            return null;
        }

        return new ReusableMediaRenderedImage(
            source: $content->image,
            mimeType: 'image/png',
            bytes: $rendered['bytes'],
            width: $rendered['width'],
            height: $rendered['height'],
        );
    }

    private function findAsset(string $uuid): ?MediaAsset
    {
        $uuid = trim($uuid);

        if ($uuid === '') {
            return null;
        }

        return $this->imageQuery()
            ->where('uuid', $uuid)
            ->first();
    }

    private function imageQuery(): Builder
    {
        return MediaAsset::query()
            ->active()
            ->ready()
            ->where('kind', MediaAsset::KIND_IMAGE)
            ->where('visibility', MediaAsset::VISIBILITY_PUBLIC);
    }

    private function present(MediaAsset $asset): ?ReusableMediaImage
    {
        $publicUrl = $asset->publicUrl();

        if (! is_string($publicUrl) || trim($publicUrl) === '') {
            return null;
        }

        $previewUrl = $asset->imagePreviewUrl();

        return new ReusableMediaImage(
            uuid: (string) $asset->uuid,
            title: (string) $asset->title,
            mimeType: strtolower(trim((string) $asset->mime_type)),
            publicUrl: trim($publicUrl),
            previewUrl: is_string($previewUrl) && trim($previewUrl) !== ''
                ? trim($previewUrl)
                : trim($publicUrl),
            width: is_int($asset->image_width) ? $asset->image_width : null,
            height: is_int($asset->image_height) ? $asset->image_height : null,
            sizeBytes: is_int($asset->size_bytes) ? $asset->size_bytes : null,
        );
    }

    /**
     * @param array<int, string> $mimeTypes
     * @return array<int, string>
     */
    private function normalizeMimeTypes(array $mimeTypes): array
    {
        return collect($mimeTypes)
            ->filter(fn (mixed $mimeType): bool => is_string($mimeType))
            ->map(fn (string $mimeType): string => strtolower(trim($mimeType)))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}