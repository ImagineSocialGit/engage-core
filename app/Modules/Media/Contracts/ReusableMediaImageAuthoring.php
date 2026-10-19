<?php

namespace App\Modules\Media\Contracts;

use App\Modules\Media\Data\ReusableMediaImage;
use Illuminate\Database\Eloquent\Model;

interface ReusableMediaImageAuthoring
{
    public function storeGeneratedSvg(
        string $svg,
        string $title,
        string $source,
        ?Model $createdBy = null,
    ): ReusableMediaImage;

    public function assigned(
        Model $subject,
        string $role,
    ): ?ReusableMediaImage;

    public function assign(
        Model $subject,
        string $role,
        string $assetUuid,
    ): ReusableMediaImage;

    public function clearAssignment(
        Model $subject,
        string $role,
    ): void;
}