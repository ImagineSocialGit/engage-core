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
    private const POINT_PAUSE_FAMILY = 'pause_campaign_family';
    private const POINT_CANCEL_FAMILY = 'cancel_campaign_family';

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
            description: 'Stop a specific Campaign that this Route started and optionally skip pending messages.',
            tip: 'Use this when a later outcome means one known Campaign should stop permanently.',
            useCases: [
                'Stop a Campaign started earlier in this Route.',
            ],
            typeLabel: 'Campaign',
            genericLabels: ['stop campaign'],
            generatedPrefixes: ['stop campaign:'],
        );

        yield new AutomationPointAuthoringDefinition(
            pointType: self::POINT_PAUSE,
            moduleKey: 'campaigns',
            name: 'Pause Campaign',
            description: 'Temporarily pause a specific existing Campaign enrollment for this contact.',
            tip: 'Use Pause when a known Campaign should stop temporarily without ending the enrollment.',
            useCases: [
                'Pause a known Campaign while a team member takes over.',
            ],
            typeLabel: 'Campaign',
            genericLabels: ['pause campaign'],
            generatedPrefixes: ['pause campaign:'],
        );

        yield new AutomationPointAuthoringDefinition(
            pointType: self::POINT_RESUME,
            moduleKey: 'campaigns',
            name: 'Resume Campaign',
            description: 'Resume a specific existing paused Campaign enrollment for this contact.',
            tip: 'Use Resume only when the Campaign should continue from its paused lifecycle position.',
            useCases: [
                'Resume nurture after a temporary human-handled pause.',
            ],
            typeLabel: 'Campaign',
            genericLabels: ['resume campaign'],
            generatedPrefixes: ['resume campaign:'],
        );

        yield new AutomationPointAuthoringDefinition(
            pointType: self::POINT_PAUSE_FAMILY,
            moduleKey: 'campaigns',
            name: 'Pause Current Nurture',
            description: 'Temporarily pause the contact’s open Campaign enrollment in a selected Campaign family.',
            tip: 'Use this for reply handling so the Route does not need to know which specific nurture Campaign the contact entered.',
            useCases: [
                'Pause the current consumer nurture when the person replies.',
            ],
            typeLabel: 'Campaign',
            genericLabels: ['pause current nurture'],
            generatedPrefixes: ['pause current nurture:'],
        );

        yield new AutomationPointAuthoringDefinition(
            pointType: self::POINT_CANCEL_FAMILY,
            moduleKey: 'campaigns',
            name: 'Stop Current Nurture',
            description: 'Permanently stop the contact’s open Campaign enrollment in a selected Campaign family.',
            tip: 'Use this for lifecycle cleanup so the Route does not need a list of every Campaign that could be running.',
            useCases: [
                'Stop the current consumer nurture after the contact becomes Engaged.',
            ],
            typeLabel: 'Campaign',
            genericLabels: ['stop current nurture'],
            generatedPrefixes: ['stop current nurture:'],
        );
    }

    public function available(string $pointType, AutomationPointAuthoringContext $context): bool
    {
        if (! in_array($pointType, [
            self::POINT_ENROLL,
            self::POINT_CANCEL,
            self::POINT_PAUSE,
            self::POINT_RESUME,
            self::POINT_PAUSE_FAMILY,
            self::POINT_CANCEL_FAMILY,
        ], true)) {
            return false;
        }

        if (in_array($pointType, [self::POINT_PAUSE_FAMILY, self::POINT_CANCEL_FAMILY], true)) {
            return Campaign::query()
                ->active()
                ->whereNotNull('family_key')
                ->where('family_key', '<>', '')
                ->exists();
        }

        if (! Campaign::query()->active()->exists()) {
            return false;
        }

        return $pointType !== self::POINT_CANCEL
            || $context->hasPointType(self::POINT_ENROLL);
    }

    public function fields(string $pointType, array $definition, AutomationPointAuthoringContext $context): array
    {
        if (in_array($pointType, [self::POINT_PAUSE_FAMILY, self::POINT_CANCEL_FAMILY], true)) {
            return [
                [
                    'type' => 'select',
                    'name' => 'family_key',
                    'label' => 'Campaign family',
                    'required' => true,
                    'value' => (string) ($definition['family_key'] ?? ''),
                    'placeholder' => 'Choose a Campaign family',
                    'options' => $this->familyOptions(),
                ],
                [
                    'type' => 'checkbox',
                    'name' => 'skip_pending_messages',
                    'label' => 'Skip pending Campaign messages',
                    'value' => (bool) ($definition['skip_pending_messages'] ?? true),
                    'help' => $pointType === self::POINT_PAUSE_FAMILY
                        ? 'Recommended for human-reply pauses: prevent already-scheduled future messages from sending while nurture is paused.'
                        : 'Recommended: prevent already-scheduled future messages from sending after nurture is stopped.',
                ],
            ];
        }

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
        if (in_array($pointType, [self::POINT_PAUSE_FAMILY, self::POINT_CANCEL_FAMILY], true)) {
            return [
                'family_key' => ['required', 'string', 'max:255'],
                'skip_pending_messages' => ['nullable', 'boolean'],
            ];
        }

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
        if (in_array($pointType, [self::POINT_PAUSE_FAMILY, self::POINT_CANCEL_FAMILY], true)) {
            $familyKey = $this->activeFamily((string) ($input['family_key'] ?? ''));

            return [
                'family_key' => $familyKey,
                'reason' => $pointType === self::POINT_PAUSE_FAMILY
                    ? 'flow_route_paused_campaign_family'
                    : 'flow_route_cancelled_campaign_family',
                'on_not_enrolled' => 'skipped',
                'skip_pending_messages' => (bool) ($input['skip_pending_messages'] ?? true),
            ];
        }

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

        if ($pointType === self::POINT_PAUSE_FAMILY) {
            return 'Pause Current Nurture';
        }

        if ($pointType === self::POINT_CANCEL_FAMILY) {
            return 'Stop Current Nurture';
        }

        return $this->verb($pointType).' Campaign: '
            .$this->campaignLabel((string) ($definition['campaign_key'] ?? ''));
    }

    public function summary(string $pointType, array $definition, AutomationPointAuthoringContext $context): string
    {
        if ($pointType === self::POINT_PAUSE_FAMILY) {
            return 'Pause current nurture.';
        }

        if ($pointType === self::POINT_CANCEL_FAMILY) {
            return 'Stop current nurture.';
        }

        return $this->verb($pointType).' Campaign: '
            .$this->campaignLabel((string) ($definition['campaign_key'] ?? '')).'.';
    }

    public function editorSummary(string $pointType, array $definition, AutomationPointAuthoringContext $context): string
    {
        if ($pointType === self::POINT_PAUSE_FAMILY) {
            return 'Pause current nurture';
        }

        if ($pointType === self::POINT_CANCEL_FAMILY) {
            return 'Stop current nurture';
        }

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

    private function activeFamily(string $key): string
    {
        $key = trim($key);

        if ($key === '' || ! Campaign::query()->active()->where('family_key', $key)->exists()) {
            throw ValidationException::withMessages([
                'family_key' => 'Choose an active Campaign family.',
            ]);
        }

        return $key;
    }

    /** @return array<int, array{value: string, label: string, description: string}> */
    private function familyOptions(): array
    {
        return Campaign::query()
            ->active()
            ->whereNotNull('family_key')
            ->where('family_key', '<>', '')
            ->orderBy('family_key')
            ->get(['family_key'])
            ->pluck('family_key')
            ->filter(fn (mixed $key): bool => is_string($key) && trim($key) !== '')
            ->unique()
            ->values()
            ->map(fn (string $key): array => [
                'value' => $key,
                'label' => Str::headline($key),
                'description' => 'The contact’s open Campaign enrollment in this family.',
            ])
            ->all();
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