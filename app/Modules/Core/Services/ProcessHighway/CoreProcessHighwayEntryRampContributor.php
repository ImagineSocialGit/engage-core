<?php

namespace App\Modules\Core\Services\ProcessHighway;

use App\Modules\Core\Models\ContactTag;
use App\Support\ProcessHighway\Contracts\ProcessHighwayEntryRampContributor;
use Illuminate\Support\Facades\Schema;

final class CoreProcessHighwayEntryRampContributor implements ProcessHighwayEntryRampContributor
{
    public function criterionKey(): string
    {
        return 'tag';
    }

    public function inspect(string $value, array $node): array
    {
        $contactCount = Schema::hasTable('contact_tags')
            ? ContactTag::query()
                ->where('tag', $value)
                ->distinct()
                ->count('contact_id')
            : 0;

        return [
            'contact_count' => $contactCount,
            'application_sources' => [[
                'key' => 'core:contact_import',
                'label' => 'Contact import',
                'detail' => 'A CSV import treatment can add this tag to imported contacts.',
            ]],
        ];
    }
}