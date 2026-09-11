<?php

namespace App\Modules\Core\Data\Contacts;

final readonly class ContactResultAction
{
    public function __construct(
        public string $key,
        public string $label,
        public string $description,
        public string $view,
        public string $capability,
        public int $sort = 100,
        public array $data = [],
        public string $groupKey = 'more',
        public string $groupLabel = 'More actions',
        public string $groupDescription = 'Additional actions available for this result set.',
        public int $groupSort = 100,
    ) {}
}