<?php

namespace App\Modules\Messaging\Listeners;

use App\Modules\Core\Events\ManualContactCreated;
use App\Modules\Messaging\Actions\GrantMessageConsentsAction;
use App\Modules\Messaging\Enums\MessageChannel;
use App\Modules\Messaging\Enums\MessagePurpose;
use App\Modules\Messaging\Services\MessageChannelAvailability;

final class GrantManualContactCreatedConsents
{
    private const CAPTURE_SCOPE = 'crm_manual_create';

    private const SOURCE = 'crm_manual_create';

    public function __construct(
        private readonly GrantMessageConsentsAction $grantMessageConsents,
        private readonly MessageChannelAvailability $channelAvailability,
    ) {}

    public function handle(ManualContactCreated $event): void
    {
        if (! $event->existingRelationshipConfirmed) {
            return;
        }

        $channels = [];

        if (filled($event->contact->email)
            && $this->channelAvailability->isRuntimeSupported(MessageChannel::Email)
        ) {
            $channels[] = MessageChannel::Email;
        }

        if (filled($event->contact->phone)
            && $this->channelAvailability->isRuntimeSupported(MessageChannel::Sms)
        ) {
            $channels[] = MessageChannel::Sms;
        }

        if ($channels === []) {
            return;
        }

        $consentedAt = now();
        $meta = [
            'crm_manual_create' => array_filter([
                'existing_relationship_confirmed' => true,
                'actor_user_id' => $event->actorUserId,
            ], fn (mixed $value): bool => $value !== null),
        ];

        $grants = [];

        foreach ($channels as $channel) {
            foreach ([MessagePurpose::Transactional, MessagePurpose::Marketing] as $purpose) {
                $grants[] = [
                    'channel' => $channel->value,
                    'purpose' => $purpose->value,
                    'scope' => self::CAPTURE_SCOPE,
                    'consented_at' => $consentedAt,
                    'ip_address' => $event->ipAddress,
                    'user_agent' => $event->userAgent,
                    'source' => self::SOURCE,
                    'meta' => $meta,
                ];
            }
        }

        $this->grantMessageConsents->handle(
            contact: $event->contact,
            grants: $grants,
        );
    }
}