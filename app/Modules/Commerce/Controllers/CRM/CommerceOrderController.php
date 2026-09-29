<?php

namespace App\Modules\Commerce\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Commerce\Models\CommerceOrder;
use App\Modules\Commerce\Services\CommerceOperatorAccessService;
use App\Modules\Commerce\Services\CommerceOrderReadService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

final class CommerceOrderController extends Controller
{
    public function index(
        Request $request,
        CommerceOrderReadService $orders,
    ): View {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $filters = $orders->filters($request->query());

        return view('crm.commerce.orders.index', [
            'title' => 'Commerce Orders',
            'heading' => 'Orders',
            'orders' => $orders->orders($user, $filters),
            'summary' => $orders->summary($user, $filters),
            'filters' => $filters,
            'filterOptions' => $orders->filterOptions($user),
        ]);
    }

    public function show(
        Request $request,
        CommerceOrder $commerceOrder,
        CommerceOrderReadService $orders,
        CommerceOperatorAccessService $operatorAccess,
    ): View {
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        abort_unless($orders->canView($user, $commerceOrder), 404);

        return view('crm.commerce.orders.show', [
            'title' => $commerceOrder->order_name
                ?: $commerceOrder->order_number
                ?: 'Commerce Order #'.$commerceOrder->getKey(),
            'heading' => $commerceOrder->order_name
                ?: $commerceOrder->order_number
                ?: 'Order #'.$commerceOrder->getKey(),
            'detail' => $orders->detail($user, $commerceOrder),
            'canOperate' => $operatorAccess->canOperate($user),
        ]);
    }
}