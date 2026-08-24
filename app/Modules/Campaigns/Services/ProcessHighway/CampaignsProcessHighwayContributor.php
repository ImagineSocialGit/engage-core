<?php

namespace App\Modules\Campaigns\Services\ProcessHighway;

use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Services\CampaignWorkspacePresenter;
use App\Modules\Core\Models\ContactStatus;
use App\Support\ProcessHighway\Contracts\ProcessHighwayContributor;
use App\Support\ProcessHighway\Data\ProcessHighwayAuthority;
use App\Support\ProcessHighway\Data\ProcessHighwayContribution;
use App\Support\ProcessHighway\Data\ProcessHighwayEdge;
use App\Support\ProcessHighway\Data\ProcessHighwayEditTarget;
use App\Support\ProcessHighway\Data\ProcessHighwayLane;
use App\Support\ProcessHighway\Data\ProcessHighwayNode;
use App\Support\ProcessHighway\ProcessHighwaySemanticKey;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class CampaignsProcessHighwayContributor implements ProcessHighwayContributor
{
    public function __construct(
        private readonly CampaignWorkspacePresenter $workspacePresenter,
        private readonly CampaignReplyProfileResolver $replyProfileResolver,
    ) {}

    /** @return iterable<int, ProcessHighwayContribution> */
    public function contributions(): iterable
    {
        if (! $this->available()) {
            return [];
        }

        $statusNames = $this->statusNames();

        return Campaign::query()
            ->where('status', '!=', Campaign::STATUS_ARCHIVED)
            ->orderBy('name')
            ->get()
            ->map(fn (Campaign $campaign): ProcessHighwayContribution => $this->campaign(
                campaign: $campaign,
                statusNames: $statusNames,
            ))
            ->values()
            ->all();
    }

    /**
     * @param array<string, string> $statusNames
     */
    private function campaign(
        Campaign $campaign,
        array $statusNames,
    ): ProcessHighwayContribution {
        $workspace = $this->workspacePresenter->forCampaign($campaign);
        $replyProfileKeys = $this->replyProfileResolver->resolve($campaign);
        $conditions = $this->conditions($campaign, $statusNames);
        $processKey = ProcessHighwaySemanticKey::campaign((string) $campaign->key);
        $linkTarget = $this->campaignLinkTarget($campaign);
        $startLinkTarget = $this->campaignPanelTarget(
            campaign: $campaign,
            panel: 'start',
            label: 'Edit Campaign Start',
            resourceType: 'campaign_eligibility',
        );
        $messagesLinkTarget = $this->campaignPanelTarget(
            campaign: $campaign,
            panel: 'messages',
            label: 'Review Campaign Messages',
            resourceType: 'campaign_messages',
        );
        $reviewLinkTarget = $this->campaignPanelTarget(
            campaign: $campaign,
            panel: 'review',
            label: 'Review Campaign',
            resourceType: 'campaign_lifecycle',
        );
        $inlineTarget = $this->campaignEligibilityTarget($campaign);
        $campaignAuthority = new ProcessHighwayAuthority(
            ownerKey: 'campaigns',
            editTargets: [
                $linkTarget,
                $startLinkTarget,
                $messagesLinkTarget,
                $reviewLinkTarget,
                $inlineTarget,
            ],
        );
        $inlineAuthority = new ProcessHighwayAuthority(
            ownerKey: 'campaigns',
            editTargets: [$inlineTarget, $startLinkTarget, $linkTarget],
        );
        $startAuthority = new ProcessHighwayAuthority(
            ownerKey: 'campaigns',
            editTargets: [$startLinkTarget, $linkTarget],
        );
        $journeyAuthority = new ProcessHighwayAuthority(
            ownerKey: 'campaigns',
            editTargets: [$messagesLinkTarget, $linkTarget],
        );
        $reviewAuthority = new ProcessHighwayAuthority(
            ownerKey: 'campaigns',
            editTargets: [$reviewLinkTarget, $linkTarget],
        );
        $nodes = [];
        $edges = [];
        $entryNodeKeys = [];
        $exitNodeKeys = [];
        $edgeOrder = 10;

        $nodes[] = new ProcessHighwayNode(
            key: $processKey,
            label: (string) $campaign->name,
            role: ProcessHighwayNode::ROLE_PROCESS,
            authority: $campaignAuthority,
            description: trim((string) ($campaign->description ?? '')) ?: null,
            state: $campaign->isActive() ? 'active' : 'off',
            stateLabel: $campaign->isActive() ? 'Active' : 'Off',
            sortOrder: 100,
            attributes: [
                'campaign_id' => (int) $campaign->getKey(),
                'campaign_key' => (string) $campaign->key,
            ],
        );

        if ($campaign->usesAutomaticEnrollment()) {
            $eligibilityGatewayKey = $processKey.':eligibility';

            $nodes[] = new ProcessHighwayNode(
                key: $eligibilityGatewayKey,
                label: $conditions === []
                    ? 'Automatic eligibility needs conditions'
                    : 'All eligibility conditions match',
                role: ProcessHighwayNode::ROLE_GATEWAY,
                authority: $inlineAuthority,
                detail: $conditions === []
                    ? 'No automatic eligibility conditions are configured.'
                    : 'Different condition types use AND; values inside one type use OR.',
                state: $conditions === [] ? 'needs_configuration' : 'configured',
                stateLabel: $conditions === [] ? 'Needs configuration' : 'Configured',
                sortOrder: 50,
                attributes: [
                    'operator' => 'and',
                    'criterion_keys' => array_column($conditions, 'key'),
                ],
            );

            if ($conditions === []) {
                $missingKey = $processKey.':eligibility:missing';
                $nodes[] = new ProcessHighwayNode(
                    key: $missingKey,
                    label: 'No eligibility conditions',
                    role: ProcessHighwayNode::ROLE_QUALIFIER,
                    authority: $inlineAuthority,
                    state: 'needs_configuration',
                    stateLabel: 'Needs configuration',
                    sortOrder: 10,
                );
                $entryNodeKeys[] = $missingKey;
                $edges[] = new ProcessHighwayEdge(
                    key: $processKey.':edge:missing-eligibility',
                    fromNodeKey: $missingKey,
                    toNodeKey: $eligibilityGatewayKey,
                    role: ProcessHighwayEdge::ROLE_REQUIRES,
                    authority: $inlineAuthority,
                    label: 'Configure before this can start',
                    sortOrder: $edgeOrder++,
                );
            }

            foreach ($conditions as $conditionIndex => $condition) {
                $conditionTargets = [];

                foreach ($condition['values'] as $valueIndex => $value) {
                    $factKey = ProcessHighwaySemanticKey::criterion(
                        $condition['key'],
                        $value,
                    );
                    $factOwner = $this->criterionOwner($condition['key']);
                    $nodes[] = new ProcessHighwayNode(
                        key: $factKey,
                        label: $condition['label'].': '.$condition['value_labels'][$valueIndex],
                        role: ProcessHighwayNode::ROLE_QUALIFIER,
                        authority: new ProcessHighwayAuthority(
                            ownerKey: $factOwner,
                            editTargets: [$inlineTarget, $startLinkTarget, $linkTarget],
                        ),
                        sortOrder: 10 + $conditionIndex,
                        referenceOnly: true,
                        attributes: [
                            'criterion_key' => $condition['key'],
                            'value' => $value,
                            'value_label' => $condition['value_labels'][$valueIndex],
                        ],
                    );
                    $entryNodeKeys[] = $factKey;
                    $conditionTargets[] = $factKey;
                }

                if (count($conditionTargets) > 1) {
                    $criterionGatewayKey = $processKey.':criterion:'.rawurlencode($condition['key']);
                    $nodes[] = new ProcessHighwayNode(
                        key: $criterionGatewayKey,
                        label: $condition['label'].': any selected value',
                        role: ProcessHighwayNode::ROLE_GATEWAY,
                        authority: $inlineAuthority,
                        detail: implode(' or ', $condition['value_labels']),
                        sortOrder: 30 + $conditionIndex,
                        attributes: [
                            'criterion_key' => $condition['key'],
                            'operator' => 'or',
                        ],
                    );

                    foreach ($conditionTargets as $targetIndex => $targetKey) {
                        $edges[] = new ProcessHighwayEdge(
                            key: $processKey.':edge:criterion:'.rawurlencode($condition['key']).':'.$targetIndex,
                            fromNodeKey: $targetKey,
                            toNodeKey: $criterionGatewayKey,
                            role: ProcessHighwayEdge::ROLE_BRANCH,
                            authority: $inlineAuthority,
                            label: 'Any match',
                            sortOrder: $edgeOrder++,
                            attributes: [
                                'operator' => 'or',
                                'criterion_key' => $condition['key'],
                            ],
                        );
                    }

                    $conditionTargets = [$criterionGatewayKey];
                }

                foreach ($conditionTargets as $targetKey) {
                    $edges[] = new ProcessHighwayEdge(
                        key: $processKey.':edge:requires:'.rawurlencode($condition['key']),
                        fromNodeKey: $targetKey,
                        toNodeKey: $eligibilityGatewayKey,
                        role: ProcessHighwayEdge::ROLE_REQUIRES,
                        authority: $inlineAuthority,
                        label: 'Required',
                        sortOrder: $edgeOrder++,
                        attributes: [
                            'operator' => 'and',
                            'criterion_key' => $condition['key'],
                        ],
                    );
                }
            }

            $edges[] = new ProcessHighwayEdge(
                key: $processKey.':edge:eligible-start',
                fromNodeKey: $eligibilityGatewayKey,
                toNodeKey: $processKey,
                role: ProcessHighwayEdge::ROLE_STARTS,
                authority: $inlineAuthority,
                label: 'Not eligible → eligible',
                sortOrder: $edgeOrder++,
            );
        } else {
            $manualEntryKey = $processKey.':entry:manual';
            $nodes[] = new ProcessHighwayNode(
                key: $manualEntryKey,
                label: 'Explicit Campaign enrollment',
                role: ProcessHighwayNode::ROLE_TRIGGER,
                authority: $startAuthority,
                detail: 'Someone or another process deliberately enrolls the contact.',
                sortOrder: 10,
            );
            $entryNodeKeys[] = $manualEntryKey;
            $edges[] = new ProcessHighwayEdge(
                key: $processKey.':edge:manual-start',
                fromNodeKey: $manualEntryKey,
                toNodeKey: $processKey,
                role: ProcessHighwayEdge::ROLE_STARTS,
                authority: $startAuthority,
                label: 'Enroll',
                sortOrder: $edgeOrder++,
            );
        }

        $journeyKey = $processKey.':journey';
        $nodes[] = new ProcessHighwayNode(
            key: $journeyKey,
            label: 'Message journey',
            role: ProcessHighwayNode::ROLE_ACTION,
            authority: $journeyAuthority,
            detail: $this->journeyDetail($workspace),
            sortOrder: 150,
            attributes: [
                'message_step_count' => (int) ($workspace['message_step_count'] ?? 0),
                'message_count' => (int) ($workspace['message_count'] ?? 0),
                'channels' => $workspace['channels'] ?? [],
            ],
        );
        $edges[] = new ProcessHighwayEdge(
            key: $processKey.':edge:journey',
            fromNodeKey: $processKey,
            toNodeKey: $journeyKey,
            role: ProcessHighwayEdge::ROLE_CONTINUES,
            authority: $journeyAuthority,
            label: 'Run journey',
            sortOrder: $edgeOrder++,
        );

        foreach ($replyProfileKeys as $replyProfileIndex => $replyProfileKey) {
            $replyNodeKey = ProcessHighwaySemanticKey::replyProfile($replyProfileKey);
            $replyAuthority = new ProcessHighwayAuthority(
                ownerKey: 'inbound_messaging',
                editTargets: [$messagesLinkTarget, $linkTarget],
            );

            $nodes[] = new ProcessHighwayNode(
                key: $replyNodeKey,
                label: $this->replyProfileLabel($replyProfileKey),
                role: ProcessHighwayNode::ROLE_TRIGGER,
                authority: $replyAuthority,
                detail: 'A reply to this Campaign carries the configured business reply profile.',
                sortOrder: 225 + $replyProfileIndex,
                referenceOnly: true,
                attributes: [
                    'event_key' => 'inbound_message.normal_reply',
                    'reply_profile_key' => $replyProfileKey,
                ],
            );
            $edges[] = new ProcessHighwayEdge(
                key: $processKey.':edge:reply-profile:'.rawurlencode($replyProfileKey),
                fromNodeKey: $journeyKey,
                toNodeKey: $replyNodeKey,
                role: ProcessHighwayEdge::ROLE_BRANCH,
                authority: $journeyAuthority,
                label: 'If the contact replies',
                sortOrder: $edgeOrder++,
                attributes: [
                    'reply_profile_key' => $replyProfileKey,
                ],
            );
        }

        $completeKey = $processKey.':exit:completed';
        $nodes[] = new ProcessHighwayNode(
            key: $completeKey,
            label: 'Journey completed',
            role: ProcessHighwayNode::ROLE_EXIT,
            authority: $reviewAuthority,
            sortOrder: 300,
        );
        $exitNodeKeys[] = $completeKey;
        $edges[] = new ProcessHighwayEdge(
            key: $processKey.':edge:completed',
            fromNodeKey: $journeyKey,
            toNodeKey: $completeKey,
            role: ProcessHighwayEdge::ROLE_EXITS,
            authority: $reviewAuthority,
            label: 'When the journey finishes',
            sortOrder: $edgeOrder++,
        );

        if ($campaign->usesAutomaticEnrollment()) {
            $ineligibleKey = $processKey.':consequence:ineligible';
            $nodes[] = new ProcessHighwayNode(
                key: $ineligibleKey,
                label: $this->ineligibleLabel($campaign),
                role: ProcessHighwayNode::ROLE_CONSEQUENCE,
                authority: $inlineAuthority,
                sortOrder: 250,
                attributes: [
                    'when_ineligible' => (string) $campaign->ineligible_behavior,
                ],
            );
            $exitNodeKeys[] = $ineligibleKey;
            $edges[] = new ProcessHighwayEdge(
                key: $processKey.':edge:ineligible',
                fromNodeKey: $journeyKey,
                toNodeKey: $ineligibleKey,
                role: ProcessHighwayEdge::ROLE_BRANCH,
                authority: $inlineAuthority,
                label: 'If eligibility ends',
                sortOrder: $edgeOrder++,
            );

            if ($campaign->reentry_policy === Campaign::REENTRY_WHEN_ELIGIBLE_AGAIN) {
                $reentryKey = $processKey.':consequence:eligible-again';
                $nodes[] = new ProcessHighwayNode(
                    key: $reentryKey,
                    label: 'May re-enter in a new eligible cycle',
                    role: ProcessHighwayNode::ROLE_CONSEQUENCE,
                    authority: $inlineAuthority,
                    sortOrder: 275,
                    attributes: [
                        'reentry_policy' => Campaign::REENTRY_WHEN_ELIGIBLE_AGAIN,
                    ],
                );
                $exitNodeKeys[] = $reentryKey;
                $edges[] = new ProcessHighwayEdge(
                    key: $processKey.':edge:eligible-again',
                    fromNodeKey: $ineligibleKey,
                    toNodeKey: $reentryKey,
                    role: ProcessHighwayEdge::ROLE_BRANCH,
                    authority: $inlineAuthority,
                    label: 'Becomes eligible again',
                    sortOrder: $edgeOrder++,
                );
                $edges[] = new ProcessHighwayEdge(
                    key: $processKey.':edge:reenter',
                    fromNodeKey: $reentryKey,
                    toNodeKey: $processKey.':eligibility',
                    role: ProcessHighwayEdge::ROLE_STARTS,
                    authority: $inlineAuthority,
                    label: 'Start a new eligible cycle',
                    sortOrder: $edgeOrder++,
                );
            }
        }

        return new ProcessHighwayContribution(
            sourceKey: 'campaigns',
            key: $processKey,
            name: (string) $campaign->name,
            description: trim((string) ($campaign->description ?? '')),
            subjectKey: 'contacts',
            lane: $this->lane($conditions),
            mechanismNodeKey: $processKey,
            authority: $campaignAuthority,
            nodes: $nodes,
            edges: $edges,
            entryNodeKeys: array_values(array_unique($entryNodeKeys)),
            exitNodeKeys: array_values(array_unique($exitNodeKeys)),
            state: $campaign->isActive() ? 'active' : 'off',
            stateLabel: $campaign->isActive() ? 'Active' : 'Off',
            entrySummary: $this->startsWhen($campaign, $conditions),
            sortOrder: 100,
            details: $this->details($campaign, $conditions, $workspace),
            attributes: [
                'mechanism_role' => 'eligibility_program',
                'campaign_id' => (int) $campaign->getKey(),
                'campaign_key' => (string) $campaign->key,
                'enrollment_mode' => (string) $campaign->enrollment_mode,
                'reentry_policy' => (string) $campaign->reentry_policy,
                'ineligible_behavior' => (string) $campaign->ineligible_behavior,
                'eligibility_filter' => is_array($campaign->eligibility_filter)
                    ? $campaign->eligibility_filter
                    : [],
                'eligibility_conditions' => $conditions,
                'active_enrollment_count' => (int) ($workspace['active_enrollment_count'] ?? 0),
                'pending_message_count' => (int) ($workspace['pending_message_count'] ?? 0),
                'message_step_count' => (int) ($workspace['message_step_count'] ?? 0),
                'message_count' => (int) ($workspace['message_count'] ?? 0),
                'channels' => $workspace['channels'] ?? [],
                'reply_profile_keys' => $replyProfileKeys,
            ],
        );
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

    /** @param array<int, array<string, mixed>> $conditions */
    private function lane(array $conditions): ProcessHighwayLane
    {
        $relationship = collect($conditions)->firstWhere('key', 'relationship');

        if (! is_array($relationship)) {
            return ProcessHighwayLane::standard();
        }

        $relationshipKeys = collect($relationship['values'] ?? [])
            ->filter(fn (mixed $value): bool => is_string($value))
            ->map(fn (string $value): string => explode(':', $value, 2)[0])
            ->filter()
            ->unique()
            ->values();

        if ($relationshipKeys->count() !== 1) {
            return ProcessHighwayLane::relationship();
        }

        $key = (string) $relationshipKeys->first();

        return ProcessHighwayLane::relationship(
            relationshipKey: $key,
            relationshipLabel: Str::headline($key),
        );
    }

    /** @param array<int, array<string, mixed>> $conditions */
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

    /** @param array<int, array<string, mixed>> $conditions */
    private function conditionSummary(array $conditions): string
    {
        return implode(' and ', array_map(
            function (array $condition): string {
                $verb = $condition['key'] === 'tag' ? 'has' : 'is';

                return $condition['label'].' '.$verb.' '.implode(' or ', $condition['value_labels']);
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

    private function ineligibleLabel(Campaign $campaign): string
    {
        return match ($campaign->ineligible_behavior) {
            Campaign::INELIGIBLE_PAUSE => 'Pause if eligibility ends',
            Campaign::INELIGIBLE_CANCEL => 'Stop if eligibility ends',
            default => 'Keep running if eligibility ends',
        };
    }

    private function replyProfileLabel(string $replyProfileKey): string
    {
        return 'Reply to '.Str::headline($replyProfileKey).' messages';
    }

    /**
     * @param array<int, array<string, mixed>> $conditions
     * @param array<string, mixed> $workspace
     * @return array<int, array{label: string, value: string}>
     */
    private function details(
        Campaign $campaign,
        array $conditions,
        array $workspace,
    ): array {
        $details = [[
            'label' => 'Enrollment',
            'value' => $campaign->usesAutomaticEnrollment()
                ? 'Automatic when eligible'
                : 'Manual only',
        ]];

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

    private function criterionOwner(string $key): string
    {
        return match ($key) {
            'status' => 'workflow',
            'relationship' => 'relationships',
            'webinar_outcome' => 'webinars',
            'tag', 'source', 'subsource' => 'core',
            default => 'campaigns',
        };
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

    private function campaignLinkTarget(Campaign $campaign): ProcessHighwayEditTarget
    {
        return ProcessHighwayEditTarget::link(
            ownerKey: 'campaigns',
            label: 'Edit Campaign',
            url: route('crm.campaigns.edit', $campaign),
            resourceType: 'campaign',
            resourceKey: (string) $campaign->key,
            resourceId: (int) $campaign->getKey(),
        );
    }

    private function campaignPanelTarget(
        Campaign $campaign,
        string $panel,
        string $label,
        string $resourceType,
    ): ProcessHighwayEditTarget {
        return ProcessHighwayEditTarget::link(
            ownerKey: 'campaigns',
            label: $label,
            url: route('crm.campaigns.edit', [
                'campaign' => $campaign,
                'panel' => $panel,
            ]),
            resourceType: $resourceType,
            resourceKey: (string) $campaign->key,
            resourceId: (int) $campaign->getKey(),
            containerType: 'campaign',
            containerKey: (string) $campaign->key,
            containerId: (int) $campaign->getKey(),
        );
    }

    private function campaignEligibilityTarget(Campaign $campaign): ProcessHighwayEditTarget
    {
        return ProcessHighwayEditTarget::inline(
            ownerKey: 'campaigns',
            label: 'Edit Campaign Start',
            url: route('crm.campaigns.eligibility.update', $campaign),
            method: 'PATCH',
            capability: 'campaigns.eligibility.update',
            resourceType: 'campaign_eligibility',
            resourceKey: (string) $campaign->key,
            resourceId: (int) $campaign->getKey(),
            containerType: 'campaign',
            containerKey: (string) $campaign->key,
            containerId: (int) $campaign->getKey(),
        );
    }
}