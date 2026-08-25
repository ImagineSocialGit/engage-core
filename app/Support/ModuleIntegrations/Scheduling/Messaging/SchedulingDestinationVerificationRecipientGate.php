<?php

namespace App\Support\ModuleIntegrations\Scheduling\Messaging;

use App\Modules\Messaging\Contracts\MessageRecipientGate;
use App\Modules\Messaging\Enums\MessageChannel;
use App\Modules\Messaging\Enums\MessagePurpose;
use App\Modules\Scheduling\Models\BookableService;
use App\Modules\Scheduling\Models\BookableSlotOffer;
use App\Modules\Scheduling\Services\PublicBookingDestinationVerificationService;
use Illuminate\Database\Eloquent\Model;

final class SchedulingDestinationVerificationRecipientGate implements MessageRecipientGate
{
    public function supports(Model $recipient): bool
    {
        return $recipient instanceof BookableSlotOffer;
    }

    public function allows(
        Model $recipient,
        string $channel,
        ?string $type = null,
        array $context = [],
    ): bool {
        return $this->denialReason(
            recipient: $recipient,
            channel: $channel,
            type: $type,
            context: $context,
        ) === null;
    }

    public function denialReason(
        Model $recipient,
        string $channel,
        ?string $type = null,
        array $context = [],
    ): ?string {
        if (! $recipient instanceof BookableSlotOffer) {
            return null;
        }

        if ($type !== MessagingSchedulingDestinationVerificationTransport::MESSAGE_TYPE) {
            return 'Scheduling slot offers may receive only destination verification messages.';
        }

        $channel = str_replace('-', '_', strtolower(trim($channel)));

        if (! in_array($channel, [
            MessageChannel::Email->value,
            MessageChannel::Sms->value,
        ], true)) {
            return 'Destination verification message channel is invalid.';
        }

        if (($context['purpose'] ?? null) !== MessagePurpose::Transactional->value
            || ($context['scope'] ?? null) !== PublicBookingDestinationVerificationService::SCOPE
        ) {
            return 'Destination verification message identity is invalid.';
        }

        if (data_get($context, 'meta.surface') !== PublicBookingDestinationVerificationService::SURFACE) {
            return 'Destination verification message provenance is invalid.';
        }

        if (! $recipient->exists
            || $recipient->isRescheduleOffer()
            || ! $recipient->isActiveAt()
        ) {
            return 'Destination verification booking offer is no longer active.';
        }

        $service = $recipient->bookableService()->first();

        if (! $service instanceof BookableService
            || $service->status !== BookableService::STATUS_ACTIVE
            || ! (bool) $service->is_public
        ) {
            return 'Destination verification booking service is no longer public and active.';
        }

        return null;
    }
}