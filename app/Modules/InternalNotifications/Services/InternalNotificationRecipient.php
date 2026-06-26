<?php

namespace App\Modules\InternalNotifications\Services;

use Illuminate\Database\Eloquent\Model;

class InternalNotificationRecipient
{
    public function __construct(
        public readonly Model $source,
        public readonly string $name,
        public readonly ?string $email,
        public readonly ?string $phone,
        public readonly string $notificationType,
        public readonly ?Model $preferenceOwner = null,
    ) {}
}