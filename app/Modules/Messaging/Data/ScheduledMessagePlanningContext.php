<?php

namespace App\Modules\Messaging\Data;

use Illuminate\Database\Eloquent\Model;

final class ScheduledMessagePlanningContext
{
    public function __construct(
        public readonly Model $recipient,
        public readonly string $channel,
        public readonly string $purpose,
        public readonly string $scope,
        public readonly string $messageType,
        public readonly ?Model $context = null,
        public readonly ?Model $behaviorOwner = null,
        public readonly ?string $dedupeKey = null,
    ) {}
}