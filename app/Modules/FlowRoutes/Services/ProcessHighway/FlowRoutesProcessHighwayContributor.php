<?php

namespace App\Modules\FlowRoutes\Services\ProcessHighway;

use App\Support\ProcessHighway\Contracts\ProcessHighwayContributor;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class FlowRoutesProcessHighwayContributor implements ProcessHighwayContributor
{
    /** @return iterable<int, array<string, mixed>> */
    public function processes(): iterable
    {
        if (! $this->available()) {
            return [];
        }

        $routes = DB::table('flow_routes')
            ->where('is_current_version', true)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        if ($routes->isEmpty()) {
            return [];
        }

        $routeIds = $routes
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        $points = DB::table('flow_route_points')
            ->whereIn('flow_route_id', $routeIds)
            ->where('is_active', true)
            ->orderBy('flow_route_id')
            ->orderBy('sort_order')
            ->get()
            ->groupBy(fn (object $point): int => (int) $point->flow_route_id);

        $statusNames = $this->statusNames();

        return $routes
            ->map(function (object $route) use ($points, $statusNames): array {
                $routePoints = $points->get((int) $route->id, collect());
                $meta = $this->jsonArray($route->meta ?? null);
                $definitionMeta = is_array($meta['definition'] ?? null)
                    ? $meta['definition']
                    : [];
                $category = $this->categoryKey($definitionMeta['category'] ?? null);

                return [
                    'source_key' => 'flow_routes',
                    'source_label' => 'Flow Route',
                    'key' => (string) $route->key,
                    'name' => (string) $route->name,
                    'description' => trim((string) ($route->description ?? '')),
                    'category' => $category,
                    'category_label' => $this->categoryLabel($category),
                    'category_priority' => $this->categoryPriority($category),
                    'sort_order' => 100,
                    'state' => 'active',
                    'state_label' => 'Active',
                    'starts_when' => $this->triggerSummary($route, $meta, $statusNames),
                    'steps' => $routePoints
                        ->map(fn (object $point): array => $this->presentPoint($point, $statusNames))
                        ->values()
                        ->all(),
                    'outcomes' => $this->outcomes($routePoints, $statusNames),
                    'details' => [],
                    'attributes' => [
                        'flow_route_id' => (int) $route->id,
                        'trigger_type' => (string) ($route->trigger_type ?? ''),
                        'trigger_key' => (string) ($route->trigger_key ?? ''),
                    ],
                    'edit_url' => route('crm.flow-routes.show', [
                        'flowRoute' => (int) $route->id,
                    ]),
                    'edit_label' => 'Edit Route',
                ];
            })
            ->values()
            ->all();
    }

    private function available(): bool
    {
        return $this->moduleEnabled()
            && Schema::hasTable('flow_routes')
            && Schema::hasTable('flow_route_points');
    }

    private function moduleEnabled(): bool
    {
        return in_array('flow_routes', config('modules.enabled', []), true);
    }

    /** @return array<string, string> */
    private function statusNames(): array
    {
        if (! Schema::hasTable('contact_statuses')) {
            return [];
        }

        return DB::table('contact_statuses')
            ->pluck('name', 'key')
            ->mapWithKeys(fn (mixed $name, mixed $key): array => [
                (string) $key => (string) $name,
            ])
            ->all();
    }

    /** @param array<string, string> $statusNames */
    private function triggerSummary(object $route, array $meta, array $statusNames): string
    {
        $triggerType = (string) ($route->trigger_type ?? 'manual');
        $triggerKey = trim((string) ($route->trigger_key ?? ''));

        if ($triggerType === 'contact_status') {
            $destination = $this->statusName($triggerKey, $statusNames);
            $transition = data_get($meta, 'definition.transition', []);
            $fromKeys = is_array($transition)
                ? array_values(array_filter(
                    $transition['from_contact_status_keys'] ?? [],
                    'is_string',
                ))
                : [];

            if ($fromKeys !== []) {
                $from = implode(' or ', array_map(
                    fn (string $key): string => $this->statusName($key, $statusNames),
                    $fromKeys,
                ));

                return "A contact moves from {$from} to {$destination}.";
            }

            return "A contact becomes {$destination}.";
        }

        if ($triggerType === 'automation_event') {
            return match ($triggerKey) {
                'inbound_message.normal_reply' => 'A contact replies to a message.',
                default => $triggerKey !== ''
                    ? 'The '.Str::lower(Str::headline(str_replace('.', ' ', $triggerKey))).' event happens.'
                    : 'An automation event happens.',
            };
        }

        return 'Someone starts this process manually.';
    }

    /** @param array<string, string> $statusNames */
    private function presentPoint(object $point, array $statusNames): array
    {
        $definition = $this->jsonArray($point->definition ?? null);
        $name = trim((string) ($point->name ?? ''));
        $type = (string) ($point->type ?? '');

        return [
            'name' => $name !== '' ? $name : Str::headline($type),
            'detail' => $this->pointDetail($type, $definition, $statusNames),
        ];
    }

    /**
     * @param array<string, mixed> $definition
     * @param array<string, string> $statusNames
     */
    private function pointDetail(
        string $type,
        array $definition,
        array $statusNames,
    ): ?string {
        return match ($type) {
            'enroll_campaign' => $this->detail('Campaign', $definition['campaign_key'] ?? null),
            'cancel_campaign' => $this->detail('Campaign', $definition['campaign_key'] ?? null),
            'cancel_campaign_family',
            'pause_campaign_family' => $this->detail('Nurture family', $definition['family_key'] ?? null),
            'create_task' => $this->detail('Task', $definition['task_template_key'] ?? null),
            'add_contact_tag',
            'remove_contact_tag' => $this->detail('Tag', $definition['tag'] ?? null),
            'change_status' => isset($definition['contact_status_key'])
                && is_string($definition['contact_status_key'])
                    ? 'Status: '.$this->statusName($definition['contact_status_key'], $statusNames)
                    : null,
            'change_relationship_stage' => $this->relationshipStageDetail($definition),
            'send_message' => $this->messageDetail($definition),
            default => null,
        };
    }

    /**
     * @param Collection<int, object> $points
     * @param array<string, string> $statusNames
     * @return array<int, string>
     */
    private function outcomes(Collection $points, array $statusNames): array
    {
        $outcomeTypes = [
            'enroll_campaign',
            'cancel_campaign',
            'cancel_campaign_family',
            'pause_campaign_family',
            'create_task',
            'add_contact_tag',
            'remove_contact_tag',
            'change_status',
            'change_relationship_stage',
            'send_message',
        ];

        return $points
            ->filter(fn (object $point): bool => in_array(
                (string) $point->type,
                $outcomeTypes,
                true,
            ))
            ->map(function (object $point) use ($statusNames): string {
                $presented = $this->presentPoint($point, $statusNames);

                return $presented['name'];
            })
            ->unique()
            ->values()
            ->all();
    }

    /** @param array<string, mixed> $definition */
    private function relationshipStageDetail(array $definition): ?string
    {
        $relationship = $definition['relationship_key'] ?? null;
        $from = $definition['from_stage_key'] ?? null;
        $to = $definition['stage_key'] ?? null;

        if (! is_string($to) || trim($to) === '') {
            return null;
        }

        $prefix = is_string($relationship) && trim($relationship) !== ''
            ? Str::headline($relationship).': '
            : '';

        if (is_string($from) && trim($from) !== '') {
            return $prefix.Str::headline($from).' → '.Str::headline($to);
        }

        return $prefix.Str::headline($to);
    }

    /** @param array<string, mixed> $definition */
    private function messageDetail(array $definition): ?string
    {
        $parts = array_values(array_filter([
            isset($definition['channel']) && is_string($definition['channel'])
                ? Str::upper($definition['channel'])
                : null,
            isset($definition['scope']) && is_string($definition['scope'])
                ? Str::headline($definition['scope'])
                : null,
        ]));

        return $parts === [] ? null : implode(' · ', $parts);
    }

    private function detail(string $label, mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return $label.': '.Str::headline($value);
    }

    /** @param array<string, string> $statusNames */
    private function statusName(string $key, array $statusNames): string
    {
        return $statusNames[$key] ?? Str::headline($key);
    }

    private function categoryKey(mixed $category): string
    {
        return is_string($category) && trim($category) !== ''
            ? trim($category)
            : 'other';
    }

    private function categoryLabel(string $category): string
    {
        return match ($category) {
            'consumer_lifecycle' => 'Lifecycle',
            'consumer_reply' => 'Consumer replies',
            'realtor_reply' => 'Realtor replies',
            'reply_acknowledgement' => 'Reply acknowledgements',
            default => Str::headline($category),
        };
    }

    private function categoryPriority(string $category): int
    {
        return match ($category) {
            'consumer_lifecycle' => 10,
            'consumer_reply' => 20,
            'realtor_reply' => 30,
            'reply_acknowledgement' => 40,
            default => 100,
        };
    }

    /** @return array<string, mixed> */
    private function jsonArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }
}