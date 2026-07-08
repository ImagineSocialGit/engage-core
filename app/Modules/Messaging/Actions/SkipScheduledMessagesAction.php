<?php

namespace App\Modules\Messaging\Actions;

use App\Modules\Core\Models\ContactImportBatch;
use App\Modules\Messaging\Enums\MessageChannel;
use App\Modules\Messaging\Enums\MessagePurpose;
use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Messaging\Services\ContactPermissionInvitationService;
use Illuminate\Database\Eloquent\Model;

class SkipScheduledMessagesAction
{
    public function forContext(Model $context, ?string $reason = null): int
    {
        return ScheduledMessage::query()
            ->where('context_type', $context->getMorphClass())
            ->where('context_id', $context->getKey())
            ->where('status', ScheduledMessage::STATUS_PENDING)
            ->update([
                'status' => ScheduledMessage::STATUS_SKIPPED,
                'skipped_at' => now(),
                'skip_reason' => $reason,
            ]);
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

        return ScheduledMessage::query()
            ->where('context_type', $context->getMorphClass())
            ->where('context_id', $context->getKey())
            ->where('meta->'.$key, $value)
            ->where('status', ScheduledMessage::STATUS_PENDING)
            ->update([
                'status' => ScheduledMessage::STATUS_SKIPPED,
                'skipped_at' => now(),
                'skip_reason' => $reason,
                'failure_reason' => null,
            ]);
    }

    public function importedContactPermissionInvitationsForImportBatch(
        ContactImportBatch $importBatch,
        ?string $reason = null,
    ): int {
        return ScheduledMessage::query()
            ->where('context_type', $importBatch->getMorphClass())
            ->where('context_id', $importBatch->getKey())
            ->where('channel', MessageChannel::Email->value)
            ->where('purpose', MessagePurpose::Marketing->value)
            ->where('scope', 'broadcast')
            ->where('message_type', ContactPermissionInvitationService::MESSAGE_TYPE_IMPORTED_CONTACT_PERMISSION_INVITATION)
            ->where('status', ScheduledMessage::STATUS_PENDING)
            ->update([
                'status' => ScheduledMessage::STATUS_SKIPPED,
                'skipped_at' => now(),
                'skip_reason' => $reason ?: 'permission_invitation_cancelled',
                'failure_reason' => null,
            ]);
    }

    public function forMetaValue(string $key, mixed $value, ?string $reason = null): int
    {
        $key = trim($key);

        if ($key === '') {
            return 0;
        }

        return ScheduledMessage::query()
            ->where('meta->'.$key, $value)
            ->where('status', ScheduledMessage::STATUS_PENDING)
            ->update([
                'status' => ScheduledMessage::STATUS_SKIPPED,
                'skipped_at' => now(),
                'skip_reason' => $reason,
            ]);
    }
}
