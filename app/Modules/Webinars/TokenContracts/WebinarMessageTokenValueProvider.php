<?php

namespace App\Modules\Webinars\TokenContracts;

use App\Support\TokenContracts\Contracts\ComputedTokenValueProvider;

class WebinarMessageTokenValueProvider implements ComputedTokenValueProvider
{
    public function value(string $sourcePath, array $context): mixed
    {
        return data_get($context, $sourcePath);
    }
}
