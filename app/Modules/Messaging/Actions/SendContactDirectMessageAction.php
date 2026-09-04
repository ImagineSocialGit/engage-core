<?php

namespace App\Modules\Messaging\Actions;

use App\Models\User;
use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Enums\MessageChannel;
use App\Modules\Messaging\Enums\MessagePurpose;
use App\Modules\Messaging\Models\MessageTemplate;
use App\Modules\Messaging\Models\MessageTemplatePreset;
use App\Modules\Messaging\Models\MessageTemplateVersion;
use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Messaging\Payloads\EmailPayload;
use App\Modules\Messaging\Payloads\SmsPayload;
use App\Modules\Messaging\Services\ContactDirectMessageComposerPresenter;
use App\Modules\Messaging\Services\MessageChannelAvailability;
use App\Modules\Messaging\Services\MessageEligibilityGate;
use App\Modules\Messaging\Services\MessageMediaAuthoringService;
use App\Modules\Messaging\Services\MessageRecipientPayloadResolver;
use App\Modules\Messaging\Services\ReusableMessageTemplateCatalog;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class SendContactDirectMessageAction
{
    public const MESSAGE_TYPE = ContactDirectMessageComposerPresenter::MESSAGE_TYPE;

    public function __construct(
        private readonly ScheduleMessageAction $scheduleMessage,
        private readonly MessageChannelAvailability $channelAvailability,
        private readonly MessageEligibilityGate $messageEligibilityGate,
        private readonly MessageRecipientPayloadResolver $payloadResolver,
        private readonly ReusableMessageTemplateCatalog $templateCatalog,
        private readonly MessageMediaAuthoringService $mediaAuthoring,
    ) {}

    public function handle(
        Contact $contact,
        string $requestKey,
        string $channel,
        string $purpose,
        ?string $subject = null,
        ?string $body = null,
        ?string $message = null,
        ?int $templatePresetId = null,
        bool $mediaSubmitted = false,
        ?UploadedFile $mediaUpload = null,
        ?string $mediaAssetUuid = null,
        ?string $mediaPosterAssetUuid = null,
        ?string $mediaTitle = null,
        ?User $actor = null,
    ): ScheduledMessage {
        $channel = strtolower(trim($channel));
        $purpose = strtolower(trim($purpose));

        if (! $this->channelAvailability->isVisibleForSurface(
            channel: $channel,
            surface: ContactDirectMessageComposerPresenter::SURFACE,
            purpose: $purpose,
            scope: ContactDirectMessageComposerPresenter::SCOPE,
            requireProvider: true,
        )) {
            throw ValidationException::withMessages([
                'direct_message.channel' => 'That delivery channel is not currently available.',
            ]);
        }

        if (! $this->messageEligibilityGate->allows(
            contact: $contact,
            channel: $channel,
            purpose: $purpose,
            scope: ContactDirectMessageComposerPresenter::SCOPE,
            messageKey: self::MESSAGE_TYPE,
        )) {
            throw ValidationException::withMessages([
                'direct_message.channel' => 'Messaging permissions or suppression currently block this message on the selected channel.',
            ]);
        }

        [$payload, $sourcePreset, $sourceVersion] = $this->sourcePayload(
            templatePresetId: $templatePresetId,
            channel: $channel,
            purpose: $purpose,
        );

        if ($channel === MessageChannel::Email->value) {
            $payload['subject'] = trim((string) $subject);
            $payload['body'] = trim((string) $body);
            $currentMedia = is_array($payload['media'] ?? null)
                ? $payload['media']
                : [];

            if ($mediaSubmitted) {
                try {
                    $resolvedMedia = $this->mediaAuthoring->resolve(
                        submitted: true,
                        upload: $mediaUpload,
                        assetUuid: $mediaAssetUuid,
                        posterAssetUuid: $mediaPosterAssetUuid,
                        title: $mediaTitle,
                        currentMedia: $currentMedia,
                        uploadedBy: $actor,
                    );
                } catch (InvalidArgumentException $exception) {
                    throw ValidationException::withMessages([
                        'direct_message.media_asset_uuid' => $exception->getMessage(),
                    ]);
                }

                if ($resolvedMedia === null) {
                    unset($payload['media']);
                } else {
                    $payload['media'] = $resolvedMedia;
                }
            }
        } else {
            $payload['message'] = trim((string) $message);
            unset($payload['subject'], $payload['body'], $payload['media']);
        }

        $payload = $this->payloadResolver->resolve(
            recipient: $contact,
            channel: $channel,
            purpose: $purpose,
            scope: ContactDirectMessageComposerPresenter::SCOPE,
            messageType: self::MESSAGE_TYPE,
            payload: $payload,
        );

        if ($payload === null) {
            throw ValidationException::withMessages([
                'direct_message.channel' => 'This contact does not have a usable destination for the selected channel.',
            ]);
        }

        return $this->scheduleMessage->handle(
            recipient: $contact,
            channel: $channel,
            purpose: $purpose,
            scope: ContactDirectMessageComposerPresenter::SCOPE,
            messageType: self::MESSAGE_TYPE,
            payloadClass: $channel === MessageChannel::Email->value
                ? EmailPayload::class
                : SmsPayload::class,
            payload: $payload,
            sendAt: now(),
            context: $contact,
            dedupeKey: implode(':', [
                'crm_contact_direct_message',
                $contact->getKey(),
                $requestKey,
            ]),
            meta: array_filter([
                'source' => 'crm_contact_direct_message',
                'surface' => 'crm_contact_direct_message',
                'message_template' => $sourcePreset instanceof MessageTemplatePreset
                    ? [
                        'preset_id' => (int) $sourcePreset->getKey(),
                        'preset_key' => (string) $sourcePreset->key,
                    ]
                    : null,
            ], static fn (mixed $value): bool => $value !== null),
            queue: $channel === MessageChannel::Email->value ? 'emails' : 'sms',
        );
    }

    /**
     * @return array{0: array<string, mixed>, 1: ?MessageTemplatePreset, 2: ?MessageTemplateVersion}
     */
    private function sourcePayload(
        ?int $templatePresetId,
        string $channel,
        string $purpose,
    ): array {
        if ($templatePresetId === null) {
            return [[], null, null];
        }

        $preset = $this->templateCatalog
            ->presets([$channel], $purpose)
            ->first(fn (MessageTemplatePreset $preset): bool => (int) $preset->getKey() === $templatePresetId);

        if (! $preset instanceof MessageTemplatePreset) {
            throw ValidationException::withMessages([
                'direct_message.template_preset_id' => 'That reusable message is not available for the selected channel and purpose.',
            ]);
        }

        $template = $preset->canonicalTemplate;
        $version = $template instanceof MessageTemplate
            ? $template->currentVersion
            : null;

        if (! $version instanceof MessageTemplateVersion) {
            throw ValidationException::withMessages([
                'direct_message.template_preset_id' => 'That reusable message does not have published copy available.',
            ]);
        }

        return [$version->payload(), $preset, $version];
    }
}