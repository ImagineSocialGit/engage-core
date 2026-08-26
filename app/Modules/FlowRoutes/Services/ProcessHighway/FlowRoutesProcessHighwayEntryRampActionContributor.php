<?php

namespace App\Modules\FlowRoutes\Services\ProcessHighway;

use App\Support\ProcessHighway\Contracts\ProcessHighwayEntryRampActionContributor;

class FlowRoutesProcessHighwayEntryRampActionContributor implements ProcessHighwayEntryRampActionContributor
{
    public function criterionKey(): string
    {
        return 'status';
    }

    public function actions(string $value, array $node): array
    {
        $value = trim($value);

        if ($value === '') {
            return [];
        }

        return [[
            'key' => 'flow_routes:create_for_status',
            'label' => 'Automate something for this status',
            'detail' => 'Create a Route for this Status. It stays unassigned until you choose it in Assignments.',
            'url' => route('crm.flow-routes.index', [
                'create' => 1,
                'status' => $value,
            ]),
            'owner_key' => 'flow_routes',
            'owner_label' => 'Flow Routes',
        ]];
    }
}