<?php

namespace App\Modules\Commerce\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Core\Access\Services\UserAccessService;
use App\Modules\Commerce\Services\CommerceCatalogReadService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

final class CommerceController extends Controller
{
    public function __invoke(
        Request $request,
        CommerceCatalogReadService $commerce,
        UserAccessService $access,
    ): View {
        $filters = $commerce->filters($request->query());

        return view('crm.commerce.index', [
            'title' => 'Commerce',
            'heading' => 'Commerce',
            'overview' => $commerce->overview(),
            'products' => $commerce->products($filters),
            'filters' => $filters,
            'filterOptions' => $commerce->filterOptions(),
            'canReviewPrivacy' => $request->user() instanceof User
                && $access->allows($request->user(), 'settings.manage'),
        ]);
    }
}