<?php

namespace App\Modules\Messaging\Actions;

use App\Modules\Core\Models\ContactImportBatch;
use App\Modules\Messaging\Enums\MessageChannel;
use App\Modules\Messaging\Enums\MessagePurpose;
use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Messaging\Services\ContactPermissionInvitationService;
use App\Modules\Messaging\Services\ScheduledMessageEventOutbox;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class SkipScheduledMessagesAction
{
    public function __construct(
        private readonly ScheduledMessageEventOutbox $eventOutbox,
    ) {}

    public function forContext(Model $context, ?string $reason = null): int
    {
        return $this->skip(
            query: ScheduledMessage::query()
                ->where('context_type', $context->getMorphClass())
                ->where('context_id', $context->getKey()),
            reason: $reason,
        );
    }

    public function forContextMetaValue(
        Model $context,
        string $key,
        mixed $value,
        ?string $reason = null,
    ): int {
        $key = trim($key);

        if ($key === '') {
            return 0;
        }

        return $this->skip(
            query: ScheduledMessage::query()
                ->where('context_type', $context->getMorphClass())
                ->where('context_id', $context->getKey())
                ->where('meta->'.$key, $value),
            reason: $reason,
            clearFailureReason: true,
        );
    }

    public function importedContactPermissionInvitationsForImportBatch(
        ContactImportBatch $importBatch,
        ?string $reason = null,
    ): int {
        return $this->skip(
            query: ScheduledMessage::query()
                ->where('context_type', $importBatch->getMorphClass())
                ->where('context_id', $importBatch->getKey())
                ->where('channel', MessageChannel::Email->value)
                ->where('purpose', MessagePurpose::Marketing->value)
                ->where('scope', 'broadcast')
                ->where('message_type', ContactPermissionInvitationService::MESSAGE_TYPE_IMPORTED_CONTACT_PERMISSION_INVITATION),
            reason: $reason ?: 'permission_invitation_cancelled',
            clearFailureReason: true,
        );
    }

    public function forMetaValue(string $key, mixed $value, ?string $reason = null): int
    {
        $key = trim($key);

        if ($key === '') {
            return 0;
        }

        return $this->skip(
            query: ScheduledMessage::query()->where('meta->'.$key, $value),
            reason: $reason,
        );
    }

    /**
     * @param Builder<ScheduledMessage> $query
     */
    private function skip(
        Builder $query,
        ?string $reason,
        bool $clearFailureReason = false,
    ): int {
        return DB::transaction(function () use ($query, $reason, $clearFailureReason): int {
            $ids = $query
                ->where('status', ScheduledMessage::STATUS_PENDING)
                ->lockForUpdate()
                ->orderBy('id')
                ->pluck('id')
                ->map(fn (mixed $id): int => (int) $id)
                ->all();

            if ($ids === []) {
                return 0;
            }

            $updates = [
                'status' => ScheduledMessage::STATUS_SKIPPED,
                'skipped_at' => now(),
                'skip_reason' => $reason,
            ];

            if ($clearFailureReason) {
                $updates['failure_reason'] = null;
            }

            $updated = ScheduledMessage::query()
                ->whereKey($ids)
                ->where('status', ScheduledMessage::STATUS_PENDING)
                ->update($updates);

            if ($updated < 1) {
                return 0;
            }

            ScheduledMessage::query()
                ->whereKey($ids)
                ->where('status', ScheduledMessage::STATUS_SKIPPED)
                ->orderBy('id')
                ->get()
                ->each(function (ScheduledMessage $scheduledMessage): void {
                    $this->eventOutbox->record(
                        scheduledMessage: $scheduledMessage,
                        eventType: ScheduledMessage::STATUS_SKIPPED,
                        occurredAt: $scheduledMessage->skipped_at,
                    );
                });

            return $updated;
        });
    }
}