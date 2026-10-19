<?php

namespace App\Modules\Media\Services;

use Illuminate\Http\UploadedFile;

final class MediaUploadPolicy
{
    public const SVG_MIME_TYPE = 'image/svg+xml';

    private const SVG_DETECTION_FALLBACK_MIME_TYPES = [
        'application/octet-stream',
        'application/x-empty',
        'application/xml',
        'text/plain',
        'text/xml',
    ];

    /** @return array<int, string> */
    public function allowedMimeTypes(): array
    {
        return collect(config('media.allowed_mime_types', []))
            ->filter(fn (mixed $value): bool => is_string($value) && trim($value) !== '')
            ->map(fn (string $value): string => strtolower(trim($value)))
            ->unique()
            ->values()
            ->all();
    }

    public function effectiveMimeType(UploadedFile $file): ?string
    {
        $allowed = $this->allowedMimeTypes();
        $detected = $this->normalizedMimeType($file->getMimeType());

        if ($detected !== null && in_array($detected, $allowed, true)) {
            return $detected;
        }

        $client = $this->normalizedMimeType($file->getClientMimeType());

        if (in_array(self::SVG_MIME_TYPE, $allowed, true)
            && $client === self::SVG_MIME_TYPE
            && in_array(
                $detected,
                [null, ...self::SVG_DETECTION_FALLBACK_MIME_TYPES],
                true,
            )
        ) {
            return self::SVG_MIME_TYPE;
        }

        if (in_array($detected, [null, 'application/octet-stream', 'application/x-empty'], true)
            && $client !== null
            && in_array($client, $allowed, true)
        ) {
            return $client;
        }

        return null;
    }

    public function kindForMimeType(string $mimeType): string
    {
        $mimeType = strtolower(trim($mimeType));

        return match (true) {
            str_starts_with($mimeType, 'image/') => 'image',
            str_starts_with($mimeType, 'video/') => 'video',
            str_starts_with($mimeType, 'audio/') => 'audio',
            in_array($mimeType, ['application/pdf', 'text/plain'], true) => 'document',
            default => 'file',
        };
    }

    private function normalizedMimeType(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = strtolower(trim($value));

        return $value !== '' ? $value : null;
    }
}