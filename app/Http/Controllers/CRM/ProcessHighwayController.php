<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Support\ProcessHighway\ProcessHighwayReadService;
use Illuminate\View\View;

class ProcessHighwayController extends Controller
{
    public function __invoke(ProcessHighwayReadService $processHighway): View
    {
        $highway = $processHighway->read();

        return view('crm.process-highway.index', [
            'title' => 'Process Highway',
            'heading' => 'Process Highway',
            'subheading' => 'A simple view of what starts each process, what Core does next, and where the process can lead.',
            'highway' => $highway,
        ]);
    }
}