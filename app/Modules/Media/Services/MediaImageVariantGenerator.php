<?php

namespace App\Modules\Media\Services;

use App\Modules\Media\Models\MediaAsset;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class MediaImageVariantGenerator
{
    public const VERSION = 1;

    public const VARIANT_MEDIUM = 'medium';
    public const VARIANT_DEFAULT = 'default';

    public function available(): bool
    {
        return extension_loaded('gd')
            && function_exists('getimagesizefromstring')
            && function_exists('imagecreatefromstring')
            && function_exists('imagecreatetruecolor')
            && function_exists('imagecopyresampled')
            && function_exists('imagewebp');
    }

    public function supports(MediaAsset $asset): bool
    {
        return (bool) config('media.image_variants.enabled', true)
            && $asset->kind === MediaAsset::KIND_IMAGE
            && is_string($asset->mime_type)
            && in_array(
                strtolower(trim($asset->mime_type)),
                $this->supportedMimeTypes(),
                true,
            );
    }

    public function generate(MediaAsset $asset): bool
    {
        if (! $this->available() || ! $this->supports($asset)) {
            return false;
        }

        $disk = trim((string) $asset->disk);
        $path = trim((string) $asset->path);

        if ($disk === '' || $path === '') {
            return false;
        }

        $bytes = Storage::disk($disk)->get($path);

        if (! is_string($bytes) || $bytes === '') {
            throw new RuntimeException(
                "Media image [{$asset->uuid}] could not be read for derivative generation.",
            );
        }

        $dimensions = @getimagesizefromstring($bytes);
        $sourceWidth = is_array($dimensions) ? (int) ($dimensions[0] ?? 0) : 0;
        $sourceHeight = is_array($dimensions) ? (int) ($dimensions[1] ?? 0) : 0;

        if ($sourceWidth < 1 || $sourceHeight < 1) {
            return false;
        }

        if (($sourceWidth * $sourceHeight) > $this->maximumSourcePixels()) {
            return false;
        }

        $source = @imagecreatefromstring($bytes);

        if ($source === false) {
            return false;
        }

        try {
            $directory = dirname($path);
            $directory = $directory === '.' ? '' : trim($directory, '/');
            $visibility = is_string($asset->visibility) && trim($asset->visibility) !== ''
                ? trim($asset->visibility)
                : MediaAsset::VISIBILITY_PUBLIC;

            $medium = $this->variant(
                source: $source,
                sourceWidth: $sourceWidth,
                sourceHeight: $sourceHeight,
                maximumWidth: $this->mediumWidth(),
            );
            $default = $this->variant(
                source: $source,
                sourceWidth: $sourceWidth,
                sourceHeight: $sourceHeight,
                maximumWidth: $this->defaultMaximumWidth(),
            );

            $mediumPath = $this->childPath($directory, self::VARIANT_MEDIUM.'.webp');
            $defaultPath = $this->childPath($directory, self::VARIANT_DEFAULT.'.webp');

            $this->write(
                disk: $disk,
                path: $mediumPath,
                bytes: $medium['bytes'],
                visibility: $visibility,
            );
            $this->write(
                disk: $disk,
                path: $defaultPath,
                bytes: $default['bytes'],
                visibility: $visibility,
            );

            $meta = is_array($asset->meta) ? $asset->meta : [];
            $meta['image_variants'] = [
                'version' => self::VERSION,
                'format' => 'webp',
                'quality' => $this->quality(),
                'generated_at' => now()->toIso8601String(),
                'files' => [
                    self::VARIANT_MEDIUM => [
                        'path' => $mediumPath,
                        'width' => $medium['width'],
                        'height' => $medium['height'],
                    ],
                    self::VARIANT_DEFAULT => [
                        'path' => $defaultPath,
                        'width' => $default['width'],
                        'height' => $default['height'],
                    ],
                ],
            ];

            $asset->forceFill(['meta' => $meta])->save();

            return true;
        } finally {
            imagedestroy($source);
        }
    }

    /** @return array{bytes: string, width: int, height: int} */
    private function variant(
        mixed $source,
        int $sourceWidth,
        int $sourceHeight,
        int $maximumWidth,
    ): array {
        $width = min($sourceWidth, max(1, $maximumWidth));
        $height = max(1, (int) round(
            $sourceHeight * ($width / $sourceWidth),
        ));

        $target = imagecreatetruecolor($width, $height);

        if ($target === false) {
            throw new RuntimeException('Media image derivative canvas could not be created.');
        }

        try {
            imagealphablending($target, false);
            imagesavealpha($target, true);

            $transparent = imagecolorallocatealpha($target, 0, 0, 0, 127);

            if ($transparent !== false) {
                imagefill($target, 0, 0, $transparent);
            }

            if (! imagecopyresampled(
                $target,
                $source,
                0,
                0,
                0,
                0,
                $width,
                $height,
                $sourceWidth,
                $sourceHeight,
            )) {
                throw new RuntimeException('Media image derivative resize failed.');
            }

            ob_start();
            $encoded = imagewebp($target, null, $this->quality());
            $bytes = ob_get_clean();

            if (! $encoded || ! is_string($bytes) || $bytes === '') {
                throw new RuntimeException('Media image derivative WebP encoding failed.');
            }

            return [
                'bytes' => $bytes,
                'width' => $width,
                'height' => $height,
            ];
        } finally {
            imagedestroy($target);
        }
    }

    private function write(
        string $disk,
        string $path,
        string $bytes,
        string $visibility,
    ): void {
        if (! Storage::disk($disk)->put($path, $bytes, $visibility)) {
            throw new RuntimeException(
                "Media image derivative [{$path}] could not be written to disk [{$disk}].",
            );
        }
    }

    private function childPath(string $directory, string $filename): string
    {
        return $directory !== ''
            ? $directory.'/'.$filename
            : $filename;
    }

    /** @return array<int, string> */
    private function supportedMimeTypes(): array
    {
        return collect(config('media.image_variants.supported_mime_types', []))
            ->filter(fn (mixed $mime): bool => is_string($mime) && trim($mime) !== '')
            ->map(fn (string $mime): string => strtolower(trim($mime)))
            ->unique()
            ->values()
            ->all();
    }

    private function mediumWidth(): int
    {
        return max(1, (int) config('media.image_variants.medium_width', 500));
    }

    private function defaultMaximumWidth(): int
    {
        return max(
            $this->mediumWidth(),
            (int) config('media.image_variants.default_max_width', 1920),
        );
    }

    private function maximumSourcePixels(): int
    {
        return max(1, (int) config(
            'media.image_variants.max_source_pixels',
            40000000,
        ));
    }

    private function quality(): int
    {
        return min(100, max(1, (int) config(
            'media.image_variants.webp_quality',
            82,
        )));
    }
}