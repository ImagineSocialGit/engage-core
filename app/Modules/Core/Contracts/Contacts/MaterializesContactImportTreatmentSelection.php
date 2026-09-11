<?php

namespace App\Modules\Core\Contracts\Contacts;

use App\Modules\Core\Data\Contacts\ContactImportTreatmentSelection;

interface MaterializesContactImportTreatmentSelection
{
    public function materializeSelection(
        ContactImportTreatmentSelection $selection,
    ): ContactImportTreatmentSelection;
}