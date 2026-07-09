<?php

namespace App\Modules\Core\Actions\Contacts;

use App\Modules\Core\Models\ContactStatus;

class ResolveContactStatusAction
{
    public function handle(?string $key = null): ?ContactStatus
    {
        $key ??= config('contacts.default_contact_status_key');

        if (! is_string($key) || trim($key) === '') {
            return null;
        }

        return ContactStatus::query()
            ->where('key', trim($key))
            ->where('is_active', true)
            ->first();
    }
}
