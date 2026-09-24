<?php

namespace App\Modules\Commerce\Contracts;

use App\Modules\Commerce\Data\CommerceCatalogPage;
use App\Modules\Commerce\Data\CommerceCatalogPageRequest;

interface CommerceCatalogProvider extends CommerceProvider
{
    public function catalogPage(
        CommerceCatalogPageRequest $request,
    ): CommerceCatalogPage;
}