<?php

namespace App\Support\ModuleIntegrations\Scheduling\Messaging;

use App\Modules\Messaging\Actions\ScheduleMessageAction;
use App\Modules\Messaging\Enums\MessageChannel;
use App\Modules\Messaging\Enums\MessagePurpose;
use App\Modules\Messaging\Payloads\EmailPayload;
use App\Modules\Messaging\Payloads\SmsPayload;
use App\Modules\Messaging\Services\MessageChannelAvailability;
use App\Modules\Messaging\Services\PhoneNumberNormalizer;
use App\Support\DestinationVerification\Contracts\DestinationVerificationTransport;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

final class MessagingSchedulingDestinationVerificationTransport implements DestinationVerificationTransport
{
    public const MESSAGE_TYPE = 'destination_verification';

    public function __construct(
        private readonly MessageChannelAvailability $channelAvailability,
        private readonly PhoneNumberNormalizer $phoneNumbers,
        private readonly ScheduleMessageAction $scheduleMessage,
    ) {}

    public function availableChannels(
        string $surface,
        string $purpose,
        string $scope,
    ): array {
        return $this->channelAvailability->visibleChannelsForSurface(
            surface: $surface,
            purpose: $purpose,
            scope: $scope,
            requireProvider: true,
        );
    }

    public function normalizeDestination(
        string $channel,
        string $destination,
    ): ?string {
        $channel = $this->channel($channel);
        $destination = trim($destination);

        if ($destination === '') {
            return null;
        }

        if ($channel === MessageChannel::Email->value) {
            $destination = strtolower($destination);

            return filter_var($destination, FILTER_VALIDATE_EMAIL) !== false
                ? $destination
                : null;
        }

        return $this->phoneNumbers->normalize($destination);
    }

    public function send(
        Model $recipient,
        string $surface,
        string $channel,
        string $purpose,
        string $scope,
        string $destination,
        string $code,
        string $dedupeKey,
        ?string $sourceIp = null,
    ): void {
        $channel = $this->channel($channel);
        $purpose = str_replace('-', '_', strtolower(trim($purpose)));
        $scope = str_replace('-', '_', strtolower(trim($scope)));
        $surface = str_replace('-', '_', strtolower(trim($surface)));

        if ($purpose !== MessagePurpose::Transactional->value) {
            throw new InvalidArgumentException(
                'Destination verification transport supports transactional messages only.',
            );
        }

        if (! in_array($channel, $this->availableChannels(
            surface: $surface,
            purpose: $purpose,
            scope: $scope,
        ), true)) {
            throw new InvalidArgumentException(
                "Destination verification channel [{$channel}] is not currently deliverable.",
            );
        }

        $destination = $this->normalizeDestination($channel, $destination);

        if ($destination === null) {
            throw new InvalidArgumentException(
                'Destination verification message requires a valid destination.',
            );
        }

        $payload = $channel === MessageChannel::Email->value
            ? [
                'to' => $destination,
                'subject' => 'Your verification code',
                'body' => "Your verification code is {$code}. It expires shortly.",
            ]
            : [
                'to' => $destination,
                'message' => "Your verification code is {$code}. It expires shortly.",
                'source_ip' => $sourceIp,
            ];

        $this->scheduleMessage->handle(
            recipient: $recipient,
            channel: $channel,
            purpose: $purpose,
            scope: $scope,
            messageType: self::MESSAGE_TYPE,
            payloadClass: $channel === MessageChannel::Email->value
                ? EmailPayload::class
                : SmsPayload::class,
            payload: $payload,
            sendAt: now(),
            dedupeKey: $dedupeKey,
            meta: [
                'surface' => $surface,
            ],
            queue: 'confirmation_messages',
        );
    }

    private function channel(string $channel): string
    {
        $channel = str_replace('-', '_', strtolower(trim($channel)));

        if (! in_array($channel, [
            MessageChannel::Email->value,
            MessageChannel::Sms->value,
        ], true)) {
            throw new InvalidArgumentException(
                "Unsupported destination verification channel [{$channel}].",
            );
        }

        return $channel;
    }
}