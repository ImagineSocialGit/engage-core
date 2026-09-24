<?php

namespace App\Modules\Commerce\Contracts;

use App\Modules\Commerce\Data\CommercePricingState;
use App\Modules\Commerce\Data\CommerceProviderVariantReference;

interface CommercePricingProvider extends CommerceProvider
{
    public function pricing(
        CommerceProviderVariantReference $variant,
        ?string $scope = null,
    ): CommercePricingState;
}