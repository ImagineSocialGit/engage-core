<?php

namespace App\Modules\Core\Contracts\Contacts;

use App\Modules\Core\Models\Contact;
use App\Modules\Core\Models\ContactStatus;
use Illuminate\Database\Eloquent\Model;

interface UpdatesContactStatus
{
    /**
     * @param array<string, mixed> $meta
     */
    public function handle(
        Contact $contact,
        ContactStatus $status,
        ?string $reason = null,
        ?string $source = null,
        ?Model $actor = null,
        array $meta = [],
        bool $force = false,
    ): Contact;
}