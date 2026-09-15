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

    /**
     * Remove the current generic Contact status while preserving the Workflow
     * profile and any unrelated Workflow assignment context.
     *
     * @param array<string, mixed> $meta
     */
    public function clear(
        Contact $contact,
        ?string $reason = null,
        ?string $source = null,
        ?Model $actor = null,
        array $meta = [],
    ): Contact;
}