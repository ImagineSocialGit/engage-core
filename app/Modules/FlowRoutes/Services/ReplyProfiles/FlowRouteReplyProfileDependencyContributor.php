<?php

namespace App\Modules\FlowRoutes\Services\ReplyProfiles;

use App\Modules\FlowRoutes\Models\FlowRoute;
use App\Modules\FlowRoutes\Models\FlowRoutePoint;
use App\Support\ReplyHandling\Contracts\ReplyProfileDependencyContributor;
use App\Support\ReplyHandling\Data\ReplyProfileDependency;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class FlowRouteReplyProfileDependencyContributor implements ReplyProfileDependencyContributor
{
    public function dependencies(): iterable
    {
        if (! Schema::hasTable('flow_routes') || ! Schema::hasTable('flow_route_points')) {
            return [];
        }

        $dependencies = [];

        foreach (FlowRoute::query()
            ->currentVersion()
            ->with('flowRoutePoints')
            ->orderBy('name')
            ->get() as $route
        ) {
            [$profileKeys, $intentKeys] = $this->references($route->flowRoutePoints->all());

            foreach ($profileKeys as $profileKey) {
                $scopedIntentKeys = $intentKeys !== [] ? $intentKeys : [null];

                foreach ($scopedIntentKeys as $intentKey) {
                    $dependencies[] = new ReplyProfileDependency(
                        key: implode(':', array_filter([
                            'flow_routes',
                            'route',
                            (string) $route->getKey(),
                            $profileKey,
                            $intentKey,
                        ], fn (mixed $value): bool => $value !== null && $value !== '')),
                        profileKey: $profileKey,
                        intentKey: $intentKey,
                        moduleKey: 'flow_routes',
                        type: 'flow_route',
                        label: (string) $route->name,
                        detail: $intentKey !== null
                            ? 'This Route branches on '.Str::headline($intentKey).' replies for this profile.'
                            : 'This Route branches on this reply profile.',
                        active: (bool) $route->is_active,
                        url: route('crm.flow-routes.show', $route),
                    );
                }
            }
        }

        return $dependencies;
    }

    /**
     * @param array<int, FlowRoutePoint> $points
     * @return array{0: array<int, string>, 1: array<int, string>}
     */
    private function references(array $points): array
    {
        $profiles = [];
        $intents = [];

        foreach ($points as $point) {
            foreach ([$point->definition, $point->settings, $point->cancel_conditions] as $value) {
                $this->collect($value, $profiles, $intents);
            }
        }

        $profiles = array_values(array_unique($profiles));
        $intents = array_values(array_unique($intents));
        sort($profiles);
        sort($intents);

        return [$profiles, $intents];
    }

    /** @param array<int, string> $profiles @param array<int, string> $intents */
    private function collect(mixed $value, array &$profiles, array &$intents): void
    {
        if (! is_array($value)) {
            return;
        }

        $path = is_string($value['path'] ?? null)
            ? trim($value['path'])
            : null;

        if ($path !== null) {
            $targets = [];

            if (is_scalar($value['value'] ?? null)) {
                $targets[] = (string) $value['value'];
            }

            if (is_array($value['values'] ?? null)) {
                foreach ($value['values'] as $target) {
                    if (is_scalar($target)) {
                        $targets[] = (string) $target;
                    }
                }
            }

            $targets = array_values(array_filter(array_map(
                fn (string $target): string => trim($target),
                $targets,
            )));

            if (str_ends_with($path, 'reply_profile_key')) {
                array_push($profiles, ...$targets);
            }

            if (str_ends_with($path, 'reply_intent_key')) {
                array_push($intents, ...$targets);
            }
        }

        foreach ($value as $nested) {
            if (is_array($nested)) {
                $this->collect($nested, $profiles, $intents);
            }
        }
    }
}