<?php

namespace App\Modules\Commerce\Contracts;

use App\Modules\Commerce\Data\CommerceInventoryAdjustmentRequest;
use App\Modules\Commerce\Data\CommerceInventoryAdjustmentResult;

interface CommerceInventoryAdjustmentProvider extends CommerceInventoryProvider
{
    public function adjustInventory(
        CommerceInventoryAdjustmentRequest $request,
    ): CommerceInventoryAdjustmentResult;
}