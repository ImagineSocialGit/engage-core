<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Models\Webinar;
use Illuminate\View\View;

class WebinarController extends Controller
{
    public function index(): View
    {
        $webinars = Webinar::query()
            ->orderByDesc('starts_at')
            ->limit(50)
            ->get();

        return view('crm.webinars.index', [
            'title' => 'Webinars',
            'heading' => 'Webinars',
            'webinars' => $webinars,
        ]);
    }
}