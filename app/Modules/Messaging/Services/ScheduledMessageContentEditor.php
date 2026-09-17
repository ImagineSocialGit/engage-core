<?php

namespace App\Modules\Messaging\Services;

use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Messaging\Models\ScheduledMessageEdit;
use App\Modules\Messaging\Payloads\EmailPayload;
use App\Modules\Messaging\Payloads\SmsPayload;

class ScheduledMessageContentEditor
{
    public function __construct(
        private readonly ScheduledMessageComponentComposer $composer,
        private readonly ScheduledMessageBulkContentRules $bulkRules,
    ) {}

    public function supported(ScheduledMessage $message): bool
    {
        return (($message->channel === 'email' && $message->payload_class === EmailPayload::class)
                || ($message->channel === 'sms' && $message->payload_class === SmsPayload::class))
            && $message->scope !== 'permission_invitation'
            && $message->message_type !== 'imported_contact_permission_invitation';
    }

    /**
     * Original composed template plus the scheduled runtime fields, without manual edits.
     * @return array<string, mixed>
     */
    public function base(ScheduledMessage $message): array
    {
        $version = $message->messageTemplateVersion;
        $template = $version === null
            ? []
            : $this->composer->compose($message, $version->payload());

        return array_replace_recursive(
            $template,
            is_array($message->payload) ? $message->payload : [],
        );
    }

    /** @return array<string, string> */
    public function fields(ScheduledMessage $message): array
    {
        $data = array_replace(
            $this->base($message),
            $this->override($message),
        );

        if ($message->channel === 'email') {
            return [
                'subject' => (string) ($data['subject'] ?? ''),
                'body' => (string) ($data['body'] ?? ''),
            ];
        }

        return ['message' => (string) ($data['message'] ?? $data['body'] ?? '')];
    }

    /** @return array<string, mixed> */
    public function individualOverride(ScheduledMessage $message): array
    {
        $edit = $message->relationLoaded('latestContentEdit')
            ? $message->getRelation('latestContentEdit')
            : $message->latestContentEdit()->first();

        return $edit instanceof ScheduledMessageEdit && is_array($edit->override_payload)
            ? $edit->override_payload
            : [];
    }

    /** @return array<string, mixed> */
    public function override(ScheduledMessage $message): array
    {
        $edit = $message->relationLoaded('latestContentEdit')
            ? $message->getRelation('latestContentEdit')
            : $message->latestContentEdit()->first();

        if ($edit instanceof ScheduledMessageEdit && $edit->action === 'restore') {
            return [];
        }

        $bulk = $this->bulkRules->override($message);
        $individual = $edit instanceof ScheduledMessageEdit && is_array($edit->override_payload)
            ? $edit->override_payload
            : [];

        return array_replace($bulk, $individual);
    }
}