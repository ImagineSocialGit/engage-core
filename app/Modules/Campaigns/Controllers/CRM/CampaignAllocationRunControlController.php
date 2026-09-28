<?php

namespace App\Modules\Campaigns\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Modules\Campaigns\Actions\ScheduleDueCampaignAllocationRunsAction;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Services\CampaignAllocationRunPreviewService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class CampaignAllocationRunControlController extends Controller
{
    public function preview(
        Campaign $campaign,
        CampaignAllocationRunPreviewService $preview,
    ): View {
        abort_unless($campaign->usesRecurringAllocation(), 404);

        return view('crm.campaigns.allocation-runs.preview', [
            'campaign' => $campaign,
            'preview' => $preview->forCampaign($campaign),
            'requestKey' => (string) Str::uuid(),
        ]);
    }

    public function store(
        Request $request,
        Campaign $campaign,
        ScheduleDueCampaignAllocationRunsAction $scheduler,
    ): RedirectResponse {
        abort_unless($campaign->usesRecurringAllocation(), 404);

        $validated = $request->validate([
            'request_key' => ['required', 'uuid'],
        ]);

        $run = $scheduler->scheduleNow(
            campaign: $campaign,
            actor: $request->user(),
            requestKey: $validated['request_key'],
        );

        return redirect()
            ->route('crm.campaigns.runs.show', ['campaign' => $campaign, 'run' => $run])
            ->with('status', 'Allocation run queued. Assignments will appear as processing completes.');
    }
}