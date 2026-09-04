<?php

namespace App\Modules\Media\Data;

final readonly class ImagePerceptualFingerprint
{
    public function __construct(
        public string $hash,
        public string $algorithm,
        public int $width,
        public int $height,
    ) {}
}