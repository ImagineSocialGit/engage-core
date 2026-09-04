<?php

namespace App\Modules\Campaigns\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Campaigns\Actions\ActivateCampaignAction;
use App\Modules\Campaigns\Actions\CreateCampaignAction;
use App\Modules\Campaigns\Actions\DeactivateCampaignAction;
use App\Modules\Campaigns\Actions\PublishCampaignMessageChainVersionAction;
use App\Modules\Campaigns\Actions\UpdateCampaignEligibilityAction;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Requests\CampaignEligibilityAuthoringRequest;
use App\Modules\Campaigns\Requests\StoreCampaignRequest;
use App\Modules\Campaigns\Requests\UpdateCampaignMessageRequest;
use App\Modules\Campaigns\Requests\UpdateCampaignMessageReplyHandlingRequest;
use App\Modules\Campaigns\Requests\UpdateCampaignScheduleRequest;
use App\Modules\Campaigns\Services\CampaignCreationGuide;
use App\Modules\Campaigns\Services\CampaignEligibilityAuthoringService;
use App\Modules\Campaigns\Services\CampaignMessageReviewPresenter;
use App\Modules\Campaigns\Services\CampaignScheduleAuthoringPresenter;
use App\Modules\Campaigns\Services\CampaignWorkspacePresenter;
use App\Modules\Messaging\Actions\PublishMessageTemplatePresetOverrideAction;
use App\Modules\Messaging\Models\MessageChainEnrollment;
use App\Modules\Messaging\Models\MessageChainStepVariant;
use App\Modules\Messaging\Models\MessageTemplatePreset;
use App\Modules\Messaging\Services\MessageMediaAuthoringService;
use App\Modules\Messaging\Services\MessageTemplateAuthoringFieldPresenter;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class CampaignController extends Controller
{
    private const EDIT_PANELS = [
        'start',
        'schedule',
        'messages',
        'review',
    ];

    public function index(): View
    {
        $campaigns = Campaign::query()
            ->with([
                'messageChain.currentVersion.steps',
                'steps' => fn ($query) => $query->where('is_active', true),
            ])
            ->withCount([
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

        $campaigns->each(function (Campaign $campaign): void {
            $currentVersion = $campaign->messageChain?->currentVersion;

            $currentVersionStepCount = $currentVersion?->isPublished()
                ? $currentVersion->steps->where('is_active', true)->count()
                : 0;

            $messageStepCount = $currentVersionStepCount > 0
                ? $currentVersionStepCount
                : $campaign->steps->count();

            $campaign->setAttribute('message_steps_count', $messageStepCount);
        });

        return view('crm.campaigns.index', [
            'campaigns' => $campaigns,
            'statusCounts' => [
                Campaign::STATUS_ACTIVE => $campaigns->where('status', Campaign::STATUS_ACTIVE)->count(),
                Campaign::STATUS_INACTIVE => $campaigns->where('status', Campaign::STATUS_INACTIVE)->count(),
                Campaign::STATUS_ARCHIVED => $campaigns->where('status', Campaign::STATUS_ARCHIVED)->count(),
            ],
        ]);
    }

    public function create(
        Request $request,
        CampaignCreationGuide $creationGuide,
        MessageTemplateAuthoringFieldPresenter $authoringFields,
    ): View {
        $options = $creationGuide->options();
        $requestedOption = $request->old('creation_intent');

        if (! is_string($requestedOption) || trim($requestedOption) === '') {
            $requestedOption = $request->query('use');
        }

        $selectedOption = $creationGuide->find($requestedOption)
            ?? ($options[0] ?? null);

        return view('crm.campaigns.create', [
            'options' => $options,
            'selectedOption' => $selectedOption,
            'builderStages' => $creationGuide->builderStages(),
            'availableFields' => $authoringFields->groupsForContext(
                CreateCampaignAction::DISPATCH_KEY,
            ),
        ]);
    }

    public function store(
        StoreCampaignRequest $request,
        CampaignCreationGuide $creationGuide,
        CreateCampaignAction $createCampaign,
        MessageMediaAuthoringService $mediaAuthoring,
    ): RedirectResponse {
        $creationOption = $creationGuide->find($request->creationIntent());

        if ($creationOption === null) {
            throw ValidationException::withMessages([
                'creation_intent' => 'Choose what this Campaign is for.',
            ]);
        }

        try {
            $payload = $request->payloadForChannel();

            if ($request->channel() === 'email') {
                $payload = $mediaAuthoring->apply(
                    payload: $payload,
                    submitted: $request->hasMessageMediaSubmission(),
                    upload: $request->messageMediaUpload(),
                    assetUuid: $request->messageMediaAssetUuid(),
                    posterAssetUuid: $request->messageMediaPosterAssetUuid(),
                    title: $request->messageMediaTitle(),
                    uploadedBy: $request->user(),
                );
            }

            $campaign = $createCampaign->handle(
                name: $request->campaignName(),
                description: $request->campaignDescription(),
                channel: $request->channel(),
                firstMessagePayload: $payload,
                creationOption: $creationOption,
                createdBy: $request->user() instanceof User
                    ? $request->user()
                    : null,
            );
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'campaign' => $exception->getMessage(),
            ]);
        }

        return redirect()
            ->route('crm.campaigns.edit', [
                'campaign' => $campaign,
                'panel' => 'start',
            ])
            ->with(
                'status',
                'Campaign created as an inactive draft. Review Start, Schedule, Messages, and Review before turning it on.',
            );
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
        Request $request,
        Campaign $campaign,
        CampaignWorkspacePresenter $workspacePresenter,
        CampaignEligibilityAuthoringService $eligibilityAuthoring,
        CampaignMessageReviewPresenter $messageReviewPresenter,
        CampaignScheduleAuthoringPresenter $schedulePresenter,
    ): View {
        $scheduleAuthoring = $schedulePresenter->forCampaign($campaign);

        return view('crm.campaigns.edit', [
            'campaign' => $campaign,
            'workspace' => $workspacePresenter->forCampaign(
                campaign: $campaign,
                schedule: $scheduleAuthoring,
            ),
            'eligibility' => $eligibilityAuthoring->forCampaign($campaign),
            'messageReview' => $messageReviewPresenter->forCampaign(
                campaign: $campaign,
                initialMessageId: $this->initialMessageId($request),
            ),
            'scheduleAuthoring' => $scheduleAuthoring,
            'initialPanel' => $this->initialPanel($request),
        ]);
    }

    public function updateSchedule(
        UpdateCampaignScheduleRequest $request,
        Campaign $campaign,
        PublishCampaignMessageChainVersionAction $publishCampaignVersion,
    ): RedirectResponse {
        $actor = $request->user();
        $published = $publishCampaignVersion->replaceSchedule(
            campaign: $campaign,
            expectedVersionId: $request->expectedVersionId(),
            submittedSteps: $request->scheduleSteps(),
            newStep: $request->newStep(),
            createdBy: $actor instanceof User ? $actor : null,
        );

        return redirect()
            ->route('crm.campaigns.edit', [
                'campaign' => $campaign,
                'panel' => 'schedule',
            ])
            ->with('status', 'Campaign schedule version '.$published->version.' published for future enrollments.');
    }

    public function updateMessage(
        UpdateCampaignMessageRequest $request,
        Campaign $campaign,
        MessageChainStepVariant $messageChainStepVariant,
        MessageTemplatePreset $messageTemplatePreset,
        PublishMessageTemplatePresetOverrideAction $publishTemplateOverride,
        PublishCampaignMessageChainVersionAction $publishCampaignVersion,
    ): RedirectResponse {
        $actor = $request->user();
        $messageChainStepVariant->loadMissing(
            'messageTemplateVersion.messageTemplate',
        );

        abort_unless(
            $messageChainStepVariant->messageTemplateVersion?->messageTemplate?->key
                === $messageTemplatePreset->key,
            404,
        );

        DB::transaction(function () use (
            $request,
            $campaign,
            $messageChainStepVariant,
            $messageTemplatePreset,
            $publishTemplateOverride,
            $publishCampaignVersion,
            $actor,
        ): void {
            $templatePublication = $publishTemplateOverride->handle(
                preset: $messageTemplatePreset,
                submittedPayload: $request->safePayload(),
                createdBy: $actor instanceof User ? $actor : null,
            );

            $publishCampaignVersion->replaceVariantTemplate(
                campaign: $campaign,
                expectedVersionId: $request->expectedVersionId(),
                messageChainStepVariantId: (int) $messageChainStepVariant->getKey(),
                replacement: $templatePublication->version,
                createdBy: $actor instanceof User ? $actor : null,
            );
        }, 3);

        return redirect()
            ->route('crm.campaigns.edit', [
                'campaign' => $campaign,
                'panel' => 'messages',
            ])
            ->with('status', 'Message and Campaign schedule versions published for future enrollments.');
    }

    public function updateMessageReplyHandling(
        UpdateCampaignMessageReplyHandlingRequest $request,
        Campaign $campaign,
        MessageChainStepVariant $messageChainStepVariant,
        PublishCampaignMessageChainVersionAction $publishCampaignVersion,
    ): RedirectResponse {
        $actor = $request->user();
        $published = $publishCampaignVersion->replaceVariantReplyProfile(
            campaign: $campaign,
            expectedVersionId: $request->expectedVersionId(),
            messageChainStepVariantId: (int) $messageChainStepVariant->getKey(),
            replyProfileKey: $request->replyProfileKey(),
            createdBy: $actor instanceof User ? $actor : null,
        );

        return redirect()
            ->route('crm.campaigns.edit', [
                'campaign' => $campaign,
                'panel' => 'messages',
            ])
            ->with('status', 'Reply handling updated in Campaign schedule version '.$published->version.'.');
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
            ->with([
                'status' => 'Campaign Start settings saved.',
                'campaign_panel' => 'start',
            ]);
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
            return $this->workspaceRedirect($request, $campaign)
                ->with('error', $exception->getMessage());
        }

        return $this->workspaceRedirect($request, $campaign)
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

        return $this->workspaceRedirect($request, $campaign)
            ->with('status', sprintf(
                'Campaign turned off. %d enrollment(s) cancelled and %d pending message(s) skipped.',
                $result['enrollments_cancelled'],
                $result['scheduled_messages_skipped'],
            ));
    }

    private function initialPanel(Request $request): ?string
    {
        $panel = $request->query('panel');

        if (! is_string($panel) || ! in_array($panel, self::EDIT_PANELS, true)) {
            $panel = $request->session()->get('campaign_panel');
        }

        return is_string($panel) && in_array($panel, self::EDIT_PANELS, true)
            ? $panel
            : null;
    }

    private function initialMessageId(Request $request): ?string
    {
        $messageId = $request->query('message');

        return is_string($messageId) && trim($messageId) !== ''
            ? trim($messageId)
            : null;
    }

    private function workspaceRedirect(
        Request $request,
        Campaign $campaign,
    ): RedirectResponse {
        $returnTo = $this->safeReturnPath($request->input('return_to'));

        return $returnTo !== null
            ? redirect($returnTo)
            : redirect()->route('crm.campaigns.show', $campaign);
    }

    private function safeReturnPath(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        if ($value === ''
            || ! str_starts_with($value, '/')
            || str_starts_with($value, '//')
            || str_contains($value, '\\')
            || preg_match('/[\x00-\x1F\x7F]/', $value) === 1
        ) {
            return null;
        }

        $parts = parse_url($value);

        if ($parts === false || isset($parts['scheme']) || isset($parts['host'])) {
            return null;
        }

        return $value;
    }
}