<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Support\ProcessHighway\ProcessHighwayReadService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProcessHighwayController extends Controller
{
    public function __invoke(
        Request $request,
        ProcessHighwayReadService $processHighway,
    ): View {
        $highway = $processHighway->read();

        return view('crm.process-highway.index', [
            'title' => 'Process Highway',
            'heading' => 'Process Highway',
            'subheading' => 'See how contact facts, follow-up programs, and automations connect across each business process.',
            'highway' => $highway,
            'initialQualifierSelection' => $this->initialQualifierSelection($request),
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function initialQualifierSelection(Request $request): array
    {
        $status = $request->query('status');

        if (! is_string($status)) {
            return [];
        }

        $status = trim($status);

        if (
            $status === ''
            || mb_strlen($status) > 191
            || preg_match('/[\x00-\x1F\x7F]/', $status) === 1
        ) {
            return [];
        }

        return [
            'status' => $status,
        ];
    }
}