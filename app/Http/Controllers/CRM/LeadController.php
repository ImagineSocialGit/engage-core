<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Models\WebinarRegistration;
use Illuminate\Http\RedirectResponse;

class LeadController extends Controller
{
    public function index()
    {
        $leads = Lead::latest()->paginate(20);

        return view('crm.leads.index', compact('leads'));
    }

    public function show(Lead $lead)
    {
        $lead->load([
            'webinarRegistrations.webinar',
            'leadNotes' => fn ($query) => $query->latest(),
            'tasks' => fn ($query) => $query->latest(),
        ]);

        return view('crm.leads.show', compact('lead'));
    }

    public function markConverted(Lead $lead, WebinarRegistration $registration): RedirectResponse
    {
        // safety: ensure registration belongs to this lead
        if ($registration->lead_id !== $lead->id) {
            abort(404);
        }

        $registration->update([
            'converted_at' => now(),
        ]);

        return back();
    }
}