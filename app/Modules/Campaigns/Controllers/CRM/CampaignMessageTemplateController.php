<?php

namespace App\Modules\Campaigns\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignStep;
use App\Modules\Campaigns\Requests\UpdateCampaignStepMessageTemplateRequest;
use App\Modules\Messaging\Actions\AssignMessageTemplatePresetAction;
use App\Modules\Messaging\Models\MessageTemplateCatalogEntry;
use App\Modules\Messaging\Models\MessageTemplatePresetAssignment;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class CampaignMessageTemplateController extends Controller
{
    public function index(Request $request): View
    {
        $campaigns = Campaign::query()
            ->with([
                'steps' => fn ($query) => $query->active()->orderBy('step_number'),
            ])
            ->active()
            ->orderBy('name')
            ->get();

        $selectedCampaign = $this->selectedCampaign($request, $campaigns);
        $selectedCampaign?->loadMissing([
            'steps' => fn ($query) => $query->active()->orderBy('step_number'),
        ]);

        $selectedStep = $this->selectedStep($request, $selectedCampaign);
        $currentAssignments = $selectedCampaign instanceof Campaign
            ? $this->currentAssignmentsForCampaign($selectedCampaign)
            : collect();

        return view('crm.campaigns.message-templates.index', [
            'campaigns' => $campaigns,
            'selectedCampaign' => $selectedCampaign,
            'selectedStep' => $selectedStep,
            'currentAssignments' => $currentAssignments,
            'templateOptionsByStep' => $selectedCampaign instanceof Campaign
                ? $this->templateOptionsByStep($selectedCampaign)
                : collect(),
        ]);
    }

    public function update(
        UpdateCampaignStepMessageTemplateRequest $request,
        CampaignStep $campaignStep,
        AssignMessageTemplatePresetAction $assignTemplatePreset,
    ): RedirectResponse {
        $campaignStep->loadMissing('campaign');
        $preset = $request->messageTemplatePreset();

        $assignTemplatePreset->handle(
            preset: $preset,
            channel: $campaignStep->channel,
            purpose: $campaignStep->purpose,
            scope: $campaignStep->scope,
            surface: 'campaigns',
            messageType: $preset->message_type,
            campaignKey: $campaignStep->campaign?->key,
            campaignStep: (int) $campaignStep->step_number,
            meta: [
                'source' => 'crm_campaign_message_template_assignment',
                'campaign' => [
                    'campaign_id' => $campaignStep->campaign_id,
                    'campaign_key' => $campaignStep->campaign?->key,
                    'campaign_step_id' => $campaignStep->id,
                    'campaign_step' => (int) $campaignStep->step_number,
                ],
            ],
        );

        return redirect()
            ->route('crm.campaigns.message-templates.index', array_filter([
                'campaign' => $campaignStep->campaign_id,
                'step' => $campaignStep->id,
            ]))
            ->with('status', 'Campaign message template updated.');
    }

    /**
     * @param Collection<int, Campaign> $campaigns
     */
    private function selectedCampaign(Request $request, Collection $campaigns): ?Campaign
    {
        $selectedCampaign = $request->query('campaign');

        if (is_numeric($selectedCampaign) && (int) $selectedCampaign > 0) {
            $selected = $campaigns->firstWhere('id', (int) $selectedCampaign);

            if ($selected instanceof Campaign) {
                return $selected;
            }
        }

        if (is_string($selectedCampaign) && trim($selectedCampaign) !== '') {
            $selected = $campaigns->firstWhere('key', $this->normalizeSegment($selectedCampaign));

            if ($selected instanceof Campaign) {
                return $selected;
            }
        }

        return $campaigns->first();
    }

    private function selectedStep(Request $request, ?Campaign $campaign): ?CampaignStep
    {
        if (! $campaign instanceof Campaign) {
            return null;
        }

        $selectedStep = $request->query('step');

        if (is_numeric($selectedStep) && (int) $selectedStep > 0) {
            $selectedById = $campaign->steps->firstWhere('id', (int) $selectedStep);

            if ($selectedById instanceof CampaignStep) {
                return $selectedById;
            }

            $selectedByNumber = $campaign->steps->firstWhere('step_number', (int) $selectedStep);

            if ($selectedByNumber instanceof CampaignStep) {
                return $selectedByNumber;
            }
        }

        return $campaign->steps->first();
    }

    /**
     * @return Collection<string, MessageTemplatePresetAssignment>
     */
    private function currentAssignmentsForCampaign(Campaign $campaign): Collection
    {
        /** @var Collection<int, MessageTemplatePresetAssignment> $assignments */
        $assignments = MessageTemplatePresetAssignment::query()
            ->active()
            ->with(['messageTemplatePreset.catalogEntries'])
            ->where('surface', 'campaigns')
            ->where('campaign_key', $campaign->key)
            ->whereNull('context_type')
            ->whereNull('context_id')
            ->orderByDesc('id')
            ->get();

        return $assignments
            ->unique(fn (MessageTemplatePresetAssignment $assignment): string => implode(':', [
                $assignment->channel,
                $assignment->purpose,
                $assignment->scope,
                $assignment->campaign_step,
            ]))
            ->keyBy(fn (MessageTemplatePresetAssignment $assignment): string => $this->assignmentKey(
                channel: $assignment->channel,
                purpose: $assignment->purpose,
                scope: $assignment->scope,
                stepNumber: (int) $assignment->campaign_step,
            ));
    }

    /**
     * @return Collection<string, Collection<int, MessageTemplateCatalogEntry>>
     */
    private function templateOptionsByStep(Campaign $campaign): Collection
    {
        $campaign->loadMissing('steps');

        $options = collect();

        foreach ($campaign->steps as $step) {
            if (! $step instanceof CampaignStep) {
                continue;
            }

            $entries = MessageTemplateCatalogEntry::query()
                ->active()
                ->with('messageTemplatePreset')
                ->where('usage_type', 'campaign_step')
                ->where('channel', $step->channel)
                ->where('purpose', $step->purpose)
                ->where('scope', $step->scope)
                ->where('meta->campaign_key', $campaign->key)
                ->where('meta->campaign_step', (int) $step->step_number)
                ->orderBy('item_order')
                ->orderBy('item_label')
                ->get()
                ->filter(fn (MessageTemplateCatalogEntry $entry): bool => (bool) $entry->messageTemplatePreset?->isActive())
                ->values();

            $options->put($this->stepKey($step), $entries);
        }

        return $options;
    }

    private function stepKey(CampaignStep $step): string
    {
        return $this->assignmentKey(
            channel: $step->channel,
            purpose: $step->purpose,
            scope: $step->scope,
            stepNumber: (int) $step->step_number,
        );
    }

    private function assignmentKey(string $channel, string $purpose, string $scope, int $stepNumber): string
    {
        return implode(':', [
            $this->normalizeSegment($channel),
            $this->normalizeSegment($purpose),
            $this->normalizeSegment($scope),
            $stepNumber,
        ]);
    }

    private function normalizeSegment(string $value): string
    {
        return str_replace('-', '_', strtolower(trim($value)));
    }
}

