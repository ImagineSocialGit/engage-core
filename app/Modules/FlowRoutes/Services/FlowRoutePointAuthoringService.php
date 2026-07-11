<?php

namespace App\Modules\FlowRoutes\Services;

use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Core\Models\ContactStatus;
use App\Modules\FlowRoutes\Data\Points\WaitPointDefinition;
use App\Modules\FlowRoutes\Enums\FlowRoutePointType;
use App\Modules\FlowRoutes\Models\FlowRoute;
use App\Modules\FlowRoutes\Models\FlowRouteCapability;
use App\Modules\FlowRoutes\Models\FlowRoutePoint;
use App\Modules\Messaging\Models\MessageTemplatePreset;
use App\Modules\Tasks\Models\TaskTemplate;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class FlowRoutePointAuthoringService
{
    public function __construct(
        private readonly FlowRouteMessageTemplateEligibilityResolver $messageTemplateEligibility,
        private readonly FlowRoutePointPlacementPolicy $placementPolicy,
    ) {}

    /**
     * @param array<string, mixed> $input
     */
    public function create(
        FlowRoute $route,
        int $capabilityId,
        array $input,
    ): FlowRoutePoint {
        $this->ensureRouteCanBeChanged($route);

        $capability = FlowRouteCapability::query()
            ->active()
            ->findOrFail($capabilityId);

        $this->ensureCapabilityIsAuthorable($capability);

        return DB::transaction(function () use ($route, $capability, $input): FlowRoutePoint {
            $definition = $this->definitionFor($capability->point_type, $input);
            $name = $this->pointName($capability->point_type, $capability->name, $input, $definition);

            $point = new FlowRoutePoint([
                'flow_route_id' => $route->getKey(),
                'flow_route_capability_id' => $capability->getKey(),
                'key' => $this->uniquePointKey($route, $name),
                'type' => $capability->point_type,
                'name' => $name,
                'description' => null,
                'sort_order' => ((int) $route->flowRoutePoints()->max('sort_order')) + 10,
                'is_start' => false,
                'is_active' => true,
                'next_flow_route_point_id' => null,
                'definition' => $definition,
                'settings' => [],
                'cancel_conditions' => [],
                'source_version' => null,
                'is_customized' => true,
                'customized_at' => now(),
                'meta' => [
                    'authoring' => [
                        'source' => 'crm',
                        'created_at' => now()->toISOString(),
                    ],
                ],
            ]);

            $currentPoints = $route->activeFlowRoutePoints()
                ->orderBy('sort_order')
                ->get();
            $proposedOrder = $this->placementPolicy->proposedAdditionOrder($currentPoints, $point);

            $this->placementPolicy->assertValidSequence($proposedOrder, 'add');

            $point->save();

            $this->markRouteCustomized($route);
            $this->rebuildSequence($route, $proposedOrder);

            return $point->refresh();
        });
    }

    /**
     * @param array<string, mixed> $input
     */
    public function update(
        FlowRoute $route,
        FlowRoutePoint $point,
        array $input,
    ): FlowRoutePoint {
        $this->ensureRouteCanBeChanged($route);
        $this->ensurePointBelongsToRoute($route, $point);

        $capability = $point->capability;

        if (! $capability instanceof FlowRouteCapability) {
            $capability = FlowRouteCapability::query()
                ->active()
                ->where('point_type', $point->type)
                ->first();
        }

        if (! $capability instanceof FlowRouteCapability) {
            throw ValidationException::withMessages([
                'point' => 'This Point cannot be edited because its capability is unavailable.',
            ]);
        }

        $this->ensureCapabilityIsAuthorable($capability);

        return DB::transaction(function () use ($route, $point, $capability, $input): FlowRoutePoint {
            $definition = $this->definitionFor($capability->point_type, $input);

            $point->forceFill([
                'flow_route_capability_id' => $capability->getKey(),
                'type' => $capability->point_type,
                'name' => $this->pointName(
                    $capability->point_type,
                    $point->name ?: $capability->name,
                    $input,
                    $definition,
                ),
                'definition' => $definition,
                'is_customized' => true,
                'customized_at' => now(),
                'meta' => array_replace_recursive($point->meta ?? [], [
                    'authoring' => [
                        'source' => 'crm',
                        'updated_at' => now()->toISOString(),
                    ],
                ]),
            ])->save();

            $this->markRouteCustomized($route);

            return $point->refresh();
        });
    }

    public function deactivate(FlowRoute $route, FlowRoutePoint $point): void
    {
        $this->ensureRouteCanBeChanged($route);
        $this->ensurePointBelongsToRoute($route, $point);

        DB::transaction(function () use ($route, $point): void {
            $proposedOrder = $route->activeFlowRoutePoints()
                ->orderBy('sort_order')
                ->get()
                ->reject(fn (FlowRoutePoint $candidate): bool => $candidate->is($point))
                ->values();

            $this->placementPolicy->assertValidSequence($proposedOrder, 'remove');

            $point->forceFill([
                'is_active' => false,
                'is_start' => false,
                'next_flow_route_point_id' => null,
                'is_customized' => true,
                'customized_at' => now(),
                'meta' => array_replace_recursive($point->meta ?? [], [
                    'authoring' => [
                        'source' => 'crm',
                        'deactivated_at' => now()->toISOString(),
                    ],
                ]),
            ])->save();

            $this->markRouteCustomized($route);
            $this->rebuildSequence($route);
        });
    }

    public function move(
        FlowRoute $route,
        FlowRoutePoint $point,
        int $direction,
    ): void {
        $this->ensureRouteCanBeChanged($route);
        $this->ensurePointBelongsToRoute($route, $point);

        if (! in_array($direction, [-1, 1], true)) {
            throw ValidationException::withMessages([
                'point' => 'Point movement must be up or down.',
            ]);
        }

        DB::transaction(function () use ($route, $point, $direction): void {
            $points = $route->activeFlowRoutePoints()
                ->orderBy('sort_order')
                ->get();

            $currentIndex = $points->search(
                fn (FlowRoutePoint $candidate): bool => $candidate->is($point),
            );

            if ($currentIndex === false) {
                return;
            }

            $targetIndex = $currentIndex + $direction;

            if ($targetIndex < 0 || $targetIndex >= $points->count()) {
                return;
            }

            $ordered = $points->values()->all();
            [$ordered[$currentIndex], $ordered[$targetIndex]] = [$ordered[$targetIndex], $ordered[$currentIndex]];
            $proposedOrder = collect($ordered);

            $this->placementPolicy->assertValidSequence($proposedOrder, 'move');

            $this->markRouteCustomized($route);
            $this->rebuildSequence($route, $proposedOrder);
        });
    }

    /**
     * @param array<int, int> $pointIds
     */
    public function reorder(FlowRoute $route, array $pointIds): void
    {
        $this->ensureRouteCanBeChanged($route);

        $submittedIds = array_values(array_map('intval', $pointIds));
        $activePoints = $route->activeFlowRoutePoints()
            ->orderBy('sort_order')
            ->get();
        $activeIds = $activePoints->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();

        $submittedSorted = $submittedIds;
        $activeSorted = $activeIds;
        sort($submittedSorted);
        sort($activeSorted);

        if ($submittedSorted !== $activeSorted) {
            throw ValidationException::withMessages([
                'point_ids' => 'The saved order must contain every active Point in this Route exactly once.',
            ]);
        }

        $pointsById = $activePoints->keyBy(fn (FlowRoutePoint $point): int => (int) $point->getKey());
        $ordered = collect($submittedIds)
            ->map(fn (int $pointId): FlowRoutePoint => $pointsById->get($pointId));

        $this->placementPolicy->assertValidSequence($ordered, 'reorder');

        DB::transaction(function () use ($route, $ordered): void {
            $this->markRouteCustomized($route);
            $this->rebuildSequence($route, $ordered);
        });
    }

    private function ensureRouteCanBeChanged(FlowRoute $route): void
    {
        $hasRunningProgress = $route->contactFlowRouteProgress()
            ->whereIn('status', ['active', 'waiting'])
            ->exists();

        if ($hasRunningProgress) {
            throw ValidationException::withMessages([
                'route' => 'This Route currently has active or waiting progress. Finish or cancel that progress before changing its Points.',
            ]);
        }
    }

    private function ensurePointBelongsToRoute(FlowRoute $route, FlowRoutePoint $point): void
    {
        if ((int) $point->flow_route_id !== (int) $route->getKey()) {
            throw ValidationException::withMessages([
                'point' => 'That Point does not belong to this Route.',
            ]);
        }
    }

    private function ensureCapabilityIsAuthorable(FlowRouteCapability $capability): void
    {
        $supported = [
            FlowRoutePointType::Wait->value,
            FlowRoutePointType::ChangeStatus->value,
            FlowRoutePointType::CreateTask->value,
            FlowRoutePointType::SendMessage->value,
            FlowRoutePointType::EnrollCampaign->value,
            FlowRoutePointType::CancelCampaign->value,
        ];

        if (! in_array($capability->point_type, $supported, true)) {
            throw ValidationException::withMessages([
                'capability_id' => 'That capability is not available in the first Route editor slice yet.',
            ]);
        }

        if (data_get($capability->meta, 'runtime.handler_available_at_sync', true) === false) {
            throw ValidationException::withMessages([
                'capability_id' => 'That capability is not currently available at runtime.',
            ]);
        }
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function definitionFor(string $pointType, array $input): array
    {
        return match ($pointType) {
            FlowRoutePointType::Wait->value => $this->waitDefinition($input),
            FlowRoutePointType::ChangeStatus->value => $this->changeStatusDefinition($input),
            FlowRoutePointType::CreateTask->value => $this->createTaskDefinition($input),
            FlowRoutePointType::SendMessage->value => $this->sendMessageDefinition($input),
            FlowRoutePointType::EnrollCampaign->value => $this->enrollCampaignDefinition($input),
            FlowRoutePointType::CancelCampaign->value => $this->cancelCampaignDefinition($input),
            default => throw ValidationException::withMessages([
                'capability_id' => 'That Point type is not authorable yet.',
            ]),
        };
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function waitDefinition(array $input): array
    {
        $mode = (string) ($input['wait_mode'] ?? 'duration');

        $definition = $mode === 'resume_at'
            ? ['resume_at' => $input['resume_at'] ?? null]
            : [
                (string) ($input['duration_unit'] ?? 'days') => $input['duration_value'] ?? null,
            ];

        $parsed = WaitPointDefinition::from($definition);

        if (! $parsed->isValid()) {
            throw ValidationException::withMessages([
                'wait_mode' => 'Choose a valid duration or a valid date and time.',
            ]);
        }

        return array_filter(
            $definition,
            static fn (mixed $value): bool => $value !== null && $value !== '',
        );
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function changeStatusDefinition(array $input): array
    {
        $statusKey = trim((string) ($input['contact_status_key'] ?? ''));

        $status = ContactStatus::query()
            ->active()
            ->where('key', $statusKey)
            ->first();

        if (! $status instanceof ContactStatus) {
            throw ValidationException::withMessages([
                'contact_status_key' => 'Choose an active contact status.',
            ]);
        }

        return [
            'contact_status_key' => (string) $status->key,
            'reason' => 'flow_route_change_status',
            'on_same_status' => 'skipped',
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function createTaskDefinition(array $input): array
    {
        $templateKey = trim((string) ($input['task_template_key'] ?? ''));
        $title = trim((string) ($input['title'] ?? ''));

        if ($templateKey !== '') {
            $template = TaskTemplate::query()
                ->active()
                ->where('key', $templateKey)
                ->first();

            if (! $template instanceof TaskTemplate) {
                throw ValidationException::withMessages([
                    'task_template_key' => 'Choose an active Task Template.',
                ]);
            }

            return [
                'task_template_key' => (string) $template->key,
            ];
        }

        if ($title === '') {
            throw ValidationException::withMessages([
                'title' => 'Enter a task title or choose a Task Template.',
            ]);
        }

        return array_filter([
            'title' => $title,
            'description' => trim((string) ($input['description'] ?? '')) ?: null,
            'due_offset_minutes' => isset($input['due_offset_minutes'])
                ? (int) $input['due_offset_minutes']
                : null,
            'priority' => trim((string) ($input['priority'] ?? '')) ?: null,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function sendMessageDefinition(array $input): array
    {
        $presetId = isset($input['message_template_preset_id'])
            ? (int) $input['message_template_preset_id']
            : 0;

        $preset = $this->messageTemplateEligibility
            ->eligiblePresets()
            ->first(fn (MessageTemplatePreset $candidate): bool => (int) $candidate->getKey() === $presetId);

        if (! $preset instanceof MessageTemplatePreset) {
            throw ValidationException::withMessages([
                'message_template_preset_id' => 'Choose a message template that is available for direct Route use.',
            ]);
        }

        return [
            'message_template_preset_key' => (string) $preset->key,
            'channel' => (string) $preset->channel,
            'purpose' => (string) $preset->purpose,
            'scope' => (string) $preset->scope,
            'dispatch_keys' => $preset->dispatchKeys(),
            'on_no_messages' => 'skipped',
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function enrollCampaignDefinition(array $input): array
    {
        $campaign = $this->activeCampaign((string) ($input['campaign_key'] ?? ''));

        return [
            'campaign_key' => (string) $campaign->key,
            'on_already_enrolled' => 'skipped',
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function cancelCampaignDefinition(array $input): array
    {
        $campaign = $this->activeCampaign((string) ($input['campaign_key'] ?? ''));

        return [
            'campaign_key' => (string) $campaign->key,
            'reason' => 'flow_route_cancelled_campaign',
            'on_not_enrolled' => 'skipped',
            'skip_pending_messages' => (bool) ($input['skip_pending_messages'] ?? true),
        ];
    }

    private function activeCampaign(string $key): Campaign
    {
        $campaign = Campaign::query()
            ->active()
            ->where('key', trim($key))
            ->first();

        if (! $campaign instanceof Campaign) {
            throw ValidationException::withMessages([
                'campaign_key' => 'Choose an active Campaign.',
            ]);
        }

        return $campaign;
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $definition
     */
    private function pointName(
        string $pointType,
        string $fallback,
        array $input,
        array $definition,
    ): string {
        $customName = trim((string) ($input['name'] ?? ''));

        if ($customName !== '') {
            return $customName;
        }

        return match ($pointType) {
            FlowRoutePointType::Wait->value => 'Wait',
            FlowRoutePointType::ChangeStatus->value => 'Change status to '.Str::headline((string) ($definition['contact_status_key'] ?? 'selected status')),
            FlowRoutePointType::CreateTask->value => isset($definition['task_template_key'])
                ? 'Create task from '.Str::headline((string) $definition['task_template_key'])
                : 'Create task: '.((string) ($definition['title'] ?? 'Task')),
            FlowRoutePointType::SendMessage->value => 'Send message',
            FlowRoutePointType::EnrollCampaign->value => 'Start Campaign: '.Str::headline((string) ($definition['campaign_key'] ?? 'selected campaign')),
            FlowRoutePointType::CancelCampaign->value => 'Stop Campaign: '.Str::headline((string) ($definition['campaign_key'] ?? 'selected campaign')),
            default => $fallback,
        };
    }

    private function uniquePointKey(FlowRoute $route, string $name): string
    {
        $base = Str::slug($name, '_') ?: 'point';
        $candidate = $base;
        $suffix = 2;

        while ($route->flowRoutePoints()->where('key', $candidate)->exists()) {
            $candidate = $base.'_'.$suffix;
            $suffix++;
        }

        return $candidate;
    }

    private function markRouteCustomized(FlowRoute $route): void
    {
        $route->forceFill([
            'is_customized' => true,
            'customized_at' => now(),
            'meta' => array_replace_recursive($route->meta ?? [], [
                'authoring' => [
                    'source' => 'crm',
                    'updated_at' => now()->toISOString(),
                ],
            ]),
        ])->save();
    }

    private function rebuildSequence(
        FlowRoute $route,
        ?Collection $orderedActivePoints = null,
    ): void {
        $activePoints = $orderedActivePoints ?? $route->activeFlowRoutePoints()
            ->orderBy('sort_order')
            ->get();

        $allPoints = $route->flowRoutePoints()
            ->orderBy('sort_order')
            ->get();

        $maxSortOrder = max(1000, (int) $allPoints->max('sort_order'));
        $offset = $maxSortOrder + 1000;

        foreach ($allPoints as $point) {
            $point->forceFill([
                'sort_order' => $point->sort_order + $offset,
                'is_start' => false,
                'next_flow_route_point_id' => null,
            ])->save();
        }

        foreach ($activePoints->values() as $index => $point) {
            $next = $activePoints->values()->get($index + 1);

            $point->forceFill([
                'sort_order' => ($index + 1) * 10,
                'is_start' => $index === 0,
                'next_flow_route_point_id' => $next?->getKey(),
            ])->save();
        }

        $inactivePoints = $allPoints
            ->where('is_active', false)
            ->values();

        foreach ($inactivePoints as $index => $point) {
            $point->forceFill([
                'sort_order' => 10000 + (($index + 1) * 10),
                'is_start' => false,
                'next_flow_route_point_id' => null,
            ])->save();
        }
    }
}
