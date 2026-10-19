<?php

namespace App\Modules\Media\Data;

final readonly class ReusableMediaRenderedImage
{
    public function __construct(
        public ReusableMediaImage $source,
        public string $mimeType,
        public string $bytes,
        public int $width,
        public int $height,
    ) {}
}