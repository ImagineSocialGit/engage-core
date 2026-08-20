<?php

namespace App\Modules\Core\Data\Contacts;

use App\Modules\Core\Models\Contact;
use App\Modules\Core\Models\ContactImportBatch;
use App\Modules\Core\Models\ContactImportOccurrence;
use Illuminate\Database\Eloquent\Model;

final readonly class ContactImportTreatmentApplication
{
    /**
     * @param array<int, string> $values
     */
    public function __construct(
        public Contact $contact,
        public ContactImportBatch $batch,
        public ContactImportOccurrence $occurrence,
        public string $targetKey,
        public array $values,
        public ?string $sourceColumn,
        public ?string $sourceValue,
        public ?Model $actor,
    ) {}
}