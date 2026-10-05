<?php

namespace App\Support\ModuleIntegrations\Portal\Messaging;

use App\Modules\Messaging\Contracts\MessageRecipientPayloadProvider;
use App\Modules\Messaging\Enums\MessageChannel;
use App\Modules\Portal\Models\PortalInvitation;
use App\Modules\Portal\Models\PortalUser;
use Illuminate\Database\Eloquent\Model;

final class PortalMessageRecipientPayloadProvider implements MessageRecipientPayloadProvider
{
    public function supports(Model $recipient): bool
    {
        return $recipient instanceof PortalInvitation
            || $recipient instanceof PortalUser;
    }

    public function destinationForChannel(
        Model $recipient,
        MessageChannel|string $channel,
    ): ?string {
        $channel = $channel instanceof MessageChannel
            ? $channel->value
            : strtolower(trim($channel));

        if ($recipient instanceof PortalInvitation) {
            return match ($channel) {
                'email' => $recipient->email,
                'sms' => $recipient->phone,
                default => null,
            };
        }

        if ($recipient instanceof PortalUser) {
            return match ($channel) {
                'email' => $recipient->email,
                'sms' => $recipient->phone,
                default => null,
            };
        }

        return null;
    }
}