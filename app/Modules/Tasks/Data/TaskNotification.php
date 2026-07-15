<?php

namespace App\Modules\Tasks\Data;

use Illuminate\Database\Eloquent\Model;

class TaskNotification
{
    /**
     * @param array<string, mixed> $content
     * @param array<string, mixed>|null $meta
     */
    public function __construct(
        public readonly TaskRecipient $recipient,
        public readonly string $notificationType,
        public readonly string $scope,
        public readonly string $messageType,
        public readonly array $content,
        public readonly ?Model $context = null,
        public readonly ?string $dedupeKey = null,
        public readonly ?array $meta = null,
    ) {}
}
