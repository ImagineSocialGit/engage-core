<?php

namespace App\Modules\Commerce\Contracts;

use App\Modules\Commerce\Data\CommerceInventoryState;
use App\Modules\Commerce\Data\CommerceProviderVariantReference;

interface CommerceInventoryReadProvider extends CommerceInventoryProvider
{
    public function inventory(
        CommerceProviderVariantReference $variant,
        ?string $scope = null,
    ): CommerceInventoryState;
}