<?php

namespace App\Modules\Commerce\Contracts;

use App\Modules\Commerce\Data\CommerceCheckoutRequest;
use App\Modules\Commerce\Data\CommerceCheckoutResult;

interface CommerceCheckoutProvider extends CommerceProvider
{
    public function createCheckout(
        CommerceCheckoutRequest $request,
    ): CommerceCheckoutResult;
}