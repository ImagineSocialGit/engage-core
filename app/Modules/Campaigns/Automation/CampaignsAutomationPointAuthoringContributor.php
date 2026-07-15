<?php

namespace App\Modules\Campaigns\Automation;

use App\Modules\Campaigns\Models\Campaign;
use App\Support\AutomationCapabilities\Contracts\AutomationPointAuthoringContributor;
use App\Support\AutomationCapabilities\Data\AutomationPointAuthoringContext;
use App\Support\AutomationCapabilities\Data\AutomationPointAuthoringDefinition;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CampaignsAutomationPointAuthoringContributor implements AutomationPointAuthoringContributor
{
    public function definitions(): iterable
    {
        yield new AutomationPointAuthoringDefinition(
            pointType: 'enroll_campaign',
            moduleKey: 'campaigns',
            name: 'Start Campaign',
            description: 'Start a Campaign for this contact. A Campaign is a series of scheduled messages sent automatically in steps.',
            tip: 'Use a Campaign when several scheduled messages should happen in sequence.',
            useCases: [
                'Start a webinar nurture Campaign.',
                'Begin a reusable long-term follow-up Campaign.',
            ],
            typeLabel: 'Campaign',
            genericLabels: ['start campaign'],
            generatedPrefixes: ['start campaign:'],
        );

        yield new AutomationPointAuthoringDefinition(
            pointType: 'cancel_campaign',
            moduleKey: 'campaigns',
            name: 'Stop Campaign',
            description: 'Stop a Campaign that this Route started and optionally skip pending messages.',
            tip: 'Use this when a later outcome means the Campaign started by this Route should stop.',
            useCases: [
                'Stop a nurture Campaign after conversion.',
                'Stop pending follow-up when the Route reaches a final outcome.',
            ],
            typeLabel: 'Campaign',
            genericLabels: ['stop campaign'],
            generatedPrefixes: ['stop campaign:'],
        );
    }

    public function available(string $pointType, AutomationPointAuthoringContext $context): bool
    {
        if (! Campaign::query()->active()->exists()) {
            return false;
        }

        return $pointType !== 'cancel_campaign' || $context->hasPointType('enroll_campaign');
    }

    public function fields(string $pointType, array $definition, AutomationPointAuthoringContext $context): array
    {
        $fields = [[
            'type' => 'select',
            'name' => 'campaign_key',
            'label' => $pointType === 'cancel_campaign' ? 'Campaign to stop' : 'Campaign to start',
            'required' => true,
            'value' => (string) ($definition['campaign_key'] ?? ''),
            'placeholder' => 'Choose a Campaign',
            'options' => Campaign::query()
                ->active()
                ->orderBy('name')
                ->get(['key', 'name', 'description'])
                ->map(fn (Campaign $campaign): array => [
                    'value' => (string) $campaign->key,
                    'label' => (string) $campaign->name,
                    'description' => (string) ($campaign->description ?? ''),
                ])->all(),
        ]];

        if ($pointType === 'cancel_campaign') {
            $fields[] = [
                'type' => 'checkbox',
                'name' => 'skip_pending_messages',
                'label' => 'Skip pending Campaign messages',
                'value' => (bool) ($definition['skip_pending_messages'] ?? true),
                'help' => 'Recommended: prevent already-scheduled future messages from sending after the Campaign is stopped.',
            ];
        }

        return $fields;
    }

    public function rules(string $pointType, AutomationPointAuthoringContext $context): array
    {
        $rules = [
            'campaign_key' => ['required', 'string', 'max:255'],
        ];

        if ($pointType === 'cancel_campaign') {
            $rules['skip_pending_messages'] = ['nullable', 'boolean'];
        }

        return $rules;
    }

    public function buildDefinition(string $pointType, array $input, AutomationPointAuthoringContext $context): array
    {
        $campaign = $this->activeCampaign((string) ($input['campaign_key'] ?? ''));

        return match ($pointType) {
            'enroll_campaign' => [
                'campaign_key' => (string) $campaign->key,
                'on_already_enrolled' => 'skipped',
            ],
            'cancel_campaign' => [
                'campaign_key' => (string) $campaign->key,
                'reason' => 'flow_route_cancelled_campaign',
                'on_not_enrolled' => 'skipped',
                'skip_pending_messages' => (bool) ($input['skip_pending_messages'] ?? true),
            ],
            default => throw ValidationException::withMessages([
                'capability_id' => 'That Campaign automation Point type is not authorable.',
            ]),
        };
    }

    public function pointName(
        string $pointType,
        string $fallback,
        array $input,
        array $definition,
        AutomationPointAuthoringContext $context,
    ): string {
        $customName = trim((string) ($input['name'] ?? ''));

        if ($customName !== '') {
            return $customName;
        }

        $label = $this->campaignLabel((string) ($definition['campaign_key'] ?? ''));

        return $pointType === 'cancel_campaign'
            ? 'Stop Campaign: '.$label
            : 'Start Campaign: '.$label;
    }

    public function summary(string $pointType, array $definition, AutomationPointAuthoringContext $context): string
    {
        $label = $this->campaignLabel((string) ($definition['campaign_key'] ?? ''));

        return $pointType === 'cancel_campaign'
            ? 'Stop Campaign: '.$label.'.'
            : 'Start Campaign: '.$label.'.';
    }

    public function editorSummary(string $pointType, array $definition, AutomationPointAuthoringContext $context): string
    {
        $label = $this->campaignLabel((string) ($definition['campaign_key'] ?? ''));

        return $pointType === 'cancel_campaign' ? 'Stop '.$label : 'Start '.$label;
    }

    private function activeCampaign(string $key): Campaign
    {
        $campaign = Campaign::query()->active()->where('key', trim($key))->first();

        if (! $campaign instanceof Campaign) {
            throw ValidationException::withMessages([
                'campaign_key' => 'Choose an active Campaign.',
            ]);
        }

        return $campaign;
    }

    private function campaignLabel(string $key): string
    {
        $name = $key !== '' ? Campaign::query()->where('key', $key)->value('name') : null;

        return is_string($name) && trim($name) !== ''
            ? $name
            : ($key !== '' ? Str::headline($key) : 'selected Campaign');
    }
}
