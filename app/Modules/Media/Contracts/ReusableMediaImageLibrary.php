<?php

namespace App\Modules\Media\Contracts;

use App\Modules\Media\Data\ReusableMediaImage;
use App\Modules\Media\Data\ReusableMediaImageContent;
use App\Modules\Media\Data\ReusableMediaRenderedImage;

interface ReusableMediaImageLibrary
{
    /**
     * @param array<int, string> $mimeTypes
     * @return array<int, ReusableMediaImage>
     */
    public function selectable(
        array $mimeTypes = [],
        int $limit = 200,
    ): array;

    public function find(string $uuid): ?ReusableMediaImage;

    public function content(string $uuid): ?ReusableMediaImageContent;

    public function renderedPng(
        string $uuid,
        int $maximumWidth = 1024,
        int $maximumHeight = 1024,
    ): ?ReusableMediaRenderedImage;
}