<?php

namespace App\Modules\Campaigns\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Services\CampaignAudienceProgressService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

final class CampaignAudienceController extends Controller
{
    public function index(
        Request $request,
        Campaign $campaign,
        CampaignAudienceProgressService $progress,
    ): View {
        $view = $request->query('view') === 'matching' ? 'matching' : 'participants';
        $status = $request->query('status');
        $status = is_string($status)
            && in_array($status, CampaignAudienceProgressService::STATUSES, true)
                ? $status : null;
        $search = $request->query('search');
        $search = is_string($search) ? mb_substr(trim($search), 0, 100) : '';
        $user = $request->user();

        return view('crm.campaigns.audience', [
            'campaign' => $campaign,
            'summary' => $progress->summary($campaign, $user),
            'viewMode' => $view,
            'statusFilter' => $status,
            'search' => $search,
            'rows' => $view === 'matching'
                ? $progress->matches($campaign, $user, $search)
                : $progress->participants($campaign, $user, $status, $search),
            'statusFilters' => CampaignAudienceProgressService::STATUSES,
        ]);
    }
}