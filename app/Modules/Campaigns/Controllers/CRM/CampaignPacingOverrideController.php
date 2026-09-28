<?php

namespace App\Modules\Campaigns\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Services\CampaignPacingOverrideService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class CampaignPacingOverrideController extends Controller
{
    public function preview(Campaign $campaign, CampaignPacingOverrideService $override): View
    {
        return view('crm.campaigns.pacing-override', [
            'campaign' => $campaign,
            'preview' => $override->preview($campaign),
            'requestKey' => (string) Str::uuid(),
        ]);
    }

    public function store(
        Request $request,
        Campaign $campaign,
        CampaignPacingOverrideService $override,
    ): RedirectResponse {
        $validated = $request->validate([
            'target' => ['required', 'integer', 'min:1', 'max:500'],
            'request_key' => ['required', 'uuid'],
        ]);
        $result = $override->apply(
            campaign: $campaign,
            actor: $request->user(),
            target: (int) $validated['target'],
            requestKey: $validated['request_key'],
        );

        return redirect()->route('crm.campaigns.show', $campaign)
            ->with('status', $result['rescheduled'].' pending emails re-spaced across today’s remaining window.');
    }
}