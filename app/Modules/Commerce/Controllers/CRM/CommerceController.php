<?php

namespace App\Modules\Commerce\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Modules\Commerce\Services\CommerceCatalogReadService;
use Illuminate\Contracts\View\View;

final class CommerceController extends Controller
{
    public function __invoke(CommerceCatalogReadService $commerce): View
    {
        return view('crm.commerce.index', [
            'title' => 'Commerce',
            'heading' => 'Commerce',
            'overview' => $commerce->overview(),
            'products' => $commerce->products(),
        ]);
    }
}