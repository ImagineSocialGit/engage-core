<?php

namespace App\Contracts\CRM\Contacts;

use App\Models\Contact;

interface ContactImportHandler
{
    public function handle(
        Contact $contact,
        array $row,
        array $mapping,
        callable $mappedValue,
    ): void;
}