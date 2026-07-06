<?php

namespace App\Modules\FlowRoutes\Data\Presets;

class FlowRoutePresetSyncResult
{
    /**
     * @var array<string, int>
     */
    public array $created = [
        'flow_routes' => 0,
        'points' => 0,
        'flow_route_points' => 0,
        'flow_route_trigger_bindings' => 0,
    ];

    /**
     * @var array<string, int>
     */
    public array $updated = [
        'flow_routes' => 0,
        'points' => 0,
        'flow_route_points' => 0,
        'flow_route_trigger_bindings' => 0,
    ];

    /**
     * @var array<string, int>
     */
    public array $skipped = [
        'flow_routes' => 0,
        'points' => 0,
        'flow_route_points' => 0,
        'flow_route_trigger_bindings' => 0,
    ];

    /**
     * @var array<int, string>
     */
    public array $warnings = [];

    /**
     * @var array<int, string>
     */
    public array $errors = [];

    public function recordCreated(string $type): void
    {
        $this->created[$type] = ($this->created[$type] ?? 0) + 1;
    }

    public function recordUpdated(string $type): void
    {
        $this->updated[$type] = ($this->updated[$type] ?? 0) + 1;
    }

    public function recordSkipped(string $type): void
    {
        $this->skipped[$type] = ($this->skipped[$type] ?? 0) + 1;
    }

    public function warn(string $message): void
    {
        $this->warnings[] = $message;
    }

    public function error(string $message): void
    {
        $this->errors[] = $message;
    }

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }

    /**
     * @return array{
     *     created: array<string, int>,
     *     updated: array<string, int>,
     *     skipped: array<string, int>,
     *     warnings: array<int, string>,
     *     errors: array<int, string>
     * }
     */
    public function toArray(): array
    {
        return [
            'created' => $this->created,
            'updated' => $this->updated,
            'skipped' => $this->skipped,
            'warnings' => $this->warnings,
            'errors' => $this->errors,
        ];
    }
}
