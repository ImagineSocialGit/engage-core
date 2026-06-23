<?php

namespace App\Contracts\Messaging;

use App\Enums\MessageChannel;
use Illuminate\Database\Eloquent\Model;

interface InternalNotificationPreferenceResolver
{
    public function supports(Model $preferenceOwner): bool;

    public function allows(
        Model $preferenceOwner,
        MessageChannel|string $channel,
        ?string $notificationType = null,
    ): bool;
}