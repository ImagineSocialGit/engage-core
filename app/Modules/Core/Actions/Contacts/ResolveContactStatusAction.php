<?php

namespace App\Modules\Core\Actions\Contacts;

use App\Modules\Core\Models\ContactStatus;

class ResolveContactStatusAction
{
    public function handle(?string $key = null): ?ContactStatus
    {
        $key ??= 'prospect';

        return ContactStatus::query()
            ->where('key', $key)
            ->where('is_active', true)
            ->first();
    }
}