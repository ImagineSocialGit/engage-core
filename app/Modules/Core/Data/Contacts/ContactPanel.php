<?php

namespace App\Modules\Core\Data\Contacts;

final readonly class ContactPanel
{
    public function __construct(
        public string $key,
        public string $title,
        public string $view,
        public array $data = [],
        public int $sort = 100,
    ) {
    }
}