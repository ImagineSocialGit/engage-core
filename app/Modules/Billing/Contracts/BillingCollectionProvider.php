<?php

namespace App\Modules\Billing\Contracts;

use App\Modules\Billing\Data\BillingCollectionRequest;
use App\Modules\Billing\Data\BillingCollectionResult;

interface BillingCollectionProvider
{
    public function key(): string;

    public function createCollection(
        BillingCollectionRequest $request,
    ): BillingCollectionResult;
}