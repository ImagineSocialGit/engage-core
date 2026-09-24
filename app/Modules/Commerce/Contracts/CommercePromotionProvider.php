<?php

namespace App\Modules\Commerce\Contracts;

use App\Modules\Commerce\Data\CommercePromotionState;
use App\Modules\Commerce\Data\CommerceProviderVariantReference;

interface CommercePromotionProvider extends CommerceProvider
{
    public function promotion(
        CommerceProviderVariantReference $variant,
        ?string $scope = null,
    ): CommercePromotionState;
}