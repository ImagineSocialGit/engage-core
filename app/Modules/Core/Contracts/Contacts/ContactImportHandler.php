<?php

namespace App\Modules\Core\Contracts\Contacts;

use App\Modules\Core\Data\Contacts\ContactImportContext;

interface ContactImportHandler
{
    public function handle(ContactImportContext $context): void;
}