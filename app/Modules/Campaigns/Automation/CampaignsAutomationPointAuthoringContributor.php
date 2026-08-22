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
    private const POINT_ENROLL = 'enroll_campaign';
    private const POINT_CANCEL = 'cancel_campaign';
    private const POINT_PAUSE = 'pause_campaign';
    private const POINT_RESUME = 'resume_campaign';

    public function definitions(): iterable
    {
        yield new AutomationPointAuthoringDefinition(
            pointType: self::POINT_ENROLL,
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
            pointType: self::POINT_CANCEL,
            moduleKey: 'campaigns',
            name: 'Stop Campaign',
            description: 'Stop a Campaign that this Route started and optionally skip pending messages.',
            tip: 'Use this when a later outcome means the Campaign started by this Route should stop permanently.',
            useCases: [
                'Stop a nurture Campaign after conversion.',
                'Stop pending follow-up when the Route reaches a final outcome.',
            ],
            typeLabel: 'Campaign',
            genericLabels: ['stop campaign'],
            generatedPrefixes: ['stop campaign:'],
        );

        yield new AutomationPointAuthoringDefinition(
            pointType: self::POINT_PAUSE,
            moduleKey: 'campaigns',
            name: 'Pause Campaign',
            description: 'Temporarily pause an existing Campaign enrollment for this contact.',
            tip: 'Use Pause when a human reply or temporary condition should stop nurture without ending the enrollment permanently.',
            useCases: [
                'Pause nurture when a person replies.',
                'Temporarily stop pending follow-up while a team member takes over.',
            ],
            typeLabel: 'Campaign',
            genericLabels: ['pause campaign'],
            generatedPrefixes: ['pause campaign:'],
        );

        yield new AutomationPointAuthoringDefinition(
            pointType: self::POINT_RESUME,
            moduleKey: 'campaigns',
            name: 'Resume Campaign',
            description: 'Resume an existing paused Campaign enrollment for this contact.',
            tip: 'Use Resume only when the Campaign should continue from its paused lifecycle position.',
            useCases: [
                'Resume nurture after a temporary human-handled pause.',
            ],
            typeLabel: 'Campaign',
            genericLabels: ['resume campaign'],
            generatedPrefixes: ['resume campaign:'],
        );
    }

    public function available(string $pointType, AutomationPointAuthoringContext $context): bool
    {
        if (! in_array($pointType, [
            self::POINT_ENROLL,
            self::POINT_CANCEL,
            self::POINT_PAUSE,
            self::POINT_RESUME,
        ], true)) {
            return false;
        }

        if (! Campaign::query()->active()->exists()) {
            return false;
        }

        return $pointType !== self::POINT_CANCEL
            || $context->hasPointType(self::POINT_ENROLL);
    }

    public function fields(string $pointType, array $definition, AutomationPointAuthoringContext $context): array
    {
        $fields = [[
            'type' => 'select',
            'name' => 'campaign_key',
            'label' => $this->campaignFieldLabel($pointType),
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

        if (in_array($pointType, [self::POINT_CANCEL, self::POINT_PAUSE], true)) {
            $fields[] = [
                'type' => 'checkbox',
                'name' => 'skip_pending_messages',
                'label' => 'Skip pending Campaign messages',
                'value' => (bool) ($definition['skip_pending_messages'] ?? true),
                'help' => $pointType === self::POINT_PAUSE
                    ? 'Recommended for human-reply pauses: prevent already-scheduled future messages from sending while the Campaign is paused.'
                    : 'Recommended: prevent already-scheduled future messages from sending after the Campaign is stopped.',
            ];
        }

        return $fields;
    }

    public function rules(string $pointType, AutomationPointAuthoringContext $context): array
    {
        $rules = [
            'campaign_key' => ['required', 'string', 'max:255'],
        ];

        if (in_array($pointType, [self::POINT_CANCEL, self::POINT_PAUSE], true)) {
            $rules['skip_pending_messages'] = ['nullable', 'boolean'];
        }

        return $rules;
    }

    public function buildDefinition(string $pointType, array $input, AutomationPointAuthoringContext $context): array
    {
        $campaign = $this->activeCampaign((string) ($input['campaign_key'] ?? ''));

        return match ($pointType) {
            self::POINT_ENROLL => [
                'campaign_key' => (string) $campaign->key,
                'on_already_enrolled' => 'skipped',
            ],
            self::POINT_CANCEL => [
                'campaign_key' => (string) $campaign->key,
                'reason' => 'flow_route_cancelled_campaign',
                'on_not_enrolled' => 'skipped',
                'skip_pending_messages' => (bool) ($input['skip_pending_messages'] ?? true),
            ],
            self::POINT_PAUSE => [
                'campaign_key' => (string) $campaign->key,
                'reason' => 'flow_route_paused_campaign',
                'on_not_enrolled' => 'skipped',
                'skip_pending_messages' => (bool) ($input['skip_pending_messages'] ?? true),
            ],
            self::POINT_RESUME => [
                'campaign_key' => (string) $campaign->key,
                'reason' => 'flow_route_resumed_campaign',
                'on_not_enrolled' => 'skipped',
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

        return $this->verb($pointType).' Campaign: '
            .$this->campaignLabel((string) ($definition['campaign_key'] ?? ''));
    }

    public function summary(string $pointType, array $definition, AutomationPointAuthoringContext $context): string
    {
        return $this->verb($pointType).' Campaign: '
            .$this->campaignLabel((string) ($definition['campaign_key'] ?? '')).'.';
    }

    public function editorSummary(string $pointType, array $definition, AutomationPointAuthoringContext $context): string
    {
        return $this->verb($pointType).' '
            .$this->campaignLabel((string) ($definition['campaign_key'] ?? ''));
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

    private function campaignFieldLabel(string $pointType): string
    {
        return match ($pointType) {
            self::POINT_CANCEL => 'Campaign to stop',
            self::POINT_PAUSE => 'Campaign to pause',
            self::POINT_RESUME => 'Campaign to resume',
            default => 'Campaign to start',
        };
    }

    private function verb(string $pointType): string
    {
        return match ($pointType) {
            self::POINT_CANCEL => 'Stop',
            self::POINT_PAUSE => 'Pause',
            self::POINT_RESUME => 'Resume',
            default => 'Start',
        };
    }

    private function campaignLabel(string $key): string
    {
        $name = $key !== '' ? Campaign::query()->where('key', $key)->value('name') : null;

        return is_string($name) && trim($name) !== ''
            ? $name
            : ($key !== '' ? Str::headline($key) : 'selected Campaign');
    }
}