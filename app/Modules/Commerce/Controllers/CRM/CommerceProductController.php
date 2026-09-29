<?php

namespace App\Modules\Commerce\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Modules\Commerce\Models\CommerceProduct;
use App\Modules\Commerce\Services\CommerceCatalogReadService;
use Illuminate\Contracts\View\View;

final class CommerceProductController extends Controller
{
    public function __invoke(
        CommerceProduct $commerceProduct,
        CommerceCatalogReadService $commerce,
    ): View {
        return view('crm.commerce.products.show', [
            'title' => $commerceProduct->name,
            'heading' => $commerceProduct->name,
            'detail' => $commerce->productDetail($commerceProduct),
        ]);
    }
}