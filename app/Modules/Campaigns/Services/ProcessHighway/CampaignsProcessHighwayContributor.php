<?php

namespace App\Modules\Campaigns\Services\ProcessHighway;

use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Services\CampaignWorkspacePresenter;
use App\Modules\Core\Models\ContactStatus;
use App\Support\ProcessHighway\Contracts\ProcessHighwayContributor;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class CampaignsProcessHighwayContributor implements ProcessHighwayContributor
{
    public function __construct(
        private readonly CampaignWorkspacePresenter $workspacePresenter,
    ) {}

    /** @return iterable<int, array<string, mixed>> */
    public function processes(): iterable
    {
        if (! $this->available()) {
            return [];
        }

        $statusNames = $this->statusNames();

        return Campaign::query()
            ->where('status', '!=', Campaign::STATUS_ARCHIVED)
            ->orderBy('name')
            ->get()
            ->map(function (Campaign $campaign) use ($statusNames): array {
                $workspace = $this->workspacePresenter->forCampaign($campaign);
                $conditions = $this->conditions($campaign, $statusNames);

                return [
                    'source_key' => 'campaigns',
                    'source_label' => 'Campaign',
                    'key' => (string) $campaign->key,
                    'name' => (string) $campaign->name,
                    'description' => trim((string) ($campaign->description ?? '')),
                    'category' => 'campaigns',
                    'category_label' => 'Campaigns',
                    'category_priority' => 15,
                    'sort_order' => 100,
                    'state' => $campaign->isActive() ? 'active' : 'off',
                    'state_label' => $campaign->isActive() ? 'Active' : 'Off',
                    'starts_when' => $this->startsWhen($campaign, $conditions),
                    'steps' => [
                        [
                            'name' => 'Message journey',
                            'detail' => $this->journeyDetail($workspace),
                        ],
                    ],
                    'outcomes' => $this->outcomes($campaign),
                    'details' => $this->details(
                        campaign: $campaign,
                        conditions: $conditions,
                        workspace: $workspace,
                    ),
                    'attributes' => [
                        'campaign_id' => (int) $campaign->getKey(),
                        'enrollment_mode' => (string) $campaign->enrollment_mode,
                        'reentry_policy' => (string) $campaign->reentry_policy,
                        'ineligible_behavior' => (string) $campaign->ineligible_behavior,
                        'eligibility_filter' => is_array($campaign->eligibility_filter)
                            ? $campaign->eligibility_filter
                            : [],
                        'eligibility_conditions' => $conditions,
                        'active_enrollment_count' => (int) $workspace['active_enrollment_count'],
                        'pending_message_count' => (int) $workspace['pending_message_count'],
                        'message_step_count' => (int) $workspace['message_step_count'],
                        'message_count' => (int) $workspace['message_count'],
                        'channels' => $workspace['channels'],
                    ],
                    'edit_url' => route('crm.campaigns.edit', $campaign),
                    'edit_label' => 'Edit Campaign',
                ];
            })
            ->values()
            ->all();
    }

    private function available(): bool
    {
        return in_array('campaigns', config('modules.enabled', []), true)
            && Schema::hasTable('campaigns');
    }

    /** @return array<string, string> */
    private function statusNames(): array
    {
        if (! Schema::hasTable('contact_statuses')) {
            return [];
        }

        return ContactStatus::query()
            ->pluck('name', 'key')
            ->mapWithKeys(fn (mixed $name, mixed $key): array => [
                (string) $key => (string) $name,
            ])
            ->all();
    }

    /**
     * @param array<string, string> $statusNames
     * @return array<int, array{
     *     key: string,
     *     label: string,
     *     values: array<int, string>,
     *     value_labels: array<int, string>
     * }>
     */
    private function conditions(Campaign $campaign, array $statusNames): array
    {
        $filter = is_array($campaign->eligibility_filter)
            ? $campaign->eligibility_filter
            : [];
        $conditions = [];

        foreach ($filter as $key => $values) {
            if (! is_string($key) || trim($key) === '' || ! is_array($values)) {
                continue;
            }

            $key = trim($key);
            $values = array_values(array_unique(array_filter(array_map(
                fn (mixed $value): ?string => is_string($value) && trim($value) !== ''
                    ? trim($value)
                    : null,
                $values,
            ))));

            if ($values === []) {
                continue;
            }

            $conditions[] = [
                'key' => $key,
                'label' => $this->criterionLabel($key),
                'values' => $values,
                'value_labels' => array_map(
                    fn (string $value): string => $this->valueLabel(
                        criterion: $key,
                        value: $value,
                        statusNames: $statusNames,
                    ),
                    $values,
                ),
            ];
        }

        return $conditions;
    }

    /**
     * @param array<int, array{
     *     key: string,
     *     label: string,
     *     values: array<int, string>,
     *     value_labels: array<int, string>
     * }> $conditions
     */
    private function startsWhen(Campaign $campaign, array $conditions): string
    {
        if (! $campaign->usesAutomaticEnrollment()) {
            return 'An explicit Campaign action enrolls the contact.';
        }

        if ($conditions === []) {
            return 'Automatic enrollment is enabled, but no eligibility conditions are configured.';
        }

        return 'A contact becomes eligible: '.$this->conditionSummary($conditions).'.';
    }

    /**
     * @param array<int, array{
     *     key: string,
     *     label: string,
     *     values: array<int, string>,
     *     value_labels: array<int, string>
     * }> $conditions
     */
    private function conditionSummary(array $conditions): string
    {
        return implode(' and ', array_map(
            function (array $condition): string {
                $verb = $condition['key'] === 'tag' ? 'has' : 'is';
                $values = implode(' or ', $condition['value_labels']);

                return $condition['label'].' '.$verb.' '.$values;
            },
            $conditions,
        ));
    }

    /** @param array<string, mixed> $workspace */
    private function journeyDetail(array $workspace): string
    {
        $steps = (int) ($workspace['message_step_count'] ?? 0);
        $messages = (int) ($workspace['message_count'] ?? 0);
        $channels = is_array($workspace['channels'] ?? null)
            ? array_values(array_filter($workspace['channels'], 'is_string'))
            : [];

        if ($steps < 1 && $messages < 1) {
            return 'No active messages configured.';
        }

        $parts = [
            $steps.' '.Str::plural('step', $steps),
            $messages.' '.Str::plural('message', $messages),
        ];

        if ($channels !== []) {
            $parts[] = implode(', ', array_map('strtoupper', $channels));
        }

        return implode(' · ', $parts);
    }

    /** @return array<int, string> */
    private function outcomes(Campaign $campaign): array
    {
        if (! $campaign->usesAutomaticEnrollment()) {
            return [
                'Explicit enrollment starts the journey',
            ];
        }

        $outcomes = [
            'Enroll when eligible',
            match ($campaign->ineligible_behavior) {
                Campaign::INELIGIBLE_PAUSE => 'Pause if eligibility ends',
                Campaign::INELIGIBLE_CANCEL => 'Stop if eligibility ends',
                default => 'Keep running if eligibility ends',
            },
        ];

        if ($campaign->reentry_policy === Campaign::REENTRY_WHEN_ELIGIBLE_AGAIN) {
            $outcomes[] = 'May re-enter after a new eligible cycle';
        }

        return $outcomes;
    }

    /**
     * @param array<int, array{
     *     key: string,
     *     label: string,
     *     values: array<int, string>,
     *     value_labels: array<int, string>
     * }> $conditions
     * @param array<string, mixed> $workspace
     * @return array<int, array{label: string, value: string}>
     */
    private function details(
        Campaign $campaign,
        array $conditions,
        array $workspace,
    ): array {
        $details = [
            [
                'label' => 'Enrollment',
                'value' => $campaign->usesAutomaticEnrollment()
                    ? 'Automatic when eligible'
                    : 'Manual only',
            ],
        ];

        if ($conditions !== []) {
            $details[] = [
                'label' => 'Eligibility',
                'value' => $this->conditionSummary($conditions),
            ];
        }

        $details[] = [
            'label' => 'Re-entry',
            'value' => $campaign->reentry_policy === Campaign::REENTRY_WHEN_ELIGIBLE_AGAIN
                ? 'When eligible again'
                : 'Never',
        ];

        $details[] = [
            'label' => 'If eligibility ends',
            'value' => match ($campaign->ineligible_behavior) {
                Campaign::INELIGIBLE_PAUSE => 'Pause the campaign',
                Campaign::INELIGIBLE_CANCEL => 'Stop the campaign',
                default => 'Keep the campaign running',
            },
        ];

        $details[] = [
            'label' => 'Active enrollments',
            'value' => number_format((int) ($workspace['active_enrollment_count'] ?? 0)),
        ];

        return $details;
    }

    private function criterionLabel(string $key): string
    {
        return match ($key) {
            'status' => 'Status',
            'relationship' => 'Relationship',
            'source' => 'Source',
            'subsource' => 'Subsource',
            'tag' => 'Tag',
            'webinar_outcome' => 'Webinar outcome',
            default => Str::headline($key),
        };
    }

    /** @param array<string, string> $statusNames */
    private function valueLabel(
        string $criterion,
        string $value,
        array $statusNames,
    ): string {
        if ($criterion === 'status') {
            return $statusNames[$value] ?? Str::headline($value);
        }

        if ($criterion === 'relationship') {
            [$relationship, $stage] = array_pad(explode(':', $value, 2), 2, null);

            if ($stage === null || $stage === '' || $stage === '*') {
                return Str::headline($relationship);
            }

            return Str::headline($relationship).' → '.Str::headline($stage);
        }

        if ($criterion === 'webinar_outcome') {
            $separator = strrpos($value, ':');

            if ($separator !== false) {
                $series = substr($value, 0, $separator);
                $outcome = substr($value, $separator + 1);

                if ($series !== '' && $outcome !== '') {
                    return Str::headline($series).' → '.Str::headline($outcome);
                }
            }
        }

        return $value;
    }
}