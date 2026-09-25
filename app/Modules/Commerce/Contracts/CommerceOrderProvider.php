<?php

namespace App\Modules\Commerce\Contracts;

use App\Modules\Commerce\Data\CommerceOrderSnapshotData;

interface CommerceOrderProvider extends CommerceProvider
{
    public function order(
        string $externalOrderId,
        ?string $scope = null,
    ): CommerceOrderSnapshotData;
}