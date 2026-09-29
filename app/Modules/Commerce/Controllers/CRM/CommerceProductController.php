<?php

namespace App\Modules\Commerce\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Commerce\Models\CommerceProduct;
use App\Modules\Commerce\Services\CommerceCatalogReadService;
use App\Modules\Commerce\Services\CommerceOperatorAccessService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

final class CommerceProductController extends Controller
{
    public function __invoke(
        Request $request,
        CommerceProduct $commerceProduct,
        CommerceCatalogReadService $commerce,
        CommerceOperatorAccessService $operatorAccess,
    ): View {
        $user = $request->user();

        return view('crm.commerce.products.show', [
            'title' => $commerceProduct->name,
            'heading' => $commerceProduct->name,
            'detail' => $commerce->productDetail($commerceProduct),
            'canOperate' => $user instanceof User
                && $operatorAccess->canOperate($user),
        ]);
    }
}