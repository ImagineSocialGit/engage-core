<?php

namespace App\Modules\Messaging\Services;

use App\Modules\Messaging\Models\MessageChainEnrollment;
use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Messaging\Models\ScheduledMessageBulkEdit;
use App\Modules\Messaging\Payloads\EmailPayload;
use App\Modules\Messaging\Payloads\SmsPayload;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class ScheduledMessageBulkContentRules
{
    /** @return array<string, mixed> */
    public function override(ScheduledMessage $message): array
    {
        if ($message->message_template_version_id === null
            || ! (($message->channel === 'email' && $message->payload_class === EmailPayload::class)
                || ($message->channel === 'sms' && $message->payload_class === SmsPayload::class))
            || $message->scope === 'permission_invitation'
            || $message->message_type === 'imported_contact_permission_invitation'
            || ! in_array($message->status, [
                ScheduledMessage::STATUS_PENDING,
                ScheduledMessage::STATUS_SENDING,
                ScheduledMessage::STATUS_SENT,
            ], true)
        ) {
            return [];
        }

        $rules = ScheduledMessageBulkEdit::query()
            ->where('message_template_version_id', $message->message_template_version_id)
            ->where('maximum_scheduled_message_id', '>=', $message->getKey())
            ->orderByDesc('id')
            ->get();

        if ($rules->isEmpty()) {
            return [];
        }

        // Appended components can carry mandatory text. A bulk body replacement
        // must never remove those components from the provider-ready message.
        if ($message->components()->exists()) {
            return [];
        }

        $origin = $message->relationLoaded('messageChainEnrollment')
            ? $message->getRelation('messageChainEnrollment')
            : $message->messageChainEnrollment()->first();

        if (! $origin instanceof MessageChainEnrollment) {
            return [];
        }

        // An occurrence-specific edit wins over its series rule even when the
        // series rule was created later.
        foreach (['webinar', 'campaign', 'webinar_series'] as $scope) {
            foreach ($rules as $rule) {
                if ($rule->source_scope !== $scope
                    || $rule->channel !== $message->channel
                    || ! $this->matches($origin, $rule)
                ) {
                    continue;
                }

                if (in_array($message->status, [
                    ScheduledMessage::STATUS_SENDING,
                    ScheduledMessage::STATUS_SENT,
                ], true)) {
                    $claimed = $message->latestDeliveryAttempt()->first();

                    if ($claimed === null
                        || $claimed->claimed_at === null
                        || $claimed->claimed_at->lessThanOrEqualTo($rule->created_at)
                    ) {
                        continue;
                    }
                }

                return is_array($rule->override_payload)
                    ? $rule->override_payload
                    : [];
            }
        }

        return [];
    }

    private function matches(
        MessageChainEnrollment $enrollment,
        ScheduledMessageBulkEdit $rule,
    ): bool {
        $origin = config('modules.outbound_message_sources', []);
        $webinarType = $origin['webinars']['origin'][0] ?? null;
        $campaignType = $origin['campaigns']['origin'][0] ?? null;

        return match ($rule->source_scope) {
            'webinar' => $enrollment->origin_type === $webinarType
                && (int) $enrollment->origin_id === $rule->source_id,
            'campaign' => $enrollment->origin_type === $campaignType
                && (int) $enrollment->origin_id === $rule->source_id,
            'webinar_series' => $enrollment->origin_type === $webinarType
                && DB::table('webinars')
                    ->where('id', $enrollment->origin_id)
                    ->where('webinar_series_id', $rule->source_id)
                    ->exists(),
            default => false,
        };
    }

    /** @return Builder<ScheduledMessage> */
    public function eligible(
        OutboundMessageIndex $index,
        \App\Models\User $user,
        string $scope,
        int $id,
        int $versionId,
    ): Builder {
        return $index->query($user, [
            'scope' => $scope,
            'scope_id' => $id,
            'period' => 'all',
        ])->where('status', ScheduledMessage::STATUS_PENDING)
            ->whereIn('operational_state', [
                ScheduledMessage::OPERATIONAL_ACTIVE,
                ScheduledMessage::OPERATIONAL_HELD,
            ])
            ->whereDoesntHave('components')
            ->where('scope', '!=', 'permission_invitation')
            ->where('message_type', '!=', 'imported_contact_permission_invitation')
            ->where(function (Builder $supported): void {
                $supported->where(fn (Builder $email) => $email
                    ->where('channel', 'email')
                    ->where('payload_class', EmailPayload::class))
                    ->orWhere(fn (Builder $sms) => $sms
                        ->where('channel', 'sms')
                        ->where('payload_class', SmsPayload::class));
            })
            ->where('message_template_version_id', $versionId);
    }
}