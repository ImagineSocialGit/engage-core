<?php

namespace App\Support\Presets\Enums;

enum PresetDomain: string
{
    case ContactStatuses = 'contact_statuses';
    case Tasks = 'tasks';
    case Campaigns = 'campaigns';
    case FlowRoutes = 'flow_routes';

    public function referenceRegistryCategory(): ?string
    {
        return match ($this) {
            self::Tasks => 'task_template_keys',
            self::Campaigns => 'campaign_keys',
            self::FlowRoutes => 'flow_route_keys',
            self::ContactStatuses => null,
        };
    }
}