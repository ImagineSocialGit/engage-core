<?php

namespace App\Modules\Campaigns\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Modules\Campaigns\Actions\ActivateCampaignAction;
use App\Modules\Campaigns\Actions\DeactivateCampaignAction;
use App\Modules\Campaigns\Actions\UpdateCampaignEligibilityAction;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Requests\CampaignEligibilityAuthoringRequest;
use App\Modules\Campaigns\Services\CampaignEligibilityAuthoringService;
use App\Modules\Campaigns\Services\CampaignWorkspacePresenter;
use App\Modules\Messaging\Models\MessageChainEnrollment;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class CampaignController extends Controller
{
    public function index(): View
    {
        $campaigns = Campaign::query()
            ->withCount([
                'steps as message_steps_count' => fn ($query) => $query->where('is_active', true),
                'enrollments as open_enrollments_count' => fn ($query) => $query->whereHas(
                    'messageChainEnrollment',
                    fn ($chainQuery) => $chainQuery->whereIn('status', [
                        MessageChainEnrollment::STATUS_ACTIVE,
                        MessageChainEnrollment::STATUS_PAUSED,
                    ]),
                ),
            ])
            ->orderBy('name')
            ->get();

        return view('crm.campaigns.index', [
            'campaigns' => $campaigns,
            'statusCounts' => [
                Campaign::STATUS_ACTIVE => $campaigns->where('status', Campaign::STATUS_ACTIVE)->count(),
                Campaign::STATUS_INACTIVE => $campaigns->where('status', Campaign::STATUS_INACTIVE)->count(),
                Campaign::STATUS_ARCHIVED => $campaigns->where('status', Campaign::STATUS_ARCHIVED)->count(),
            ],
        ]);
    }

    public function show(
        Campaign $campaign,
        CampaignWorkspacePresenter $workspacePresenter,
    ): View {
        return view('crm.campaigns.show', [
            'campaign' => $campaign,
            'workspace' => $workspacePresenter->forCampaign($campaign),
        ]);
    }

    public function edit(
        Campaign $campaign,
        CampaignWorkspacePresenter $workspacePresenter,
        CampaignEligibilityAuthoringService $eligibilityAuthoring,
    ): View {
        return view('crm.campaigns.edit', [
            'campaign' => $campaign,
            'workspace' => $workspacePresenter->forCampaign($campaign),
            'eligibility' => $eligibilityAuthoring->forCampaign($campaign),
        ]);
    }

    public function previewEligibility(
        CampaignEligibilityAuthoringRequest $request,
        Campaign $campaign,
        CampaignEligibilityAuthoringService $eligibilityAuthoring,
    ): JsonResponse {
        $criteria = $eligibilityAuthoring->normalizeForCampaign(
            campaign: $campaign,
            input: $request->eligibilityCriteria(),
        );

        return response()->json([
            'matching_count' => $eligibilityAuthoring->matchingCount($criteria),
        ]);
    }

    public function updateEligibility(
        CampaignEligibilityAuthoringRequest $request,
        Campaign $campaign,
        CampaignEligibilityAuthoringService $eligibilityAuthoring,
        UpdateCampaignEligibilityAction $updateEligibility,
    ): RedirectResponse {
        $criteria = $eligibilityAuthoring->normalizeForCampaign(
            campaign: $campaign,
            input: $request->eligibilityCriteria(),
        );

        $updateEligibility->handle(
            campaign: $campaign,
            criteria: $criteria,
            enrollmentMode: $request->enrollmentMode(),
            reentryPolicy: $request->reentryPolicy(),
            ineligibleBehavior: $request->ineligibleBehavior(),
        );

        return redirect()
            ->route('crm.campaigns.edit', $campaign)
            ->with('status', 'Campaign Start settings saved.');
    }

    public function activate(
        Request $request,
        Campaign $campaign,
        ActivateCampaignAction $activateCampaign,
    ): RedirectResponse {
        try {
            $result = $activateCampaign->handle(
                campaign: $campaign,
                actor: $request->user(),
                source: 'crm_campaign_workspace',
            );
        } catch (InvalidArgumentException $exception) {
            return redirect()
                ->route('crm.campaigns.show', $campaign)
                ->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('crm.campaigns.show', $campaign)
            ->with(
                'status',
                $result['status_changed']
                    ? 'Campaign activated. New eligible enrollments may begin; previously cancelled enrollments remain historical.'
                    : 'Campaign was already active.',
            );
    }

    public function deactivate(
        Request $request,
        Campaign $campaign,
        DeactivateCampaignAction $deactivateCampaign,
    ): RedirectResponse {
        $result = $deactivateCampaign->handle(
            campaign: $campaign,
            actor: $request->user(),
            source: 'crm_campaign_workspace',
        );

        return redirect()
            ->route('crm.campaigns.show', $campaign)
            ->with('status', sprintf(
                'Campaign turned off. %d enrollment(s) cancelled and %d pending message(s) skipped.',
                $result['enrollments_cancelled'],
                $result['scheduled_messages_skipped'],
            ));
    }
}