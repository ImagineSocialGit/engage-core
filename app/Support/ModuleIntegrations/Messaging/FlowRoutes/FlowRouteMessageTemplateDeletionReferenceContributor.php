<?php

namespace App\Support\ModuleIntegrations\Messaging\FlowRoutes;

use App\Modules\Messaging\Contracts\MessageTemplateDeletionReferenceContributor;
use App\Modules\Messaging\Data\MessageTemplateDeletionReference;
use App\Modules\Messaging\Models\MessageTemplate;
use App\Modules\Messaging\Models\MessageTemplatePreset;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

final class FlowRouteMessageTemplateDeletionReferenceContributor implements MessageTemplateDeletionReferenceContributor
{
    private const TERMINAL_PLAN_ITEM_STATUSES = [
        'completed',
        'skipped',
        'cancelled',
        'failed',
    ];

    public function references(
        MessageTemplatePreset $preset,
        ?MessageTemplate $template,
    ): iterable {
        $key = trim((string) $preset->key);

        if ($key === '') {
            return;
        }

        $configuredPoints = $this->configuredPointsReferencing($key);

        if ($configuredPoints->isNotEmpty()) {
            yield new MessageTemplateDeletionReference(
                moduleKey: 'flow_routes',
                moduleLabel: 'Flow Routes',
                contextLabel: 'Current route definitions',
                detail: number_format($configuredPoints->count()).' current route '.($configuredPoints->count() === 1 ? 'point selects' : 'points select').' this template.',
                url: Route::has('crm.flow-routes.index')
                    ? route('crm.flow-routes.index')
                    : null,
            );
        }

        $activePlanItems = $this->activePlanItemsReferencing($key);

        if ($activePlanItems->isNotEmpty()) {
            yield new MessageTemplateDeletionReference(
                moduleKey: 'flow_routes',
                moduleLabel: 'Flow Routes',
                contextLabel: 'In-progress route plans',
                detail: number_format($activePlanItems->count()).' in-progress route '.($activePlanItems->count() === 1 ? 'step still needs' : 'steps still need').' this template.',
                url: Route::has('crm.flow-routes.index')
                    ? route('crm.flow-routes.index')
                    : null,
            );
        }
    }

    /**
     * @return Collection<int, object>
     */
    private function configuredPointsReferencing(string $key): Collection
    {
        if (! Schema::hasTable('flow_route_points')
            || ! Schema::hasTable('flow_routes')
            || ! $this->hasColumns('flow_route_points', ['id', 'flow_route_id', 'definition', 'settings'])
            || ! Schema::hasColumn('flow_routes', 'id')
        ) {
            return collect();
        }

        $query = DB::table('flow_route_points as points')
            ->join('flow_routes as routes', 'routes.id', '=', 'points.flow_route_id')
            ->select([
                'points.id',
                'points.flow_route_id',
                'points.definition',
                'points.settings',
            ]);

        if (Schema::hasColumn('flow_routes', 'is_current_version')) {
            $query->where('routes.is_current_version', true);
        }

        return $query
            ->orderBy('points.id')
            ->get()
            ->filter(fn (object $row): bool =>
                $this->referencesTemplateKey($row->definition ?? null, $key)
                || $this->referencesTemplateKey($row->settings ?? null, $key)
            )
            ->values();
    }

    /**
     * @return Collection<int, object>
     */
    private function activePlanItemsReferencing(string $key): Collection
    {
        if (! Schema::hasTable('contact_flow_route_plan_items')
            || ! $this->hasColumns(
                'contact_flow_route_plan_items',
                ['id', 'status', 'definition_snapshot', 'settings_snapshot'],
            )
        ) {
            return collect();
        }

        return DB::table('contact_flow_route_plan_items')
            ->whereNotIn('status', self::TERMINAL_PLAN_ITEM_STATUSES)
            ->select([
                'id',
                'status',
                'definition_snapshot',
                'settings_snapshot',
            ])
            ->orderBy('id')
            ->get()
            ->filter(fn (object $row): bool =>
                $this->referencesTemplateKey($row->definition_snapshot ?? null, $key)
                || $this->referencesTemplateKey($row->settings_snapshot ?? null, $key)
            )
            ->values();
    }

    /**
     * @param array<int, string> $columns
     */
    private function hasColumns(string $table, array $columns): bool
    {
        foreach ($columns as $column) {
            if (! Schema::hasColumn($table, $column)) {
                return false;
            }
        }

        return true;
    }

    private function referencesTemplateKey(mixed $value, string $key): bool
    {
        $decoded = $this->decoded($value);

        if (! is_array($decoded)) {
            return false;
        }

        foreach ($decoded as $field => $candidate) {
            if (in_array($field, ['message_template_key', 'message_template_preset_key'], true)
                && is_string($candidate)
                && trim($candidate) === $key
            ) {
                return true;
            }

            if ($field === 'message_template_keys_by_channel'
                && is_array($candidate)
                && collect($candidate)->contains(
                    fn (mixed $channelKey): bool =>
                        is_string($channelKey) && trim($channelKey) === $key,
                )
            ) {
                return true;
            }

            if (is_array($candidate) && $this->referencesTemplateKey($candidate, $key)) {
                return true;
            }
        }

        return false;
    }

    private function decoded(mixed $value): mixed
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $decoded = json_decode($value, true);

        return json_last_error() === JSON_ERROR_NONE
            ? $decoded
            : null;
    }
}