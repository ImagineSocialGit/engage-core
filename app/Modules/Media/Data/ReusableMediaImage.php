<?php

namespace App\Modules\Media\Data;

final readonly class ReusableMediaImage
{
    public function __construct(
        public string $uuid,
        public string $title,
        public string $mimeType,
        public string $publicUrl,
        public string $previewUrl,
        public ?int $width,
        public ?int $height,
        public ?int $sizeBytes,
    ) {}
}