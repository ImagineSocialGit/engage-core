<?php

namespace App\Contracts\CRM\Contacts;

use App\Models\Contact;

interface ContactPanelProvider
{
    public function panels(Contact $contact): array;
}