<?php

namespace App\Modules\Workflow\Services\ProcessHighway;

use App\Modules\Core\Models\ContactStatus;
use App\Modules\Workflow\Models\ContactWorkflowProfile;
use App\Support\ProcessHighway\Contracts\ProcessHighwayEntryRampContributor;
use Illuminate\Support\Facades\Schema;

final class WorkflowProcessHighwayEntryRampContributor implements ProcessHighwayEntryRampContributor
{
    public function criterionKey(): string
    {
        return 'status';
    }

    public function inspect(string $value, array $node): array
    {
        $status = Schema::hasTable('contact_statuses')
            ? ContactStatus::query()->where('key', $value)->first()
            : null;
        $contactCount = $status instanceof ContactStatus
            && Schema::hasTable('contact_workflow_profiles')
                ? ContactWorkflowProfile::query()
                    ->where('contact_status_id', $status->getKey())
                    ->count()
                : 0;

        return [
            'contact_count' => $contactCount,
            'application_sources' => [
                [
                    'key' => 'workflow:contact_editor',
                    'label' => 'Contact workspace',
                    'detail' => 'A user can assign this status directly from a Contact workspace.',
                ],
                [
                    'key' => 'workflow:contact_import',
                    'label' => 'Contact import',
                    'detail' => 'A CSV import treatment can assign this status to imported contacts.',
                ],
            ],
        ];
    }
}