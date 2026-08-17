<?php

namespace App\Modules\Reporting\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Modules\Reporting\Services\ReportingWorkspaceReadService;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class ReportingController extends Controller
{
    private const RANGE_OPTIONS = [7, 30, 90];

    public function index(
        Request $request,
        ReportingWorkspaceReadService $workspace,
    ): View {
        $days = $request->integer('days', 30);

        if (! in_array($days, self::RANGE_OPTIONS, true)) {
            $days = 30;
        }

        return view('crm.reporting.index', [
            'title' => 'Reporting',
            'heading' => 'Reporting',
            'subheading' => 'See where real visitors move forward, get stuck, or fail to complete public actions.',
            'report' => $workspace->webinarRegistration($days),
            'rangeOptions' => self::RANGE_OPTIONS,
        ]);
    }
}