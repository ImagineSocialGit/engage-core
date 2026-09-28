<?php

namespace App\Modules\Campaigns\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Services\CampaignAllocationHistoryService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

final class CampaignAllocationHistoryController extends Controller
{
    public function index(Campaign $campaign, CampaignAllocationHistoryService $history): View
    {
        abort_unless($campaign->usesRecurringAllocation(), 404);

        return view('crm.campaigns.allocation-runs.index', [
            'campaign' => $campaign,
            'runs' => $history->runs($campaign),
        ]);
    }

    public function show(
        Request $request,
        Campaign $campaign,
        int $run,
        CampaignAllocationHistoryService $history,
    ): View {
        abort_unless($campaign->usesRecurringAllocation(), 404);

        $allocationRun = $history->run($campaign, $run);
        $messages = $history->messages($allocationRun);
        $requestedKey = $request->query('message');
        $messageKey = is_string($requestedKey)
            && $messages->contains('key', $requestedKey)
                ? $requestedKey : null;

        return view('crm.campaigns.allocation-runs.show', [
            'campaign' => $campaign,
            'run' => $allocationRun,
            'messages' => $messages,
            'messageKey' => $messageKey,
            'assignments' => $history->assignments(
                $allocationRun,
                $request->user(),
                $messageKey,
            ),
        ]);
    }
}