<?php

namespace App\Modules\Core\Contracts\Contacts;

use App\Modules\Core\Models\Contact;

interface ContactPanelProvider
{
    public function panels(Contact $contact): array;
}