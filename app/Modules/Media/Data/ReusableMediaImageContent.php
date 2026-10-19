<?php

namespace App\Modules\Media\Data;

final readonly class ReusableMediaImageContent
{
    public function __construct(
        public ReusableMediaImage $image,
        public string $bytes,
    ) {}
}