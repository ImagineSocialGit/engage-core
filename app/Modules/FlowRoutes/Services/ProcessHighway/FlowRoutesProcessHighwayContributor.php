<?php

namespace App\Modules\FlowRoutes\Services\ProcessHighway;

use App\Modules\FlowRoutes\Models\FlowRoute;
use App\Modules\FlowRoutes\Models\FlowRoutePoint;
use App\Modules\FlowRoutes\Services\FlowRoutePresentationResolver;
use App\Support\ProcessHighway\Contracts\ProcessHighwayContributor;
use App\Support\ProcessHighway\Data\ProcessHighwayAuthority;
use App\Support\ProcessHighway\Data\ProcessHighwayContribution;
use App\Support\ProcessHighway\Data\ProcessHighwayEdge;
use App\Support\ProcessHighway\Data\ProcessHighwayEditTarget;
use App\Support\ProcessHighway\Data\ProcessHighwayLane;
use App\Support\ProcessHighway\Data\ProcessHighwayNode;
use App\Support\ProcessHighway\ProcessHighwaySemanticKey;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class FlowRoutesProcessHighwayContributor implements ProcessHighwayContributor
{
    public function __construct(
        private readonly FlowRoutePresentationResolver $presentation,
    ) {}

    /** @return iterable<int, ProcessHighwayContribution> */
    public function contributions(): iterable
    {
        if (! $this->available()) {
            return [];
        }

        return FlowRoute::query()
            ->active()
            ->with([
                'activeFlowRoutePoints.capability',
                'activeTriggerBindings',
            ])
            ->orderBy('name')
            ->get()
            ->map(fn (FlowRoute $route): ProcessHighwayContribution => $this->route($route))
            ->values()
            ->all();
    }

    private function route(FlowRoute $route): ProcessHighwayContribution
    {
        $routePresentation = $this->presentation->route($route);
        $points = $route->activeFlowRoutePoints
            ->sortBy('sort_order')
            ->values();
        $presentedPoints = collect(
            $this->presentation->presentedPoints($route, $points),
        )->keyBy('key');
        $replyProfileKeys = $this->replyProfileKeys($route, $points);
        $replyIntentKeys = $this->replyIntentKeys($route, $points);
        $replyChannels = $this->replyChannels($route, $points);
        $processKey = ProcessHighwaySemanticKey::flowRoute((string) $route->key);
        $routeTarget = $this->routeTarget($route);
        $routeAuthority = new ProcessHighwayAuthority(
            ownerKey: 'flow_routes',
            editTargets: [$routeTarget],
        );
        $nodes = [];
        $edges = [];
        $edgeOrder = 10;
        $exitNodeKeys = [];

        $this->putNode($nodes, new ProcessHighwayNode(
            key: $processKey,
            label: (string) $route->name,
            role: ProcessHighwayNode::ROLE_PROCESS,
            authority: $routeAuthority,
            description: trim((string) ($route->description ?? '')) ?: null,
            state: 'active',
            stateLabel: 'Active',
            sortOrder: 100,
            attributes: [
                'flow_route_id' => (int) $route->getKey(),
                'flow_route_key' => (string) $route->key,
            ],
        ));

        [$triggerNodes, $triggerSummary] = $this->triggerNodes(
            route: $route,
            routePresentation: $routePresentation,
            routeTarget: $routeTarget,
            replyProfileKeys: $replyProfileKeys,
        );

        foreach ($triggerNodes as $triggerIndex => $triggerNode) {
            $this->putNode($nodes, $triggerNode);
            $edges[] = new ProcessHighwayEdge(
                key: $processKey.':edge:trigger:'.$triggerIndex,
                fromNodeKey: $triggerNode->key,
                toNodeKey: $processKey,
                role: ProcessHighwayEdge::ROLE_STARTS,
                authority: $routeAuthority,
                label: 'Starts Route',
                sortOrder: $edgeOrder++,
            );
        }

        $completedExitKey = $processKey.':exit:completed';
        $this->putNode($nodes, new ProcessHighwayNode(
            key: $completedExitKey,
            label: 'Route completed',
            role: ProcessHighwayNode::ROLE_EXIT,
            authority: $routeAuthority,
            sortOrder: 400,
            attributes: [
                'highway_visibility' => 'hidden',
                'technical_completion' => true,
            ],
        ));
        $exitNodeKeys[] = $completedExitKey;

        $pointsById = $points->keyBy(
            fn (FlowRoutePoint $point): int => (int) $point->getKey(),
        );
        $pointsByKey = $points->keyBy(
            fn (FlowRoutePoint $point): string => (string) $point->key,
        );

        foreach ($points as $pointIndex => $point) {
            $presented = $presentedPoints->get((string) $point->key, []);
            $moduleKey = is_string($presented['module_key'] ?? null)
                ? $presented['module_key']
                : 'flow_routes';
            $pointTarget = $this->pointTarget($route, $point, $moduleKey);
            $pointAuthority = new ProcessHighwayAuthority(
                ownerKey: $moduleKey,
                editTargets: [$pointTarget],
            );
            $pointKey = ProcessHighwaySemanticKey::flowRoutePoint(
                (string) $route->key,
                (string) $point->key,
            );
            $pointType = (string) $point->type;
            $pointLabel = $this->pointLabel($point, $presented);
            $pointDetail = is_string($presented['summary'] ?? null)
                ? trim($presented['summary'])
                : null;

            $this->putNode($nodes, new ProcessHighwayNode(
                key: $pointKey,
                label: $pointLabel,
                role: in_array($pointType, ['branch_evaluate', 'condition'], true)
                    ? ProcessHighwayNode::ROLE_GATEWAY
                    : ProcessHighwayNode::ROLE_ACTION,
                authority: $pointAuthority,
                description: trim((string) ($point->description ?? '')) ?: null,
                detail: $pointDetail !== '' ? $pointDetail : null,
                sortOrder: 150 + $pointIndex,
                attributes: [
                    'flow_route_id' => (int) $route->getKey(),
                    'flow_route_key' => (string) $route->key,
                    'flow_route_point_id' => (int) $point->getKey(),
                    'flow_route_point_key' => (string) $point->key,
                    'point_type' => $pointType,
                ],
            ));

            $this->addPointConsequence(
                route: $route,
                point: $point,
                pointNodeKey: $pointKey,
                pointAuthority: $pointAuthority,
                pointTarget: $pointTarget,
                nodes: $nodes,
                edges: $edges,
                edgeOrder: $edgeOrder,
            );
        }

        $startPoints = $points
            ->filter(fn (FlowRoutePoint $point): bool => (bool) $point->is_start)
            ->values();

        if ($startPoints->isEmpty() && $points->isNotEmpty()) {
            $startPoints = collect([$points->first()]);
        }

        if ($startPoints->isEmpty()) {
            $edges[] = new ProcessHighwayEdge(
                key: $processKey.':edge:no-points',
                fromNodeKey: $processKey,
                toNodeKey: $completedExitKey,
                role: ProcessHighwayEdge::ROLE_EXITS,
                authority: $routeAuthority,
                label: 'No active Points',
                sortOrder: $edgeOrder++,
                attributes: [
                    'highway_visibility' => 'hidden',
                    'technical_completion' => true,
                ],
            );
        } else {
            foreach ($startPoints as $startIndex => $startPoint) {
                $edges[] = new ProcessHighwayEdge(
                    key: $processKey.':edge:start:'.$startIndex,
                    fromNodeKey: $processKey,
                    toNodeKey: ProcessHighwaySemanticKey::flowRoutePoint(
                        (string) $route->key,
                        (string) $startPoint->key,
                    ),
                    role: ProcessHighwayEdge::ROLE_CONTINUES,
                    authority: $routeAuthority,
                    label: 'First Point',
                    sortOrder: $edgeOrder++,
                );
            }
        }

        foreach ($points as $point) {
            $pointKey = ProcessHighwaySemanticKey::flowRoutePoint(
                (string) $route->key,
                (string) $point->key,
            );
            $definition = $this->definition($point);

            if ((string) $point->type === 'branch_evaluate') {
                $this->addBranchEdges(
                    route: $route,
                    point: $point,
                    pointNodeKey: $pointKey,
                    definition: $definition,
                    pointsByKey: $pointsByKey,
                    routeAuthority: $routeAuthority,
                    routeTarget: $routeTarget,
                    completedExitKey: $completedExitKey,
                    nodes: $nodes,
                    edges: $edges,
                    exitNodeKeys: $exitNodeKeys,
                    edgeOrder: $edgeOrder,
                );

                continue;
            }

            $nextPoint = $point->next_flow_route_point_id !== null
                ? $pointsById->get((int) $point->next_flow_route_point_id)
                : null;
            $nextNodeKey = $nextPoint instanceof FlowRoutePoint
                ? ProcessHighwaySemanticKey::flowRoutePoint(
                    (string) $route->key,
                    (string) $nextPoint->key,
                )
                : $completedExitKey;

            $edges[] = new ProcessHighwayEdge(
                key: $processKey.':edge:next:'.rawurlencode((string) $point->key),
                fromNodeKey: $pointKey,
                toNodeKey: $nextNodeKey,
                role: $nextPoint instanceof FlowRoutePoint
                    ? ProcessHighwayEdge::ROLE_CONTINUES
                    : ProcessHighwayEdge::ROLE_EXITS,
                authority: $routeAuthority,
                label: $nextPoint instanceof FlowRoutePoint ? 'Then' : 'Complete',
                sortOrder: $edgeOrder++,
                attributes: $nextPoint instanceof FlowRoutePoint ? [] : [
                    'highway_visibility' => 'hidden',
                    'technical_completion' => true,
                ],
            );
        }

        return new ProcessHighwayContribution(
            sourceKey: 'flow_routes',
            key: $processKey,
            name: (string) $route->name,
            description: trim((string) ($route->description ?? '')),
            subjectKey: 'contacts',
            lane: $this->lane($points),
            mechanismNodeKey: $processKey,
            authority: $routeAuthority,
            nodes: array_values($nodes),
            edges: $edges,
            entryNodeKeys: array_map(
                fn (ProcessHighwayNode $triggerNode): string => $triggerNode->key,
                $triggerNodes,
            ),
            exitNodeKeys: array_values(array_unique($exitNodeKeys)),
            state: 'active',
            stateLabel: 'Active',
            entrySummary: $triggerSummary,
            sortOrder: $this->sortOrder($route, $replyProfileKeys),
            attributes: [
                'mechanism_role' => 'procedural_orchestration',
                'flow_route_id' => (int) $route->getKey(),
                'flow_route_key' => (string) $route->key,
                'trigger_type' => (string) ($route->trigger_type ?? ''),
                'trigger_key' => (string) ($route->trigger_key ?? ''),
                'category' => (string) data_get($route->meta, 'definition.category', 'other'),
                'role' => (string) data_get(
                    $route->meta,
                    'definition.default_role',
                    data_get($route->meta, 'definition.role', ''),
                ),
                'point_count' => $points->count(),
                'reply_profile_keys' => $replyProfileKeys,
                'reply_intent_keys' => $replyIntentKeys,
                'reply_channels' => $replyChannels,
            ],
        );
    }

    private function available(): bool
    {
        return in_array('flow_routes', config('modules.enabled', []), true)
            && Schema::hasTable('flow_routes')
            && Schema::hasTable('flow_route_points');
    }

    /**
     * @param array<string, mixed> $routePresentation
     * @param array<int, string> $replyProfileKeys
     * @return array{0: array<int, ProcessHighwayNode>, 1: string}
     */
    private function triggerNodes(
        FlowRoute $route,
        array $routePresentation,
        ProcessHighwayEditTarget $routeTarget,
        array $replyProfileKeys,
    ): array {
        $triggerType = (string) ($route->trigger_type ?? FlowRoute::TRIGGER_MANUAL);
        $triggerKey = trim((string) ($route->trigger_key ?? ''));
        $summary = trim((string) ($routePresentation['trigger_summary'] ?? ''));

        if (
            $triggerType === FlowRoute::TRIGGER_AUTOMATION_EVENT
            && $triggerKey === 'inbound_message.normal_reply'
            && $replyProfileKeys !== []
        ) {
            return [
                array_map(
                    fn (string $replyProfileKey): ProcessHighwayNode => new ProcessHighwayNode(
                        key: ProcessHighwaySemanticKey::replyProfile($replyProfileKey),
                        label: $this->replyProfileLabel($replyProfileKey),
                        role: ProcessHighwayNode::ROLE_TRIGGER,
                        authority: new ProcessHighwayAuthority(
                            ownerKey: 'inbound_messaging',
                            editTargets: [
                                $this->replyProfileTarget($replyProfileKey),
                                $routeTarget,
                            ],
                        ),
                        detail: $summary !== '' ? $summary : null,
                        sortOrder: 10,
                        referenceOnly: true,
                        attributes: [
                            'event_key' => $triggerKey,
                            'reply_profile_key' => $replyProfileKey,
                        ],
                    ),
                    $replyProfileKeys,
                ),
                'A contact replies through '.implode(' or ', array_map(
                    fn (string $replyProfileKey): string => Str::headline($replyProfileKey),
                    $replyProfileKeys,
                )).'.',
            ];
        }

        if ($triggerType === FlowRoute::TRIGGER_CONTACT_STATUS) {
            $statusLabel = Str::headline($triggerKey);
            $statusPrefix = 'When a '.config('contacts.labels.singular', 'contact').' moves to ';

            if (str_starts_with($summary, $statusPrefix)) {
                $statusLabel = Str::before(Str::after($summary, $statusPrefix), '.');
                $statusLabel = Str::before($statusLabel, ' from ');
                $statusLabel = Str::before($statusLabel, ' after ');
                $statusLabel = Str::before($statusLabel, ' through ');
            }

            return [[
                new ProcessHighwayNode(
                    key: ProcessHighwaySemanticKey::status($triggerKey),
                    label: 'Status: '.$statusLabel,
                    role: ProcessHighwayNode::ROLE_QUALIFIER,
                    authority: new ProcessHighwayAuthority(
                        ownerKey: 'workflow',
                        editTargets: [$routeTarget],
                    ),
                    detail: $summary !== '' ? $summary : null,
                    sortOrder: 10,
                    referenceOnly: true,
                    attributes: [
                        'criterion_key' => 'status',
                        'value' => $triggerKey,
                    ],
                ),
            ], $summary !== '' ? $summary : "A contact becomes {$statusLabel}."];
        }

        if ($triggerType === FlowRoute::TRIGGER_AUTOMATION_EVENT) {
            $ownerKey = $this->automationEventOwner($triggerKey);

            return [[
                new ProcessHighwayNode(
                    key: ProcessHighwaySemanticKey::automationEvent($triggerKey),
                    label: $this->automationEventLabel($triggerKey),
                    role: ProcessHighwayNode::ROLE_TRIGGER,
                    authority: new ProcessHighwayAuthority(
                        ownerKey: $ownerKey,
                        editTargets: [$routeTarget],
                    ),
                    detail: $summary !== '' ? $summary : null,
                    sortOrder: 10,
                    referenceOnly: true,
                    attributes: [
                        'event_key' => $triggerKey,
                    ],
                ),
            ], $summary !== '' ? $summary : 'The configured automation event happens.'];
        }

        $manualKey = ProcessHighwaySemanticKey::flowRoute((string) $route->key).':entry:manual';

        return [[
            new ProcessHighwayNode(
                key: $manualKey,
                label: 'Manual start',
                role: ProcessHighwayNode::ROLE_TRIGGER,
                authority: new ProcessHighwayAuthority(
                    ownerKey: 'flow_routes',
                    editTargets: [$routeTarget],
                ),
                sortOrder: 10,
            ),
        ], $summary !== '' ? $summary : 'Someone starts this Route manually.'];
    }

    /**
     * @param array<string, mixed> $presented
     */
    private function pointLabel(FlowRoutePoint $point, array $presented): string
    {
        foreach ([$presented['label'] ?? null, $presented['type_label'] ?? null] as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        $name = trim((string) ($point->name ?? ''));

        return $name !== '' ? $name : Str::headline((string) $point->type);
    }

    /**
     * @param array<string, ProcessHighwayNode> $nodes
     * @param array<int, ProcessHighwayEdge> $edges
     */
    private function addPointConsequence(
        FlowRoute $route,
        FlowRoutePoint $point,
        string $pointNodeKey,
        ProcessHighwayAuthority $pointAuthority,
        ProcessHighwayEditTarget $pointTarget,
        array &$nodes,
        array &$edges,
        int &$edgeOrder,
    ): void {
        $definition = $this->definition($point);
        $type = (string) $point->type;
        $targetNode = null;
        $edgeLabel = null;
        $edgeRole = ProcessHighwayEdge::ROLE_CONSEQUENCE;

        if ($type === 'change_status' && $this->string($definition['contact_status_key'] ?? null) !== null) {
            $statusKey = $this->string($definition['contact_status_key']) ?? '';
            $targetNode = new ProcessHighwayNode(
                key: ProcessHighwaySemanticKey::status($statusKey),
                label: 'Status: '.Str::headline($statusKey),
                role: ProcessHighwayNode::ROLE_QUALIFIER,
                authority: new ProcessHighwayAuthority('workflow', [$pointTarget]),
                sortOrder: 300,
                referenceOnly: true,
                attributes: [
                    'criterion_key' => 'status',
                    'value' => $statusKey,
                ],
            );
            $edgeLabel = 'Changes status to';
        } elseif (
            in_array($type, ['add_contact_tag', 'remove_contact_tag'], true)
            && $this->string($definition['tag'] ?? null) !== null
        ) {
            $tag = $this->string($definition['tag']) ?? '';
            $present = $type === 'add_contact_tag';
            $targetNode = new ProcessHighwayNode(
                key: ProcessHighwaySemanticKey::tag($tag, $present),
                label: ($present ? 'Tag: ' : 'Tag removed: ').$tag,
                role: ProcessHighwayNode::ROLE_QUALIFIER,
                authority: new ProcessHighwayAuthority('core', [$pointTarget]),
                sortOrder: 300,
                referenceOnly: true,
                attributes: [
                    'criterion_key' => 'tag',
                    'value' => $tag,
                    'present' => $present,
                ],
            );
            $edgeLabel = $present ? 'Adds' : 'Removes';
        } elseif (
            $type === 'change_relationship_stage'
            && $this->string($definition['relationship_key'] ?? null) !== null
            && $this->string($definition['stage_key'] ?? null) !== null
        ) {
            $relationshipKey = $this->string($definition['relationship_key']) ?? '';
            $stageKey = $this->string($definition['stage_key']) ?? '';
            $targetNode = new ProcessHighwayNode(
                key: ProcessHighwaySemanticKey::relationship($relationshipKey, $stageKey),
                label: Str::headline($relationshipKey).' → '.Str::headline($stageKey),
                role: ProcessHighwayNode::ROLE_QUALIFIER,
                authority: new ProcessHighwayAuthority('relationships', [$pointTarget]),
                sortOrder: 300,
                referenceOnly: true,
                attributes: [
                    'criterion_key' => 'relationship',
                    'relationship_key' => $relationshipKey,
                    'stage_key' => $stageKey,
                ],
            );
            $edgeLabel = 'Changes relationship stage to';
        } elseif (
            $type === 'enroll_campaign'
            && $this->string($definition['campaign_key'] ?? null) !== null
        ) {
            $campaignKey = $this->string($definition['campaign_key']) ?? '';
            $targetNode = new ProcessHighwayNode(
                key: ProcessHighwaySemanticKey::campaign($campaignKey),
                label: 'Campaign: '.Str::headline($campaignKey),
                role: ProcessHighwayNode::ROLE_PROCESS,
                authority: new ProcessHighwayAuthority('campaigns', [$pointTarget]),
                sortOrder: 300,
                referenceOnly: true,
                attributes: [
                    'campaign_key' => $campaignKey,
                ],
            );
            $edgeLabel = 'Starts Campaign';
            $edgeRole = ProcessHighwayEdge::ROLE_EXITS_TO;
        } elseif (
            $type === 'cancel_campaign'
            && $this->string($definition['campaign_key'] ?? null) !== null
        ) {
            $campaignKey = $this->string($definition['campaign_key']) ?? '';
            $targetNode = new ProcessHighwayNode(
                key: ProcessHighwaySemanticKey::campaignState($campaignKey, 'cancelled'),
                label: 'Campaign stopped: '.Str::headline($campaignKey),
                role: ProcessHighwayNode::ROLE_CONSEQUENCE,
                authority: new ProcessHighwayAuthority('campaigns', [$pointTarget]),
                sortOrder: 300,
                referenceOnly: true,
                attributes: [
                    'campaign_key' => $campaignKey,
                    'campaign_state' => 'cancelled',
                ],
            );
            $edgeLabel = 'Stops Campaign';
        } elseif (
            in_array($type, ['cancel_campaign_family', 'pause_campaign_family'], true)
            && $this->string($definition['family_key'] ?? null) !== null
        ) {
            $familyKey = $this->string($definition['family_key']) ?? '';
            $state = $type === 'pause_campaign_family' ? 'paused' : 'cancelled';
            $targetNode = new ProcessHighwayNode(
                key: ProcessHighwaySemanticKey::campaignFamilyState($familyKey, $state),
                label: Str::headline($familyKey).' family '.($state === 'paused' ? 'paused' : 'stopped'),
                role: ProcessHighwayNode::ROLE_CONSEQUENCE,
                authority: new ProcessHighwayAuthority('campaigns', [$pointTarget]),
                sortOrder: 300,
                referenceOnly: true,
                attributes: [
                    'family_key' => $familyKey,
                    'family_state' => $state,
                ],
            );
            $edgeLabel = $state === 'paused' ? 'Pauses Campaign family' : 'Stops Campaign family';
        }

        if (! $targetNode instanceof ProcessHighwayNode || $edgeLabel === null) {
            return;
        }

        $this->putNode($nodes, $targetNode);
        $edges[] = new ProcessHighwayEdge(
            key: ProcessHighwaySemanticKey::flowRoute((string) $route->key)
                .':edge:consequence:'.rawurlencode((string) $point->key).':'.rawurlencode($type),
            fromNodeKey: $pointNodeKey,
            toNodeKey: $targetNode->key,
            role: $edgeRole,
            authority: $pointAuthority,
            label: $edgeLabel,
            sortOrder: $edgeOrder++,
            attributes: [
                'point_type' => $type,
            ],
        );
    }

    /**
     * @param array<string, mixed> $definition
     * @param Collection<string, FlowRoutePoint> $pointsByKey
     * @param array<string, ProcessHighwayNode> $nodes
     * @param array<int, ProcessHighwayEdge> $edges
     * @param array<int, string> $exitNodeKeys
     */
    private function addBranchEdges(
        FlowRoute $route,
        FlowRoutePoint $point,
        string $pointNodeKey,
        array $definition,
        Collection $pointsByKey,
        ProcessHighwayAuthority $routeAuthority,
        ProcessHighwayEditTarget $routeTarget,
        string $completedExitKey,
        array &$nodes,
        array &$edges,
        array &$exitNodeKeys,
        int &$edgeOrder,
    ): void {
        $processKey = ProcessHighwaySemanticKey::flowRoute((string) $route->key);
        $branches = is_array($definition['branches'] ?? null)
            ? array_values(array_filter($definition['branches'], 'is_array'))
            : [];

        foreach ($branches as $branchIndex => $branch) {
            $targetPointKey = $this->string($branch['target_flow_route_point_key'] ?? null);
            $targetPoint = $targetPointKey !== null
                ? $pointsByKey->get($targetPointKey)
                : null;

            if ($targetPoint instanceof FlowRoutePoint) {
                $targetNodeKey = ProcessHighwaySemanticKey::flowRoutePoint(
                    (string) $route->key,
                    (string) $targetPoint->key,
                );
            } else {
                $targetNodeKey = $processKey.':exit:missing-branch-target:'.$branchIndex;
                $this->putNode($nodes, new ProcessHighwayNode(
                    key: $targetNodeKey,
                    label: $targetPointKey === null
                        ? 'Branch target is not configured'
                        : 'Missing branch target: '.Str::headline($targetPointKey),
                    role: ProcessHighwayNode::ROLE_EXIT,
                    authority: new ProcessHighwayAuthority('flow_routes', [$routeTarget]),
                    state: 'blocked',
                    stateLabel: 'Blocked',
                    sortOrder: 390,
                ));
                $exitNodeKeys[] = $targetNodeKey;
            }

            $edges[] = new ProcessHighwayEdge(
                key: $processKey.':edge:branch:'.rawurlencode((string) $point->key).':'.$branchIndex,
                fromNodeKey: $pointNodeKey,
                toNodeKey: $targetNodeKey,
                role: $targetPoint instanceof FlowRoutePoint
                    ? ProcessHighwayEdge::ROLE_BRANCH
                    : ProcessHighwayEdge::ROLE_EXITS,
                authority: $routeAuthority,
                label: $this->branchLabel($branch, $branchIndex),
                sortOrder: $edgeOrder++,
                attributes: [
                    'branch_index' => $branchIndex,
                    'conditions' => $branch['conditions'] ?? [],
                    'mode' => $branch['mode'] ?? 'all',
                ],
            );
        }

        $defaultTargetKey = $this->string(
            $definition['default_target_flow_route_point_key'] ?? null,
        );
        $defaultTarget = $defaultTargetKey !== null
            ? $pointsByKey->get($defaultTargetKey)
            : null;

        if ($defaultTarget instanceof FlowRoutePoint) {
            $edges[] = new ProcessHighwayEdge(
                key: $processKey.':edge:branch-default:'.rawurlencode((string) $point->key),
                fromNodeKey: $pointNodeKey,
                toNodeKey: ProcessHighwaySemanticKey::flowRoutePoint(
                    (string) $route->key,
                    (string) $defaultTarget->key,
                ),
                role: ProcessHighwayEdge::ROLE_BRANCH,
                authority: $routeAuthority,
                label: 'Otherwise',
                sortOrder: $edgeOrder++,
                attributes: [
                    'default' => true,
                ],
            );

            return;
        }

        $onNoMatch = $this->string($definition['on_no_match'] ?? null) ?? 'completed';
        $noMatchExitKey = $onNoMatch === 'completed'
            ? $completedExitKey
            : $processKey.':exit:branch-no-match:'.rawurlencode((string) $point->key);

        if ($noMatchExitKey !== $completedExitKey) {
            $this->putNode($nodes, new ProcessHighwayNode(
                key: $noMatchExitKey,
                label: 'No branch matched — '.Str::headline($onNoMatch),
                role: ProcessHighwayNode::ROLE_EXIT,
                authority: new ProcessHighwayAuthority('flow_routes', [$routeTarget]),
                state: $onNoMatch,
                stateLabel: Str::headline($onNoMatch),
                sortOrder: 390,
            ));
            $exitNodeKeys[] = $noMatchExitKey;
        }

        $edges[] = new ProcessHighwayEdge(
            key: $processKey.':edge:branch-no-match:'.rawurlencode((string) $point->key),
            fromNodeKey: $pointNodeKey,
            toNodeKey: $noMatchExitKey,
            role: ProcessHighwayEdge::ROLE_EXITS,
            authority: $routeAuthority,
            label: 'No branch matched',
            sortOrder: $edgeOrder++,
            attributes: [
                'on_no_match' => $onNoMatch,
                ...($onNoMatch === 'completed' ? [
                    'highway_visibility' => 'hidden',
                    'technical_completion' => true,
                ] : []),
            ],
        );
    }

    /** @param array<string, mixed> $branch */
    private function branchLabel(array $branch, int $index): string
    {
        $conditions = is_array($branch['conditions'] ?? null)
            ? array_values(array_filter($branch['conditions'], 'is_array'))
            : [];
        $labels = array_values(array_filter(array_map(
            fn (array $condition): ?string => $this->conditionLabel($condition),
            $conditions,
        )));

        if ($labels === []) {
            return 'Branch '.($index + 1);
        }

        $join = ($branch['mode'] ?? 'all') === 'any' ? ' or ' : ' and ';

        return implode($join, $labels);
    }

    /** @param array<string, mixed> $condition */
    private function conditionLabel(array $condition): ?string
    {
        $path = $this->string($condition['path'] ?? null);
        $source = $this->string($condition['source'] ?? null);
        $operator = $this->string($condition['operator'] ?? null) ?? 'equals';
        $values = is_array($condition['values'] ?? null)
            ? array_values(array_filter(array_map(
                fn (mixed $value): ?string => is_scalar($value) ? (string) $value : null,
                $condition['values'],
            )))
            : [];

        if ($values === [] && is_scalar($condition['value'] ?? null)) {
            $values[] = (string) $condition['value'];
        }

        $field = match (true) {
            $path !== null && str_ends_with($path, 'reply_profile_key') => 'Reply profile',
            $path !== null && str_ends_with($path, 'reply_intent_key') => 'Reply intent',
            $path !== null && str_ends_with($path, 'channel') => 'Channel',
            $source === 'contact_status' => 'Status',
            $source === 'contact_relationship' => 'Relationship',
            $path !== null => Str::headline(Str::afterLast($path, '.')),
            $source !== null => Str::headline($source),
            default => 'Condition',
        };

        $valueLabel = implode(' or ', array_map(
            fn (string $value): string => $field === 'Channel'
                ? strtoupper($value)
                : Str::headline($value),
            $values,
        ));

        if ($valueLabel === '') {
            return $field.' '.Str::lower(Str::headline($operator));
        }

        return $field.' '.match ($operator) {
            'not_equals', 'not_equal' => 'is not ',
            'in' => 'is ',
            'not_in' => 'is not ',
            default => 'is ',
        }.$valueLabel;
    }

    /**
     * @param Collection<int, FlowRoutePoint> $points
     * @return array<int, string>
     */
    private function replyProfileKeys(FlowRoute $route, Collection $points): array
    {
        return $this->scopedConditionValues($route, $points, 'reply_profile_key');
    }

    /**
     * @param Collection<int, FlowRoutePoint> $points
     * @return array<int, string>
     */
    private function replyIntentKeys(FlowRoute $route, Collection $points): array
    {
        return $this->scopedConditionValues($route, $points, 'reply_intent_key');
    }

    /**
     * @param Collection<int, FlowRoutePoint> $points
     * @return array<int, string>
     */
    private function replyChannels(FlowRoute $route, Collection $points): array
    {
        return $this->scopedConditionValues($route, $points, 'channel');
    }

    /**
     * @param Collection<int, FlowRoutePoint> $points
     * @return array<int, string>
     */
    private function scopedConditionValues(
        FlowRoute $route,
        Collection $points,
        string $pathSuffix,
    ): array {
        $entryConditions = data_get($route->meta, 'definition.entry_conditions', []);
        $entryValues = $this->conditionValues(
            is_array($entryConditions) ? $entryConditions : [],
            $pathSuffix,
        );

        return $entryValues !== []
            ? $entryValues
            : $this->branchConditionValues($points, $pathSuffix);
    }

    /**
     * @param Collection<int, FlowRoutePoint> $points
     * @return array<int, string>
     */
    private function branchConditionValues(Collection $points, string $pathSuffix): array
    {
        $conditions = $points
            ->flatMap(function (FlowRoutePoint $point): array {
                $definition = $this->definition($point);
                $branches = is_array($definition['branches'] ?? null)
                    ? array_values(array_filter($definition['branches'], 'is_array'))
                    : [];

                return collect($branches)
                    ->flatMap(fn (array $branch): array => is_array($branch['conditions'] ?? null)
                        ? array_values(array_filter($branch['conditions'], 'is_array'))
                        : [])
                    ->all();
            })
            ->all();

        return $this->conditionValues($conditions, $pathSuffix);
    }

    /**
     * @param array<int, mixed> $conditions
     * @return array<int, string>
     */
    private function conditionValues(array $conditions, string $pathSuffix): array
    {
        return collect($conditions)
            ->filter(fn (mixed $condition): bool => is_array($condition))
            ->filter(function (array $condition) use ($pathSuffix): bool {
                $path = $this->string($condition['path'] ?? null);
                $operator = $this->string($condition['operator'] ?? null) ?? 'equals';

                return $path !== null
                    && str_ends_with($path, $pathSuffix)
                    && in_array($operator, ['equals', 'equal', 'in'], true);
            })
            ->flatMap(function (array $condition): array {
                $values = is_array($condition['values'] ?? null)
                    ? $condition['values']
                    : [$condition['value'] ?? null];

                return array_values(array_filter(array_map(
                    fn (mixed $value): ?string => $this->string($value),
                    $values,
                )));
            })
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /** @param array<int, string> $replyProfileKeys */
    private function sortOrder(FlowRoute $route, array $replyProfileKeys): int
    {
        if ($replyProfileKeys === []) {
            return 100;
        }

        return data_get($route->meta, 'definition.category') === 'reply_acknowledgement'
            ? 240
            : 200;
    }

    private function replyProfileLabel(string $replyProfileKey): string
    {
        return 'Reply to '.Str::headline($replyProfileKey).' messages';
    }

    private function automationEventOwner(string $eventKey): string
    {
        return match (Str::before($eventKey, '.')) {
            'inbound_message' => 'inbound_messaging',
            'webinar' => 'webinars',
            'task' => 'tasks',
            'permission_invitation' => 'messaging',
            default => 'flow_routes',
        };
    }

    private function automationEventLabel(string $eventKey): string
    {
        return match ($eventKey) {
            'inbound_message.normal_reply' => 'Contact replies to a message',
            'webinar.attended' => 'Contact attends a webinar',
            'webinar.missed' => 'Contact misses a webinar',
            'webinar.registered' => 'Contact registers for a webinar',
            'webinar.cancelled' => 'Contact cancels a webinar registration',
            'task.completed' => 'Task is completed',
            'permission_invitation.accepted' => 'Communication preferences are confirmed',
            default => Str::headline(str_replace('.', ' ', $eventKey)),
        };
    }

    /** @param Collection<int, FlowRoutePoint> $points */
    private function lane(Collection $points): ProcessHighwayLane
    {
        $relationshipKeys = $points
            ->map(function (FlowRoutePoint $point): ?string {
                $definition = $this->definition($point);

                return $this->string($definition['relationship_key'] ?? null);
            })
            ->filter()
            ->unique()
            ->values();

        if ($relationshipKeys->isEmpty()) {
            return ProcessHighwayLane::standard();
        }

        if ($relationshipKeys->count() !== 1) {
            return ProcessHighwayLane::relationship();
        }

        $relationshipKey = (string) $relationshipKeys->first();

        return ProcessHighwayLane::relationship(
            relationshipKey: $relationshipKey,
            relationshipLabel: Str::headline($relationshipKey),
        );
    }

    /** @param array<string, ProcessHighwayNode> $nodes */
    private function putNode(array &$nodes, ProcessHighwayNode $node): void
    {
        $existing = $nodes[$node->key] ?? null;

        if (! $existing instanceof ProcessHighwayNode) {
            $nodes[$node->key] = $node;

            return;
        }

        if (
            $existing->role !== $node->role
            || $existing->authority->ownerKey !== $node->authority->ownerKey
        ) {
            throw new InvalidArgumentException(sprintf(
                'Flow Route [%s] produced conflicting appearances for semantic node [%s].',
                $node->attributes['flow_route_key'] ?? 'unknown',
                $node->key,
            ));
        }

        $nodes[$node->key] = new ProcessHighwayNode(
            key: $existing->key,
            label: $existing->label,
            role: $existing->role,
            authority: $existing->authority->merge($node->authority),
            description: $existing->description ?? $node->description,
            detail: $existing->detail ?? $node->detail,
            state: $existing->state,
            stateLabel: $existing->stateLabel,
            sortOrder: min($existing->sortOrder, $node->sortOrder),
            referenceOnly: $existing->referenceOnly && $node->referenceOnly,
            attributes: array_replace_recursive($existing->attributes, $node->attributes),
        );
    }

    private function routeTarget(FlowRoute $route): ProcessHighwayEditTarget
    {
        return ProcessHighwayEditTarget::link(
            ownerKey: 'flow_routes',
            label: 'Edit Route',
            url: route('crm.flow-routes.show', $route),
            resourceType: 'flow_route',
            resourceKey: (string) $route->key,
            resourceId: (int) $route->getKey(),
        );
    }

    private function replyProfileTarget(string $replyProfileKey): ProcessHighwayEditTarget
    {
        return ProcessHighwayEditTarget::link(
            ownerKey: 'inbound_messaging',
            label: 'Edit Reply Handling',
            url: route('crm.inbound-messaging.reply-profiles.index', [
                'profile' => $replyProfileKey,
            ]),
            resourceType: 'reply_profile',
            resourceKey: $replyProfileKey,
        );
    }

    private function pointTarget(
        FlowRoute $route,
        FlowRoutePoint $point,
        string $ownerKey,
    ): ProcessHighwayEditTarget {
        return ProcessHighwayEditTarget::link(
            ownerKey: $ownerKey,
            label: 'Edit Route Point',
            url: route('crm.flow-routes.show', $route),
            resourceType: 'flow_route_point',
            resourceKey: (string) $route->key.':'.(string) $point->key,
            resourceId: (int) $point->getKey(),
            containerType: 'flow_route',
            containerKey: (string) $route->key,
            containerId: (int) $route->getKey(),
        );
    }

    /** @return array<string, mixed> */
    private function definition(FlowRoutePoint $point): array
    {
        $settings = is_array($point->settings) ? $point->settings : [];
        $definition = is_array($point->definition) ? $point->definition : [];

        return array_replace_recursive($settings, $definition);
    }

    private function string(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }
}