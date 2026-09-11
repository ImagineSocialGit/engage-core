<?php

namespace App\Modules\Core\Data\Contacts;

final readonly class ContactPanel
{
    public const PLACEMENT_MAIN = 'main';
    public const PLACEMENT_RAIL = 'rail';

    public function __construct(
        public string $key,
        public string $title,
        public string $view,
        public array $data = [],
        public int $sort = 100,
        public string $module = 'core',
        public string $placement = self::PLACEMENT_MAIN,
    ) {
    }
}