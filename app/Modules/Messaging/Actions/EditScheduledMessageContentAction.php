<?php

namespace App\Modules\Messaging\Actions;

use App\Models\User;
use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Messaging\Models\ScheduledMessageEdit;
use App\Modules\Messaging\Services\MessageTemplateTokenValidator;
use App\Modules\Messaging\Services\ScheduledMessageContentEditor;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EditScheduledMessageContentAction
{
    public function __construct(
        private readonly ScheduledMessageContentEditor $content,
        private readonly MessageTemplateTokenValidator $tokens,
    ) {}

    /** @param array<string, string> $fields */
    public function save(
        ScheduledMessage $message,
        User $actor,
        array $fields,
        ?string $reason = null,
    ): ?ScheduledMessageEdit {
        return $this->change($message, $actor, $fields, 'edit', $reason);
    }

    public function restore(
        ScheduledMessage $message,
        User $actor,
        ?string $reason = null,
    ): ?ScheduledMessageEdit {
        return $this->change($message, $actor, [], 'restore', $reason);
    }

    /**
     * @param array<string, string> $fields
     */
    private function change(
        ScheduledMessage $message,
        User $actor,
        array $fields,
        string $action,
        ?string $reason,
    ): ?ScheduledMessageEdit {
        return DB::transaction(function () use ($message, $actor, $fields, $action, $reason): ?ScheduledMessageEdit {
            $locked = ScheduledMessage::query()->lockForUpdate()->findOrFail($message->getKey());

            if (! $this->content->supported($locked)
                || $locked->status !== ScheduledMessage::STATUS_PENDING
                || ! in_array($locked->operational_state, [
                    ScheduledMessage::OPERATIONAL_ACTIVE,
                    ScheduledMessage::OPERATIONAL_HELD,
                ], true)
            ) {
                throw ValidationException::withMessages([
                    'scheduled_message' => 'Only a pending email or text message can be edited.',
                ]);
            }

            $base = $this->content->base($locked);
            $previous = $this->content->individualOverride($locked);
            $override = [];

            if ($action === 'edit') {
                $keys = $locked->channel === 'email'
                    ? ['subject', 'body']
                    : ['message'];

                foreach ($keys as $key) {
                    if (! is_string($fields[$key] ?? null) || trim($fields[$key]) === '') {
                        throw ValidationException::withMessages([
                            $key => 'Enter message content before saving.',
                        ]);
                    }

                    $maximumBytes = $key === 'subject' ? 998 : ($key === 'message' ? 4096 : 32768);

                    if (strlen($fields[$key]) > $maximumBytes) {
                        throw ValidationException::withMessages([
                            $key => 'Message content exceeds the allowed size.',
                        ]);
                    }

                    if ($fields[$key] !== ($base[$key] ?? null)) {
                        $override[$key] = $fields[$key];
                    }
                }

                $oldTokens = $this->tokens->resolvableTokensFromPayload($base);
                $newTokens = $this->tokens->resolvableTokensFromPayload(array_replace($base, $override));

                if (array_diff($newTokens, $oldTokens) !== []) {
                    throw ValidationException::withMessages([
                        'content' => 'This edit introduces a token that was not available in the original message.',
                    ]);
                }
            }

            if ($override === $previous
                && ($action !== 'restore'
                    || $locked->latestContentEdit?->action === 'restore'
                    || $this->content->override($locked) === [])
            ) {
                return null;
            }

            return ScheduledMessageEdit::query()->create([
                'scheduled_message_id' => $locked->getKey(),
                'message_template_version_id' => $locked->message_template_version_id,
                'actor_id' => $actor->getKey(),
                'actor_email' => $actor->email,
                'override_payload' => $override,
                'action' => $action,
                'reason' => filled($reason) ? trim($reason) : null,
            ]);
        }, 3);
    }
}