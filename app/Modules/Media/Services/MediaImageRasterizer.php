<?php

namespace App\Modules\Media\Services;

use DOMDocument;
use DOMElement;
use SVG\SVG;
use Throwable;

final class MediaImageRasterizer
{
    /** @var array<int, string> */
    private const RASTER_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
    ];

    public function __construct(
        private readonly MediaSvgSafetyValidator $svgSafety,
    ) {}

    /**
     * @return array{bytes: string, width: int, height: int}|null
     */
    public function renderPng(
        string $mimeType,
        string $bytes,
        int $maximumWidth,
        int $maximumHeight,
    ): ?array {
        $mimeType = strtolower(trim($mimeType));
        $maximumWidth = max(1, min(4096, $maximumWidth));
        $maximumHeight = max(1, min(4096, $maximumHeight));

        if ($bytes === '') {
            return null;
        }

        if ($mimeType === 'image/svg+xml') {
            return $this->renderSvg(
                bytes: $bytes,
                maximumWidth: $maximumWidth,
                maximumHeight: $maximumHeight,
            );
        }

        if (! in_array($mimeType, self::RASTER_MIME_TYPES, true)) {
            return null;
        }

        return $this->renderRaster(
            bytes: $bytes,
            maximumWidth: $maximumWidth,
            maximumHeight: $maximumHeight,
        );
    }

    /**
     * @return array{bytes: string, width: int, height: int}|null
     */
    private function renderRaster(
        string $bytes,
        int $maximumWidth,
        int $maximumHeight,
    ): ?array {
        $source = @imagecreatefromstring($bytes);

        if (! $source instanceof \GdImage) {
            return null;
        }

        try {
            $sourceWidth = imagesx($source);
            $sourceHeight = imagesy($source);

            if ($sourceWidth < 1 || $sourceHeight < 1) {
                return null;
            }

            [$width, $height] = $this->fitDimensions(
                sourceWidth: $sourceWidth,
                sourceHeight: $sourceHeight,
                maximumWidth: $maximumWidth,
                maximumHeight: $maximumHeight,
            );

            $target = $this->transparentCanvas($width, $height);

            if (! $target instanceof \GdImage) {
                return null;
            }

            try {
                imagecopyresampled(
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
                );

                $png = $this->pngBytes($target);
            } finally {
                imagedestroy($target);
            }

            return $png !== null
                ? [
                    'bytes' => $png,
                    'width' => $width,
                    'height' => $height,
                ]
                : null;
        } finally {
            imagedestroy($source);
        }
    }

    /**
     * @return array{bytes: string, width: int, height: int}|null
     */
    private function renderSvg(
        string $bytes,
        int $maximumWidth,
        int $maximumHeight,
    ): ?array {
        try {
            $this->svgSafety->assertSafeBytes($bytes);
            [$sourceWidth, $sourceHeight] = $this->svgDimensions($bytes);
            [$width, $height] = $this->fitDimensions(
                sourceWidth: $sourceWidth,
                sourceHeight: $sourceHeight,
                maximumWidth: $maximumWidth,
                maximumHeight: $maximumHeight,
            );
            $image = SVG::fromString($bytes)->toRasterImage($width, $height);
        } catch (Throwable) {
            return null;
        }

        if (! $image instanceof \GdImage) {
            return null;
        }

        try {
            $png = $this->pngBytes($image);
        } finally {
            imagedestroy($image);
        }

        return $png !== null
            ? [
                'bytes' => $png,
                'width' => $width,
                'height' => $height,
            ]
            : null;
    }

    /** @return array{0: int, 1: int} */
    private function svgDimensions(string $bytes): array
    {
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);

        try {
            $loaded = $document->loadXML(
                $bytes,
                LIBXML_NONET | LIBXML_COMPACT | LIBXML_NOERROR | LIBXML_NOWARNING,
            );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if ($loaded !== true || ! $document->documentElement instanceof DOMElement) {
            return [1, 1];
        }

        $root = $document->documentElement;
        $viewBox = preg_split(
            '/[\s,]+/',
            trim((string) $root->getAttribute('viewBox')),
            -1,
            PREG_SPLIT_NO_EMPTY,
        );

        if (is_array($viewBox) && count($viewBox) === 4) {
            $width = (float) $viewBox[2];
            $height = (float) $viewBox[3];

            if ($width > 0 && $height > 0) {
                return [
                    max(1, (int) round($width * 1000)),
                    max(1, (int) round($height * 1000)),
                ];
            }
        }

        $width = $this->numericSvgLength($root->getAttribute('width'));
        $height = $this->numericSvgLength($root->getAttribute('height'));

        return $width > 0 && $height > 0
            ? [
                max(1, (int) round($width * 1000)),
                max(1, (int) round($height * 1000)),
            ]
            : [1, 1];
    }

    private function numericSvgLength(string $value): float
    {
        $value = trim($value);

        if (preg_match('/\A([0-9]+(?:\.[0-9]+)?)(?:px|pt|pc|mm|cm|in)?\z/i', $value, $matches) !== 1) {
            return 0.0;
        }

        return (float) $matches[1];
    }

    /** @return array{0: int, 1: int} */
    private function fitDimensions(
        int $sourceWidth,
        int $sourceHeight,
        int $maximumWidth,
        int $maximumHeight,
    ): array {
        $sourceWidth = max(1, $sourceWidth);
        $sourceHeight = max(1, $sourceHeight);
        $scale = min(
            1.0,
            $maximumWidth / $sourceWidth,
            $maximumHeight / $sourceHeight,
        );

        return [
            max(1, (int) round($sourceWidth * $scale)),
            max(1, (int) round($sourceHeight * $scale)),
        ];
    }

    private function transparentCanvas(
        int $width,
        int $height,
    ): ?\GdImage {
        $image = imagecreatetruecolor($width, $height);

        if (! $image instanceof \GdImage) {
            return null;
        }

        imagealphablending($image, false);
        imagesavealpha($image, true);
        $transparent = imagecolorallocatealpha($image, 0, 0, 0, 127);

        if ($transparent === false) {
            imagedestroy($image);

            return null;
        }

        imagefill($image, 0, 0, $transparent);
        imagealphablending($image, true);

        return $image;
    }

    private function pngBytes(\GdImage $image): ?string
    {
        ob_start();

        try {
            $written = imagepng($image, null, 6);
            $bytes = ob_get_clean();
        } catch (Throwable) {
            ob_end_clean();

            return null;
        }

        return $written === true && is_string($bytes) && $bytes !== ''
            ? $bytes
            : null;
    }
}