<?php

namespace App\Modules\Messaging\Actions;

use App\Models\User;
use App\Modules\Core\Access\Services\UserAccessService;
use App\Modules\Messaging\Models\MessageTemplateVersion;
use App\Modules\Messaging\Models\ScheduledMessageBulkEdit;
use App\Modules\Messaging\Payloads\EmailPayload;
use App\Modules\Messaging\Payloads\SmsPayload;
use App\Modules\Messaging\Services\MessageTemplateTokenValidator;
use App\Modules\Messaging\Services\OutboundMessageIndex;
use App\Modules\Messaging\Services\ScheduledMessageBulkContentRules;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EditScheduledMessageBulkContentAction
{
    public function __construct(
        private readonly OutboundMessageIndex $index,
        private readonly ScheduledMessageBulkContentRules $rules,
        private readonly MessageTemplateTokenValidator $tokens,
        private readonly UserAccessService $access,
    ) {}

    /** @param array<string, string> $fields */
    public function save(
        User $actor,
        string $scope,
        int $sourceId,
        int $versionId,
        array $fields,
        ?string $reason = null,
    ): ScheduledMessageBulkEdit {
        return $this->write($actor, $scope, $sourceId, $versionId, $fields, 'save', $reason);
    }

    public function clear(
        User $actor,
        string $scope,
        int $sourceId,
        int $versionId,
        ?string $reason = null,
    ): ScheduledMessageBulkEdit {
        return $this->write($actor, $scope, $sourceId, $versionId, [], 'clear', $reason);
    }

    /** @param array<string, string> $fields */
    private function write(
        User $actor,
        string $scope,
        int $sourceId,
        int $versionId,
        array $fields,
        string $action,
        ?string $reason,
    ): ScheduledMessageBulkEdit {
        if (! $this->access->allows($actor, 'contacts.view_all')
            || ! $this->access->allows($actor, 'contacts.manage')
            || ! in_array($scope, ['webinar', 'webinar_series', 'campaign'], true)
            || ! module_enabled($scope === 'campaign' ? 'campaigns' : 'webinars')
        ) {
            abort(403);
        }

        $version = MessageTemplateVersion::query()->findOrFail($versionId);
        $base = $version->payload();

        return DB::transaction(function () use (
            $actor, $scope, $sourceId, $versionId, $fields,
            $action, $reason, $base,
        ): ScheduledMessageBulkEdit {
            $eligible = $this->rules->eligible(
                $this->index, $actor, $scope, $sourceId, $versionId,
            );
            $sample = (clone $eligible)->orderBy('scheduled_messages.id')->firstOrFail();
            $count = (clone $eligible)->count();
            $maximumId = (int) (clone $eligible)->max('scheduled_messages.id');
            $channel = (string) $sample->channel;
            $override = [];

            if ($action === 'save') {
                $keys = $channel === 'email' ? ['subject', 'body'] : ['message'];

                foreach ($keys as $key) {
                    $value = $fields[$key] ?? null;
                    $limit = $key === 'subject' ? 998 : ($key === 'message' ? 4096 : 32768);

                    if (! is_string($value) || trim($value) === '' || strlen($value) > $limit) {
                        throw ValidationException::withMessages([
                            $key => 'Enter content within the allowed size.',
                        ]);
                    }

                    // A rule is independent of per-recipient runtime fields.
                    // Persist complete authored fields so later content edits do
                    // not inherit a different individual's runtime copy.
                    $override[$key] = $value;
                }

                $addedTokens = array_diff(
                    $this->tokens->resolvableTokensFromPayload(array_replace($base, $override)),
                    $this->tokens->resolvableTokensFromPayload($base),
                );

                if ($addedTokens !== []) {
                    throw ValidationException::withMessages([
                        'content' => 'Bulk content cannot introduce a token absent from the pinned template.',
                    ]);
                }
            }

            return ScheduledMessageBulkEdit::query()->create([
                'source_scope' => $scope,
                'source_id' => $sourceId,
                'message_template_version_id' => $versionId,
                'maximum_scheduled_message_id' => $maximumId,
                'channel' => $channel,
                'matching_count_at_creation' => $count,
                'override_payload' => $override,
                'action' => $action,
                'actor_id' => $actor->getKey(),
                'actor_email' => $actor->email,
                'reason' => filled($reason) ? trim($reason) : null,
            ]);
        }, 3);
    }
}