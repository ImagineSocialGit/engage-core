<?php

namespace App\Modules\Forms\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Modules\Forms\Services\FormsSurfaceReadService;
use Illuminate\Contracts\View\View;

final class FormsController extends Controller
{
    public function __invoke(FormsSurfaceReadService $forms): View
    {
        return view('crm.forms.index', [
            'title' => 'Forms',
            'heading' => 'Forms',
            'subheading' => 'See which published forms are accepting submissions, where they are available, and what each successful submission does.',
            'overview' => $forms->read(),
        ]);
    }
}