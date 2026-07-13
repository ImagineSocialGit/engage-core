<?php

namespace App\Modules\Webinars\Data;

use App\Modules\Core\Models\Contact;
use App\Modules\Webinars\Models\WebinarRegistration;

readonly class WebinarRegistrationImportResult
{
    public function __construct(
        public Contact $contact,
        public WebinarRegistration $registration,
        public bool $contactCreated,
        public bool $registrationCreated,
        public int $consentsCreated,
        public int $consentsUpdated,
        public int $remindersScheduled,
    ) {}
}
