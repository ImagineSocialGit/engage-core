<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Http\Requests\CRM\StoreLeadNoteRequest;
use App\Models\Lead;

class LeadNoteController extends Controller
{
    public function store(StoreLeadNoteRequest $request, Lead $lead)
    {
        $lead->leadNotes()->create($request->validated());

        return redirect()->back();
    }
}