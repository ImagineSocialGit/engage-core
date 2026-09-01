<?php

namespace App\Modules\FlowRoutes\Services;

use App\Modules\FlowRoutes\Models\FlowRoute;
use App\Modules\FlowRoutes\Models\FlowRouteTriggerBinding;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FlowRouteActivationService
{
    public function __construct(
        private readonly FlowRouteBindingConflictDetector $conflicts,
    ) {}

    public function enable(FlowRoute $route): FlowRouteTriggerBinding
    {
        $this->ensureCurrent($route);

        if ($route->isArchivedFromAuthoring()) {
            throw ValidationException::withMessages([
                'flow_route' => 'That automation has been deleted and cannot be turned on.',
            ]);
        }

        if (! filled($route->trigger_key)) {
            throw ValidationException::withMessages([
                'flow_route' => 'This automation has no automatic starting event.',
            ]);
        }

        if (! $route->activeFlowRoutePoints()->exists()) {
            throw ValidationException::withMessages([
                'flow_route' => 'Add at least one action before turning this automation on.',
            ]);
        }

        return DB::transaction(function () use ($route): FlowRouteTriggerBinding {
            $triggerRoutes = FlowRoute::query()
                ->currentVersion()
                ->forTrigger((string) $route->trigger_type, $route->trigger_key)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $locked = $triggerRoutes->firstWhere('id', (int) $route->getKey())
                ?? FlowRoute::query()->lockForUpdate()->findOrFail($route->getKey());
            $locked->load('activeFlowRoutePoints');

            $this->conflicts->assertNoConflicts($locked);

            $locked->forceFill(['is_active' => true])->save();

            $binding = FlowRouteTriggerBinding::query()->firstOrNew([
                'trigger_type' => $locked->trigger_type,
                'trigger_key' => $locked->trigger_key,
                'flow_route_id' => $locked->getKey(),
                'context_type' => null,
                'context_id' => null,
            ]);

            $binding->forceFill([
                'is_active' => true,
                'meta' => array_replace_recursive($binding->meta ?? [], [
                    'selection' => [
                        'source' => 'crm',
                        'selected_at' => now()->toISOString(),
                    ],
                ]),
            ])->save();

            return $binding;
        });
    }

    public function disable(FlowRoute $route): void
    {
        $this->ensureCurrent($route);

        $route->activeTriggerBindings()
            ->global()
            ->update(['is_active' => false]);
    }

    public function changeKind(FlowRoute $route, string $kind): void
    {
        $this->ensureCurrent($route);

        if (! in_array($kind, FlowRoute::AUTHORING_KINDS, true)) {
            throw ValidationException::withMessages([
                'authoring_kind' => 'Choose Route or Automatic behavior.',
            ]);
        }

        if ($kind === FlowRoute::AUTHORING_KIND_AUTOMATIC_BEHAVIOR
            && $route->activeFlowRoutePoints()->count() !== 1
        ) {
            throw ValidationException::withMessages([
                'authoring_kind' => 'An Automatic behavior must contain exactly one action.',
            ]);
        }

        $route->forceFill([
            'is_customized' => true,
            'customized_at' => now(),
            'meta' => array_replace_recursive($route->meta ?? [], [
                'authoring' => [
                    'kind' => $kind,
                    'kind_changed_at' => now()->toISOString(),
                ],
            ]),
        ])->save();
    }

    public function archive(FlowRoute $route): void
    {
        $this->ensureCurrent($route);

        if ($route->activeContactFlowRouteProgress()->exists()) {
            throw ValidationException::withMessages([
                'flow_route' => 'This automation is currently running for at least one contact. Turn it off and let active work finish before deleting it.',
            ]);
        }

        DB::transaction(function () use ($route): void {
            $this->disable($route);

            $route->forceFill([
                'is_active' => false,
                'is_customized' => true,
                'customized_at' => now(),
                'meta' => array_replace_recursive($route->meta ?? [], [
                    'authoring' => [
                        'archived_at' => now()->toISOString(),
                    ],
                ]),
            ])->save();
        });
    }

    private function ensureCurrent(FlowRoute $route): void
    {
        if (! $route->is_current_version) {
            throw ValidationException::withMessages([
                'flow_route' => 'Only the current version can be changed.',
            ]);
        }
    }
}