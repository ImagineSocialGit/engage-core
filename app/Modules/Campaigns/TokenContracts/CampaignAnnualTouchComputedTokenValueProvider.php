<?php

namespace App\Modules\Campaigns\TokenContracts;

use App\Support\TokenContracts\Contracts\ComputedTokenValueProvider;

final class CampaignAnnualTouchComputedTokenValueProvider implements ComputedTokenValueProvider
{
    public function value(string $sourcePath, array $context): mixed
    {
        return data_get($context, $sourcePath);
    }
}