<?php

namespace App\Modules\InboundMessaging\Actions\Sms;

use App\Modules\Core\Models\Contact;
use App\Modules\InboundMessaging\Models\InboundMessage;
use App\Modules\InboundMessaging\Services\Reply\InboundReplyIntentClassifier;
use App\Modules\InboundMessaging\Services\Reply\InboundReplyTextNormalizer;
use App\Modules\InboundMessaging\Services\Reply\InboundSmsReplyCorrelator;
use App\Modules\InboundMessaging\Services\Sms\InboundSmsSenderResolver;
use App\Modules\Messaging\Enums\MessageChannel;
use App\Modules\Messaging\Enums\MessagePurpose;
use App\Modules\Messaging\Models\ConsentRevocation;
use App\Modules\Messaging\Models\MessageConsent;
use BackedEnum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class ReconcileInboundSmsHistoryAction
{
    public function __construct(
        private readonly InboundSmsSenderResolver $senderResolver,
        private readonly InboundSmsReplyCorrelator $replyCorrelator,
        private readonly InboundReplyTextNormalizer $replyTextNormalizer,
        private readonly InboundReplyIntentClassifier $replyIntentClassifier,
    ) {}

    /**
     * @return array{
     *     unmatched_sms: int,
     *     resolvable: int,
     *     unresolved: int,
     *     manual_link_conflicts: int,
     *     correlatable_normal_replies: int,
     *     repairable_stop_messages: int
     * }
     */
    public function inspect(): array
    {
        $summary = [
            'unmatched_sms' => 0,
            'resolvable' => 0,
            'unresolved' => 0,
            'manual_link_conflicts' => 0,
            'correlatable_normal_replies' => 0,
            'repairable_stop_messages' => 0,
        ];

        $this->candidateQuery()
            ->orderBy('id')
            ->chunkById(250, function ($messages) use (&$summary): void {
                foreach ($messages as $message) {
                    $summary['unmatched_sms']++;
                    $contact = $this->senderResolver->resolve(
                        $message->from_value,
                    );

                    if (! $contact instanceof Contact) {
                        $summary['unresolved']++;
                        continue;
                    }

                    if ($this->manualLinkConflicts($message, $contact)) {
                        $summary['manual_link_conflicts']++;
                        continue;
                    }

                    $summary['resolvable']++;

                    if ($message->classification === InboundMessage::CLASSIFICATION_NORMAL_REPLY
                        && $message->correlated_scheduled_message_id === null
                        && $this->replyCorrelator->correlate(
                            contact: $contact,
                            fromValue: $message->from_value,
                            receivedAt: $message->received_at,
                        ) !== null
                    ) {
                        $summary['correlatable_normal_replies']++;
                    }

                    if ($message->classification === InboundMessage::CLASSIFICATION_CONSENT_REVOCATION) {
                        $summary['repairable_stop_messages']++;
                    }
                }
            });

        return $summary;
    }

    /**
     * @return array{
     *     examined: int,
     *     linked: int,
     *     unresolved: int,
     *     manual_link_conflicts: int,
     *     replies_correlated: int,
     *     stop_messages_processed: int,
     *     stop_revocations_created: int
     * }
     */
    public function handle(): array
    {
        $summary = [
            'examined' => 0,
            'linked' => 0,
            'unresolved' => 0,
            'manual_link_conflicts' => 0,
            'replies_correlated' => 0,
            'stop_messages_processed' => 0,
            'stop_revocations_created' => 0,
        ];

        $this->candidateQuery()
            ->select('id')
            ->orderBy('id')
            ->chunkById(250, function ($messages) use (&$summary): void {
                foreach ($messages as $message) {
                    $this->reconcileOne((int) $message->getKey(), $summary);
                }
            });

        return $summary;
    }

    /**
     * @param array<string, int> $summary
     */
    private function reconcileOne(int $messageId, array &$summary): void
    {
        DB::transaction(function () use ($messageId, &$summary): void {
            $message = InboundMessage::query()
                ->lockForUpdate()
                ->find($messageId);

            if (! $message instanceof InboundMessage
                || $this->hasContactSender($message)
            ) {
                return;
            }

            $summary['examined']++;
            $contact = $this->senderResolver->resolve($message->from_value);

            if (! $contact instanceof Contact) {
                $summary['unresolved']++;
                return;
            }

            if ($this->manualLinkConflicts($message, $contact)) {
                $summary['manual_link_conflicts']++;
                return;
            }

            $message->forceFill([
                'sender_type' => $contact->getMorphClass(),
                'sender_id' => $contact->getKey(),
            ]);
            $summary['linked']++;

            if ($message->classification === InboundMessage::CLASSIFICATION_NORMAL_REPLY) {
                $this->reconcileNormalReply($message, $contact, $summary);
            }

            if ($message->classification === InboundMessage::CLASSIFICATION_CONSENT_REVOCATION) {
                $created = $this->reconcileStop($message, $contact);
                $summary['stop_revocations_created'] += $created;
                $summary['stop_messages_processed']++;

                if ($message->processed_at === null) {
                    $message->processed_at = now();
                }
            }

            $message->save();
        }, 3);
    }

    /** @param array<string, int> $summary */
    private function reconcileNormalReply(
        InboundMessage $message,
        Contact $contact,
        array &$summary,
    ): void {
        if ($message->correlated_scheduled_message_id !== null) {
            return;
        }

        $correlated = $this->replyCorrelator->correlate(
            contact: $contact,
            fromValue: $message->from_value,
            receivedAt: $message->received_at,
        );

        if ($correlated === null) {
            if ($message->reply_correlation_method === null) {
                $message->reply_correlation_method = 'none';
            }

            return;
        }

        $normalizedText = $this->replyTextNormalizer->normalize($message->body);
        $intent = $this->replyIntentClassifier->classify(
            $correlated->replyProfileKey(),
            $normalizedText,
        );

        $message->forceFill([
            'correlated_scheduled_message_id' => $correlated->getKey(),
            'purpose' => $this->enumValue($correlated->purpose),
            'scope' => $correlated->scope,
            'reply_intent_key' => $intent,
            'reply_correlation_method' => 'heuristic',
        ]);
        $summary['replies_correlated']++;
    }

    private function reconcileStop(
        InboundMessage $message,
        Contact $contact,
    ): int {
        $receivedAt = $message->received_at
            ?? $message->created_at
            ?? now();
        $purposes = $this->stopPurposes(
            message: $message,
            contact: $contact,
            receivedAt: $receivedAt,
        );
        $created = 0;

        foreach ($purposes as $purpose) {
            if ($this->stopRevocationExists(
                message: $message,
                contact: $contact,
                purpose: $purpose,
                receivedAt: $receivedAt,
            )) {
                continue;
            }

            $consent = MessageConsent::query()
                ->where('contact_id', $contact->getKey())
                ->where('channel', MessageChannel::Sms->value)
                ->where('purpose', $purpose)
                ->where('consented_at', '<=', $receivedAt)
                ->orderByDesc('consented_at')
                ->orderByDesc('id')
                ->first();

            ConsentRevocation::query()->create([
                'contact_id' => $contact->getKey(),
                'message_consent_id' => $consent?->getKey(),
                'channel' => MessageChannel::Sms->value,
                'purpose' => $purpose,
                'scope' => 'channel_purpose',
                'reason' => ConsentRevocation::REASON_STOP,
                'revoked_at' => $receivedAt,
                'source' => $this->source($message),
                'meta' => [
                    'reason_context' => 'inbound_stop_keyword',
                    'inbound_message_id' => $message->getKey(),
                    'reconciliation' => [
                        'source' => 'inbound_sms_history',
                        'reconciled_at' => now()->toISOString(),
                    ],
                    'consent' => [
                        'permission_boundary' => 'channel_purpose',
                        'requested_scope' => null,
                        'related_consent_scope' => $consent?->scope,
                    ],
                ],
            ]);
            $created++;
        }

        return $created;
    }

    /** @return array<int, string> */
    private function stopPurposes(
        InboundMessage $message,
        Contact $contact,
        Carbon $receivedAt,
    ): array {
        $purpose = $this->enumValue($message->purpose);

        if ($purpose !== null) {
            return [$purpose];
        }

        return MessageConsent::query()
            ->where('contact_id', $contact->getKey())
            ->where('channel', MessageChannel::Sms->value)
            ->where('consented_at', '<=', $receivedAt)
            ->pluck('purpose')
            ->map(fn (mixed $value): ?string => $this->enumValue($value))
            ->filter(fn (?string $value): bool => $value !== null)
            ->unique()
            ->values()
            ->all();
    }

    private function stopRevocationExists(
        InboundMessage $message,
        Contact $contact,
        string $purpose,
        Carbon $receivedAt,
    ): bool {
        return ConsentRevocation::query()
            ->where('contact_id', $contact->getKey())
            ->where('channel', MessageChannel::Sms->value)
            ->where('purpose', $purpose)
            ->where('reason', ConsentRevocation::REASON_STOP)
            ->where('revoked_at', $receivedAt)
            ->where(function (Builder $query) use ($message): void {
                $query
                    ->where('source', $this->source($message))
                    ->orWhere('meta->inbound_message_id', $message->getKey());
            })
            ->exists();
    }

    private function manualLinkConflicts(
        InboundMessage $message,
        Contact $resolved,
    ): bool {
        return $message->related_contact_id !== null
            && (int) $message->related_contact_id !== (int) $resolved->getKey();
    }

    private function hasContactSender(InboundMessage $message): bool
    {
        if ($message->sender_id === null || ! is_string($message->sender_type)) {
            return false;
        }

        return in_array($message->sender_type, [
            Contact::class,
            (new Contact())->getMorphClass(),
        ], true);
    }

    private function candidateQuery(): Builder
    {
        return InboundMessage::query()
            ->where('channel', MessageChannel::Sms->value)
            ->where(function (Builder $query): void {
                $query
                    ->whereNull('sender_id')
                    ->orWhereNull('sender_type');
            });
    }

    private function source(InboundMessage $message): string
    {
        $provider = trim((string) $message->provider);

        return $provider !== ''
            ? $provider.'_inbound_sms'
            : 'inbound_sms';
    }

    private function enumValue(mixed $value): ?string
    {
        if ($value instanceof MessagePurpose) {
            return $value->value;
        }

        if ($value instanceof BackedEnum) {
            return (string) $value->value;
        }

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }
}