<?php

namespace App\Modules\Core\Contracts\Contacts;

use App\Modules\Core\Models\Contact;

interface ContactImportHandler
{
    public function handle(
        Contact $contact,
        array $row,
        array $mapping,
        callable $mappedValue,
    ): void;
}